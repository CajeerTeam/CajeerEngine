#!/usr/bin/env python3
"""Chromium DOM regression. Intercept vendor script and fetch, never send real user analytics."""
import os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
SOURCE=(ROOT/'resources/consent/consent.js').read_text()
TRACKING=dict(revision=1,enabled=True,hosts=['site.example'],metrika_id='',ga4_id='',gtm_id='',ga4_debug=False,matomo_id='42',matomo_url='https://stats.example.com/matomo',plausible_id='site.example',plausible_url='https://plausible.io')
POLICY=dict(revision=1,ttl_days=180,version='a'*64,title_ru='Приватность',message_ru='Выбор',title_en='Privacy',message_en='Choose',policy_url='',script_origins=['https://stats.example.com'],tracking=TRACKING)
def check(ok,msg):
    if not ok: raise AssertionError(msg)
    print('PASS',msg,flush=True)
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    def setup(policy=POLICY,host='site.example',conflict=False,fail=False):
        page=browser.new_page();page.set_content('<html lang="en"><head><script type="text/plain" data-ce-consent-config="{}"></script></head><body></body></html>')
        page.evaluate('''({source,policy,host,conflict,fail})=>{
          const boot=document.querySelector('[data-ce-consent-config]');boot.dataset.ceConsentConfig=JSON.stringify(policy);boot.dataset.ceConsentDynamic='0';
          window.test={requests:[],scripts:[],reloads:0,storage:null};
          const storage={getItem:()=>test.storage,setItem:(k,v)=>{test.storage=v},removeItem:()=>{test.storage=null}};
          const location={origin:'https://'+host,hostname:host,protocol:'https:',pathname:'/article',reload:()=>test.reloads++};
          const fetch=async(url,options)=>{test.requests.push({url,options,body:JSON.parse(options.body)});if(fail)throw new Error('network');return {type:'opaque'};};
          Object.defineProperty(document,'cookie',{configurable:true,get:()=>'',set:()=>{}});
          if(conflict)window._paq=[];
          const append=document.head.append.bind(document.head);document.head.append=(...nodes)=>{for(const n of nodes){if(n instanceof HTMLScriptElement&&n.dataset.ceTracking){test.scripts.push(n.src);n.dispatchEvent(new Event('load'));}else append(n);}};
          new Function('location','localStorage','fetch',source)(location,storage,fetch);
        }''',dict(source=SOURCE,policy=policy,host=host,conflict=conflict,fail=fail));page.wait_for_function('CajeerConsent.getState().ready');return page
    page=setup();check(page.evaluate('!test.scripts.length&&!test.requests.length&&!CajeerAnalytics.emit("generate_lead")'),'no tracking before consent')
    page.get_by_role('button',name='Customize').click();page.get_by_role('checkbox',name='Analytics').check();page.get_by_role('button',name='Save choices').click()
    check(page.evaluate('test.scripts')==['https://stats.example.com/matomo/matomo.js'],'approved Matomo script loaded after analytics consent')
    check(page.evaluate('_paq.some(x=>x[0]==="disableCookies")&&_paq.some(x=>x[0]==="requireConsent")&&_paq.some(x=>x[0]==="setConsentGiven")&&_paq.some(x=>x[0]==="setTrackerUrl"&&x[1]==="https://stats.example.com/matomo/matomo.php")'),'Matomo official configuration and consent commands')
    check(page.evaluate('_paq.filter(x=>x[0]==="trackPageView").length===1&&test.requests.length===1&&test.requests[0].url==="https://plausible.io/api/event"&&test.requests[0].body.name==="pageview"&&test.requests[0].body.domain==="site.example"'),'single initial view via Matomo and Plausible')
    check(page.evaluate('test.requests[0].options.credentials==="omit"&&test.requests[0].options.mode==="no-cors"&&test.requests[0].options.headers["Content-Type"]==="text/plain"'),'Plausible browser request uses simple credential-free transport')
    check(page.evaluate('!CajeerAnalytics.pageView("/article?secret=1#x")&&CajeerAnalytics.pageView("/next?secret=1#x")&&test.requests.at(-1).body.url==="https://site.example/next"'),'page deduplication and query redaction')
    check(page.evaluate('CajeerAnalytics.emit("generate_lead",{value:12,currency:"EUR"})&&_paq.at(-1)[0]==="trackEvent"&&_paq.at(-1)[2]==="generate_lead"&&test.requests.at(-1).body.name==="generate_lead"&&test.requests.at(-1).body.props.value===12'),'custom event actually routed to both providers')
    check(page.evaluate('(()=>{const n=test.requests.length;CajeerAnalytics.emit("pageview");return test.requests.length===n&&!CajeerAnalytics.emit("generate_lead",{email:"private@example.com"})})()'),'reserved pageview and PII rejected')
    page.get_by_role('button',name='Privacy preferences').click();page.get_by_role('button',name='Reject all').click();check(page.evaluate('test.reloads===1&&!CajeerAnalytics.emit("generate_lead")&&_paq.at(-1)[0]==="forgetConsentGiven"&&CajeerAnalytics.getJournal().length===0'),'withdrawal stops both providers');page.close()
    page=setup({**POLICY,'script_origins':[]});page.get_by_role('button',name='Accept all').click();check(page.evaluate('test.scripts.length===0&&test.requests.length===1'),'operator removal blocks custom Matomo endpoint, cloud Plausible stays active');page.close()
    page=setup(host='other.example');page.get_by_role('button',name='Accept all').click();check(page.evaluate('test.scripts.length===0&&test.requests.length===0'),'unapproved site host blocked');page.close()
    page=setup(conflict=True);page.get_by_role('button',name='Accept all').click();check(page.evaluate('CajeerTracking.getStatus().matomo.status==="error"&&test.scripts.length===0'),'existing Matomo tracker not duplicated');page.close()
    page=setup(fail=True);page.get_by_role('button',name='Accept all').click();page.wait_for_function('CajeerTracking.getStatus().plausible.status==="error"');check(page.evaluate('CajeerTracking.getStatus().plausible.reason==="transport-failed"'),'Plausible transport failure visible');page.close()
    browser.close()
