import argparse
import base64
import datetime
import hashlib
import io
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile
import urllib.error
import urllib.request

GH_SHA = '7469124f706944133d6a169691dd1c6c3511b12e85878d255e044e2948df4c9b'
ARCHIVE_SHA = 'bb766f710eef8ede859c18578c72c327597cd4c8a85b06001b1f3843c6019386'
SEED_SHA = 'ee1c532601d1da9087840f0fa63394eb51017ae59806b2febdfc5366f33692dc'
ANCHORS = {'github': 'eb303461e0109458144b6ebcbfd62aaa6f6d7481e9ca77504b85f17761661462', 'sigstore': '27aea7e372aa7864065174f0661784fcf8b0e28b1dbfebeada31a0f1fb19167f'}
FLOORS = {'github': {'root': 9, 'timestamp': 1004, 'snapshot': 82, 'targets': 10}, 'sigstore': {'root': 16, 'timestamp': 816, 'snapshot': 166, 'targets': 14}}
MIRRORS = {'github': 'https://tuf-repo.github.com', 'sigstore': 'https://tuf-repo-cdn.sigstore.dev'}
NAMES = {'root.json', 'timestamp.json', 'snapshot.json', 'targets.json', 'targets/trusted_root.json'}
http = urllib.request.build_opener(urllib.request.ProxyHandler({}))


def sha(data):
    return hashlib.sha256(data).hexdigest()


