#!/usr/bin/env python3
"""Real Chromium network/consent regression, no external accounts required.
Run: python3 -m pip install playwright; python3 -m playwright install chromium;
     python3 tools/consent-browser-regression.py
"""
import html
import json
import os
import shutil
import subprocess
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / 'resources/consent'
POLICY = json.loads(subprocess.check_output(['php', '-r', "require 'core/Privacy/ConsentPolicy.php'; echo json_encode(CajeerEngine\\Privacy\\ConsentPolicy::defaults());"], cwd=ROOT))
POLICY.update(version='a'*64, script_origins=['https://tracker.example'])
STATE = {'policy': POLICY.copy(), 'fail': False}
REQUESTS = []

def check(condition, message):
    if not condition:
        raise AssertionError(message)
    print('PASS', message, flush=True)

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_GET(self):
        path=self.path.split('?')[0]
        REQUESTS.append(path)
        status=200
        if path == '/api/v1/public/consent':
            status=503 if STATE['fail'] else 200
            body=json.dumps({'data': STATE['policy']}).encode(); mime='application/json'
        elif path.startswith('/assets/ce-consent/'):
            name=path.rsplit('/',1)[-1]; body=(ASSETS/name).read_bytes(); mime='application/javascript' if name.endswith('.js') else 'text/css'
        elif path in ['/analytics.js','/marketing.js','/functional.js']:
            kind=path[1:-3]; cookie={'analytics':'_ga','marketing':'_fbp','functional':'ce_optional_test'}[kind]
            body=f'window.{kind}Loaded=(window.{kind}Loaded||0)+1; document.cookie="{cookie}=test; Path=/";'.encode(); mime='application/javascript'
        else:
            dynamic='0' if path=='/static' else '1'
            config=html.escape(json.dumps(POLICY),quote=True)
            body=f'''<!doctype html><html lang="en"><head><meta charset="utf-8"><link rel="stylesheet" href="/assets/ce-consent/consent.css"><script defer src="/assets/ce-consent/consent.js" data-ce-consent-config="{config}" data-ce-consent-dynamic="{dynamic}"></script></head><body><h1>Consent fixture</h1>
            <script type="text/plain" data-ce-consent="analytics" data-ce-src="/analytics.js"></script>
            <script type="text/plain" data-ce-consent="marketing" data-ce-src="/marketing.js"></script>
            <script type="text/plain" data-ce-consent="functional" data-ce-src="/functional.js"></script>
            <script type="text/plain" data-ce-consent="analytics" data-ce-src="https://tracker.example/allowed.js"></script>
            <script type="text/plain" data-ce-consent="analytics" data-ce-src="https://evil.example/disallowed.js"></script>
            <script type="text/plain" data-ce-consent="analytics" data-ce-src="javascript:alert(1)"></script>
            </body></html>'''.encode();mime='text/html'
        self.send_response(status);self.send_header('Content-Type',mime);self.send_header('Cache-Control','no-store')
        if mime=='text/html':self.send_header('Content-Security-Policy',"default-src 'self'; script-src 'self' https://tracker.example; style-src 'self'; connect-src 'self'")
        self.end_headers();self.wfile.write(body)

