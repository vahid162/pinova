import test from 'node:test';
import assert from 'node:assert/strict';
import { createOrdinaryTimingRecorder, timingProofSerialization } from '../Integration/ordinary-timing-proof.mjs';
test('actual recorder serializes finite per-hop timings and excludes unrelated content', () => {
    const r = createOrdinaryTimingRecorder();
    assert.equal(r.start('secret/raw/url', 0, 1, 100), null);
    const first = r.start('on-normal-6-page', 0, 10, 1000);
    r.headers(first, 30, 301); r.end(first, 35);
    const second = r.start('on-normal-6-page', 1, 36, 1026);
    r.headers(second, 60, 200); r.end(second, 70);
    const copy = JSON.parse(JSON.stringify(r.snapshot()));
    assert.equal(copy.resource_acceptance, false); assert.equal(copy.incomplete, true);
    assert.equal(copy.completed_normal_requests, 1); assert.equal(copy.expected_normal_requests, 20);
    assert.deepEqual(copy.hops, [
        {label:'on-normal-6-page',hop:0,start_epoch_ms:1000,headers_after_ms:20,end_after_ms:25,status:301},
        {label:'on-normal-6-page',hop:1,start_epoch_ms:1026,headers_after_ms:24,end_after_ms:34,status:200}]);
    copy.hops[0].label = 'changed'; assert.equal(r.snapshot().hops[0].label, 'on-normal-6-page');
});
test('missing/reversed timing and bounds remain incomplete, never accepted', () => {
    const r = createOrdinaryTimingRecorder();
    const token = r.start('off-normal-1-page',0,20,1000);
    r.headers(token,19,200); r.end(token,25);
    assert.equal(r.snapshot().incomplete,true);
    assert.equal(r.start('off-normal-1-page',4,20,1000),null);
    assert.equal(r.start('off-normal-1-page',0,NaN,1000),null);
    const bounded = createOrdinaryTimingRecorder();
    for(let i=0;i<80;i++) bounded.start('on-normal-1-page',0,1,1);
    assert.equal(bounded.start('on-normal-1-page',0,1,1),null);
    assert.equal(bounded.snapshot().hops.length,80);
});
test('all twenty existing normal requests serialize complete coverage', () => {
    const r = createOrdinaryTimingRecorder();
    for (const mode of ['off','on']) for (let index=1;index<=10;index++) {
        const token=r.start(`${mode}-normal-${index}-page`,0,1,1000);
        r.headers(token,2,200); r.end(token,3);
    }
    const emptyPHP = JSON.parse(JSON.stringify(r.snapshot()));
    assert.equal(emptyPHP.client_coverage.state, 'observed');
    assert.equal(emptyPHP.php_observation_coverage.state, 'partial_or_unknown');
    assert.equal(emptyPHP.incomplete, true);
});
test('actual serialization requires unambiguous PHP pairs and known observed CPU', () => {
    const r=createOrdinaryTimingRecorder(), markers=[];
    for(const mode of ['off','on']) for(let index=1;index<=10;index++) {
        const label=`${mode}-normal-${index}-page`, pid=index+10+(mode==='on'?20:0);
        const token=r.start(label,0,1,1000);r.headers(token,2,200);r.end(token,3);
        markers.push({case:label,event:'request_start',pid,at:1,php_request_start_epoch_s:0.9},
            {case:label,event:'timing_wp_shutdown',pid,at:1.1,mu_wall_ms:100,mu_cpu_ms:50});
    }
    const serialize = list => JSON.parse(JSON.stringify(r.snapshot(list)));
    assert.equal(serialize(markers).incomplete,false);
    assert.equal(serialize(markers.slice(1)).incomplete,true);
    assert.equal(serialize([...markers,markers[0]]).incomplete,true);
    const unknown=markers.map(row=>({...row})); delete unknown[1].mu_cpu_ms;
    assert.equal(serialize(unknown).incomplete,true);
    assert.ok(serialize(unknown).php_observation_coverage.reasons.includes('php_timing_or_cpu_unknown'));
    const negative=markers.map(row=>({...row})); negative[1].mu_cpu_ms=-1;
    assert.equal(serialize(negative).incomplete,true);
    const wrongPid=markers.map(row=>({...row}));wrongPid[1].pid=999;
    assert.equal(serialize(wrongPid).incomplete,true);
});
test('actual frozen-gate serializer adds proof metadata without changing evaluation thresholds', () => {
    const thresholds=Object.freeze({normal:Object.freeze({ordinary_p95_ms:504.19619374999456})});
    const before=JSON.stringify(thresholds);
    const serialized=JSON.parse(JSON.stringify(timingProofSerialization('frozen-resource-gates.json', thresholds)));
    assert.equal(serialized.proof_metadata.resource_acceptance,false);
    assert.equal(serialized.normal.ordinary_p95_ms,504.19619374999456);
    assert.equal(JSON.stringify(thresholds),before);
    assert.equal(Object.hasOwn(thresholds,'proof_metadata'),false);
    assert.equal(timingProofSerialization('full-stack-result.json',thresholds),thresholds);
});
