#!/usr/bin/env python3
"""End-to-end load runner test against an ephemeral local TLS staging fixture."""
import http.server
import json
import os
import ssl
import subprocess
import sys
import tempfile
import threading
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class Fixture(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def do_GET(self):
        code = 503 if self.path.startswith('/fail') else 200
        payload = b'{"ok":true}' if code == 200 else b'{"error":"test"}'
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, *_):
        pass


with tempfile.TemporaryDirectory(prefix='ce-staging-load-') as path:
    temp = Path(path)
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-sha256', '-nodes', '-days', '1',
                    '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost',
                    '-keyout', str(temp / 'key.pem'), '-out', str(temp / 'cert.pem')],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    host = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Fixture)
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.load_cert_chain(str(temp / 'cert.pem'), str(temp / 'key.pem'))
    host.socket = tls.wrap_socket(host.socket, server_side=True)
    thread = threading.Thread(target=host.serve_forever, daemon=True)
    thread.start()
    try:
        base = [sys.executable, str(ROOT / 'tools/staging-load.py'), '--base-url', f'https://localhost:{host.server_port}',
                '--ca', str(temp / 'cert.pem'), '--workers', '4', '--duration', '3', '--warmup', '0',
                '--min-rps', '2', '--max-p95-ms', '1500', '--max-errors', '0']
        env = dict(os.environ, CE_LOAD_DISPOSABLE='yes')
        good = subprocess.run(base + ['--route', '/ok', '--report', str(temp / 'good.json')],
                              env=env, text=True, capture_output=True, timeout=25)
        assert good.returncode == 0, (good.stdout[-2000:], good.stderr[-2000:])
        evidence = json.loads((temp / 'good.json').read_text())
        assert evidence['ok'] and evidence['requests'] > 0 and evidence['errors'] == 0
        assert evidence['rps'] >= 2 and evidence['routes']['/ok']['requests'] > 0
        bad = subprocess.run(base + ['--route', '/fail', '--report', str(temp / 'bad.json')],
                             env=env, text=True, capture_output=True, timeout=25)
        assert bad.returncode == 1, (bad.stdout[-2000:], bad.stderr[-2000:])
        evidence = json.loads((temp / 'bad.json').read_text())
        assert not evidence['ok'] and evidence['errors'] > 0 and evidence['error_types']['http_503'] > 0
        disabled = subprocess.run(base + ['--route', '/ok', '--report', str(temp / 'disabled.json')],
                                  env={**os.environ, 'CE_LOAD_DISPOSABLE': 'no'}, text=True, capture_output=True)
        assert disabled.returncode != 0 and not (temp / 'disabled.json').exists()
        print('OK: verified HTTPS load, strict performance gate, HTTP 503 detection and disposable safety')
    finally:
        host.shutdown()
        host.server_close()
