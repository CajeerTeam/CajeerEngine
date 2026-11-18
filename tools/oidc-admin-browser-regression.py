#!/usr/bin/env python3
"""Actual published admin JS in Chromium; OIDC login button respects backend feature flag."""
from pathlib import Path
import os, shutil
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parents[1]
js=(root/'public/admin/assets/admin-app.js').read_text()
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    for enabled in (True, False):
        page=browser.new_page()
        errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content('<div id="app"></div>')
        page.evaluate('''(enabled) => {
          const store = new Map(); window.requests=[];
          Object.defineProperty(window,'localStorage',{value:{getItem:(k)=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:(k)=>store.delete(k)}});
          Object.defineProperty(window,'sessionStorage',{value:{getItem:(k)=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:(k)=>store.delete(k),key:()=>null,length:0}});
          window.fetch=async(url,opt={})=>{
            requests.push(String(url));
            const path=String(url).split('/api/v1')[1];
            if(path==='/auth/oidc/status')return new Response(JSON.stringify({data:{enabled,provider:'SkiF4er ID'}}),{status:200});
            if(path==='/auth/me')return new Response(JSON.stringify({error:{message:'unauthenticated'}}),{status:401});
            return new Response(JSON.stringify({error:{message:'unauthenticated'}}),{status:401});
          }
        }''',enabled)
        page.add_script_tag(content=js)
        page.get_by_text('Вход в CajeerEngine',exact=False).count()  # script owns the UI
        page.wait_for_function("document.querySelector('[data-form=login]') !== null")
        button=page.locator('a[href="/auth/oidc/start"]')
        assert button.count()==(1 if enabled else 0), ('enabled:',enabled,'count:',button.count())
        assert not errors,errors
        assert page.evaluate("requests.includes('/api/v1/auth/oidc/status')")
        print('PASS published Admin SPA OIDC button ' + ('visible' if enabled else 'hidden') + ' and no JS errors')
        page.close()
    browser.close()
