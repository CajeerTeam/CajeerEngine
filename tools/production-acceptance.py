#!/usr/bin/env python3
"""Destructive acceptance of the disposable, locally deployed TLS host; stdlib only."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import hashlib
import http.client
import json
import math
import os
from pathlib import Path
import socket
import ssl
import tempfile
import time
from urllib.parse import quote

REQUESTS = 400
WORKERS = 8
MAX_P95_MS = 1000
MIN_RPS = 10
ROUTES = ('page', 'search', 'health', 'editorial')
SECURITY = ('admin_headers', 'private_paths', 'anonymous', 'malformed', 'cookie_csrf',
            'viewer_rbac', 'revocation', 'media_validation', 'draft_privacy', 'rate_limit')


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def sha(path):
    with Path(path).open('rb') as f:
        return hashlib.file_digest(f, 'sha256').hexdigest()


def write_report(path, data):
    path = Path(path)
    with tempfile.NamedTemporaryFile(mode='w', dir=path.parent, delete=False) as f:
        tmp = Path(f.name)
        try:
            json.dump(data, f, indent=2, allow_nan=False)
            f.write('\n'); f.flush(); os.fsync(f.fileno())
        except BaseException:
            tmp.unlink(missing_ok=True)
            raise
    try:
        os.replace(tmp, path)
    finally:
        tmp.unlink(missing_ok=True)


class LocalTLS(http.client.HTTPSConnection):
    def connect(self):
        # SNI and hostname verification use the configured hostname; network is loopback only.
        raw = socket.create_connection(('127.0.0.1', self.port), self.timeout)
        try:
            self.sock = self._context.wrap_socket(raw, server_hostname=self.host)
        except BaseException:
            raw.close(); raise


class Client:
    def __init__(self, domain, ca, port=443):
        self.domain = domain; self.port = port
        self.context = ssl.create_default_context(cafile=str(ca))
        self.context.minimum_version = ssl.TLSVersion.TLSv1_2

    def request(self, path, method='GET', data=None, headers=None, raw=None):
        conn = LocalTLS(self.domain, self.port, timeout=10, context=self.context)
        headers = dict(headers or {})
        if data is not None:
            raw = json.dumps(data).encode(); headers['Content-Type'] = 'application/json'
        try:
            conn.request(method, path, body=raw, headers=headers)
            res = conn.getresponse()
            body = res.read(4 * 1024 * 1024 + 1)
            require(len(body) <= 4 * 1024 * 1024, 'response exceeded 4 MiB')
            return res.status, {k.lower(): v for k, v in res.getheaders()}, body
        finally:
            conn.close()


def expect(client, path, status, **kwargs):
    code, headers, body = client.request(path, **kwargs)
    require(code == status, f'{kwargs.get("method", "GET")} {path}: expected {status}, got {code}')
    return headers, body


def data(body):
    return json.loads(body)['data']


def percentile(values, p):
    return sorted(values)[max(0, math.ceil(len(values) * p) - 1)]


def load(client, routes, count=REQUESTS, workers=WORKERS):
    def one(i):
        name = ROUTES[i % len(ROUTES)]
        path, headers, validate = routes[name]
        start = time.monotonic()
        error = None
        try:
            status, _, body = client.request(path, headers=headers)
            if status != 200: error = f'HTTP {status}'
            elif not validate(body): error = 'semantic mismatch'
        except Exception as e:
            error = type(e).__name__  # no credentials, URLs or response bodies in report
        return name, (time.monotonic() - start) * 1000, error
    started = time.monotonic()
    with ThreadPoolExecutor(max_workers=workers) as pool:
        results = list(pool.map(one, range(count)))
    elapsed = max(time.monotonic() - started, 0.000001)
    per_route = {}
    for name in ROUTES:
        rows = [(ms, err) for route, ms, err in results if route == name]
        per_route[name] = {'requests': len(rows), 'errors': sum(e is not None for _, e in rows),
                           'p95_ms': round(percentile([ms for ms, _ in rows], .95), 3)}
    errors = {}
    for _, _, err in results:
        if err: errors[err] = errors.get(err, 0) + 1
    return {'requests': count, 'workers': workers, 'elapsed_seconds': round(elapsed, 3),
            'requests_per_second': round(count / elapsed, 3), 'errors': sum(errors.values()),
            'error_types': errors, 'p95_ms': round(percentile([ms for _, ms, _ in results], .95), 3),
            'routes': per_route}


def load_pass(report):
    def nonnegative_number(x):
        return type(x) in (int, float) and math.isfinite(x) and x >= 0
    return (report['requests'] == REQUESTS and report['workers'] == WORKERS and report['errors'] == 0
            and nonnegative_number(report['requests_per_second']) and report['requests_per_second'] >= MIN_RPS
            and nonnegative_number(report['p95_ms']) and report['p95_ms'] <= MAX_P95_MS
            and set(report['routes']) == set(ROUTES)
            and all(r['requests'] == REQUESTS // len(ROUTES) and r['errors'] == 0
                    and nonnegative_number(r['p95_ms']) and r['p95_ms'] <= MAX_P95_MS for r in report['routes'].values()))


def run(client, version, email, password, report):
    checks = report['checks']
    def passed(name): checks[name] = True; print('PASS', name, flush=True)
    def call(path, status=200, **kwargs): return expect(client, path, status, **kwargs)
    def login(email, password, browser=False):
        return call('/api/v1/auth/login', 201, method='POST', data={'email':email,'password':password},
                    headers={'X-Cajeer-Browser':'1'} if browser else {})
    h, b = call('/admin/')
    require(b'admin-app.js' in b and 'frame-ancestors' in h.get('content-security-policy','')
            and h.get('x-content-type-options') == 'nosniff' and h.get('x-frame-options') == 'SAMEORIGIN'
            and 'max-age=31536000' in h.get('strict-transport-security',''), 'admin security headers')
    h, _ = call('/admin/assets/admin-app.js')
    require(h.get('x-content-type-options') == 'nosniff', 'asset nosniff'); passed('admin_headers')
    for path in ('/.env','/%2eenv','/install/','/upgrade/','/uploads/acceptance.php','/composer.json'):
        call(path, 404)
    passed('private_paths')
    for path in ('/api/v1/users','/api/v1/%75sers','/api/v1/admin/dashboard','/metrics'):
        call(path,401)
    call('/api/v1/users',401,headers={'Authorization':'Bearer ce_invalid'}); passed('anonymous')
    call('/api/v1/auth/login',422,method='POST',raw=b'{',headers={'Content-Type':'application/json'})
    call('/api/v1/auth/login',415,method='POST',raw=b'x',headers={'Content-Type':'text/plain'}); passed('malformed')
    _, b = login(email,password); token=data(b)['access_token']; auth={'Authorization':'Bearer '+token}
    h,b = login(email,password,True); cookie=h.get('set-cookie',''); csrf=data(b)['csrf_token']
    require(all(x in cookie.lower() for x in ('secure','httponly','samesite=strict')) and 'access_token' not in data(b),'cookie flags')
    browser={'Cookie':cookie.split(';',1)[0]}
    call('/api/v1/auth/me',headers=browser)
    call('/api/v1/auth/logout',403,method='POST',headers=browser)
    call('/api/v1/auth/logout',method='POST',headers={**browser,'X-CSRF-Token':csrf})
    call('/api/v1/auth/me',401,headers=browser); passed('cookie_csrf')
    viewer_email='acceptance-viewer@example.invalid'
    _,b=call('/api/v1/users',201,method='POST',headers=auth,data={'email':viewer_email,'name':'Acceptance Viewer','password':password,'roles':['viewer']})
    viewer_id=data(b)['id']; _,b=login(viewer_email,password); viewer={'Authorization':'Bearer '+data(b)['access_token']}
    call('/api/v1/content/pages',403,method='POST',headers=viewer,data={'title':'denied','data':{}}); passed('viewer_rbac')
    call('/api/v1/users/'+viewer_id,method='DELETE',headers=auth)
    call('/api/v1/auth/me',401,headers=viewer); passed('revocation')
    call('/api/v1/media',422,method='POST',headers=auth,data={'filename':'fake.png','mime_type':'image/png','content_base64':'PGh0bWw+YmFkPC9odG1sPg=='})
    passed('media_validation')
    title='CertificationUniquePage'; path='/certification-unique-page'
    _,b=call('/api/v1/content/pages',201,method='POST',headers=auth,data={'title':title,'slug':path[1:],'status':'draft','locale':'ru','data':{'heading':title,'path':path,'body':'<p>Certification</p>'}})
    entry=data(b)['id']; call(path,404)
    call('/api/v1/content/pages/'+entry+'/publish',method='POST',headers=auth)
    page_headers, page_body=call(path)
    require(title.encode() in page_body, 'published page missing')
    require(page_headers.get('x-ratelimit-limit') == '10000', 'load profile quota not active')
    report['global_rate_limit']=int(page_headers['x-ratelimit-limit'])
    search='/api/v1/public/search?q='+quote(title)+'&locale=ru&type=pages'
    def found(b): return any(item.get('url') == path for item in data(b))
    require(found(call(search)[1]), 'published search missing')
    call('/api/v1/content/pages/'+entry+'/unpublish',method='POST',headers=auth)
    call(path,404); require(not found(call(search)[1]),'unpublished search leaked')
    call('/api/v1/content/pages/'+entry+'/publish',method='POST',headers=auth); passed('draft_privacy')
    # The workload uses a deliberately raised global limit, not the default anti-abuse capacity.
    routes={'page':(path,{},lambda b:title.encode() in b), 'search':(search,{},found),
            'health':('/api/v1/health',{},lambda b:json.loads(b).get('version')==version),
            'editorial':('/api/v1/content/pages',auth,lambda b: isinstance(data(b),list) and any(x.get('id')==entry for x in data(b)))}
    report['load']=load(client,routes)
    require(load_pass(report['load']),'load budget failed'); print('PASS mixed load',flush=True)
    # Verify rate limiting even for malformed auth input and concurrent requests.
    def attack(_):
        return client.request('/api/v1/auth/login',method='POST',raw=b'{',headers={'Content-Type':'application/json'})
    with ThreadPoolExecutor(max_workers=8) as pool: hits=list(pool.map(attack,range(32)))
    require(all(code in (422,429) for code,_,_ in hits) and sum(code==429 for code,_,_ in hits)>=12
            and all(int(h.get('retry-after','0'))>0 and h.get('x-ratelimit-limit')=='20' for code,h,_ in hits if code==429),'auth throttle bypass')
    report['auth_rate_limit']=20
    passed('rate_limit')
    # No new login needed after exhausting auth quota; fixture is disposable.


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--artifact',type=Path,required=True); parser.add_argument('--version',required=True)
    parser.add_argument('--ca',type=Path,required=True); parser.add_argument('--domain',required=True)
    parser.add_argument('--secrets',type=Path,required=True); parser.add_argument('--email',required=True)
    parser.add_argument('--report',type=Path,required=True)
    args=parser.parse_args()
    require(os.environ.get('CE_DEPLOY_DISPOSABLE')=='yes','disposable host required')
    require(not args.report.exists(),'report already exists')
    report={'schema':1,'version':args.version,'artifact_sha256':sha(args.artifact),'profile':'nginx-tls-fpm-pgsql',
            'checks':{},'ok':False}
    try:
        password=json.loads(args.secrets.read_text())['admin_password']
        run(Client(args.domain,args.ca),args.version,args.email,password,report)
        require(set(report['checks'])==set(SECURITY),'incomplete security checks')
        report['ok']=True
    finally:
        write_report(args.report,report)

if __name__=='__main__': main()
