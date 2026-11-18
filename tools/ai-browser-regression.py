#!/usr/bin/env python3
"""Chromium regression for shipped static AI chat, SSE, settings, history and XSS escaping."""
import json
import os
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parents[1]
script = (root / 'public/admin/assets/admin-app.js').read_text()
with sync_playwright() as playwright:
    browser = playwright.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'), headless=True, args=['--no-sandbox'])
    page = browser.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.set_content('<div id="app"></div>')
    page.evaluate('''() => {
      const cache = new Map();
      Object.defineProperty(window, 'localStorage', {value: {getItem:k=>cache.get(k)||null, setItem:(k,v)=>cache.set(k,v), removeItem:k=>cache.delete(k)}});
      window.confirm=()=>true;window.prompt=(_, value)=>value==='Demo'?'Renamed':'Renamed';
      location.hash='#/ai';window.calls=[];window.alertFired=false;
      window.fakeConversations=[];window.fakeMessages={};window.fakeConfigured=false;window.toolToggleSent=false;
      window.fetch=async (url,opts={})=>{
        const path=String(url).replace('/api/v1','');calls.push({path,method:opts.method||'GET', headers:Object.fromEntries(new Headers(opts.headers||{}).entries())});
        const body=opts.body?JSON.parse(opts.body):{};
        const json=(value,status=200)=>({ok:status<400,status,json:async()=>value,text:async()=>JSON.stringify(value)});
        if(path==='/auth/oidc/status')return json({data:{enabled:false}});
        if(path==='/auth/me')return json({data:{user_id:'u1',name:'Admin',email:'admin@test',csrf_token:'token-csrf',scopes:['*']}});
        if(path==='/ai/tools')return json({data:[{name:'cms_content_search',description:'Поиск содержимого',scope:'content:read'}]});
        if(path==='/ai/status')return json({data:{configured:fakeConfigured,credential_source:fakeConfigured?'vault':'none',model:'gpt-4.1-mini',max_output_tokens:1024,monthly_token_limit:250000}});
        if(path==='/ai/conversations'&&(!opts.method||opts.method==='GET'))return json({data:fakeConversations});
        if(path==='/ai/settings'&&opts.method==='PUT'){fakeConfigured=!!body.api_key||fakeConfigured; if(body.remove_key)fakeConfigured=false;return json({data:{configured:fakeConfigured,credential_source:fakeConfigured?'vault':'none',model:body.model||'gpt-4.1-mini',max_output_tokens:body.max_output_tokens||1024,monthly_token_limit:body.monthly_token_limit||250000}})}
        if(path==='/ai/chat/stream'){
          if(!fakeConfigured)return json({error:{message:'Missing API key'}},422);
          if(!opts.headers['X-CSRF-Token'])throw new Error('Missing CSRF');
          if(body.tools_enabled!==true)throw new Error('Tools enabled switch not propagated');toolToggleSent=true;
          const id=body.conversation_id||'11111111-1111-4111-8111-111111111111';
          if(!fakeConversations.some(c=>c.id===id))fakeConversations.push({id,title:'Demo',updated_at:100});
          fakeMessages[id]=[...(fakeMessages[id]||[]),{id:'usr',role:'user',content:body.message,created_at:1},{id:'ans',role:'assistant',content:'AI answer <script>alert(1)</script>',created_at:2,tools:[{name:'cms_content_search',status:'ok',duration_ms:2}]}];
          const frames=['event: tool\\ndata: '+JSON.stringify({name:'cms_content_search',status:'ok',duration_ms:2})+'\\n\\n',
            'event: delta\\ndata: '+JSON.stringify({text:'AI answer <script>'})+'\\n\\n',
            'event: delta\\ndata: '+JSON.stringify({text:'alert(1)</script>'})+'\\n\\n',
            'event: done\\ndata: '+JSON.stringify({conversation_id:id,title:'Demo',text:'AI answer <script>alert(1)</script>',usage:{input_tokens:10,output_tokens:10},model:'gpt-4.1-mini'})+'\\n\\n'];
          const encoder=new TextEncoder();const full=frames.join('');
          return {ok:true,status:200,body:new ReadableStream({start(c){for(let i=0;i<full.length;i+=9)c.enqueue(encoder.encode(full.slice(i,i+9)));c.close();}})};
        }
        const match=path.match(/^\\/ai\\/conversations\\/([^/]+)$/);
        if(match){const id=decodeURIComponent(match[1]);if(opts.method==='DELETE'){fakeConversations=fakeConversations.filter(c=>c.id!==id);delete fakeMessages[id];return json({data:{deleted:true}})}if(opts.method==='PATCH'){fakeConversations.find(c=>c.id===id).title=body.title;return json({data:{id,title:body.title}})}return json({data:fakeMessages[id]||[]})}
        return json({data:{}},200)
      }
    }''')
    page.add_script_tag(content=script)
    page.wait_for_selector('h1:text("AI Assistant")',timeout=12000)
    assert page.get_by_text('не настроен', exact=True).count() >= 1
    page.locator('[data-ai-settings]').evaluate('(el)=>el.closest("details").open=true')
    page.locator('[data-ai-settings] input[name="api_key"]').fill('sk-test-browser')
    page.locator('[data-ai-settings] button[type="submit"]').click()
    page.wait_for_function('window.fakeConfigured===true')
    assert not page.locator('[data-ai-tools-toggle]').is_checked()
    page.locator('[data-ai-tools-toggle]').check()
    page.locator('[data-ai-chat] textarea').fill('Привет')
    page.locator('[data-ai-chat] button[type="submit"]').click()
    page.wait_for_function('window.fakeMessages["11111111-1111-4111-8111-111111111111"]?.length===2')
    page.wait_for_selector('.ai-bubble.assistant:has-text("AI answer")')
    assert page.locator('script').count() == 1, 'Unescaped HTML executed or inserted into DOM'
    assert page.get_by_text('AI answer <script>alert(1)</script>', exact=True).count() >= 1
    assert page.evaluate('window.toolToggleSent')
    assert page.get_by_text('cms_content_search (ok)').count() >= 1
    assert len(page.evaluate('window.fakeConversations')) == 1
    page.locator('[data-ai-rename]').click()
    page.wait_for_function('window.fakeConversations[0]?.title==="Renamed"')
    page.locator('[data-ai-delete]').click()
    page.wait_for_function('window.fakeConversations.length===0')
    calls=page.evaluate('window.calls')
    assert any(c['path']=='/ai/settings' and c['headers'].get('x-csrf-token')=='token-csrf' for c in calls), 'Settings CSRF header absent'
    assert not errors, errors
    print('PASS static SPA: OpenAI settings, SSE fragments, history, rename/delete, XSS escaping, CSRF')
    browser.close()
