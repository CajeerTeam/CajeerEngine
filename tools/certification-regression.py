#!/usr/bin/env python3
"""Test report fail-closed behavior and the real concurrent HTTPS load transport."""
import copy
import importlib.util
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading
import unittest

ROOT=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('evidence', ROOT/'tools/certification-evidence.py')
e=importlib.util.module_from_spec(spec); spec.loader.exec_module(e)
a=e.a


def fixture(directory, version, commit):
    for mode in ('source','dist'): (directory/f'cajeerengine-{version}-{mode}.zip').write_bytes(mode.encode())
    report={'schema':1,'ok':True,'version':version,'profile':'nginx-tls-fpm-pgsql','global_rate_limit':10000,'auth_rate_limit':20,
            'artifact_sha256':a.sha(directory/f'cajeerengine-{version}-dist.zip'),'checks':dict.fromkeys(a.SECURITY,True),
            'load':{'requests':400,'workers':8,'errors':0,'requests_per_second':100,'p95_ms':30,'routes':{r:{'requests':100,'errors':0,'p95_ms':30} for r in a.ROUTES}}}
    a.write_report(directory/'production-acceptance.json',report)
    a.write_report(directory/'dependency-audit.json',{'advisories':[],'abandoned':[]})
    e.seal(directory,version,commit)
    return report


class EvidenceTests(unittest.TestCase):
    def test_valid_and_tampered(self):
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp); fixture(d,'1.3.0','a'*40); e.verify(d,'1.3.0','a'*40)
            with self.assertRaises(ValueError): e.verify(d,'1.3.0','b'*40)
            (d/'cajeerengine-1.3.0-source.zip').write_bytes(b'changed')
            with self.assertRaises(ValueError): e.verify(d,'1.3.0','a'*40)

    def test_reject_report_failures(self):
        cases=[('security',lambda r:r['checks'].pop('rate_limit')),
               ('errors',lambda r:r['load'].update(errors=1)),
               ('slow route',lambda r:r['load']['routes']['page'].update(p95_ms=1001)),
               ('low throughput',lambda r:r['load'].update(requests_per_second=9)),
               ('wrong artifact',lambda r:r.update(artifact_sha256='a'*64)),
               ('wrong profile',lambda r:r.update(global_rate_limit=120)),
               ('incomplete load',lambda r:r['load']['routes'].pop('search')),
               ('failure',lambda r:r.update(ok=False))]
        for name,mutation in cases:
            with self.subTest(name=name),tempfile.TemporaryDirectory() as tmp:
                d=Path(tmp); r=fixture(d,'1.3.0','a'*40); mutation(r); a.write_report(d/'production-acceptance.json',r)
                with self.assertRaises(ValueError): e.seal(d,'1.3.0','a'*40)

    def test_reject_audit_and_missing_report(self):
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp); fixture(d,'1.3.0','a'*40)
            a.write_report(d/'dependency-audit.json',{'advisories':{'a/b':[{'id':'CVE-test'}]},'abandoned':[]})
            with self.assertRaises(ValueError): e.seal(d,'1.3.0','a'*40)
            a.write_report(d/'dependency-audit.json',{'advisories':[],'abandoned':{'a/b':'c/d'}})
            with self.assertRaises(ValueError): e.seal(d,'1.3.0','a'*40)
            (d/'production-acceptance.json').unlink()
            with self.assertRaises(FileNotFoundError): e.verify(d,'1.3.0','a'*40)

    def test_real_tls_load_transport_and_semantic_failure(self):
        # This is a test of the load generator, not a CMS benchmark. No production certificate is emitted.
        class Handler(BaseHTTPRequestHandler):
            protocol_version='HTTP/1.1'
            def do_GET(self):
                body=b'valid' if self.path!='/wrong' else b'wrong'; self.send_response(200); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body)
            def log_message(self,*args): pass
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp); cert=d/'cert.pem'; key=d/'key.pem'
            subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=acceptance.invalid','-addext','subjectAltName=DNS:acceptance.invalid','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            server=ThreadingHTTPServer(('127.0.0.1',0),Handler); ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
            try:
                client=a.Client('acceptance.invalid',cert,server.server_port)
                routes={r:('/ok',{},lambda b:b==b'valid') for r in a.ROUTES}
                result=a.load(client,routes)
                self.assertTrue(a.load_pass(result),result)
                print('TLS harness fixture:',json.dumps(result),flush=True)
                routes['page']=('/wrong',{},lambda b:b==b'valid')
                result=a.load(client,routes,count=16,workers=4)
                self.assertEqual(result['errors'],4); self.assertEqual(result['error_types'],{'semantic mismatch':4})
                with self.assertRaises(ssl.SSLCertVerificationError): a.Client('wrong.invalid',cert,server.server_port).request('/ok')
            finally: server.shutdown(); server.server_close(); thread.join()

if __name__=='__main__': unittest.main(verbosity=2)
