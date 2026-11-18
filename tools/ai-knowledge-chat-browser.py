#!/usr/bin/env python3
"""Chromium admin RAG UI: real event handlers, mocked network transport, DOM injection checks."""
import os,shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
with sync_playwright() as driver:
    browser=driver.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page()
    errors=[]
    page.on('pageerror',lambda error:errors.append(str(error)))
    page.set_content('<div id="app"></div>')
    page.evaluate(r'''() => {
      const cache=new Map();for(const k of ['localStorage','sessionStorage'])Object.defineProperty(window,k,{value:{getItem:name=>cache.get(name)||null,setItem:(name,value)=>cache.set(name,value),removeItem:name=>cache.delete(name)}})
      location.hash='#/knowledge-chat';window.calls=[];window.alert=()=>{throw Error('XSS')};window.confirm=()=>true;
      const C='11111111-1111-4111-8111-111111111111',D='22222222-2222-4222-8222-222222222222';
      let conversations=[],messages=[],next=0;
      const ok=(data,status=200)=>new Response(JSON.stringify({data}),{status,headers:{'Content-Type':'application/json'}})
      window.fetch=async(url,opt={})=>{
        const path=new URL(String(url),'https://test.local').pathname.replace('/api/v1','');
        const method=opt.method||'GET',body=opt.body?JSON.parse(opt.body):{},csrf=new Headers(opt.headers||{}).get('X-CSRF-Token');
        calls.push({path,method,body,csrf});
        if(path==='/auth/oidc/status')return ok({enabled:false});
        if(path==='/auth/me')return ok({user_id:'admin',csrf_token:'CSRF-CASE',scopes:['*']});
        if(path==='/ai/status')return ok({configured:true,model:'gpt-test'});
        if(path==='/knowledge/chat/conversations' && method==='GET')return ok(conversations);
        if(path==='/knowledge/chat/ask' && method==='POST'){
          if(body.visibility!=='public')throw Error('Expected public-only scope');
          if(!body.question)throw Error('Missing question');
          conversations=[{id:C,title:'Вопрос о документации',visibility:'public',locale:'ru',updated_at:42}];
          messages=[{id:'33333333-3333-4333-8333-333333333333',role:'user',content:body.question,sources:[],revoked:false,feedback:null},
            {id:'44444444-4444-4444-8444-444444444444',role:'assistant',content:'Ответ <img src=x onerror=alert(1)> [1]',sources:[{number:1,id:D,title:'Инструкция <script>alert(1)</script>',locale:'ru',visibility:'public',revision:1,chunk_no:0}],revoked:false,feedback:null}];
          return ok({conversation_id:C,message_id:'44444444-4444-4444-8444-444444444444',text:messages[1].content,sources:messages[1].sources,usage:{input_tokens:30,output_tokens:10}})
        }
        if(path==='/knowledge/chat/conversations/'+C){
          if(method==='DELETE'){conversations=[];messages=[];return ok({deleted:true})}
          return ok(messages)
        }
        if(path.endsWith('/feedback') && method==='POST'){
          messages[1].feedback=body.rating;return ok({feedback:body.rating})
        }
        if(path==='/knowledge/documents/'+D)return ok({id:D,source_kind:'manual',title:'Источник',slug:'source',body:'Текст',locale:'ru',status:'published',visibility:'public',revision:1,indexed_revision:1,category_id:null});
        if(path==='/knowledge/categories')return ok([]);
        if(path==='/knowledge/documents')return ok({items:[],total:0});
        if(path==='/content-types')return ok([]);
        return ok({});
      }
    }''')
    page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-kc-ask]').wait_for(timeout=12000)
    page.locator('#kc-visibility').select_option('public')
    page.locator('#kc-locale').select_option('ru')
    page.locator('[data-kc-ask] textarea[name=question]').fill('Как работает документация?')
    page.locator('[data-kc-ask] button[type=submit]').click()
    page.get_by_text('Ответ <img src=x onerror=alert(1)> [1]').wait_for()
    assert page.locator('img').count()==0, 'Answer must render as escaped text, never HTML'
    assert page.locator('script').count()==1, 'Source title must not inject script'
    assert page.locator('[data-kc-source]').count()==1
    assert page.locator('#kc-visibility').is_disabled(),'Cannot widen conversation scope'
    page.locator('[data-kc-rate][data-rating="1"]').click()
    page.wait_for_function('() => window.calls.some(x=>x.path.endsWith("/feedback")&&x.body.rating===1)')
    page.locator('[data-kc-source]').click()
    page.wait_for_function('() => location.hash === "#/knowledge"')
    assert page.locator('[data-kb-document] input[name=title]').input_value()=='Источник', 'Citation opens source in KB'
    page.locator('[data-route="knowledge-chat"]').click()
    page.locator('[data-kc-delete]').click()
    page.wait_for_function('() => window.calls.some(x=>x.path.includes("/knowledge/chat/conversations/")&&x.method==="DELETE")')
    calls=page.evaluate('calls')
    writes=[x for x in calls if x['path'].startswith('/knowledge/chat/') and x['method'] in ['POST','DELETE']]
    assert writes and all(x['csrf']=='CSRF-CASE' for x in writes),writes
    assert any(x['path']=='/knowledge/chat/ask' and x['body']['locale']=='ru' for x in writes)
    assert not errors,errors
    print('PASS Chromium: RAG answers, cited document navigation, per-conversation scope lock, feedback, deletion, CSRF and safe text rendering')
    browser.close()
