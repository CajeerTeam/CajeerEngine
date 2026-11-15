#!/usr/bin/env python3
"""Real Chromium DOM tests; --network additionally exercises request interception and CSP.
No vendor credentials or real analytics data are required. Vendor libraries are test doubles.
"""
import argparse
import html
import json
import os
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
SOURCE=(ROOT/'resources/consent/consent.js').read_text()
POLICY={'revision':1,'ttl_days':180,'version':'a'*64,'title_ru':'Приватность','message_ru':'Выбор','title_en':'Privacy','message_en':'Choose','policy_url':'','script_origins':[]}
DIRECT={'revision':1,'enabled':True,'hosts':['site.example'],'metrika_id':'12345678','ga4_id':'G-ABC1234567','gtm_id':'','ga4_debug':True}
GTM={**DIRECT,'metrika_id':'','ga4_id':'','ga4_debug':False,'gtm_id':'GTM-ABC123'}

def check(ok,msg):
    if not ok: raise AssertionError(msg)
    print('PASS',msg,flush=True)

def dom(browser):
    def setup(cfg=DIRECT,host='site.example',path='/article',dynamic=True,conflict=False,fail=False):
        page=browser.new_page();page.set_content('<html lang="en"><head><script type="text/plain" data-ce-consent-config="{}"></script></head><body></body></html>')
        page.evaluate('''({source,policy,host,path,dynamic,conflict,fail})=>{
          const boot=document.querySelector('[data-ce-consent-config]');boot.dataset.ceConsentConfig=JSON.stringify(policy);boot.dataset.ceConsentDynamic=dynamic?'1':'0';
          window.test={policy,requests:[],scripts:[],reloads:0,storage:null,fail};
          const storage={getItem:()=>test.storage,setItem:(k,v)=>{test.storage=v},removeItem:()=>{test.storage=null}};
          const location={origin:'https://'+host,hostname:host,protocol:'https:',pathname:path,reload:()=>{test.reloads++}};
          const fetch=async url=>{test.requests.push(url);return {ok:true,json:async()=>({data:test.policy})}};
          Object.defineProperty(document,'cookie',{configurable:true,get:()=>'',set:()=>{}});
          if(conflict){const x=document.createElement('script');x.type='text/plain';x.src='https://www.googletagmanager.com/gtag/js?id=G-OTHER';document.head.append(x);}
          const append=document.head.append.bind(document.head);document.head.append=(...nodes)=>{for(const n of nodes){if(n instanceof HTMLScriptElement&&n.dataset.ceTracking){test.scripts.push(n.src);n.dispatchEvent(new Event(test.fail?'error':'load'));}else append(n);}};
          new Function('location','localStorage','fetch',source)(location,storage,fetch);
        }''',{'source':SOURCE,'policy':{**POLICY,'tracking':cfg},'host':host,'path':path,'dynamic':dynamic,'conflict':conflict,'fail':fail})
        page.wait_for_function('CajeerConsent.getState().ready');return page
    def granular(page):
        page.get_by_role('button',name='Customize').click();page.get_by_role('checkbox',name='Analytics').check();page.get_by_role('button',name='Save choices').click()
    page=setup()
    check(page.evaluate('test.scripts.length===0 && CajeerTracking.event("lead")===false'),'before consent no script and no event')
    granular(page)
    check(page.evaluate('test.scripts')==['https://mc.yandex.ru/metrika/tag.js','https://www.googletagmanager.com/gtag/js?id=G-ABC1234567'],'exact real library URLs after analytics consent')
    check(page.evaluate('CajeerTracking.getStatus().ga4.status')=='loaded','script load status')
    check(page.evaluate('Array.from(ym.a[0])')[2]['defer'] is True and page.evaluate('Array.from(ym.a[1])')[1:3]==['hit','https://site.example/article'],'Metrica init and manual hit')
    check(page.evaluate('''()=>{const c=dataLayer.find(x=>x[0]==='config');return c[1]==='G-ABC1234567'&&!c[2].send_page_view&&c[2].debug_mode&&c[2].allow_google_signals===false}'''),'GA4 config disables automatic pageview and signals')
    check(page.evaluate('dataLayer.filter(x=>x[0]==="event"&&x[1]==="page_view").length')==1,'one explicit GA4 pageview')
    check(page.evaluate('CajeerTracking.pageView("/article?private=secret#x")') is False,'same page deduplicated including query fragment')
    check(page.evaluate('CajeerTracking.pageView("/another?private=secret#x")') is True and page.evaluate('dataLayer.at(-1)[2].page_location')=='https://site.example/another','manual view strips query and fragment')
    check(page.evaluate('CajeerTracking.pageView("https://other.example/a") || CajeerTracking.pageView("/admin/")') is False,'foreign and administrative pageviews refused')
    check(page.evaluate('CajeerTracking.event("generate_lead",{value:12,currency:"USD"})') is True and page.evaluate('Array.from(ym.a.at(-1))')[1:3]==['reachGoal','generate_lead'] and page.evaluate('dataLayer.at(-1)[1]')=='generate_lead','real event and goal command mapping')
    check(page.evaluate('CajeerTracking.event("lead",{email:"x@example.com"}) || CajeerTracking.event("page_view")') is False,'arbitrary PII parameter and duplicate pageview event rejected')
    page.get_by_role('button',name='Privacy preferences').click();page.get_by_role('button',name='Reject all').click()
    check(page.evaluate('test.reloads===1 && window["ga-disable-G-ABC1234567"] && !CajeerTracking.event("lead") && CajeerTracking.getStatus().ga4.status==="stopped"'),'withdraw stops providers and forces reload');page.close()
    page=setup(cfg=GTM);granular(page);check(page.evaluate('test.scripts.length===0'),'GTM not loaded with analytics-only grant')
    page.get_by_role('button',name='Privacy preferences').click();page.get_by_role('button',name='Accept all').click()
    check(page.evaluate('test.scripts')==['https://www.googletagmanager.com/gtm.js?id=GTM-ABC123'],'GTM loads exclusively with all optional grants')
    check(page.evaluate('dataLayer.some(x=>x.event==="gtm.js") && dataLayer.some(x=>x.event==="ce_page_view") && CajeerTracking.event("lead") && dataLayer.at(-1).event==="ce_lead"'),'GTM bootstrap and dataLayer events')
    page.get_by_role('button',name='Privacy preferences').click();page.get_by_role('checkbox',name='Marketing').uncheck();page.get_by_role('button',name='Save choices').click()
    check(page.evaluate('test.reloads===1 && !CajeerTracking.event("lead")'),'GTM stops when marketing alone revoked');page.close()
    for args,msg in [({'host':'staging.example'},'host mismatch'),({'path':'/admin/'},'admin exclusion'),({'cfg':{**DIRECT,'enabled':False}},'disabled config'),({'cfg':{**DIRECT,'gtm_id':'GTM-ABC123'}},'conflicting config')]:
        page=setup(**args);page.get_by_role('button',name='Accept all').click();check(page.evaluate('test.scripts.length===0'),msg);page.close()
    page=setup(conflict=True);granular(page);check(page.evaluate('CajeerTracking.getStatus().ga4.reason')=='existing-tag' and len(page.evaluate('test.scripts'))==1,'existing Google tag not duplicated');page.close()
    page=setup(fail=True);granular(page);check(page.evaluate('CajeerTracking.getStatus().ga4.reason==="load-failed" && !CajeerTracking.event("lead")'),'failed library reports error, no events');page.close()
    page=setup(dynamic=False);granular(page);check(page.evaluate('test.requests.length===0 && test.scripts.length===2'),'static runtime no API dependency');page.close()
    page=setup();granular(page);page.evaluate('''()=>{test.policy={...test.policy,version:'b'.repeat(64),tracking:{...test.policy.tracking,enabled:false}};document.dispatchEvent(new Event('visibilitychange'));}''');page.wait_for_function('test.reloads===1');check(page.evaluate('!CajeerTracking.event("lead")'),'configuration revision stops previously loaded tracking');page.close()

