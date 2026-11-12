#!/usr/bin/env python3
"""Local deployment safety tests; actual Nginx/TLS test when nginx and openssl exist."""
import json
import os
from pathlib import Path
import shutil
import socket
import ssl
import stat
import subprocess
import tempfile
import time
import unittest
from unittest.mock import patch
import urllib.request
import urllib.error
import zipfile

import production as p

SITE={'domain':'deploy.example.invalid','db_host':'127.0.0.1','db_port':5432,'db_name':'cms','db_user':'cms','db_sslmode':'disable','admin_email':'admin@example.invalid'}

class Safety(unittest.TestCase):
    def test_config_and_journal(self):
        with tempfile.TemporaryDirectory() as d, patch.object(p,'ETC',Path(d)), patch.object(p,'JOURNAL',Path(d)/'journal'):
            path=Path(d)/'site.json'; path.write_text(json.dumps(SITE))
            with patch.object(p,'root_file',lambda path,*a:path):
                self.assertEqual(p.config(),SITE)
                path.write_text(json.dumps({**SITE,'domain':'x; include /etc/passwd;'}))
                with self.assertRaises(ValueError): p.config()
                path.write_text(json.dumps({**SITE,'db_host':'db.remote','db_sslmode':'disable'}))
                with self.assertRaises(ValueError): p.config()
            p.record('deploying',version='1.2.5')
            self.assertEqual(json.loads(p.JOURNAL.read_text())['state'],'deploying')
            self.assertEqual(stat.S_IMODE(p.JOURNAL.stat().st_mode),0o600)

    def test_extract_rejects_unsafe(self):
        for name,mode in [('cajeerengine/../../escape',stat.S_IFREG),('cajeerengine/public/link',stat.S_IFLNK),('cajeerengine/a\\b',stat.S_IFREG),('/etc/passwd',stat.S_IFREG),('cajeerengine/a/',stat.S_IFDIR)]:
            with self.subTest(name=name), tempfile.TemporaryDirectory() as d:
                d=Path(d); z=d/'bad.zip'; stage=d/'stage'; stage.mkdir()
                with zipfile.ZipFile(z,'w') as f:
                    i=zipfile.ZipInfo(name); i.create_system=3; i.external_attr=(mode|0o777)<<16; f.writestr(i,'bad')
                with self.assertRaises(ValueError): p.extract(z,stage)
                self.assertFalse((d/'escape').exists())

    def test_extract_duplicate(self):
        import warnings
        with tempfile.TemporaryDirectory() as d:
            d=Path(d); z=d/'bad.zip'; stage=d/'stage'; stage.mkdir()
            with warnings.catch_warnings(), zipfile.ZipFile(z,'w') as f:
                warnings.simplefilter('ignore'); f.writestr('cajeerengine/a','one'); f.writestr('cajeerengine/a','two')
            with self.assertRaises(ValueError): p.extract(z,stage)

    @unittest.skipUnless(os.geteuid()==0,'requires root for ownership test')
    def test_extract_valid(self):
        import pwd,grp
        with tempfile.TemporaryDirectory() as d, patch.object(p,'USER','nobody'), patch.object(p,'GROUP','nogroup'):
            d=Path(d); z=d/'ok.zip'; stage=d/'stage'; stage.mkdir()
            with zipfile.ZipFile(z,'w') as f:
                f.writestr('cajeerengine/bin/cajeer','code'); f.writestr('cajeerengine/public/index.php','index')
            p.extract(z,stage)
            self.assertEqual((stage/'public/index.php').read_text(),'index')
            self.assertEqual((stage/'public/index.php').stat().st_uid,pwd.getpwnam('nobody').pw_uid)
            self.assertEqual(stat.S_IMODE((stage/'bin/cajeer').stat().st_mode),0o750)

    def test_activation_failure_keeps_gate(self):
        with tempfile.TemporaryDirectory() as d, patch.object(p,'GATE',Path(d)/'maintenance'), patch.object(p,'STATE',Path(d)), patch.object(p,'JOURNAL',Path(d)/'journal'), patch.object(p,'WORKERS_ALLOWED',Path(d)/'workers'), patch.object(p,'FPM_ALLOWED',Path(d)/'fpm'):
            p.gate_on()
            with patch.object(p,'verify_installed'),patch.object(p,'current_version',return_value='1.2.5'),patch.object(p,'app_run'),patch.object(p,'run'),patch.object(p,'health',side_effect=RuntimeError('not ready')):
                with self.assertRaises(RuntimeError): p.activate('1.2.5','a'*64,SITE)
            self.assertTrue(p.GATE.exists()); self.assertFalse(p.JOURNAL.exists())
            with patch.object(p,'verify_installed'),patch.object(p,'current_version',return_value='1.2.5'),patch.object(p,'app_run',return_value=json.dumps({'timestamp':int(time.time())+1})),patch.object(p,'run'),patch.object(p,'health'):
                p.activate('1.2.5','a'*64,SITE)
            self.assertFalse(p.GATE.exists()); self.assertEqual(json.loads(p.JOURNAL.read_text())['sha256'],'a'*64)

    @unittest.skipUnless(shutil.which('nginx') and shutil.which('openssl'),'nginx/openssl unavailable')
    def test_real_nginx_tls_gate(self):
        # This is a real Nginx process, not a mock. PHP-FPM/DB are exercised in the separate host smoke.
        with tempfile.TemporaryDirectory() as d:
            d=Path(d); d.chmod(0o755); etc=d/'etc'; etc.mkdir(); (etc/'tls').mkdir(); app=d/'app'; app.mkdir(); state=d/'state'; state.mkdir()
            public=app/'public'; public.mkdir(); (public/'admin/assets').mkdir(parents=True); (public/'uploads').mkdir(); (public/'install').mkdir()
            (public/'admin/index.html').write_text('admin'); (public/'admin/assets/admin-app.js').write_text('asset'); (public/'uploads/secret.php').write_text('secret'); (public/'install/index.php').write_text('install'); (public/'index.php').write_text('index'); (public/'.env').write_text('secret')
            subprocess.run(['openssl','req','-x509','-nodes','-newkey','rsa:2048','-days','2','-subj','/CN=deploy.example.invalid','-addext','subjectAltName=DNS:deploy.example.invalid','-keyout',str(etc/'tls/privkey.pem'),'-out',str(etc/'tls/fullchain.pem')],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            def port():
                with socket.socket() as s: s.bind(('127.0.0.1',0)); return s.getsockname()[1]
            http,tls,local=port(),port(),port()
            with patch.object(p,'APP',app),patch.object(p,'ETC',etc),patch.object(p,'GATE',state/'maintenance'),patch.object(p,'WORKERS_ALLOWED',state/'workers'),patch.object(p,'FPM_ALLOWED',state/'fpm'):
                _,_,nginx=p.service_config(SITE)
                nginx=nginx.replace('listen 80;','listen 127.0.0.1:'+str(http)+';').replace('listen 443 ssl;','listen 127.0.0.1:'+str(tls)+' ssl;').replace('127.0.0.1:18085','127.0.0.1:'+str(local))
                conf=d/'nginx.conf'; conf.write_text(f'pid {d}/nginx.pid; error_log {d}/error.log; events {{ worker_connections 32; }} http {{ access_log off; client_body_temp_path {d}/body; fastcgi_temp_path {d}/fastcgi; proxy_temp_path {d}/proxy; uwsgi_temp_path {d}/uwsgi; scgi_temp_path {d}/scgi; {nginx} }}')
                subprocess.run(['nginx','-t','-p',str(d),'-c',str(conf)],check=True,stdout=subprocess.DEVNULL)
                process=subprocess.Popen(['nginx','-p',str(d),'-c',str(conf),'-g','daemon off;'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
                try:
                    for _ in range(30):
                        try:
                            with socket.create_connection(('127.0.0.1',tls),timeout=.2): break
                        except OSError: time.sleep(.1)
                    def request(path):
                        req=urllib.request.Request('https://127.0.0.1:'+str(tls)+path,headers={'Host':SITE['domain']})
                        try:
                            with urllib.request.urlopen(req,context=ssl._create_unverified_context(),timeout=3) as r: return r.status,r.read()
                        except urllib.error.HTTPError as e: return e.code,e.read()
                    self.assertEqual(request('/admin/assets/admin-app.js'),(200,b'asset'))
                    self.assertEqual(request('/admin/deep')[0],200)
                    for path in ('/uploads/secret.php','/install/index.php','/.env','/upgrade/','/admin/assets/missing.js'):
                        self.assertEqual(request(path)[0],404,path)
                    p.gate_on(); self.assertEqual(request('/admin/assets/admin-app.js')[0],503)
                    p.GATE.unlink(); self.assertEqual(request('/admin/assets/admin-app.js')[0],200)
                finally:
                    process.terminate(); process.wait(timeout=10)

if __name__=='__main__': unittest.main(verbosity=2)
