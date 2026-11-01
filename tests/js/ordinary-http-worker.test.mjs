import test from 'node:test';
import assert from 'node:assert/strict';
import { Worker } from 'node:worker_threads';
import { createOrdinaryHTTP, ordinaryTransport, ordinaryRuntimeVersion } from '../Integration/ordinary-http-worker.mjs';

async function responder() {
    const worker = new Worker(`
        const { parentPort } = require('node:worker_threads');
        const { createServer } = require('node:http');
        const server = createServer((req,res) => {
            res.setHeader('X-Pinova-Fixture-PHP','8.4.27');
            if(req.url === '/php-missing') res.removeHeader('X-Pinova-Fixture-PHP');
            if(req.url === '/php-malformed') res.setHeader('X-Pinova-Fixture-PHP','unexpected');
            if(req.url === '/php-wrong') res.setHeader('X-Pinova-Fixture-PHP','8.3.36');
            if(req.url === '/delay') {
                parentPort.postMessage({type:'entered'});
                setTimeout(()=>res.end('ok'),80);return;
            }
            if(req.url === '/held') {parentPort.postMessage({type:'entered'});return;}
            if(req.url === '/reset') {req.socket.destroy();return;}
            if(req.url === '/stall') return;
            if(req.url === '/large') {res.end('x'.repeat(2*1024*1024+1));return;}
            if(req.url === '/redirect') {res.writeHead(302,{Location:'/ok'}).end();return;}
            if(req.url === '/missing') {res.writeHead(302).end();return;}
            if(req.url === '/foreign') {res.writeHead(302,{Location:'http://127.0.0.2:1/ok'}).end();return;}
            if(req.url === '/credentials') {res.writeHead(302,{Location:'http://user:fixture@127.0.0.1:'+server.address().port+'/ok'}).end();return;}
            if(req.url.startsWith('/chain/')) {const n=Number(req.url.slice(7));res.writeHead(302,{Location:'/chain/'+(n-1)}).end();return;}
            if(req.url === '/shape' && (req.method!=='GET'||req.socket.remoteAddress!=='127.0.0.120'||req.headers['x-pinova-fixture-case']!=='worker-test')) {res.writeHead(418).end();return;}
            res.end('ok');
        });
        server.listen(0,'127.0.0.1',()=>{
            const closed=createServer();closed.listen(0,'127.0.0.1',()=>{
                const closedPort=closed.address().port;closed.close(()=>parentPort.postMessage({type:'ready',port:server.address().port,closedPort}));
            });
        });
    `, { eval: true });
    let enteredResolve;
    const entered = new Promise(resolve => { enteredResolve = resolve; });
    const ready = await new Promise((resolve,reject)=> {
        worker.on('error',reject);
        worker.on('message',message=>{if(message.type==='ready')resolve(message);if(message.type==='entered')enteredResolve();});
    });
    return { base:`http://127.0.0.1:${ready.port}`, closedBase:`http://127.0.0.1:${ready.closedPort}`,
        entered, close:()=>worker.terminate() };
}
const input = (base, route, category='ordinary') => ({url:base+route,label:'worker-test',category});

async function using(fn) {
    const server=await responder();let client;
    try {client=await createOrdinaryHTTP({base:server.base,phpMinor:'8.4'});return await fn(server,client);}
    finally {if(client)assert.equal((await client.close()).terminated,true);await server.close();}
}

test('blocked parent must not inflate ordinary HTTP timer', async()=>{
    const server=await responder();let client;
    const inline=process.env.PINOVA_ORDINARY_HTTP_TRANSPORT==='inline';
    try {
        client=inline ? {request:request=>ordinaryTransport({base:server.base,phpMinor:'8.4'},request),close:async()=>({terminated:true})} :
            await createOrdinaryHTTP({base:server.base,phpMinor:'8.4'});
        const response=client.request(input(server.base,'/delay'));
        await server.entered; // The independent responder witnessed the actual outgoing GET.
        const blockStart=performance.now();while(performance.now()-blockStart<300) {}
        const result=await response;
        console.log('ORDINARY_HTTP_CONTROL='+JSON.stringify({transport:inline?'inline':'worker',independent_responder:true,
            responder_delay_ms:80,parent_block_ms:performance.now()-blockStart,recorded_http_ms:result.ms,status:result.status}));
        assert.equal(result.status,200);
        assert.ok(result.ms<225,'BLOCKED_PARENT_TIMING_REGRESSION');
    } finally {if(client)await client.close();await server.close();}
});

