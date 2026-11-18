#!/usr/bin/env python3
"""Production static Identity UI: real event handlers and cookie-only API calls."""
import os,shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
js=(root/'public/admin/assets/admin-app.js').read_text()
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
 page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 page.set_content('<div id="app"></div>')
 page.evaluate('''() => {
  const m=new Map();for(const n of ['localStorage','sessionStorage'])Object.defineProperty(window,n,{value:{getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,v),removeItem:k=>m.delete(k)}});
  location.hash='#/identity';window.calls=[];window.sessions=[
    {id:'11111111-1111-1111-1111-111111111111',provider:'local',device:'Firefox',network:'192.168.1.0/24',current:true,last_seen_at:'2026-11-18 08:00:00',expires_at:null},
    {id:'22222222-2222-2222-2222-222222222222',provider:'skif4er',device:'Android',network:'10.20.0.0/24',current:false,last_seen_at:'2026-11-18 07:00:00',expires_at:null}
  ];
  window.fetch=async(url,opt={}) => {
    let path=String(url).split('/api/v1')[1], method=opt.method||'GET';
    calls.push({path,method,credentials:opt.credentials,csrf:opt.headers?.get?.('X-CSRF-Token')});
    let data={};
    if(path==='/auth/me')data={user_id:'uuid',csrf_token:'csrf-old',scopes:['content:read'],cookie:true,session:true};
    else if(path==='/auth/oidc/status')data={enabled:true};
    else if(path==='/identity/account')data={enabled:true,linked:true,provisioned:false};
    else if(path==='/auth/sessions' && method==='GET')data=sessions;
    else if(path?.startsWith('/auth/sessions/') && method==='DELETE'){
       sessions=sessions.filter(s=>!path.endsWith(s.id));data={revoked:true};
    } else if(path==='/auth/sessions/rotate')data={csrf_token:'csrf-new',expires_at:'2026-12-01 00:00:00'};
    else if(path==='/auth/sessions/revoke-others'){sessions=sessions.filter(s=>s.current);data={revoked_count:1};}
    else if(path==='/auth/sessions/revoke-all'){sessions=[];data={revoked_count:1};}
    return new Response(JSON.stringify({data}),{status:200});
  };
}''')
 page.add_script_tag(content=js)
 page.get_by_text('Активные сессии').wait_for()
 assert page.get_by_text('Android').count()==1
 assert page.evaluate('calls.some(x=>x.path==="/auth/sessions"&&x.credentials==="same-origin")')
 print('PASS separate local and external session metadata displayed')
 page.once('dialog',lambda d:d.accept())
 page.locator('[data-session-revoke="22222222-2222-2222-2222-222222222222"]').click()
 page.wait_for_function('!document.querySelector("[data-session-revoke=\\"22222222-2222-2222-2222-222222222222\\"]")')
 assert page.evaluate('calls.some(x=>x.path.endsWith("22222222-2222-2222-2222-222222222222")&&x.method==="DELETE"&&x.csrf==="csrf-old")')
 print('PASS selectively revokes a remote device using cookie and CSRF')
 page.locator('[data-session-rotate]').click()
 page.wait_for_function('calls.some(x=>x.path==="/auth/sessions/rotate")')
 page.once('dialog',lambda d:d.accept())
 page.locator('[data-session-others]').click()
 page.wait_for_function('calls.some(x=>x.path==="/auth/sessions/revoke-others"&&x.csrf==="csrf-new")')
 print('PASS rotation renews CSRF used by next mutation')
 page.once('dialog',lambda d:d.accept())
 page.locator('[data-session-all]').click()
 page.wait_for_function('location.hash.includes("login")')
 assert not errors,errors
 print('PASS global local logout returns to login without JS exceptions')
 browser.close()
