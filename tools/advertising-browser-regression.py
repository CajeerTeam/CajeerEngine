#!/usr/bin/env python3
"""Real Chromium DOM; vendor downloads intercepted, command queues inspected."""
import os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
SOURCE=(ROOT/'resources/consent/consent.js').read_text()
TRACKING=dict(revision=1,enabled=True,hosts=['site.example'],metrika_id='12345678',ga4_id='G-ABC12345',gtm_id='',ga4_debug=False,google_ads_id='AW-123456789',meta_id='123456789012345',tiktok_id='C1234567890123456789',vk_id='12345678',ads_events=['generate_lead','sign_up'],ads_lead_label='AbC-D_efG',ads_signup_label='Signup123',ads_yandex_lead_goal='generate_lead',ads_attribution=True)
POLICY=dict(revision=1,ttl_days=180,version='a'*64,title_ru='Приватность',message_ru='Выбор',title_en='Privacy',message_en='Choose',policy_url='',script_origins=[],tracking=TRACKING)
def check(ok,msg):
    if not ok: raise AssertionError(msg)
    print('PASS',msg,flush=True)
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    def setup(tracking=TRACKING,host='site.example',path='/article',conflict=False,fail=False):
        page=browser.new_page();page.set_content('<html lang="en"><head><script type="text/plain" data-ce-consent-config="{}"></script></head><body></body></html>')
        page.evaluate('''({source,policy,host,path,conflict,fail})=>{
          const boot=document.querySelector('[data-ce-consent-config]');boot.dataset.ceConsentConfig=JSON.stringify(policy);boot.dataset.ceConsentDynamic='0';
          window.test={scripts:[],reloads:0,storage:null,session:null};
          const storage={getItem:()=>test.storage,setItem:(k,v)=>{test.storage=v},removeItem:()=>{test.storage=null}};
          const session={getItem:()=>test.session,setItem:(k,v)=>{test.session=v},removeItem:()=>{test.session=null}};
          const location={origin:'https://'+host,hostname:host,protocol:'https:',pathname:path,search:'?utm_source=google&utm_medium=cpc&utm_campaign=summer&utm_term=private@example.com&gclid=secret',reload:()=>test.reloads++};
          Object.defineProperty(document,'cookie',{configurable:true,get:()=>'',set:()=>{}});
          if(conflict){const s=document.createElement('script');s.type='text/plain';s.src='https://connect.facebook.net/en_US/fbevents.js';document.head.append(s);}
          const append=document.head.append.bind(document.head);document.head.append=(...nodes)=>{for(const n of nodes){if(n instanceof HTMLScriptElement&&n.dataset.ceTracking){test.scripts.push({name:n.dataset.ceTracking,src:n.src});n.dispatchEvent(new Event(fail?'error':'load'));}else append(n);}};
          new Function('location','localStorage','sessionStorage',source)(location,storage,session);
        }''',dict(source=SOURCE,policy={**POLICY,'tracking':tracking},host=host,path=path,conflict=conflict,fail=fail));page.wait_for_function('CajeerConsent.getState().ready');return page
    def choose(page,analytics,marketing):
        if page.get_by_role('button',name='Privacy preferences').is_visible():page.get_by_role('button',name='Privacy preferences').click()
        page.get_by_role('button',name='Customize').click()
        page.get_by_role('checkbox',name='Analytics').set_checked(analytics);page.get_by_role('checkbox',name='Marketing').set_checked(marketing)
        page.get_by_role('button',name='Save choices').click()
    page=setup();check(page.evaluate('test.scripts.length===0&&!test.session'),'no requests or attribution before consent')
    page.get_by_role('button',name='Reject all').click();check(page.evaluate('test.scripts.length===0'),'refusal blocks all tags')
    choose(page,True,False);check(page.evaluate("test.scripts.map(s=>s.name).sort().join(',')==='ga4,metrika'"),'analytics only loads analytics tags')
    check(page.evaluate("!CajeerAnalytics.getAttribution()&&!test.session"),'no attribution without marketing')
    page.evaluate("CajeerAnalytics.emit('generate_lead')");check(page.evaluate("!window.ym.a.some(x=>x[1]==='reachGoal'&&x[2]==='generate_lead')"),'overlapping Yandex goal blocked without marketing')
    choose(page,True,True);check(page.evaluate("test.scripts.map(s=>s.name).sort().join(',')==='ga4,meta,metrika,tiktok,vk'"),'all four ads initialized, Google shares GA4 tag')
    check(page.evaluate("dataLayer.some(x=>x[0]==='config'&&x[1]==='AW-123456789')"),'Google Ads destination configured after grant')
    check(page.evaluate("fbq.queue.some(x=>x[0]==='trackSingle'&&x[2]==='PageView')&&ttq.some(x=>x[0]==='page')&&_tmr.some(x=>x.type==='pageView')"),'page views reach actual vendor command queues')
    check(page.evaluate("CajeerAnalytics.getAttribution().first.source==='google'&&!test.session.includes('private')&&!test.session.includes('secret')"),'session UTM allowlist excludes term and click ID')
    id='1'*32
    check(page.evaluate("id=>CajeerAnalytics.emit('generate_lead',{value:25,currency:'USD'},{id})",id),'explicit lead dispatched')
    check(page.evaluate("id=>dataLayer.some(x=>x[0]==='event'&&x[1]==='conversion'&&x[2].send_to==='AW-123456789/AbC-D_efG'&&x[2].transaction_id===id)&&fbq.queue.some(x=>x[2]==='Lead'&&x[4].eventID===id)&&ttq.some(x=>x[0]==='track'&&x[1]==='Contact'&&x[3].event_id===id)&&_tmr.some(x=>x.goal==='generatelead'&&x.value===25)&&ym.a.filter(x=>x[1]==='reachGoal'&&x[2]==='generate_lead').length===1",id),'correct mapping, values and stable IDs, no duplicate Yandex goal')
    check(page.evaluate("id=>!CajeerAnalytics.emit('generate_lead',{value:25,currency:'USD'},{id})",id),'same event ID deduplicated')
    check(page.evaluate("CajeerAnalytics.getJournal().some(x=>x.name==='generate_lead'&&x.outcomes.google_ads==='submitted'&&x.outcomes.yandex_ads==='submitted')"),'journal includes advertising outcomes')
    check(page.evaluate("!CajeerAnalytics.emit('purchase',{value:25,currency:'USD'})&&!CajeerAnalytics.emit('generate_lead',{email:'private@example.com'})"),'untrusted purchases and raw PII rejected')
    page.evaluate("CajeerAnalytics.emit('form_submit')");check(page.evaluate("!ttq.some(x=>x[1]==='SubmitForm')"),'unselected conversion not sent to ads')
    choose(page,True,False);check(page.evaluate("test.reloads===1&&!test.session&&CajeerTracking.getStatus().meta.status==='stopped'&&!CajeerAnalytics.emit('generate_lead')"),'marketing withdrawal stops tags, clears UTM, reloads');page.close()
    page=setup(tracking={**TRACKING,'ga4_id':'','metrika_id':'','ads_yandex_lead_goal':''});page.get_by_role('button',name='Accept all').click();check(page.evaluate("test.scripts.some(s=>s.name==='google_ads'&&s.src.includes('AW-123456789'))"),'standalone Google Ads loads Google tag');page.close()
    page=setup();choose(page,False,True);check(page.evaluate('test.scripts.length===0'),'marketing-only does not bypass analytics prerequisite');page.close()
    for opts,msg in [({'host':'other.example'},'unapproved host'),({'path':'/admin/'},'admin excluded'),({'tracking':{**TRACKING,'gtm_id':'GTM-ABCDE'}},'GTM conflict'),({'tracking':{**TRACKING,'meta_id':'invalid'}},'invalid pixel ID')]:
        page=setup(**opts);page.get_by_role('button',name='Accept all').click();check(page.evaluate('test.scripts.length===0'),msg);page.close()
    page=setup(conflict=True);page.get_by_role('button',name='Accept all').click();check(page.evaluate("!test.scripts.some(s=>s.name==='meta')&&CajeerTracking.getStatus().meta.reason==='existing-tag'"),'existing Meta tag not duplicated');page.close()
    page=setup(fail=True);page.get_by_role('button',name='Accept all').click();check(page.evaluate("CajeerTracking.getStatus().meta.status==='error'&&CajeerTracking.getStatus().google_ads.status==='error'"),'load failure and shared tag failure visible');page.close()
    browser.close()