test('actual worker preserves source/header shape, finite diagnostics and ten concurrent warmups', async()=>using(async(server,client)=>{
    const rows=await Promise.all(Array.from({length:10},()=>client.request(input(server.base,'/shape','shop'))));
    for(const row of rows) {
        assert.equal(row.status,200);assert.ok(row.ms>0);assert.ok(Number.isFinite(row.completed));
        assert.deepEqual(row.diagnostics,[{category:'shop',status:200,selected_php:true}]);
        assert.deepEqual(Object.keys(row).sort(),['completed','diagnostics','ms','php_version','status']);
    }
    const closed=await client.close();assert.equal(closed.complete,true);assert.equal(closed.requests_completed,10);
}));

test('actual worker preserves redirect limit and refuses foreign or credential-bearing destinations', async()=>using(async(server,client)=>{
    const redirect=await client.request(input(server.base,'/redirect'));
    assert.equal(redirect.status,200);assert.deepEqual(redirect.diagnostics.map(d=>d.status),[302,200]);
    const bounded=await client.request(input(server.base,'/chain/5'));
    assert.equal(bounded.status,302);assert.equal(bounded.diagnostics.length,4);
    for(const route of ['/foreign','/credentials','/missing']) {
        await assert.rejects(client.request(input(server.base,route)),error=>error.fixtureAssertion===true&&
            ['unsafe_redirect','missing_location'].includes(error.code)&&error.diagnostics[0].selected_php===true);
    }
}));

test('actual worker retains reset/refusal and body-bound failure diagnostics', async()=>using(async(server,client)=>{
    await assert.rejects(client.request(input(server.base,'/reset')),error=>error.diagnostics[0].failure==='connection_reset');
    await assert.rejects(client.request(input(server.base,'/large')),error=>error.diagnostics.length===1&&
        ['transport_failure','connection_reset'].includes(error.diagnostics[0].failure));
    const refused=await createOrdinaryHTTP({base:server.closedBase,phpMinor:'8.4'});
    try {await assert.rejects(refused.request(input(server.closedBase,'/ok')),error=>error.diagnostics[0].failure==='connection_refused');}
    finally {assert.equal((await refused.close()).complete,true);}
}));

test('actual worker retains the original 25-second transport inactivity timeout',{timeout:40000},async()=>using(async(server,client)=>{
    const started=performance.now();
    await assert.rejects(client.request(input(server.base,'/stall')),error=>error.diagnostics.length===1&&
        ['transport_failure','connection_reset'].includes(error.diagnostics[0].failure));
    assert.ok(performance.now()-started>=24500);
}));

test('owned cleanup rejects pending requests without replay and reports lifecycle failure',async()=>{
    const server=await responder();const client=await createOrdinaryHTTP({base:server.base,phpMinor:'8.4'});
    try {
        const rejection=client.request(input(server.base,'/held')).catch(error=>error);
        await server.entered;
        const closing=client.close();assert.equal(client.close(),closing);
        const receipt=await closing;
        assert.equal(receipt.terminated,true);assert.equal(receipt.complete,false);
        assert.equal(receipt.lifecycle_failure,'ordinary_worker_pending_at_cleanup');assert.equal(receipt.requests_started,1);
        assert.equal((await rejection).code,'ordinary_worker_pending_at_cleanup');
        await assert.rejects(client.request(input(server.base,'/ok')));
    } finally {await client.close();await server.close();}
});

test('actual worker response shape feeds the shared warmup runtime-profile consumer',async()=>using(async(server,client)=>{
    const warm=await Promise.all(Array.from({length:8},()=>client.request(input(server.base,'/ok'))));
    const receipt={profile:{}};
    receipt.profile.php_fpm=ordinaryRuntimeVersion(warm,'8.4');
    assert.equal(receipt.profile.php_fpm,'8.4.27');
    assert.ok(warm.every(response=>response.php_version==='8.4.27'&&!Object.hasOwn(response,'headers')));
    for(const route of ['/php-missing','/php-malformed','/php-wrong']) {
        const response=await client.request(input(server.base,route));
        assert.equal(response.status,200);
        assert.equal(response.diagnostics[0].selected_php,false);
        assert.throws(()=>ordinaryRuntimeVersion([response],'8.4'),error=>error.code==='runtime_unknown'&&error.fixtureAssertion===true);
        if(route!=='/php-wrong')assert.equal(response.php_version,null);
    }
}));