def unique(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate JSON key')
        result[key] = value
    return result


def parse(data):
    assert len(data) <= 1000000
    return json.loads(data, object_pairs_hook=unique)


def write(path, body):
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as stream:
        stream.write(body)


def fetch(url, limit):
    request = urllib.request.Request(url, headers={'User-Agent': 'pinova-public-trust-readiness'})
    with http.open(request, timeout=20) as response:
        assert response.geturl().startswith('https://')
        body = response.read(limit + 1)
        assert len(body) <= limit
        return body


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--seed', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    assert os.environ.get('GITHUB_ACTIONS') == 'true' and os.environ.get('RUNNER_ENVIRONMENT') == 'github-hosted'
    os.umask(0o077)
    run = os.environ['GITHUB_RUN_ID']
    attempt = os.environ['GITHUB_RUN_ATTEMPT']
    assert re.fullmatch(r'[0-9]+', run) and re.fullmatch(r'[0-9]+', attempt)
    root = Path(args.output)
    assert root == Path('/tmp/pinova-release-trust-' + run + '-' + attempt)
    root.mkdir(mode=0o700)
    seed_body = Path(args.seed).read_bytes()
    assert len(seed_body) < 200000 and sha(seed_body) == SEED_SHA
    seed = parse(seed_body)
    assert seed['schema'] == 'pinova.public-tuf-seed.v1' and seed['anchors'] == ANCHORS and seed['floors'] == FLOORS and seed['mirrors'] == MIRRORS
    assert len(seed['entries']) == 10
    seen = set()
    for entry in seed['entries']:
        label, name = entry['label'], entry['path']
        assert label in MIRRORS and name in NAMES and (label, name) not in seen
        seen.add((label, name))
        body = base64.b64decode(entry['base64'], validate=True)
        assert len(body) == entry['bytes'] and sha(body) == entry['sha256']
        data = parse(body)
        host = MIRRORS[label].removeprefix('https://')
        write(root / 'trust/cache/gh/.sigstore/root' / host / name, body)
        if name == 'root.json':
            assert sha(body) == ANCHORS[label]
            write(root / 'trust/anchors' / (label + '-root.json'), body)
        if name != 'targets/trusted_root.json':
            role = name[:-5]
            assert data['signed']['_type'] == role and data['signed']['version'] >= FLOORS[label][role]
    assert seen == {(label, name) for label in MIRRORS for name in NAMES}
    archive = fetch('https://github.com/cli/cli/releases/download/v2.102.0/gh_2.102.0_linux_amd64.tar.gz', 25000000)
    assert sha(archive) == ARCHIVE_SHA
    with tarfile.open(fileobj=io.BytesIO(archive), mode='r:gz') as tar:
        members = tar.getmembers()
        assert len(members) <= 1000 and sum(m.size for m in members) <= 100000000
        selected = [m for m in members if m.name == 'gh_2.102.0_linux_amd64/bin/gh']
        assert len(selected) == 1 and selected[0].isfile() and selected[0].size < 80000000
        with tar.extractfile(selected[0]) as stream:
            binary = stream.read(80000001)
    assert sha(binary) == GH_SHA
    executable = root / 'bin/gh'
    write(executable, binary)
    executable.chmod(0o700)
    env = {'PATH': '/usr/bin:/bin', 'LC_ALL': 'C', 'NO_COLOR': '1', 'GH_CONFIG_DIR': str(root / 'gh-config'), 'XDG_CONFIG_HOME': str(root / 'xdg-config'), 'XDG_STATE_HOME': str(root / 'xdg-state'), 'XDG_CACHE_HOME': str(root / 'trust/cache'), 'GH_PROMPT_DISABLED': '1', 'GH_NO_UPDATE_NOTIFIER': '1'}
    receipt = {'schema': 'pinova.native-tuf-refresh.v1', 'head': os.environ['GITHUB_SHA'], 'run': run, 'attempt': attempt, 'gh_sha256': GH_SHA, 'archive_sha256': ARCHIVE_SHA, 'seed_sha256': SEED_SHA, 'anchors': ANCHORS, 'floors': FLOORS, 'results': {}}
    failed = False
    for label, mirror in MIRRORS.items():
        stdout_path = root / 'trust' / (label + '-trusted-root.jsonl')
        stderr_path = root / 'trust' / (label + '-refresh.stderr')
        command = [str(executable), 'attestation', 'trusted-root', '--hostname', 'github.com', '--tuf-url', mirror, '--tuf-root', str(root / 'trust/anchors' / (label + '-root.json'))]
        result = {'mirror': mirror, 'status': 'unknown'}
        try:
            assert sha(executable.read_bytes()) == GH_SHA
            with stdout_path.open('xb') as stdout, stderr_path.open('xb') as stderr:
                process = subprocess.run(command, env=env, stdout=stdout, stderr=stderr, timeout=55, check=False)
            result['exit_code'] = process.returncode
            assert stdout_path.stat().st_size <= 1000000 and stderr_path.stat().st_size <= 1000000
            assert process.returncode == 0
            cache = root / 'trust/cache/gh/.sigstore/root' / mirror.removeprefix('https://')
            roles = {}
            now = datetime.datetime.now(datetime.timezone.utc)
            for role, floor in FLOORS[label].items():
                body = (cache / (role + '.json')).read_bytes()
                signed = parse(body)['signed']
                assert signed['_type'] == role and isinstance(signed['version'], int) and signed['version'] >= floor
                assert datetime.datetime.fromisoformat(signed['expires'].replace('Z', '+00:00')) > now
                roles[role] = {'version': signed['version'], 'expires': signed['expires'], 'sha256': sha(body)}
            target = (cache / 'targets/trusted_root.json').read_bytes()
            expected = parse((cache / 'targets.json').read_bytes())['signed']['targets']['trusted_root.json']
            assert len(target) == expected['length'] and sha(target) == expected['hashes']['sha256']
            output = stdout_path.read_bytes()
            assert parse(output) == parse(target)
            result.update({'status': 'native_verified', 'roles': roles, 'target_sha256': sha(target), 'output_sha256': sha(output), 'checked_at': now.isoformat()})
        except Exception as error:
            result.update({'status': 'failed_closed', 'error_type': type(error).__name__})
            failed = True
        receipt['results'][label] = result
        write(root / (label + '-result.json'), (json.dumps(result, sort_keys=True) + '\n').encode())
    receipt['complete'] = not failed
    write(root / 'refresh-receipt.json', (json.dumps(receipt, sort_keys=True) + '\n').encode())
    print(json.dumps({'complete': not failed, 'results': {label: result['status'] for label, result in receipt['results'].items()}}, sort_keys=True))
    return 1 if failed else 0


if __name__ == '__main__':
    raise SystemExit(main())