server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
threading.Thread(target=server.serve_forever,daemon=True).start()
base=f'http://127.0.0.1:{server.server_port}'
try:
    with sync_playwright() as pw:
        executable=os.getenv('CHROMIUM_PATH') or shutil.which('chromium')
        browser=pw.chromium.launch(headless=True,executable_path=executable,args=['--no-sandbox'])
        ctx=browser.new_context(); external=[]
        def route(r):
            external.append(r.request.url);r.fulfill(status=200,content_type='application/javascript',body='window.externalLoaded=true;')
        ctx.route('https://tracker.example/**',route);ctx.route('https://evil.example/**',route)
        page=ctx.new_page(); errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.goto(base);page.get_by_role('button',name='Reject all').wait_for()
        check(not any(x in REQUESTS for x in ['/analytics.js','/marketing.js','/functional.js']) and not external,'no optional HTTP requests before consent')
        check(page.evaluate("CajeerConsent.allows('analytics')") is False,'default denied')
        check(page.evaluate("dataLayer[0][2].ad_user_data")=='denied','Google Consent Mode defaults queued without network')
        page.get_by_role('button',name='Reject all').click();page.reload();page.wait_for_function('CajeerConsent.getState().ready')
        check(not page.get_by_role('dialog').is_visible() and not page.evaluate("CajeerConsent.allows('analytics')"),'rejection persisted across reload')
        page.get_by_role('button',name='Privacy preferences').click();page.get_by_role('checkbox',name='Analytics').check();page.get_by_role('button',name='Save choices').click();page.wait_for_function('window.analyticsLoaded===1 && window.externalLoaded===true')
        check(page.evaluate("CajeerConsent.allows('analytics') && !CajeerConsent.allows('marketing')") and '/marketing.js' not in REQUESTS and '/functional.js' not in REQUESTS,'granular consent loads only analytics')
        check(external==['https://tracker.example/allowed.js'],'only approved external origin loaded')
        check(page.evaluate("dataLayer.at(-1)[2].analytics_storage")=='granted' and page.evaluate("dataLayer.at(-1)[2].ad_storage")=='denied','Google consent update follows categories')
        check(page.evaluate("JSON.parse(localStorage.getItem('ce.consent.v1')).history.length") == 2,'bounded local decision history recorded')
        tab=ctx.new_page();tab.goto(base);tab.wait_for_function('window.analyticsLoaded===1')
        page.get_by_role('button',name='Privacy preferences').click();page.get_by_role('button',name='Reject all').click();page.wait_for_function('CajeerConsent.getState().ready && !CajeerConsent.allows("analytics")');tab.wait_for_function('CajeerConsent.getState().ready && !CajeerConsent.allows("analytics")');tab.wait_for_function('window.analyticsLoaded===undefined')
        check(page.evaluate("!document.cookie.includes('_ga=')") and tab.evaluate("!document.cookie.includes('_ga=')"),'revocation removes managed cookies and reloads active tabs')
        tab.close();page.get_by_role('button',name='Privacy preferences').click();page.get_by_role('button',name='Accept all').click();page.wait_for_function('window.marketingLoaded===1 && window.functionalLoaded===1')
        STATE['policy']={**POLICY,'version':'b'*64,'revision':2};page.reload();page.get_by_role('button',name='Reject all').wait_for()
        check(page.evaluate("!CajeerConsent.allows('analytics') && CajeerConsent.getState().revision===2") and page.evaluate('window.analyticsLoaded===undefined'),'fresh API policy invalidates stored consent even with stale cached HTML')
        page.get_by_role('button',name='Accept all').click();page.wait_for_function('window.analyticsLoaded===1')
        page.evaluate("let x=JSON.parse(localStorage.getItem('ce.consent.v1'));x.expires=Date.now()-1;localStorage.setItem('ce.consent.v1',JSON.stringify(x))");page.reload();page.get_by_role('button',name='Reject all').wait_for()
        check(page.evaluate("!CajeerConsent.allows('analytics')"),'expired consent is denied')
        STATE['fail']=True;page.reload();page.wait_for_timeout(400)
        check(page.evaluate("!CajeerConsent.getState().ready && !CajeerConsent.allows('analytics')") and page.evaluate('window.analyticsLoaded===undefined'),'policy API failure fails closed')
        ctx.close();ctx=browser.new_context();ctx.route('https://tracker.example/**',route);ctx.route('https://evil.example/**',route);page=ctx.new_page();n=REQUESTS.count('/api/v1/public/consent');page.goto(base+'/static');page.get_by_role('button',name='Accept all').wait_for();page.get_by_role('button',name='Accept all').click();page.wait_for_function('window.analyticsLoaded===1')
        check(REQUESTS.count('/api/v1/public/consent')==n,'static export snapshot works without API')
        check(not errors,'no browser runtime exceptions')
        ctx.close();browser.close()
finally:
    server.shutdown();server.server_close()
