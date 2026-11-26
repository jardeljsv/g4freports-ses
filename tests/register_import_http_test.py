#!/usr/bin/env python3
"""Real multipart upload to the real endpoint; GLPI auth/config are isolated doubles."""
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
import uuid

php = sys.argv[1] if len(sys.argv) > 1 else 'php'
fixture_script = Path(__file__).with_name('register_import_http_fixture.php').resolve()
checks = 0
with tempfile.TemporaryDirectory(prefix='g4fr-http-') as directory:
    directory = Path(directory)
    subprocess.run([php, str(fixture_script), '--prepare', str(directory)], check=True)
    fixture = json.loads((directory / 'fixture.json').read_text())
    before = (directory / 'state.json').read_bytes()
    with socket.socket() as socket_:
        socket_.bind(('127.0.0.1', 0))
        port = socket_.getsockname()[1]
    env = dict(os.environ, G4FR_IMPORT_FIXTURE_STATE=str(directory / 'state.json'))
    with (directory / 'server.log').open('wb') as log:
        server = subprocess.Popen([php, '-d', 'upload_max_filesize=3M', '-d', 'post_max_size=5M', '-S', f'127.0.0.1:{port}', str(fixture_script)], env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.02)
            def request(kind='staff', data=None, params='', fields=None, filename='register.xlsx', method='POST'):
                global checks
                boundary = 'g4fr' + uuid.uuid4().hex
                values = dict(kind=kind, rows=json.dumps(fixture[kind]), revision=fixture[kind + '_revision'], _ses_nonce='fixture-nonce', _glpi_csrf_token='fixture-csrf')
                values.update(fields or {})
                body = bytearray()
                for name, value in values.items():
                    body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
                body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="workbook"; filename="{filename}"\r\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n'.encode())
                body.extend(data if data is not None else (directory / (kind + '.xlsx')).read_bytes())
                body.extend(f'\r\n--{boundary}--\r\n'.encode())
                req = urllib.request.Request(f'http://127.0.0.1:{port}/import{params}', data=bytes(body) if method == 'POST' else None, method=method, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
                try:
                    response = urllib.request.urlopen(req, timeout=15)
                except urllib.error.HTTPError as error:
                    response = error
                payload = json.loads(response.read())
                assert (directory / 'state.json').read_bytes() == before, 'Preview wrote configuration'
                checks += 1
                return response.code, payload
            code, result = request()
            assert code == 200 and result['ok'] and result['summary']['updated'] == 1, result
            assert result['rows'][0]['monthly_value'] == '3100.00' and result['rows'][0]['billing_days_overrides'] == {'2026-05': 0, '2026-06': 22}
            assert result['csrf_token'] == 'next-test-token'
            code, result = request('tests')
            assert code == 200 and result['summary']['unchanged'] == 1 and result['rows'][0]['number'] == '00123'
            for options, expected in [
                ({'params': '?fixture_user=0'}, 403),
                ({'params': '?fixture_configure=0'}, 403),
                ({'fields': {'_ses_nonce': 'wrong'}}, 403),
                ({'fields': {'_glpi_csrf_token': 'wrong'}}, 403),
                ({'fields': {'revision': 'a' * 64}}, 409),
                ({'fields': {'rows': '{broken'}}, 400),
                ({'data': b'not a zip'}, 400),
                ({'data': b'x' * 2097153}, 400),
                ({'filename': 'register.xlsm'}, 400),
                ({'data': (directory / 'tests.xlsx').read_bytes()}, 400),
                ({'method': 'GET'}, 405),
            ]:
                code, result = request(**options)
                assert code == expected and not result['ok'], (options.keys(), code, result)
        finally:
            server.terminate()
            server.wait(timeout=5)
    logs = (directory / 'server.log').read_text()
    assert 'Fatal error' not in logs and 'Warning:' not in logs, logs
print(f'Register import real HTTP multipart: {checks} requests passed; no configuration writes. GLPI CSRF emulated.')
