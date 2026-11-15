#!/usr/bin/env python3
"""Chromium DOM regression with controlled transport; useful in offline sandboxes.
The separate consent-browser-regression.py exercises actual HTTP navigation.
"""
import json
import os
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[1]
SOURCE=(ROOT/'resources/consent/consent.js').read_text()
POLICY={'revision':1,'ttl_days':180,'version':'a'*64,'title_ru':'Приватность','message_ru':'Выбор','title_en':'Privacy','message_en':'Choose','policy_url':'/privacy-policy','script_origins':['https://tracker.example']}

def check(ok,msg):
    if not ok: raise AssertionError(msg)
    print('PASS',msg,flush=True)

with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    def setup(dynamic=True,record=None,fail=False):
        page=browser.new_page();page.set_content('''<html lang="en"><body><script type="text/plain" data-ce-consent-config="{}" data-ce-consent-dynamic="1"></script><script type="text/plain" data-ce-consent="analytics" data-ce-src="/analytics.js"></script><script type="text/plain" data-ce-consent="marketing" data-ce-src="/marketing.js"></script><script type="text/plain" data-ce-consent="functional" data-ce-src="/functional.js"></script><script type="text/plain" data-ce-consent="analytics" data-ce-src="https://tracker.example/tag.js"></script><script type="text/plain" data-ce-consent="analytics" data-ce-src="https://evil.example/tag.js"></script></body></html>''')
        page.evaluate('''({source,policy,dynamic,record,fail})=>{
          const boot=document.querySelector('[data-ce-consent-config]');boot.dataset.ceConsentConfig=JSON.stringify(policy);boot.dataset.ceConsentDynamic=dynamic?'1':'0';
          window.test={policy,fail,requests:[],scripts:[],reloads:0,storage:record?JSON.stringify(record):null,cookies:{_ga:'x',_fbp:'x',ce_session:'essential'}};
          Object.defineProperty(document,'cookie',{configurable:true,get:()=>Object.entries(test.cookies).map(([k,v])=>k+'='+v).join('; '),set:v=>{const k=v.split('=')[0];if(v.includes('Max-Age=0'))delete test.cookies[k];}});
          const nativeAfter=Element.prototype.after;Element.prototype.after=function(...nodes){for(const n of nodes)if(n instanceof HTMLScriptElement){test.scripts.push(n.src);if(n.src.endsWith('/analytics.js'))test.cookies._ga='yes';}else nativeAfter.call(this,n);};
          const storage={getItem:()=>test.storage,setItem:(k,v)=>{test.storage=v},removeItem:()=>{test.storage=null}};
          const location={origin:'https://site.example',hostname:'site.example',protocol:'https:',pathname:'/',reload:()=>{test.reloads++}};
          const fetch=async url=>{test.requests.push(url);return {ok:!test.fail,json:async()=>({data:test.policy})}};
          new Function('location','localStorage','fetch',source)(location,storage,fetch);
        }''',{'source':SOURCE,'policy':POLICY,'dynamic':dynamic,'record':record,'fail':fail})
        return page
    page=setup();page.get_by_role('button',name='Reject all').wait_for()
    check(page.evaluate('test.scripts.length===0 && !CajeerConsent.allows("analytics")'),'no attempted optional load before consent')
    check(page.evaluate('dataLayer[0][2].ad_user_data')=='denied','Google default denied')
    page.get_by_role('button',name='Reject all').click();saved=page.evaluate('JSON.parse(test.storage)');check(saved['categories']['analytics'] is False,'rejection stored')
    page.close();page=setup(record=saved);page.wait_for_function('CajeerConsent.getState().ready');check(not page.get_by_role('dialog').is_visible(),'rejection restored')
    page.get_by_role('button',name='Privacy preferences').click();page.get_by_role('checkbox',name='Analytics').check();page.get_by_role('button',name='Save choices').click()
    check(page.evaluate('test.scripts')==['https://site.example/analytics.js','https://tracker.example/tag.js'],'granular allowlist and analytics only')
    check(page.evaluate('dataLayer.at(-1)[2].analytics_storage')=='granted' and page.evaluate('dataLayer.at(-1)[2].ad_storage')=='denied','Google consent update')
    check(page.evaluate('JSON.parse(test.storage).history.length')==2,'decision history')
    accepted=page.evaluate('JSON.parse(test.storage)');page.get_by_role('button',name='Privacy preferences').click();page.get_by_role('button',name='Reject all').click()
    check(page.evaluate('test.reloads===1 && !CajeerConsent.allows("analytics") && !test.cookies._ga && test.cookies.ce_session==="essential"'),'revocation and cookie cleanup preserve essential cookie')
    page.close();page=setup(record=accepted);page.wait_for_function('CajeerConsent.getState().ready');check(page.evaluate('CajeerConsent.allows("analytics")'),'grant restored')
    page.evaluate('''()=>{test.storage=null;window.dispatchEvent(new StorageEvent('storage',{key:'ce.consent.v1'}))}''');check(page.evaluate('!CajeerConsent.allows("analytics") && test.reloads===1'),'cross-tab storage event revokes')
    page.close();page=setup(record={**accepted,'version':'b'*64});page.get_by_role('button',name='Reject all').wait_for();check(page.evaluate('test.scripts.length===0'),'changed policy invalidates grant')
    page.close();page=setup(record={**accepted,'expires':1});page.get_by_role('button',name='Reject all').wait_for();check(page.evaluate('test.scripts.length===0'),'expired grant denied')
    page.close();page=setup(fail=True,record=accepted);page.wait_for_timeout(200);check(page.evaluate('!CajeerConsent.getState().ready && test.scripts.length===0'),'API unavailable fails closed')
    page.close();page=setup(dynamic=False);page.get_by_role('button',name='Accept all').click();check(page.evaluate('test.requests.length===0 && CajeerConsent.allows("marketing") && test.scripts.length===4'),'static policy and all categories work offline')
    page.close();browser.close()
