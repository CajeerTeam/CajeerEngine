#!/usr/bin/env python3
"""Exercise the shipped production Admin UI against deterministic API responses."""
import os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
script=(root/'public/admin/assets/admin-app.js').read_text()
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page(); errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content('<div id="app"></div>')
    page.evaluate('''() => {
      const m=new Map();for(const name of ['localStorage','sessionStorage'])Object.defineProperty(window,name,{value:{getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,v),removeItem:k=>m.delete(k)}});
      location.hash='#/identity';window.calls=[];window.linked=false;
      window.fetch=async(url,opt={})=>{
        const path=String(url).split('/api/v1')[1]; const method=opt.method||'GET'; const body=opt.body?JSON.parse(opt.body):null;
        calls.push({path,method,body,csrf:opt.headers?.['X-CSRF-Token']});
        let data={};
        if(path==='/auth/oidc/status')data={enabled:true,provider:'SkiF4er ID'};
        else if(path==='/auth/me')data={user_id:'user-id',csrf_token:'csrf',scopes:['content:read'],cookie:true,session:true};
        else if(path==='/identity/account')data={enabled:true,linked:linked,provisioned:false,created_at:null};
        else if(path==='/identity/unlink') { linked=false;data={unlinked:true}; }
        else if(path==='/identity/link/start')data={authorization_url:'https://id.skif4er.ru/realms/skif4er/protocol/openid-connect/auth'};
        return new Response(JSON.stringify({data}),{status:200});
      };
    }''')
    page.add_script_tag(content=script)
    page.locator('[data-identity-form]').wait_for()
    assert page.get_by_text('Внешняя учётная запись не привязана.').count()==1
    assert page.get_by_text('Привязать через SkiF4er ID').count()==1
    assert page.evaluate('calls.some(c=>c.path==="/identity/account")')
    print('PASS Identity settings load for authenticated user')
    page.evaluate('linked=true')
    page.locator('[data-action="refresh"]').click()
    page.get_by_text('Отвязать SkiF4er ID').wait_for()
    page.locator('[data-identity-form] [name="password"]').fill('LocalStrongPassword123')
    page.locator('[data-identity-form] [name="two_factor_code"]').fill('123456')
    page.once('dialog',lambda d:d.accept())
    page.locator('[data-identity-form] [type="submit"]').click()
    page.get_by_text('Внешний аккаунт отвязан.').wait_for()
    assert page.evaluate('calls.some(c=>c.path==="/identity/unlink" && c.method==="POST" && c.body.password==="LocalStrongPassword123" && c.body.two_factor_code==="123456")')
    assert not errors, errors
    print('PASS unlink reauthentication, POST, refresh and no JS errors')
    browser.close()
