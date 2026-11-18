#!/usr/bin/env python3
"""Chromium: a real static-admin AI Analytics interaction with stubbed authenticated API responses."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import shutil, os
root=Path(__file__).resolve().parents[1]
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page(); errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content('<div id="app"></div>')
    page.evaluate(r'''() => {
      const store=new Map();for(const key of ['localStorage','sessionStorage']) Object.defineProperty(window,key,{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});
      location.hash='#/analytics-insights';window.alert=()=>{throw Error('XSS executed')}; window.requests=[];
      const id='f'.repeat(32), source={id,name:'Site <strong>unsafe</strong>',provider:'ga4',enabled:true,last_success:'2026-11-18T10:00:00Z'};
      const result={available:true,provider:'ga4',connection_id:id,from:'2026-11-01',to:'2026-11-16',fetched_at:'2026-11-18T10:00:00Z',comparison_status:'ready',warnings:['Sampled provider data'],
        changes:[{metric:'sessions',label:'Sessions',current:150,previous:200,percent:-25}],observations:[{code:'period_decline',message:'Sessions fell',metric:'sessions',percent:-25}],daily_signals:[],
        segments:{pages:{quality:{complete:false},items:[{label:'<img src=x onerror=alert(1)>',values:{sessions:45}}]}}};
      const ok=data=>new Response(JSON.stringify({data}),{status:200,headers:{'Content-Type':'application/json'}});
      window.fetch=async(url,opts={})=>{
        const path=new URL(String(url),'https://test.invalid').pathname.replace('/api/v1','');
        const method=opts.method||'GET',body=opts.body?JSON.parse(opts.body):null,csrf=new Headers(opts.headers||{}).get('X-CSRF-Token');
        requests.push({path,method,body,csrf});
        if(path==='/auth/oidc/status')return ok({enabled:false});
        if(path==='/auth/me')return ok({user_id:'operator',csrf_token:'CSRF-ANALYTICS',scopes:['analytics:read']});
        if(path==='/analytics/reports')return ok({items:[source],enabled:true});
        if(path==='/ai/analytics/insights')return ok(result);
        if(path==='/ai/analytics/explain')return ok({insights:result,explanation:'Source <script>alert(1)</script> is incomplete',usage:{input_tokens:60,output_tokens:30}});
        return ok({});
      }
    }''')
    page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-insights-query]').wait_for(timeout=12000)
    assert page.get_by_text('Site <strong>unsafe</strong>').count()==1
    page.locator('[data-insights-query] button').click()
    page.get_by_text('Sessions fell',exact=False).wait_for(timeout=12000)
    assert page.locator('img').count()==0,'User page label executed as HTML'
    assert page.get_by_text('<img src=x onerror=alert(1)>').count()==1
    page.locator('[data-insights-explain]').click()
    page.get_by_text('Source <script>alert(1)</script> is incomplete').wait_for(timeout=12000)
    calls=page.evaluate('window.requests')
    assert any(c['path']=='/ai/analytics/insights' and c['method']=='GET' for c in calls),calls
    assert any(c['path']=='/ai/analytics/explain' and c['method']=='POST' and c['csrf']=='CSRF-ANALYTICS' and c['body']=={'connection_id':'f'*32} for c in calls),calls
    assert not page.locator('script[src=x]').count() and not errors,errors
    print('PASS AI External Analytics static SPA: saved report, comparison, HTML escaping, AI explanation and CSRF')
    browser.close()
