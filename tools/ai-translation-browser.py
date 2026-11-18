#!/usr/bin/env python3
"""Chromium regression for real production static AI Translation Studio UI with mocked API."""
import os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page()
    errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content('<div id="app"></div>')
    page.evaluate(r'''() => {
      const cache=new Map();for(const key of ['localStorage','sessionStorage'])Object.defineProperty(window,key,{value:{getItem:k=>cache.get(k)||null,setItem:(k,v)=>cache.set(k,v),removeItem:k=>cache.delete(k)}});
      location.hash='#/translations'; window.confirm=()=>true;
      window.calls=[];
      const id='11111111-1111-4111-8111-111111111111';
      window.sourceEntry={id,title:'Installation <img src=x onerror=alert(1)>',locale:'en',slug:'install',status:'published',revision:7,data:{body:'<p>Instructions</p>'}};
      window.translationEntry=null;
      window.fetch=async(url,opt={})=>{
        const loc=new URL(String(url),'https://example.test');const path=loc.pathname.replace('/api/v1','');
        const method=opt.method||'GET', body=opt.body?JSON.parse(opt.body):{};
        const csrf=new Headers(opt.headers||{}).get('X-CSRF-Token');
        calls.push({path,method,body,csrf});
        const send=(data,status=200)=>new Response(JSON.stringify({data}),{status,headers:{'Content-Type':'application/json'}});
        if(path==='/auth/oidc/status')return send({enabled:false});
        if(path==='/auth/me')return send({user_id:'admin',csrf_token:'securecsrf',scopes:['*']});
        if(path==='/content-types')return send([{handle:'pages',name:'Pages',localized:true}]);
        if(path==='/content/pages')return send([sourceEntry,...(translationEntry?[translationEntry]:[])]);
        if(path==='/ai/translations/overview')return send({source:{id:sourceEntry.id,title:sourceEntry.title,locale:'en',revision:7},localizations:[
          {id:sourceEntry.id,title:sourceEntry.title,locale:'en',status:'published',revision:7,ai_generated:false,stale:null},
          ...(translationEntry?[{id:translationEntry.id,title:translationEntry.title,locale:'de',status:translationEntry.status,revision:translationEntry.revision,ai_generated:true,stale:false}]:[])
        ]});
        if(path==='/ai/translations/glossary' && method==='GET')return send(window.savedTerms||[]);
        if(path==='/ai/translations/glossary' && method==='POST'){
          window.savedTerms=[{id:'33333333-3333-4333-8333-333333333333',source_term:body.term,translated_term:body.translation}];return send(window.savedTerms);
        }
        if(path==='/ai/translations/proposals')return send({id:'22222222-2222-4222-8222-222222222222',source_locale:'en',target_locale:body.target_locale,
          source_revision:7,target_entry_id:body.update_existing?(translationEntry?.id||null):null,original_title:sourceEntry.title,translated_title:'Übersetzt <img src=x onerror=alert(1)>',
          translated_data:{body:'<p>Hallo <script>alert(1)</script></p>'},segment_count:3,usage:{input_tokens:85,output_tokens:42}});
        if(path==='/ai/translations/batches' && method==='POST'){
          window.batchRequest=body;return send({id:'66666666-6666-4666-8666-666666666666',type:'pages',target_locale:body.target_locale,total:1,ready:0,failed:0,items:[{id:'77777777-7777-4777-8777-777777777777',source_entry_id:id,status:'queued',proposal_id:null,error_message:null}]});
        }
        if(path==='/ai/translations/batches/66666666-6666-4666-8666-666666666666')return send({id:'66666666-6666-4666-8666-666666666666',type:'pages',target_locale:'fr',total:1,ready:1,failed:0,items:[{id:'77777777-7777-4777-8777-777777777777',source_entry_id:id,status:'ready',proposal_id:'88888888-8888-4888-8888-888888888888',error_message:null}]});
        if(path==='/ai/translations/proposals/88888888-8888-4888-8888-888888888888')return send({id:'88888888-8888-4888-8888-888888888888',source_locale:'en',target_locale:'fr',source_revision:7,target_entry_id:null,original_title:sourceEntry.title,translated_title:'Bonjour <img onerror=alert(1)>',translated_data:{body:'<p>Bonjour</p>'},segment_count:null,usage:{input_tokens:null,output_tokens:null}});
        if(path==='/ai/translations/proposals/22222222-2222-4222-8222-222222222222/apply'){
          translationEntry={id:'44444444-4444-4444-8444-444444444444',locale:'de',slug:'install-de',title:'Übersetzt',status:'draft',revision:1,data:{body:'<p>Hallo</p>'}};
          return send({created:true,entry:translationEntry});
        }
        return send({});
      };
    }''')
    page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-translations-select]').wait_for()
    assert page.locator('img').count()==0,'untrusted page title inserted as HTML'
    page.locator('select[name=locale]').select_option('de')
    page.locator('[data-translation-term] input[name=term]').fill('install')
    page.locator('[data-translation-term] input[name=translation]').fill('installieren')
    page.locator('[data-translation-term] button').click()
    page.get_by_text('Термин сохранён.').wait_for()
    page.locator('[data-translations-select] textarea[name=instructions]').fill('Formal tone')
    page.locator('[data-translations-select] button[type=submit]').click()
    page.locator('[data-translation-accept]').wait_for()
    assert page.locator('img').count()==0 and page.locator('script').count()==1,'untrusted model HTML executed'
    assert 'Übersetzt <img' in page.locator('.ai-editor-compare').inner_text()
    calls=page.evaluate('calls')
    assert any(c['path']=='/ai/translations/proposals' and c['method']=='POST' and c['body']['target_locale']=='de' and c['body']['update_existing'] is False and c['csrf']=='securecsrf' for c in calls),calls
    assert any(c['path']=='/ai/translations/glossary' and c['method']=='POST' and c['csrf']=='securecsrf' for c in calls),calls
    page.locator('[data-translation-accept]').click()
    page.get_by_text('Черновик локализации создан.').wait_for()
    assert page.evaluate('translationEntry.status')=='draft'
    calls=page.evaluate('calls')
    assert any(c['path'].endswith('/apply') and c['body']['confirm_published'] is False and c['csrf']=='securecsrf' for c in calls),calls
    page.locator('select[name=locale]').select_option('fr')
    page.locator('[data-translation-check]').first.check()
    page.locator('[data-translation-batch]').click()
    page.get_by_text('Переводы поставлены в очередь.',exact=False).wait_for()
    assert page.evaluate('batchRequest.entry_ids')==[page.evaluate('sourceEntry.id')]
    page.locator('[data-translation-refresh]').click()
    page.locator('[data-translation-open]').wait_for()
    page.locator('[data-translation-open]').click()
    page.locator('[data-translation-accept]').wait_for()
    assert page.locator('img').count()==0,'Queued proposal contains unescaped HTML'
    assert 'Bonjour <img' in page.locator('.ai-editor-compare').inner_text()
    calls=page.evaluate('calls')
    assert any(c['path']=='/ai/translations/batches' and c['csrf']=='securecsrf' and c['body']['target_locale']=='fr' for c in calls),calls
    assert errors==[],errors
    print('PASS Chromium translation: glossary, safe preview, CSRF, draft, batch queue and reviewing proposal')
    browser.close()
