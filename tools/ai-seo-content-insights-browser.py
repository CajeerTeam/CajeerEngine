#!/usr/bin/env python3
"""Chromium acceptance: real static SPA SEO analytics matching, proposal, approval and XSS-safe rendering."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import shutil,os
root=Path(__file__).resolve().parents[1]
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content('<div id="app"></div>')
    page.evaluate(r'''() => {
        const store=new Map();for(const key of ['localStorage','sessionStorage'])Object.defineProperty(window,key,{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});
        location.hash='#/seo'; window.alert=()=>{throw Error('XSS executed')};window.requests=[];window.applied=false;
        const cid='f'.repeat(32),eid='11111111-1111-4111-8111-111111111111';
        const entry={id:eid,title:'Setup page',status:'published',locale:'ru',revision:3,data:{seo_title:''}};
        const insight={available:true,connection_id:cid,provider:'ga4',from:'2026-10-01',to:'2026-10-15',comparison_status:'ready',matched:1,examined:1,warnings:['External <img src=x onerror=alert(1)>'],notes:'No causal proof',items:[{type:'pages',entry_id:eid,revision:3,title:'Unsafe <script>alert(1)</script>',path:'/setup',score:40,priority:90,metric:'sessions',current:50,previous:100,change_percent:-50,trend:'decline',issues:[{code:'missing_seo_title'}],recommendations:[{code:'missing_seo_title',field:'seo_title',action:'Generate safe <script>alert(1)</script>',severity:'high'}]}]};
        const ok=data=>new Response(JSON.stringify({data}),{status:200,headers:{'Content-Type':'application/json'}});
        window.fetch=async(url,options={})=>{
            const path=new URL(String(url),'https://example.invalid').pathname.replace('/api/v1','');
            const method=options.method||'GET',body=options.body?JSON.parse(options.body):null,csrf=new Headers(options.headers||{}).get('X-CSRF-Token');
            requests.push({path,method,body,csrf});
            if(path==='/auth/oidc/status')return ok({enabled:false});
            if(path==='/auth/me')return ok({user_id:'operator',csrf_token:'CSRF-SEO-TEST',scopes:['content:read','content:write','analytics:read']});
            if(path==='/content-types')return ok([{handle:'pages',name:'Pages'}]);
            if(path==='/content/pages')return ok([entry]);
            if(path==='/analytics/reports')return ok({items:[{id:cid,provider:'ga4',name:'Search property'}],enabled:true});
            if(path==='/ai/seo/insights')return ok(insight);
            if(path==='/ai/seo/insights/proposals')return ok({proposal:{id:'22222222-2222-4222-8222-222222222222',original:'',proposed:'Better <script>alert(1)</script>',expected_revision:3,field:'seo_title',usage:{input_tokens:40,output_tokens:20}}});
            if(path.includes('/ai/editor/proposals/')&&path.endsWith('/apply')){window.applied=true;return ok({entry:{revision:4}})}
            if(path==='/ai/seo/audit')return ok({type:'pages',entry_id:eid,title:'Setup page',revision:3,issues:[],score:90});
            return ok({});
        };
    }''')
    page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-seo-insights]').wait_for(timeout=12000)
    page.locator('[data-seo-insights] button').click()
    page.get_by_text('Unsafe <script>alert(1)</script>').wait_for(timeout=12000)
    assert page.locator('img').count()==0,'Unsafe analytics source text rendered as HTML'
    assert page.get_by_text('External <img src=x onerror=alert(1)>').count()==1
    page.locator('[data-seo-insight-propose]').click()
    page.get_by_text('Better <script>alert(1)</script>').wait_for(timeout=12000)
    page.evaluate('window.confirm=()=>true')
    page.locator('[data-seo-insight-approve]').click()
    page.get_by_text('SEO-правка сохранена как новая ревизия.',exact=False).wait_for(timeout=12000)
    calls=page.evaluate('window.requests')
    assert any(x['path']=='/ai/seo/insights' and x['method']=='GET' for x in calls),calls
    assert any(x['path']=='/ai/seo/insights/proposals' and x['method']=='POST' and x['csrf']=='CSRF-SEO-TEST' for x in calls),calls
    assert any(x['path'].endswith('/apply') and x['body']=={'confirm_published':True} for x in calls),calls
    assert page.evaluate('window.applied') and not errors,errors
    print('PASS AI SEO Content Insights static SPA: matching, safe text, CSRF proposal, explicit approved revision')
    browser.close()
