#!/usr/bin/env python3
"""Real Chromium: session-bound reauthentication UI and one-time guarded mutation retry."""
import os,shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
script=(root/'public/admin/assets/admin-app.js').read_text()
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content('<div id="app"></div>')
    page.evaluate('''() => {
      const m = new Map();for(const key of ['localStorage','sessionStorage'])Object.defineProperty(window,key,{value:{getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,v),removeItem:k=>m.delete(k)}});
      location.hash='#/identity';window.calls=[];window.confirmed=false;
      window.fetch=async (url,opt={})=>{
        const path=String(url).split('/api/v1')[1],method=opt.method||'GET';
        calls.push({path,method,body:opt.body,csrf:opt.headers?.get?.('X-CSRF-Token'),credentials:opt.credentials});
        if(path==='/auth/oidc/status')return new Response(JSON.stringify({data:{enabled:true}}),{status:200});
        if(path==='/auth/me')return new Response(JSON.stringify({data:{user_id:'u',csrf_token:'csrf-123',cookie:true,session:true,scopes:['*']}}),{status:200});
        if(path==='/identity/account')return new Response(JSON.stringify({data:{enabled:true,linked:false}}),{status:200});
        if(path==='/auth/sessions')return new Response(JSON.stringify({data:[{id:'id',provider:'local',current:true,device:'Firefox',network:'',last_seen_at:'2026-11-18'}]}),{status:200});
        if(path==='/auth/assurance/confirm'){confirmed=true;return new Response(JSON.stringify({data:{fresh:true,expires_at:'2026-11-18T15:00:00Z'}}),{status:200});}
        if(path==='/users'&&method==='POST'&&!confirmed)return new Response(JSON.stringify({error:{code:'step_up_required',message:'Reauthentication required'}}),{status:403});
        return new Response(JSON.stringify({data:[]}),{status:200});
      }
    }''')
    page.add_script_tag(content=script)
    page.locator('[data-assurance-form]').wait_for()
    page.locator('[data-assurance-form] [name=password]').fill('SecretPass!')
    page.locator('[data-assurance-form] [name=two_factor_code]').fill('123456')
    page.locator('[data-assurance-form] [type=submit]').click()
    page.wait_for_function('window.confirmed===true')
    calls=page.evaluate('calls')
    assert any(c['path']=='/auth/assurance/confirm' and c['method']=='POST' and c['csrf']=='csrf-123' and c['credentials']=='same-origin' for c in calls)
    print('PASS production UI confirms local password and TOTP with HttpOnly/CSRF transport')
    page.evaluate('confirmed=false')
    prompts=['SecretPass!', '123456']
    def handle(dialog):
        if dialog.type == 'prompt': dialog.accept(prompts.pop(0))
        else: dialog.accept()
    page.on('dialog',handle)
    page.evaluate('''() => api('/users',{method:'POST',body:JSON.stringify({})})''')
    calls=page.evaluate('calls')
    assert len([c for c in calls if c['path']=='/users' and c['method']=='POST'])==2
    assert len([c for c in calls if c['path']=='/auth/assurance/confirm'])==2
    assert not prompts and not errors,errors
    print('PASS privileged mutation receives 403, prompts reauthentication, confirms and retries once')
    browser.close()