def network(browser):
    # Playwright fulfills the vendor requests, but Chromium actually performs script fetches under CSP.
    ctx=browser.new_context();requests=[]
    policy={**POLICY,'tracking':DIRECT}
    markup=f'<html lang="en"><head><script defer src="/consent.js" data-ce-consent-config="{html.escape(json.dumps(policy),quote=True)}" data-ce-consent-dynamic="1"></script></head><body></body></html>'
    def site(r):
        path=r.request.url.split('site.example',1)[1]
        if path=='/consent.js':r.fulfill(content_type='application/javascript',body=SOURCE)
        elif path=='/api/v1/public/consent':r.fulfill(content_type='application/json',body=json.dumps({'data':policy}))
        else:r.fulfill(content_type='text/html',headers={'Content-Security-Policy':"default-src 'self'; script-src 'self' https://mc.yandex.ru https://www.googletagmanager.com; connect-src 'self'; style-src 'self' 'unsafe-inline'"},body=markup)
    def vendor(r):
        requests.append(r.request.url);r.fulfill(content_type='application/javascript',body='window.vendorLoaded=(window.vendorLoaded||0)+1;')
    ctx.route('https://site.example/**',site);ctx.route('https://mc.yandex.ru/**',vendor);ctx.route('https://www.googletagmanager.com/**',vendor)
    page=ctx.new_page();page.goto('https://site.example/');page.get_by_role('button',name='Reject all').wait_for();check(not requests,'network: no vendor requests before consent')
    page.get_by_role('button',name='Accept all').click();page.wait_for_function('window.vendorLoaded===2');check(len(requests)==2,'network: real browser loaded both vendor script URLs under CSP')
    page.get_by_role('button',name='Privacy preferences').click();page.get_by_role('button',name='Reject all').click();page.wait_for_function('CajeerConsent.getState().ready && !CajeerConsent.allows("analytics")');page.wait_for_function('window.vendorLoaded===undefined');check(len(requests)==2,'network: reload after revoke sends no vendor request')
    ctx.close()

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--network',action='store_true');args=parser.parse_args()
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
        dom(browser)
        if args.network:network(browser)
        browser.close()
