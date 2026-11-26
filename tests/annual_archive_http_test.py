#!/usr/bin/env python3
"""Actual archive/controllers/storage/exporters; GLPI auth and native CSRF are doubles."""
import io
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
import xml.etree.ElementTree as ET

php = sys.argv[1] if len(sys.argv) > 1 else 'php'
script = Path(__file__).with_name('annual_archive_http_fixture.php').resolve()
checks = 0
with tempfile.TemporaryDirectory(prefix='g4fr-archive-http-') as temp:
    directory = Path(temp)
    subprocess.run([php, str(script), '--prepare', temp], check=True)
    fixture = json.loads((directory / 'fixture.json').read_text())
    old_id = fixture['old_record']['id']
    current_id = fixture['draft_revision']
    other_id = fixture['other_record']['id']
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    env = dict(os.environ, G4FR_ARCHIVE_FIXTURE_DIR=temp)
    with (directory / 'server.log').open('wb') as log:
        server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', str(script)], env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.02)

            def request(action='list', method='GET', fields=None, query=None, path='/archive', closed=None, csrf=True):
                global checks
                values = {'_ses_nonce': 'fixture-nonce'}
                if action is not None:
                    values['action'] = action
                if action == 'list':
                    values['year'] = '2026'
                if method == 'POST':
                    values['_glpi_csrf_token'] = 'fixture-csrf' if csrf else 'wrong'
                values.update(fields or {})
                params = dict(query or {})
                params['fixture_request'] = str(checks + 1)
                if method == 'GET':
                    params.update(values)
                data = urllib.parse.urlencode(values).encode() if method == 'POST' else None
                url = f'http://127.0.0.1:{port}{path}?' + urllib.parse.urlencode(params)
                req = urllib.request.Request(url, data=data, method=method)
                try:
                    response = urllib.request.urlopen(req, timeout=30)
                except urllib.error.HTTPError as error:
                    response = error
                raw = response.read()
                content_type = response.headers.get('Content-Type', '')
                result = json.loads(raw) if 'application/json' in content_type else raw
                checks += 1
                if csrf:
                    assert response.headers.get('X-Content-Type-Options') == 'nosniff', response.headers
                    assert 'no-store' in response.headers.get('Cache-Control', ''), response.headers
                if isinstance(result, dict) and result.get('ok') is False:
                    assert temp not in result['error'] and 'Stack trace' not in result['error'], result
                if closed is not None:
                    # PHP's request shutdown writes this after the response; allow it to finish.
                    for _ in range(100):
                        try:
                            state = json.loads((directory / 'last_request.json').read_text())
                            if state['request'] == params['fixture_request']:
                                break
                        except (FileNotFoundError, json.JSONDecodeError):
                            pass
                        time.sleep(.002)
                    assert state['request'] == params['fixture_request'] and state['session_status'] == 1, 'Authorized work retained the PHP session lock'
                return response.code, result, response.headers

            def preview(selections=None, months=None, **options):
                payload = {'months': months or ['2026-01', '2026-02', '2026-03'],
                           'selections': selections if selections is not None else [
                               {'month': '2026-01', 'id': current_id}, {'month': '2026-02', 'id': old_id}]}
                return request('preview', 'POST', {'payload': json.dumps(payload)}, **options)

            # Read-only plugin users can read their own metadata despite invalid live registers.
            code, result, _ = request(closed=True)
            assert code == 200 and len(result['revisions']) == 1, result
            assert result['revisions'][0]['id'] == old_id and result['revisions'][0]['source_status'] == 'unknown'
            assert not {'summary', 'snapshot', 'tickets'} & result['revisions'][0].keys()
            assert request(query={'fixture_user': '44', 'fixture_configure': '1'})[1]['revisions'] == []
            for options, expected in [
                ({'query': {'fixture_user': '0'}}, 403),
                ({'query': {'fixture_enabled': '0'}}, 403),
                ({'fields': {'_ses_nonce': 'wrong'}}, 403),
                ({'fields': {'year': '1999'}}, 400),
                ({'fields': {'year': '2101'}}, 400),
                ({'fields': {'year[]': '2026', 'year': ''}}, 400),
                ({'action': 'preview'}, 405),
                ({'action': 'archive'}, 405),
                ({'action': 'prepare_activity'}, 405),
                ({'action': 'list', 'method': 'POST'}, 405),
                ({'action': 'unknown'}, 400),
            ]:
                assert request(**options)[0] == expected, options

            archive_fields = {'token': fixture['token'], 'draft_revision': current_id}
            assert request('archive', 'POST', archive_fields, csrf=False)[0] == 403
            for override, query, expected in [
                ({'draft_revision': 'f' * 64}, None, 409),
                ({'draft_revision': '', 'draft_revision[]': current_id}, None, 400),
                ({'token': '', 'token[]': fixture['token']}, None, 400),
                ({'token': fixture['old_token'], 'draft_revision': old_id}, None, 400),
                ({}, {'fixture_user': '43', 'fixture_configure': '1'}, 400),
            ]:
                values = dict(archive_fields, **override)
                code, result, _ = request('archive', 'POST', values, query=query, closed=True)
                assert code == expected and result['ok'] is False, (code, result)
            # Injected client values never replace the frozen server-owned draft.
            values = dict(archive_fields, snapshot='{"metrics":{"INS1":{"value":999}}}', owner='43', gross_value='999999', notes='Forged narrative')
            code, result, _ = request('archive', 'POST', values, closed=True)
            assert code == 200 and result['record']['id'] == current_id and result['csrf_token'] == 'next-test-token', result
            assert result['record']['summary']['metrics']['INS1']['value'] == 80
            assert request('archive', 'POST', archive_fields)[1]['record']['id'] == current_id, 'Retry not idempotent'
            assert len(request()[1]['revisions']) == 2, 'Idempotent archive created a duplicate'

            code, result, _ = preview(closed=True)
            assert code == 200 and result['ok'], result
            report = result['report']
            assert report['coverage'] == {'requested': 3, 'available': 2, 'missing_months': ['2026-03']}
            assert report['financial']['subtotal_cents']['gross'] == 200000, report['financial']
            assert report['months'][1]['summary']['rules_version'] == 'historical-rule-version'
            assert report['metrics']['INS1']['months'][1]['value'] == 85
            assert report['metrics']['INS1']['months'][1]['comparison']['delta_pp'] is None, 'Unknown provenance compared conclusively'
            assert preview([{'month': '2026-01', 'id': other_id}], ['2026-01'])[0] == 404
            assert preview(query={'fixture_user': '43', 'fixture_configure': '1'})[0] == 404, 'Admin bypassed archive owner'
            assert preview([{'month': '2026-03', 'id': current_id}], ['2026-03'])[0] == 404
            assert preview([{'month': '2026-01', 'id': 'f' * 64}], ['2026-01'])[0] == 404

            # Daily projection is an explicit, one-revision operation with the
            # same access boundary; original archived files are never rewritten.
            bundle = directory / 'documents' / 'g4freports' / 'monthly_archive' / '42' / '2026-01' / current_id
            original_files = {name: (bundle / name).read_bytes() for name in ['snapshot.json', 'summary.json', 'manifest.json', 'manifest.sha256']}
            activity_fields = {'id': current_id, 'month': '2026-01'}
            assert request('prepare_activity', 'POST', activity_fields, csrf=False)[0] == 403
            assert request('prepare_activity', 'POST', activity_fields, query={'fixture_user': '43', 'fixture_configure': '1'})[0] == 404
            assert request('prepare_activity', 'POST', dict(activity_fields, _ses_nonce='wrong'))[0] == 403
            assert request('prepare_activity', 'POST', {'id[]': current_id, 'month': '2026-01'})[0] == 400
            assert request('prepare_activity', 'POST', dict(activity_fields, month='2026-03'))[0] == 404
            code, prepared, _ = request('prepare_activity', 'POST', activity_fields, closed=True)
            assert code == 200 and prepared['activity']['month'] == '2026-01', (code, prepared)
            assert request('prepare_activity', 'POST', activity_fields)[1]['activity'] == prepared['activity'], 'Prepared projection not idempotent'
            for name, binary in original_files.items():
                assert (bundle / name).read_bytes() == binary, f'Preparation changed original {name}'
            code, prepared_preview, _ = preview(closed=True)
            assert code == 200 and prepared_preview['report']['months'][0]['summary']['activity'] == prepared['activity']
            assert prepared_preview['analytics']['volumes']['tickets']['rows'][0]['value'] == 123, 'Projection replaced frozen monthly total'
            assert len(prepared['activity']['days']) <= 31

            for payload in [
                '{broken', 'x' * 16385,
                json.dumps({'months': ['2026-01', '2026-01']}),
                json.dumps({'months': ['2026-01'], 'selections': [{'month': '2026-01', 'id': [current_id]}]}),
                json.dumps({'months': ['2026-01'], 'selections': [{'month': '2026-01', 'id': current_id}] * 2}),
                json.dumps({'months': ['2026-01'], 'report': {'metrics': {}}}),
                json.dumps({'months': [f'{2025 + n // 12}-{n % 12 + 1:02d}' for n in range(25)]}),
            ]:
                assert request('preview', 'POST', {'payload': payload})[0] == 400

            # Original evidence remains available without current-rule or source normalization.
            for ident, month, expected in [(current_id, '2026-01', fixture['snapshot']), (old_id, '2026-02', fixture['old_snapshot'])]:
                code, result, headers = request('download_snapshot', fields={'id': ident, 'month': month}, closed=True)
                assert code == 200 and result == expected, result
                assert headers['Content-Disposition'].startswith('attachment; filename="SES-arquivado-')
            for options, expected in [
                ({'fields': {'id': current_id, 'month': '2026-01'}, 'query': {'fixture_user': '43', 'fixture_configure': '1'}}, 404),
                ({'fields': {'id[]': current_id, 'month': '2026-01'}}, 400),
            ]:
                assert request('download_snapshot', **options)[0] == expected

            payload = json.dumps({'months': ['2026-01', '2026-02', '2026-03'], 'selections': [
                {'month': '2026-01', 'id': current_id}, {'month': '2026-02', 'id': old_id}]})
            for format_ in ['docx', 'xlsx']:
                code, binary, headers = request(None, 'POST', {'format': format_, 'payload': payload}, path='/annual_export', closed=True)
                assert code == 200 and isinstance(binary, bytes), (code, binary)
                assert headers['X-G4F-CSRF-Token'] == 'next-test-token'
                assert headers['Content-Disposition'].endswith('.' + format_ + '"')
                with zipfile.ZipFile(io.BytesIO(binary)) as archive:
                    assert archive.testzip() is None
                    for name in archive.namelist():
                        if name.endswith(('.xml', '.rels')):
                            ET.fromstring(archive.read(name))
                code, _, _ = request(None, 'POST', {'format': format_, 'payload': payload}, path='/annual_export', query={'fixture_user': '43', 'fixture_configure': '1'})
                assert code == 404
            assert request(None, 'GET', path='/annual_export')[0] == 405
            assert request(None, 'POST', {'format': 'xlsx', 'payload': payload}, path='/annual_export', csrf=False)[0] == 403
            assert request(None, 'POST', {'format': 'xlsx', 'payload': payload, '_ses_nonce': 'wrong'}, path='/annual_export')[0] == 403
            assert request(None, 'POST', {'format': 'pdf', 'payload': payload}, path='/annual_export')[0] == 400
            missing_payload = json.dumps({'months': ['2026-03'], 'selections': []})
            code, missing_preview, _ = request('preview', 'POST', {'payload': missing_payload}, closed=True)
            assert code == 200 and missing_preview['report']['coverage']['available'] == 0
            for format_ in ['docx', 'xlsx']:
                code, result, _ = request(None, 'POST', {'format': format_, 'payload': missing_payload}, path='/annual_export', closed=True)
                assert code == 400 and 'pelo menos uma revisão' in result['error'], result

            # A draft changed after its displayed preview is rejected; the archived revision stays frozen.
            draft_file = next((directory / 'temporary').glob('g4freports_ses_*/' + fixture['token'] + '.json'))
            draft = json.loads(draft_file.read_text())
            draft['snapshot']['manual']['notes'] = 'Changed after preview'
            draft_file.write_text(json.dumps(draft, ensure_ascii=False, separators=(',', ':')))
            assert request('archive', 'POST', archive_fields, closed=True)[0] == 409
            assert request('download_snapshot', fields={'id': current_id, 'month': '2026-01'})[1] == fixture['snapshot']

            # Listings/annual previews read compact metadata/summaries, never the raw snapshot body.
            bundle = directory / 'documents' / 'g4freports' / 'monthly_archive' / '42' / '2026-01' / current_id
            raw_file = bundle / 'snapshot.json'
            raw = raw_file.read_bytes()
            raw_file.write_bytes(b'!' + raw[1:])  # Preserve manifest stat/length, invalidate body checksum.
            assert request()[0] == 200 and preview()[0] == 200
            assert request('download_snapshot', fields={'id': current_id, 'month': '2026-01'})[0] == 503
            summary_file = bundle / 'summary.json'
            raw = summary_file.read_bytes()
            summary_file.write_bytes(b'!' + raw[1:])
            assert preview()[0] == 503, 'Corrupt published evidence silently ignored'
        finally:
            server.terminate()
            server.wait(timeout=5)
    logs = (directory / 'server.log').read_text()
    assert 'Fatal error' not in logs and 'Warning:' not in logs and 'Source client invoked' not in logs, logs
print(f'Annual archive HTTP: {checks} requests passed; sources disabled, owner boundaries, immutable values, session release and real Office files checked. GLPI CSRF emulated.')
