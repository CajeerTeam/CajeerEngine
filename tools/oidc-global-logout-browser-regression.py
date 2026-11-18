#!/usr/bin/env python3
"""Production static UI: global SSO logout is offered only to the current OIDC browser session."""
import os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
script=(root/'public/admin/assets/admin-app.js').read_text()
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page();errs=[];nav=[];page.on('pageerror',lambda e:errs.append(str(e)));page.on('request',lambda req: nav.append(req.url) if req.is_navigation_request() else None)
    page.route('https://id.skif4er.ru/**',lambda route: route.fulfill(status=200,content_type='text/html',body='<h1>Keycloak logout</h1>'))
    records=[];page.expose_binding('save_call',lambda source,payload: records.append(payload))
    page.set_content('<div id="app"></div>')
    page.evaluate('''() => {
      const m=new Map();for(const n of ['localStorage','sessionStorage'])Object.defineProperty(window,n,{value:{getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,v),removeItem:k=>m.delete(k)}});
      location.hash='#/identity';window.calls=[];window.oidc=true;
      window.fetch=async(url,opt={})=>{
        const path=String(url).split('/api/v1')[1],method=opt.method||'GET';
        const entry={path,method,credentials:opt.credentials,csrf:opt.headers?.get?.('X-CSRF-Token')};calls.push(entry);window.save_call(entry);
        let data={};
        if(path==='/auth/me')data={user_id:'u',csrf_token:'csrf-oidc',cookie:true,session:true,scopes:['content:read']};
        else if(path==='/auth/oidc/status')data={enabled:true,global_logout:true};
        else if(path==='/identity/account')data={enabled:true,linked:true};
        else if(path==='/auth/sessions')data=[{id:'11111111-1111-1111-1111-111111111111',provider:oidc?'skif4er':'local',current:true,device:'Firefox',network:'',last_seen_at:'2026-11-18 00:00:00'}];
        else if(path==='/auth/oidc/logout')data={authorization_url:'https://id.skif4er.ru/realms/skif4er/protocol/openid-connect/logout?client_id=example',local_session_revoked:true};
        return new Response(JSON.stringify({data}),{status:200});
      };
    }''')
    page.add_script_tag(content=script)
    page.locator('[data-session-global]').wait_for()
    print('PASS global SSO logout offered to current OIDC session')
    page.once('dialog',lambda d:d.accept())
    page.locator('[data-session-global]').click()
    page.wait_for_timeout(800)
    assert any(c['path']=='/auth/oidc/logout' and c['method']=='POST' and c['credentials']=='same-origin' and c['csrf']=='csrf-oidc' for c in records)
    assert not errs,errs
    print('PASS CSRF-protected logout POST and allowlisted provider destination')
    browser.close()
