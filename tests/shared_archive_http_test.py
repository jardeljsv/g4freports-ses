#!/usr/bin/env python3
"""Real controllers/storage/monthly exporters; isolated GLPI auth and source-denial fixture."""
import concurrent.futures
import hashlib
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

php = sys.argv[1] if len(sys.argv) > 1 else 'php'
fixture_script = Path(__file__).with_name('annual_archive_http_fixture.php').resolve()
checks = 0


def parts(raw):
    with zipfile.ZipFile(io.BytesIO(raw)) as package:
        assert package.testzip() is None
        return {name: package.read(name) for name in package.namelist()}


with tempfile.TemporaryDirectory(prefix='g4fr-shared-http-') as temporary:
    directory = Path(temporary)
    subprocess.run([php, str(fixture_script), '--prepare-shared', temporary], check=True)
    fixture = json.loads((directory / 'fixture.json').read_text())
    monthly = fixture['monthly_record']
    mid, month = monthly['id'], monthly['month']
    original_bundle = directory / 'documents/g4freports/monthly_archive/42' / month / mid
    original_hashes = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in original_bundle.iterdir()}
    servers, logs, ports = [], [], []
    try:
        for index in range(3):
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0))
                port = sock.getsockname()[1]
            log = (directory / f'server-{index}.log').open('wb')
            server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', str(fixture_script)],
                                      env=dict(os.environ, G4FR_ARCHIVE_FIXTURE_DIR=temporary), stdout=log, stderr=log)
            servers.append(server)
            logs.append(log)
            ports.append(port)
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.02)

        def request(path='/archive', action='list', user=42, method='GET', values=None, query=None,
                    sharing='[55]', worker=0, csrf=True, nonce='fixture-nonce', security_headers=True, return_raw=False):
            global checks
            fields = {'_ses_nonce': nonce}
            if action is not None:
                fields['action'] = action
            if action == 'list':
                fields['year'] = '2026'
            if method == 'POST':
                fields['_glpi_csrf_token'] = 'fixture-csrf' if csrf else 'incorrect'
            fields.update(values or {})
            params = {'fixture_user': str(user), 'fixture_shared_groups': sharing}
            params.update(query or {})
            if method == 'GET':
                params.update(fields)
            data = urllib.parse.urlencode(fields).encode() if method == 'POST' else None
            url = f'http://127.0.0.1:{ports[worker]}{path}?' + urllib.parse.urlencode(params)
            try:
                response = urllib.request.urlopen(urllib.request.Request(url, data=data, method=method), timeout=30)
            except urllib.error.HTTPError as error:
                response = error
            body = response.read()
            result = json.loads(body) if not return_raw and 'application/json' in response.headers.get('Content-Type', '') else body
            checks += 1
            if security_headers and csrf:
                assert response.headers.get('X-Content-Type-Options') == 'nosniff', response.headers
                assert 'no-store' in response.headers.get('Cache-Control', ''), response.headers
            if isinstance(result, dict) and result.get('ok') is False:
                assert temporary not in result['error'] and 'Stack trace' not in result['error'], result
            return response.status, result, response.headers

        code, data, _ = request(user=43)
        assert code == 200 and all(r['creator_id'] == 43 for r in data['revisions']), data
        assert data['sharing'] == {'enabled': True, 'allowed': True}
        code, data, _ = request(action='share', method='POST', values={'id': mid, 'month': month})
        assert code == 200 and data['record']['creator_id'] == 42
        shared = data['record']
        ref = shared['archive_ref']
        pinned = {'id': mid, 'month': month, 'archive_ref': ref}
        code, repeat, _ = request(action='share', method='POST', values={'id': mid, 'month': month})
        assert code == 200 and repeat['record'] == shared
        code, data, _ = request(user=43)
        assert any(r.get('archive_ref') == ref and r['creator_id'] == 42 for r in data['revisions'])
        code, data, _ = request(user=44)
        assert code == 200 and not data['sharing']['allowed'] and all(r['creator_id'] == 44 for r in data['revisions'])
        code, data, _ = request(sharing='[1]', query={'fixture_groups': '55,56'})
        assert code == 200 and not data['sharing']['allowed'], 'Session numeric index leaked group1 access'
        code, data, _ = request(query={'fixture_group_db_error': '1'})
        assert code == 200 and not data['sharing']['allowed'], 'Membership failure must fail closed'

        code, data, _ = request(action='archive', method='POST', values={'token': fixture['private_token'], 'draft_revision': fixture['private_revision']})
        assert code == 200 and data['record']['id'] == fixture['private_revision']
        code, data, _ = request(user=43)
        assert all(r['id'] != fixture['private_revision'] for r in data['revisions']), 'Configured sharing must not automatically publish a private draft'
        code, data, _ = request(action='archive', method='POST', values={'token': fixture['token'], 'draft_revision': fixture['draft_revision'], 'share': '1'})
        assert code == 200 and data['record']['visibility'] == 'shared'
        code, _, _ = request(action='archive', method='POST', values={'token': fixture['token'], 'draft_revision': fixture['draft_revision'], 'share': 'yes'})
        assert code == 400
        code, _, _ = request(action='archive', user=44, method='POST', values={'token': fixture['token'], 'draft_revision': fixture['draft_revision'], 'share': '1'})
        assert code == 403

        for fields in ({'id': mid, 'month': month, 'owner': '42'}, {'id': mid, 'month': month, 'archive_ref': ref}):
            code, _, _ = request(action='share', user=43, method='POST', values=fields)
            assert code == 400
        code, _, _ = request(action='share', user=44, method='POST', values={'id': mid, 'month': month})
        assert code == 403
        code, _, _ = request(action='share', method='POST', values={'id': mid, 'month': month}, csrf=False)
        assert code == 403
        code, _, _ = request(action='share', values={'id': mid, 'month': month})
        assert code == 405

        code, raw_snapshot, _ = request(action='download_snapshot', user=43, values=pinned)
        assert code == 200 and raw_snapshot == fixture['monthly_snapshot']
        code, exact_json, headers = request(action='download_snapshot', user=43, values=pinned, return_raw=True)
        assert code == 200 and exact_json == (original_bundle / 'snapshot.json').read_bytes()
        assert int(headers['Content-Length']) == len(exact_json) and hashlib.sha256(exact_json).hexdigest() == mid
        for user, sharing in ((44, '[55]'), (43, ''), (43, '[1]')):
            code, _, _ = request(action='download_snapshot', user=user, values=pinned, sharing=sharing)
            assert code == 404
        code, _, _ = request(action='download_snapshot', user=43, values=pinned, query={'fixture_groups': '56'})
        assert code == 404, 'Membership removal must revoke next request'
        code, data, _ = request(action='download_snapshot', values=pinned, sharing='')
        assert code == 200 and data == fixture['monthly_snapshot'], 'Author retains own saved link after sharing disabled'
        forged = dict(pinned, archive_ref='a' * 64)
        code, _, _ = request(action='download_snapshot', user=43, values=forged)
        assert code == 404
        code, _, _ = request(action='download_snapshot', user=43, values=dict(pinned, month='2026-05'))
        assert code == 404
        code, _, _ = request(action='download_snapshot', user=43, values={'id': mid, 'month': month})
        assert code == 404, 'Content hash alone must not confer cross-account access'

        expected = {}
        for fmt in ('docx', 'xlsx'):
            code, live, _ = request('/monthly_live', None, values={'token': fixture['monthly_token'], 'format': fmt}, query={'fixture_no_archive': '1'})
            assert code == 200, live
            expected[fmt] = parts(live)
            code, saved, headers = request('/monthly_export', None, user=43, values=dict(pinned, format=fmt))
            assert code == 200, saved
            assert parts(saved) == expected[fmt], 'Historical download must equal complete normal monthly export'
            assert f'rev-{mid[:12]}.{fmt}' in headers['Content-Disposition']
            assert 'word/document.xml' in expected[fmt] if fmt == 'docx' else 'xl/workbook.xml' in expected[fmt]
            code, _, _ = request('/monthly_export', None, user=44, values=dict(pinned, format=fmt))
            assert code == 404
            code, _, _ = request('/monthly_export', None, user=43, values=dict(pinned, format=fmt), query={'fixture_enabled': '0'})
            assert code == 403
        code, _, _ = request('/monthly_export', None, values=dict(pinned, format='json'))
        assert code == 400
        code, _, _ = request('/monthly_export', None, values=dict(pinned, format='docx', owner='42'))
        assert code == 400
        code, _, _ = request('/monthly_export', None, values=dict(pinned, format='docx'), nonce='incorrect')
        assert code == 403
        code, _, _ = request('/monthly_export', None, method='POST', values=dict(pinned, format='docx'))
        assert code == 405
        old = fixture['old_record']
        code, _, _ = request('/monthly_export', None, values={'id': old['id'], 'month': old['month'], 'format': 'docx'})
        assert code == 409, 'Unsupported rules must not be recalculated through current monthly renderer'

        payload = json.dumps({'months': [month], 'selections': [pinned]})
        code, data, _ = request(action='preview', user=43, method='POST', values={'payload': payload})
        assert code == 200 and data['report']['coverage']['available'] == 1, data
        for fmt in ('docx', 'xlsx'):
            code, data, _ = request('/annual_export', None, user=43, method='POST', values={'payload': payload, 'format': fmt})
            assert code == 200 and parts(data) != expected[fmt], 'Annual comparison must remain a separate report family'
        for path, action, values in (
            ('/archive', 'preview', {'payload': payload}),
            ('/archive', 'prepare_activity', pinned),
            ('/annual_export', None, {'payload': payload, 'format': 'docx'}),
        ):
            code, _, _ = request(path, action, user=44, method='POST', values=values)
            assert code == 404
        code, data, _ = request(action='prepare_activity', user=43, method='POST', values=pinned)
        assert code == 200 and data['activity']['revision'] == mid, data
        code, saved, _ = request('/monthly_export', None, user=43, values=dict(pinned, format='docx'))
        assert code == 200 and parts(saved) == expected['docx'], 'Annual daily preparation must not affect monthly output'

        # Three independent PHP servers share the same temporary admission volume.
        # A fixture holder acquires the production guard; the other two real monthly
        # routes must refuse before reading even a deliberately unreadable snapshot.
        snapshot_path = original_bundle / 'snapshot.json'
        original_snapshot = snapshot_path.read_bytes()
        with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
            holding = pool.submit(request, '/hold_admission', None, 42, 'GET', None, None, '[55]', 0, True, 'fixture-nonce', False)
            for _ in range(200):
                if (directory / 'admission_ready').exists():
                    break
                time.sleep(.01)
            assert (directory / 'admission_ready').exists(), 'Concurrent holder did not acquire real production admission'
            # Keep length so the cheap manifest stat passes. Hashing or decoding
            # content before admission would incorrectly return503 instead409.
            snapshot_path.write_bytes(b'X' * len(original_snapshot))
            jobs = [pool.submit(request, '/monthly_export', None, user, 'GET', dict(pinned, format='xlsx'), None, '[55]', worker)
                    for user, worker in ((43, 1), (45, 2))]
            try:
                assert [job.result()[0] for job in jobs] == [409, 409], 'Contention must be handled before full snapshot read'
                code, _, _ = request(user=42, worker=1)
                assert code == 200, 'Holding export must release session for other user actions'
            finally:
                snapshot_path.write_bytes(original_snapshot)
                (directory / 'admission_release').write_text('release')
            assert holding.result()[0] == 200
        code, saved, _ = request('/monthly_export', None, user=45, values=dict(pinned, format='xlsx'), worker=2)
        assert code == 200 and parts(saved) == expected['xlsx'], 'Retry after admission release must succeed'

        for failure in ('memory', 'timeout'):
            code, data, headers = request('/fail_admission', None, values={'failure': failure}, worker=1)
            assert code == 503 and data.get('ok') is False and 'recursos disponíveis' in data['error'], (code, dict(headers), data)
            assert 'Content-Disposition' not in headers and 'Fatal error' not in json.dumps(data)
            code, data, _ = request('/monthly_export', None, user=43, values=dict(pinned, format='docx'), worker=2)
            assert code == 200 and parts(data) == expected['docx'], 'Fatal resource failure must release admission slot'
        code, data, _ = request('/fail_admission', None, values={'failure': 'other'}, worker=1, security_headers=False)
        assert code == 500 and (not isinstance(data, dict) or 'recursos disponíveis' not in data.get('error', '')), 'Unrelated fatal errors must not be relabeled resource exhaustion'
        code, data, _ = request('/monthly_export', None, user=43, values=dict(pinned, format='docx'), worker=2)
        assert code == 200 and parts(data) == expected['docx']

        # Corrupt evidence is rejected; known archive metadata does not bypass hashes.
        snapshot_path.write_bytes(b'corrupt')
        code, _, _ = request('/monthly_export', None, user=43, values=dict(pinned, format='xlsx'))
        assert code == 503
        snapshot_path.write_bytes(original_snapshot)
        assert {name: hashlib.sha256((original_bundle / name).read_bytes()).hexdigest() for name in original_hashes} == original_hashes

        index_path = directory / 'documents/g4freports/monthly_archive/shared_catalogue/2026.json'
        original_index = index_path.read_bytes()
        index_path.write_bytes(b'corrupt shared catalogue')
        code, data, _ = request(action='archive', method='POST', values={'token': fixture['private_token'], 'draft_revision': fixture['private_revision'], 'share': '1'})
        assert code == 503 and data['archive_saved'] is True and data['record']['id'] == fixture['private_revision'], data
        code, data, _ = request(action='download_snapshot', values={'id': fixture['private_revision'], 'month': '2026-01'})
        assert code == 200 and data == fixture['private_snapshot'], 'Failed publication must leave original monthly archive readable'
        index_path.write_bytes(original_index)
        code, data, _ = request(action='archive', method='POST', values={'token': fixture['private_token'], 'draft_revision': fixture['private_revision'], 'share': '1'})
        assert code == 200 and data['record']['visibility'] == 'shared', 'Retry repairs publication without creating a new snapshot revision'
        print(f'Shared/archive monthly HTTP: {checks} requests passed; complete monthly Office parity, shared ACLs, immutable archive, no source clients, independent-process admission and retry checked.')
    finally:
        for server in servers:
            server.terminate()
        for server in servers:
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
        for log in logs:
            log.close()
