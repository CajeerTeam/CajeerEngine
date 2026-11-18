#!/usr/bin/env python3
"""Chromium: real static support UI and public widget with mocked HTTP transport."""
import os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
TICKET='11111111-1111-4111-8111-111111111111'
with sync_playwright() as driver:
    browser=driver.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page()
    errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content('<html><body><div id="app"></div></body></html>')
    page.evaluate(r'''() => {
      const store=new Map();Object.defineProperty(window,'localStorage',{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});
      window.calls=[];window.alert=()=>{throw Error('XSS')};window.confirm=()=>true;location.hash='#/support';
      const ID='11111111-1111-4111-8111-111111111111';let rev=1;
      const ticket={id:ID,subject:'<img src=x onerror=alert(1)>',name:'Customer',email:'customer@example.org',locale:'ru',priority:'normal',status:'open',revision:rev,needs_human:1,updated_at:10,assigned_to:null,
      messages:[{id:'msg1',role:'visitor',content:'Question <svg onload=alert(1)>',sources:[],created_at:1}]};
      const ok=data=>new Response(JSON.stringify({data}),{status:200,headers:{'Content-Type':'application/json'}});
      window.fetch=async(url,opts={})=>{
        const path=new URL(String(url),'https://example.test').pathname.replace('/api/v1','');
        const method=opts.method||'GET',body=opts.body?JSON.parse(opts.body):{};
        const csrf=new Headers(opts.headers||{}).get('X-CSRF-Token');calls.push({path,method,csrf,body});
        if(path==='/auth/oidc/status')return ok({enabled:false});
        if(path==='/auth/me')return ok({user_id:'operator',email:'operator@example.org',csrf_token:'CSRF-SUPPORT',scopes:['*']});
        if(path==='/support/tickets' || path.startsWith('/support/tickets?'))return ok({items:[ticket],total:1,limit:30,offset:0});
        if(path===`/support/tickets/${ID}`){
          if(method==='PATCH'){Object.assign(ticket,body);ticket.revision++;return ok(ticket)}
          return ok(ticket);
        }
        if(path===`/support/tickets/${ID}/messages`){ticket.messages.push({id:'msg2',role:'operator',content:body.message,sources:[]});ticket.status='waiting';return ok(ticket)}
        if(path===`/support/tickets/${ID}/ai-draft`)return ok({text:'AI answer <script>alert(1)</script> [[S1]]',sources:[{id:'source1',title:'Knowledge'}]});
        return ok({});
      };
    }''')
    page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-sp-open]').wait_for(timeout=10000)
    assert page.locator('img,svg').count()==0, 'Ticket fields must not be rendered as executable HTML'
    page.locator('[data-sp-open]').click()
    page.get_by_text('Question <svg onload=alert(1)>').wait_for()
    page.locator('[data-sp-update] select[name=priority]').select_option('high')
    page.locator('[data-sp-update] button:not([type=button])').click()
    page.wait_for_function('() => window.calls.some(c=>c.method==="PATCH"&&c.body.priority==="high")')
    page.locator('[data-sp-draft]').click()
    page.get_by_text('AI answer <script>alert(1)</script> [[S1]]').wait_for()
    assert page.locator('script').count()==1,'Provider text must not inject scripts'
    page.locator('[data-sp-reply] button:not([type=button])').click()
    page.wait_for_function('() => window.calls.some(c=>c.path.endsWith("/messages")&&c.method==="POST")')
    calls=page.evaluate('window.calls')
    writes=[c for c in calls if c['path'].startswith('/support/tickets/') and c['method']!='GET']
    assert len(writes)>=3 and all(c['csrf']=='CSRF-SUPPORT' for c in writes),writes
    assert not errors,errors
    print('PASS Chromium admin: queue, priority CAS, AI draft, reply, CSRF and XSS-safe rendering')
    page2=browser.new_page()
    page2.set_content('<html lang="ru"><body><p>Public CMS</p></body></html>')
    page2.evaluate(r'''() => {
      window.calls=[];window.alert=()=>{throw Error('XSS')};
      const ID='11111111-1111-4111-8111-111111111111';const TOKEN='a'.repeat(43);
      const storage=new Map();Object.defineProperty(window,'localStorage',{value:{getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)}});let messages=[];let status='open';let human=true;
      const ok=(data,code=200)=>new Response(JSON.stringify({data}),{status:code,headers:{'Content-Type':'application/json'}})
      window.fetch=async(url,opts={})=>{
        const path=new URL(String(url),'https://test.local').pathname.replace('/api/v1','')
        const method=opts.method||'GET';const body=opts.body?JSON.parse(opts.body):{};
        const secret=new Headers(opts.headers||{}).get('X-Support-Token');calls.push({path,method,body,secret,credentials:opts.credentials});
        if(path==='/public/support/config')return ok({enabled:true,auto_reply:false})
        if(path==='/public/support/tickets'&&method==='POST'){
          messages=[{id:'v1',role:'visitor',content:body.message,sources:[]}];return ok({ticket_id:ID,access_token:TOKEN,status:'open'},201)
        }
        if(path==='/public/support/tickets/'+ID&&method==='GET')return ok({id:ID,subject:'<img src=x onerror=alert(1)>',status,needs_human:human,messages,created_at:1,updated_at:1})
        if(path==='/public/support/tickets/'+ID+'/messages'&&method==='POST'){
          messages.push({id:'v2',role:'visitor',content:body.message,sources:[]});return ok({id:ID,messages})
        }
        if(path==='/public/support/tickets/'+ID+'/handoff'&&method==='POST'){
          human=true;status='open';return ok({id:ID,messages})
        }
        return ok({})
      }
    }''')
    page2.add_script_tag(content=(root/'public/support/widget.js').read_text())
    page2.locator('.ce-support-toggle').click()
    page2.locator('.ce-support-panel input[name=name]').fill('Customer')
    page2.locator('.ce-support-panel input[name=email]').fill('customer@example.org')
    page2.locator('.ce-support-panel input[name=subject]').fill('Help')
    page2.locator('.ce-support-panel textarea[name=message]').fill('Question <script>alert(1)</script>')
    page2.get_by_role('button',name='Создать обращение').click()
    page2.get_by_text('Question <script>alert(1)</script>').wait_for()
    assert page2.locator('.ce-support-panel script,.ce-support-panel img').count()==0,'Public messages must be textContent-only'
    page2.locator('.ce-support-panel textarea[name=message]').fill('More details')
    page2.get_by_role('button',name='Отправить').click()
    page2.get_by_text('More details').wait_for()
    page2.get_by_role('button',name='Позвать оператора').click()
    page2.get_by_text('Обращение передано оператору.').wait_for()
    calls=page2.evaluate('window.calls')
    assert any(c['path']=='/public/support/tickets' and c['credentials']=='omit' for c in calls)
    assert all(c['secret']=='a'*43 for c in calls if c['path'].startswith('/public/support/tickets/'+TICKET) and c['method']!='GET')
    assert not errors,errors
    print('PASS Chromium public: ticket creation, guest capability, conversation, handoff and safe DOM insertion')
    browser.close()
