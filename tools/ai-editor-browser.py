#!/usr/bin/env python3
"""Real Chromium event-flow test against the shipped, unbundled static production admin JS."""
import os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parents[1]
with sync_playwright() as playwright:
    browser = playwright.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'), headless=True, args=['--no-sandbox'])
    page = browser.new_page()
    errors=[]
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.set_content('<div id="app"></div>')
    page.evaluate(r'''() => {
      const cache = new Map(); for (const key of ['localStorage','sessionStorage'])
        Object.defineProperty(window,key,{value:{getItem:k=>cache.get(k)||null,setItem:(k,v)=>cache.set(k,v),removeItem:k=>cache.delete(k)}});
      location.hash='#/content';window.confirm=()=>true;window.prompt=()=>null;
      const id='11111111-1111-4111-8111-111111111111';
      window.fakeTypes=[{handle:'pages',name:'Pages',fields:[
        {handle:'body',label:'Body',type:'richtext'},
        {handle:'lead',label:'Lead',type:'textarea'},
        {handle:'blocks',label:'Blocks',type:'blocks'}],blocks:[{handle:'paragraph',name:'Paragraph',fields:[{handle:'heading',label:'Heading',type:'text'}]}]}];
      window.fakeEntries=[{id,title:'Existing entry',slug:'existing',locale:'ru',status:'published',revision:3,data:{body:'<p>Safe text</p>',lead:'Original lead',blocks:[{handle:'paragraph',data:{heading:'Old heading'}}]}}];
      window.apiCalls=[];
      window.fetch=async(url,opt={})=>{
        const full=new URL(String(url),'https://example.test');const path=full.pathname.replace('/api/v1','');const method=opt.method||'GET';
        const body=opt.body?JSON.parse(opt.body):{};
        apiCalls.push({path,method,body,csrf:new Headers(opt.headers||{}).get('X-CSRF-Token')});
        const json=(data,status=200)=>new Response(JSON.stringify({data}),{status,headers:{'Content-Type':'application/json'}});
        if(path==='/auth/oidc/status')return json({enabled:false});
        if(path==='/auth/me')return json({user_id:'owner',csrf_token:'csrf-value',scopes:['*']});
        if(path==='/content-types')return json(fakeTypes);
        if(path==='/content/pages'&&method==='GET')return json(fakeEntries);
        if(path==='/content/pages'&&method==='POST'){const data={...body,id:'22222222-2222-4222-8222-222222222222',revision:1};fakeEntries.push(data);return json(data,201);}
        if(path==='/ai/editor/proposals'&&method==='POST'){
          if(!new Headers(opt.headers||{}).get('X-CSRF-Token'))return json({},403);
          if(!fakeTypes[0].fields.some(f=>f.handle===body.field)&&body.field!=='title')return json({},422);
          const original=body.entry_id?(body.block_index!=null?'Old heading':body.field==='title'?'Existing entry':body.field==='body'?'<p>Safe text</p>':'Original lead'):'';
          const proposed=body.entry_id?'New <img src=x onerror=alert(1)>':'Draft generated content';
          return json({id:'33333333-3333-4333-8333-333333333333',type:'pages',entry_id:body.entry_id,field:body.field,block_index:body.block_index??null,block_field:body.block_field??null,mode:body.mode,original,proposed,expected_revision:body.entry_id?3:null,usage:{input_tokens:20,output_tokens:10}});
        }
        if(path.match(/^\/ai\/editor\/proposals\/[^/]+\/apply$/)&&method==='POST'){
          if(body.confirm_published!==true)return json({message:'Explicit confirmation required'},422);
          const entry=fakeEntries[0];entry.data.lead='New AI copy';entry.revision++;return json({type:'pages',entry});
        }
        if(path==='/content/pages/'+fakeEntries[0].id&&method==='GET')return json(fakeEntries[0]);
        if(path==='/cms/preview-token')return json({preview_url:'/preview/test'});
        return json({});
      };
    }''')
    page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-ai-editor-form]').wait_for()
    page.locator('[data-edit-entry]').first.click()
    page.locator('[data-ai-editor-form] select[name=field]').select_option('lead')
    page.locator('[data-ai-editor-form] select[name=mode]').select_option('rewrite')
    page.locator('[data-ai-editor-form] textarea[name=instructions]').fill('Edit for clarity')
    page.locator('[data-ai-editor-form] button[type=submit]').click()
    try: page.locator('[data-ai-editor-accept]').wait_for(timeout=4500)
    except Exception:
        print('DEBUG:',page.evaluate('apiCalls'),page.locator('body').inner_text()[-3000:], errors)
        raise
    assert page.locator('.ai-editor-text').count()==2
    assert page.locator('.ai-editor-text').nth(1).inner_text()=='New <img src=x onerror=alert(1)>'
    assert page.locator('.ai-editor-compare img').count()==0
    assert page.locator('.ai-editor-compare script').count()==0
    calls=page.evaluate('apiCalls')
    assert any(c['path']=='/ai/editor/proposals' and c['method']=='POST' and c['csrf']=='csrf-value' and c['body']['field']=='lead' for c in calls),calls
    page.locator('[data-ai-editor-accept]').click()
    page.get_by_text('AI-правка сохранена как новая ревизия.').wait_for()
    calls=page.evaluate('apiCalls')
    assert any('/apply' in c['path'] and c['body'].get('confirm_published') is True for c in calls),calls
    assert page.evaluate('fakeEntries[0].revision')==4
    print('PASS browser saved-entry AI generation, inert diff, CSRF, publication confirmation, new revision')
    page.locator('[data-edit-entry]').first.click()
    assert page.locator('[data-ai-editor-form] select[name=field] option[value="blocks|0|heading"]').count()==1
    page.locator('[data-ai-editor-form] select[name=field]').select_option('blocks|0|heading')
    page.locator('[data-ai-editor-form] textarea[name=instructions]').fill('Rewrite block')
    page.locator('[data-ai-editor-form] button[type=submit]').click()
    page.locator('[data-ai-editor-accept]').wait_for()
    calls=page.evaluate('apiCalls')
    assert any(c['path']=='/ai/editor/proposals' and c['body'].get('block_index')==0 and c['body'].get('block_field')=='heading' for c in calls),calls
    print('PASS browser selects schema-validated block text generation')
    page.locator('[data-action="reset-entry"]').click()
    page.locator('[data-ai-editor-form] select[name=field]').select_option('lead')
    page.locator('[data-ai-editor-form] textarea[name=instructions]').fill('Generate draft')
    page.locator('[data-ai-editor-form] button[type=submit]').click()
    page.locator('[data-ai-editor-accept]').wait_for()
    page.locator('[data-ai-editor-accept]').click()
    page.get_by_text('AI-текст перенесён в черновик.',exact=False).wait_for()
    data=page.evaluate('JSON.parse(document.querySelector("[data-form=entry] [name=fields]").value)')
    assert data['lead']=='Draft generated content',data
    assert not any('/apply' in c['path'] and c['body'].get('confirm_published') is False for c in page.evaluate('apiCalls'))
    assert errors==[],errors
    print('PASS browser new draft local approval and preservation of unsaved state; no JS errors')
    browser.close()
