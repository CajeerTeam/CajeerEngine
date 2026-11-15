#!/usr/bin/env python3
"""Chromium event pipeline with intercepted vendor commands and first-party transport."""
import json, os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
SOURCE=(ROOT/'resources/consent/consent.js').read_text()
RULES=[dict(name=n,enabled=True,automatic=n in ['search','file_download','outbound_click','scroll'],ga4='site_'+n if n in ['search','file_download','outbound_click','scroll'] else n,metrika=n,gtm=n) for n in ['page_view','search','file_download','outbound_click','scroll','generate_lead','form_submit']]
RULES[1]['metrika']='search_goal';RULES[5]['ga4']='qualified_lead';RULES[5]['metrika']='';RULES[6]['enabled']=False
EVENTS=dict(revision=1,enabled=True,allow_custom=False,collect_diagnostics=True,rules=RULES)
POLICY=dict(revision=1,ttl_days=180,version='a'*64,title_ru='Приватность',message_ru='Выбор',title_en='Privacy',message_en='Choose',policy_url='',script_origins=[],tracking=dict(revision=1,enabled=True,hosts=['site.example'],metrika_id='12345678',ga4_id='G-ABC1234567',gtm_id='',ga4_debug=False),events=EVENTS)
def check(ok,msg):
    if not ok: raise AssertionError(msg)
    print('PASS',msg,flush=True)
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    def setup(dynamic=True,policy=POLICY):
        page=browser.new_page();page.set_content('''<html lang="en"><head><script type="text/plain" data-ce-consent-config="{}"></script></head><body><main style="min-height:4000px"><p data-ce-analytics-search="7">Results</p><a id="download" href="https://site.example/files/private-name.pdf?token=private" download>Download</a><a id="outbound" href="https://outside.example/private?token=secret">External</a><a id="ignored" data-ce-analytics-ignore href="https://outside.example/ignored">Ignore</a></main></body></html>''')
        page.evaluate('''({source,policy,dynamic})=>{
          const boot=document.querySelector('[data-ce-consent-config]');boot.dataset.ceConsentConfig=JSON.stringify(policy);boot.dataset.ceConsentDynamic=dynamic?'1':'0';
          window.test={policy,requests:[],diagnostics:[],scripts:[],reloads:0,storage:null};
          const storage={getItem:()=>test.storage,setItem:(k,v)=>{test.storage=v},removeItem:()=>{test.storage=null}};
          const location={origin:'https://site.example',hostname:'site.example',protocol:'https:',pathname:'/search',reload:()=>test.reloads++};
          const fetch=async(url,options)=>{test.requests.push(url);if(url.includes('/analytics/events')){test.diagnostics.push(JSON.parse(options.body));return {ok:true};}return {ok:true,json:async()=>({data:test.policy})}};
          Object.defineProperty(document,'cookie',{configurable:true,get:()=>'',set:()=>{}});
          const append=document.head.append.bind(document.head);document.head.append=(...nodes)=>{for(const n of nodes){if(n instanceof HTMLScriptElement&&n.dataset.ceTracking){test.scripts.push(n.src);n.dispatchEvent(new Event('load'));}else append(n);}};
          window.addEventListener('click',e=>{if(e.target.closest('a'))e.preventDefault();});
          new Function('location','localStorage','fetch',source)(location,storage,fetch);
        }''',{'source':SOURCE,'policy':policy,'dynamic':dynamic});page.wait_for_function('CajeerConsent.getState().ready');return page
    page=setup();check(page.evaluate('!CajeerAnalytics.emit("generate_lead") && !test.diagnostics.length && !test.scripts.length'),'no events, diagnostics or vendor loads before consent')
    page.get_by_role('button',name='Accept all').click();page.wait_for_function('test.diagnostics.length===2')
    check(page.evaluate('CajeerAnalytics.getJournal().map(r=>r.name)')==['page_view','search'],'automatic pageview and CMS search exactly once')
    check(page.evaluate('dataLayer.find(x=>x[0]==="event"&&x[1]==="site_search")[2].result_count')==7,'GA4 search mapping and numeric count')
    check(page.evaluate("ym.a.some(x=>x[1]==='reachGoal'&&x[2]==='search_goal')"),'Metrica custom goal mapping')
    check(page.evaluate('''()=>{const id='b'.repeat(32);return CajeerAnalytics.emit('generate_lead',{value:15,currency:'USD'},{id})&&!CajeerAnalytics.emit('generate_lead',{value:15,currency:'USD'},{id})}'''),'stable event ID deduplicated')
    check(page.evaluate('dataLayer.at(-1)[1]')=='qualified_lead' and page.evaluate("ym.a.some(x=>x[1]==='reachGoal'&&x[2]==='search_goal')"),'per-provider disable and mapped GA4 event')
    check(page.evaluate('!CajeerAnalytics.emit("purchase",{value:1,currency:"USD"}) && !CajeerAnalytics.emit("form_submit") && !CajeerAnalytics.emit("unknown") && !CajeerAnalytics.emit("generate_lead",{email:"test@example.com"})'),'browser purchase, disabled, unknown and PII rejected')
    page.locator('#download').click();page.locator('#outbound').click();page.locator('#ignored').click()
    check(page.evaluate('CajeerAnalytics.getJournal().filter(r=>r.name==="file_download").length')==1,'trusted download click collected')
    check(page.evaluate('CajeerAnalytics.getJournal().filter(r=>r.name==="outbound_click").length')==1,'trusted outbound click and ignore marker')
    check(page.evaluate('dataLayer.find(x=>x[0]==="event"&&x[1]==="site_file_download")[2].file_extension')=='pdf','file extension only')
    page.evaluate('window.scrollTo(0,document.documentElement.scrollHeight)');page.wait_for_function('CajeerAnalytics.getJournal().filter(r=>r.name==="scroll").length===4')
    page.evaluate('window.scrollTo(0,0)');page.evaluate('window.scrollTo(0,document.documentElement.scrollHeight)');page.wait_for_timeout(150)
    check(page.evaluate('CajeerAnalytics.getJournal().filter(r=>r.name==="scroll").length')==4,'scroll thresholds deduplicated')
    check(page.evaluate('JSON.stringify(test.diagnostics).includes("private") || JSON.stringify(test.diagnostics).includes("currency")') is False,'diagnostic transport excludes URLs and event parameters')
    check(page.evaluate('test.diagnostics.every(r=>Object.keys(r).sort().join(",")==="id,name,outcomes,version")'),'diagnostics strict shape')
    page.get_by_role('button',name='Privacy preferences').click();page.get_by_role('button',name='Reject all').click();check(page.evaluate('test.reloads===1 && !CajeerAnalytics.emit("generate_lead") && CajeerAnalytics.getJournal().length===0'),'revoke stops dispatch and clears browser journal');page.close()
    page=setup(dynamic=False);page.get_by_role('button',name='Accept all').click();check(page.evaluate('test.requests.length===0 && CajeerAnalytics.getJournal().length===2'),'static export tracking without collector request');page.close()
    page=setup(policy={**POLICY,'events':{**EVENTS,'enabled':False}});page.get_by_role('button',name='Accept all').click();check(page.evaluate('!CajeerAnalytics.emit("generate_lead") && !CajeerAnalytics.getJournal().length && !test.scripts.length'),'disabled event config prevents dispatch');page.close()
    browser.close()
