#!/usr/bin/env python3
"""Run real DOM consent logic with vendor script insertion intercepted."""
import os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
SOURCE=(ROOT/'resources/consent/consent.js').read_text()
TOKEN='a'*32
TRACKING=dict(revision=1,enabled=True,hosts=['site.example'],metrika_id='',ga4_id='',gtm_id='',ga4_debug=False,cloudflare_token=TOKEN)
POLICY=dict(revision=1,ttl_days=180,version='a'*64,title_ru='Приватность',message_ru='Выбор',title_en='Privacy',message_en='Choose',policy_url='',script_origins=[],tracking=TRACKING)
def check(ok,msg):
    if not ok: raise AssertionError(msg)
    print('PASS',msg,flush=True)
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    def setup(host='site.example',conflict=False,tracking=TRACKING,path='/article',fail=False):
        page=browser.new_page();page.set_content('<html lang="en"><head><script type="text/plain" data-ce-consent-config="{}"></script></head><body></body></html>')
        page.evaluate('''({source,policy,host,conflict,path,fail})=>{
          const boot=document.querySelector('[data-ce-consent-config]');boot.dataset.ceConsentConfig=JSON.stringify(policy);boot.dataset.ceConsentDynamic='0';
          window.test={scripts:[],reloads:0,storage:null};
          const storage={getItem:()=>test.storage,setItem:(k,v)=>{test.storage=v},removeItem:()=>{test.storage=null}};
          const location={origin:'https://'+host,hostname:host,protocol:'https:',pathname:path,reload:()=>test.reloads++};
          Object.defineProperty(document,'cookie',{configurable:true,get:()=>'',set:()=>{}});
          if(conflict){const s=document.createElement('script');s.type='text/plain';s.src='https://static.cloudflareinsights.com/beacon.min.js';document.head.append(s);}
          const append=document.head.append.bind(document.head);document.head.append=(...nodes)=>{for(const n of nodes){if(n instanceof HTMLScriptElement&&n.dataset.ceTracking){test.scripts.push({src:n.src,type:n.type});n.dispatchEvent(new Event(fail?'error':'load'));}else append(n);}};
          new Function('location','localStorage',source)(location,storage);
        }''',dict(source=SOURCE,policy={**POLICY,'tracking':tracking},host=host,conflict=conflict,path=path,fail=fail));page.wait_for_function('CajeerConsent.getState().ready');return page
    page=setup();check(page.evaluate('test.scripts.length===0'),'no beacon before consent')
    page.get_by_role('button',name='Reject all').click();check(page.evaluate('test.scripts.length===0'),'no beacon after refusal')
    page.get_by_role('button',name='Privacy preferences').click();page.get_by_role('button',name='Customize').click();page.get_by_role('checkbox',name='Analytics').check();page.get_by_role('button',name='Save choices').click()
    scripts=page.evaluate('test.scripts');check(len(scripts)==1 and scripts[0]['src']=='https://static.cloudflareinsights.com/beacon.min.js?token='+TOKEN and scripts[0]['type']=='module','official manual beacon and public token after consent')
    check(page.evaluate('CajeerTracking.getStatus().cloudflare.status==="loaded"'),'load diagnostic')
    page.evaluate('CajeerTracking.pageView("/next");CajeerAnalytics.emit("generate_lead")');check(page.evaluate('test.scripts.length===1'),'no duplicate beacon and no unsupported custom event dispatch')
    page.get_by_role('button',name='Privacy preferences').click();page.get_by_role('button',name='Reject all').click();check(page.evaluate('test.reloads===1&&CajeerTracking.getStatus().cloudflare.status==="stopped"'),'withdrawal removes managed beacon and reloads');page.close()
    for opts,msg in [({'host':'other.example'},'unapproved hostname'),({'path':'/admin/'},'admin excluded'),({'tracking':{**TRACKING,'gtm_id':'GTM-ABCDE'}},'GTM conflict'),({'tracking':{**TRACKING,'cloudflare_token':'invalid'}},'invalid site token')]:
        page=setup(**opts);page.get_by_role('button',name='Accept all').click();check(page.evaluate('test.scripts.length===0'),msg);page.close()
    page=setup(conflict=True);page.get_by_role('button',name='Accept all').click();check(page.evaluate('test.scripts.length===0&&CajeerTracking.getStatus().cloudflare.reason==="existing-tag"'),'existing beacon not duplicated');page.close()
    page=setup(fail=True);page.get_by_role('button',name='Accept all').click();check(page.evaluate('CajeerTracking.getStatus().cloudflare.reason==="load-failed"'),'load error visible');page.close()
    browser.close()
