#!/usr/bin/env python3
"""Chromium acceptance: static production AI Actions approval and secure preview."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import os, shutil

root=Path(__file__).resolve().parent.parent
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page()
    problems=[];page.on('pageerror',lambda e: problems.append(str(e)))
    page.set_content('<html><body><div id="app"></div></body></html>')
    page.evaluate(r'''() => {
      const storage=new Map(); Object.defineProperty(window,'localStorage',{value:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)}});
      location.hash='#/ai';window.alert=()=>{throw Error('Injected alert')};window.confirm=()=>true;
      window.calls=[];
      const ID='11111111-1111-4111-8111-111111111111';
      const actions=[{id:ID,action:'cms_action_update_seo',before:{status:'published',value:'Old title',revision:1},payload:{type:'pages',id:ID,field:'seo_title',value:'<img src=x onerror=alert(1)>'},state:'pending',expected_revision:1,expires_at:1999999999}];
      const ok=data=>new Response(JSON.stringify({data}),{status:200,headers:{'Content-Type':'application/json'}});
      window.fetch=async(url,opts={})=>{
        const path=new URL(String(url),'https://example.test').pathname.replace('/api/v1','');
        const method=opts.method||'GET';const body=opts.body?JSON.parse(opts.body):{};
        const csrf=new Headers(opts.headers||{}).get('X-CSRF-Token');calls.push({path,method,body,csrf});
        if(path==='/auth/oidc/status')return ok({enabled:false});
        if(path==='/auth/me')return ok({user_id:'operator',csrf_token:'CSRF-ACTIONS',scopes:['*']});
        if(path==='/ai/status')return ok({configured:true,model:'gpt-4.1-mini',credential_source:'vault',max_output_tokens:1024,monthly_token_limit:100000});
        if(path==='/ai/conversations')return ok([]);
        if(path==='/ai/tools')return ok([{name:'cms_content_search',description:'Search CMS',scope:'content:read'},
          {name:'cms_action_update_seo',description:'Prepare SEO change',scope:'content:write',requires_approval:true}]);
        if(path==='/ai/actions'&&method==='GET')return ok(actions);
        if(path===`/ai/actions/${ID}/approve`&&method==='POST') {actions.pop();return ok({state:'applied',result:{id:ID}})}
        if(path===`/ai/actions/${ID}/reject`&&method==='POST') {actions.pop();return ok({state:'rejected'})}
        return ok({});
      }
    }''')
    page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-ai-action-approve]').wait_for(timeout=12000)
    assert page.locator('img[src=x]').count()==0,'AI action payload rendered as HTML'
    assert page.get_by_text('<img src=x onerror=alert(1)>').count()>=1,'AI preview is escaped text'
    page.locator('[data-ai-tools-toggle]').check()
    page.locator('[data-ai-actions-toggle]').check()
    page.locator('[data-ai-action-approve]').click()
    page.wait_for_function('() => window.calls.some(c=>c.path.endsWith("/approve")&&c.method==="POST")')
    approved=page.evaluate('window.calls.filter(c=>c.path.endsWith("/approve"))')
    assert len(approved)==1 and approved[0]['body']=={'confirm':True,'confirm_published':True},approved
    assert approved[0]['csrf']=='CSRF-ACTIONS',approved
    page.get_by_text('Нет ожидающих предложений.').wait_for(timeout=10000)
    assert not problems,problems
    print('PASS static Chromium AI Actions: preview, no DOM injection, explicit double confirmation and CSRF')
    browser.close()
