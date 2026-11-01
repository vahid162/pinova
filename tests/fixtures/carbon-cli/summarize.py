"""Validate finite results from six ordinary CLI test invocations."""
import hashlib
import json
import os
from pathlib import Path
import re
import sys

CASES = ('now', 'string', 'immutable_input', 'timestamp', 'mixed_immutable', 'mixed_illuminate')
SETTINGS = {'opcache.enable': '1', 'opcache.enable_cli': '1', 'opcache.optimization_level': '0x7FFEBFFF',
            'opcache.jit': '1235', 'opcache.jit_buffer_size': '256M', 'opcache.protect_memory': '0',
            'opcache.validate_timestamps': '1', 'opcache.file_update_protection': '2', 'opcache.revalidate_freq': '2'}


def valid(value, case):
    return (isinstance(value, dict) and set(value) == {'case', 'ok', 'iterations', 'php', 'sapi', 'settings',
            'jit_enabled', 'jit_on', 'buffer_size'} and value['case'] == case and value['ok'] is True
            and type(value['iterations']) is int and value['iterations'] == 1000
            and value['php'] == '8.4.27' and value['sapi'] == 'cli' and value['settings'] == SETTINGS
            and value['jit_enabled'] is True and value['jit_on'] is True
            and type(value['buffer_size']) is int
            and 256 * 1024 * 1024 - 4096 <= value['buffer_size'] <= 256 * 1024 * 1024 + 4096)


def main():
    source = os.environ.get('GITHUB_SHA', '')
    assert re.fullmatch('[a-f0-9]{40}', source)
    root, target = Path(sys.argv[1]), Path(sys.argv[2])
    report = {'schema': 'pinova.carbon-cli-diagnostic.v1', 'source': source, 'mode': 'cli_only',
              'resource_acceptance': False, 'same_native_stack_verified': False,
              'fpm_acceptance': False, 'cases': []}
    for case in CASES:
        row = {'case': case, 'state': 'unexecuted'}
        code = root / (case + '.exit')
        output = root / (case + '.json')
        if code.is_file() and code.stat().st_size <= 4:
            raw = code.read_text().strip()
            if raw.isdigit() and 0 <= int(raw) <= 255:
                row.update(state='failed', exit_code=int(raw), reason='process_failed')
                if int(raw) == 0:
                    try:
                        if output.stat().st_size > 16384:
                            raise ValueError()
                        value = json.loads(output.read_bytes())
                        if valid(value, case):
                            row.update(state='passed', observation=value)
                            row.pop('reason')
                        else:
                            row['reason'] = 'assertion_or_configuration_failed'
                    except (OSError, ValueError, TypeError):
                        row['reason'] = 'finite_output_unavailable'
        report['cases'].append(row)
    repo = Path(os.environ['GITHUB_WORKSPACE'])
    here = Path(__file__).parent
    report['hashes'] = {label: hashlib.sha256(path.read_bytes()).hexdigest() for label, path in
                        {'case': here / 'case.php', 'summary': Path(__file__), 'lock': repo / 'composer.lock',
                         'installed': repo / 'vendor/composer/installed.json'}.items()}
    target.write_text(json.dumps(report, sort_keys=True) + '\n')
    return 0 if all(row['state'] == 'passed' for row in report['cases']) else 1


if __name__ == '__main__':
    raise SystemExit(main())
