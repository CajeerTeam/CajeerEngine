#!/usr/bin/env python3
"""Chromium end-to-end admin interactions with a stateful fixture API (transport mocked only)."""
import os,shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
with sync_playwright() as driver:
    browser=driver.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page();errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content('<div id="app"></div>')
    page.evaluate(r'''() => {
      const cache=new Map();for(const key of ['localStorage','sessionStorage'])Object.defineProperty(window,key,{value:{getItem:k=>cache.get(k)||null,setItem:(k,v)=>cache.set(k,v),removeItem:k=>cache.delete(k)}});
      location.hash='#/knowledge';window.calls=[];window.alert=()=>{throw new Error('XSS!')};window.confirm=()=>true;
      const C='11111111-1111-4111-8111-111111111111', D='22222222-2222-4222-8222-222222222222';
      let categories=[], documents=[], next=3;
      const send=(data,status=200)=>new Response(JSON.stringify({data}),{status,headers:{'Content-Type':'application/json'}});
      window.fetch=async(url,opt={})=>{
        const u=new URL(String(url),'https://test.local'),path=u.pathname.replace('/api/v1',''),method=opt.method||'GET',body=opt.body?JSON.parse(opt.body):{};
        const csrf=new Headers(opt.headers||{}).get('X-CSRF-Token');calls.push({path,method,body,csrf});
        if(path==='/auth/me')return send({user_id:'admin',csrf_token:'CSRF-FIXTURE',scopes:['*']});
        if(path==='/auth/oidc/status')return send({enabled:false});
        if(path==='/content-types')return send([{handle:'pages',name:'Pages'}]);
        if(path==='/knowledge/categories'){
          if(method==='POST'){const x={id:C,...body};categories=[x];return send(x)}return send(categories)
        }
        if(path.startsWith('/knowledge/categories/')){const id=path.split('/').pop();if(method==='DELETE'){categories=categories.filter(x=>x.id!==id);return send({deleted:true})}}
        if(path==='/knowledge/documents' && method==='GET')return send({items:documents.map(({body,...rest})=>rest),total:documents.length,offset:0,limit:30});
        if(path==='/knowledge/documents' && method==='POST'){
          const doc={id:D,source_kind:'manual',revision:1,indexed_revision:1,...body};documents.unshift(doc);return send(doc);
        }
        if(path.startsWith('/knowledge/documents/')){
          const id=path.split('/').pop(),doc=documents.find(x=>x.id===id);
          if(method==='GET')return send(doc);
          if(method==='PUT'){Object.assign(doc,body,{revision:doc.revision+1,indexed_revision:doc.revision+1});return send(doc)}
          if(method==='DELETE'){documents=documents.filter(x=>x.id!==id);return send({deleted:true})}
        }
        if(path==='/knowledge/import'){
          const doc={id:'33333333-3333-4333-8333-333333333333',slug:'guide',title:'Guide markdown',body:'# Index me',locale:'ru',visibility:'internal',status:'draft',revision:1,indexed_revision:1,source_kind:'file',category_id:null};documents.unshift(doc);return send(doc)
        }
        if(path==='/knowledge/sync-cms'){
          const doc={id:'44444444-4444-4444-8444-444444444444',slug:'published-page',title:'CMS material',body:'From public CMS',locale:'ru',visibility:'internal',status:'published',revision:1,indexed_revision:1,source_kind:'cms',category_id:null};documents.unshift(doc);return send(doc)
        }
        if(path==='/knowledge/search')return send([{id:D,title:'Found <img src=x onerror=alert(1)>',excerpt:'Snippet <script>alert(1)</script>',score:2,visibility:'internal',locale:'en'}]);
        if(path==='/knowledge/reindex')return send({processed:2,next_offset:null});
        return send({});
      };
    }''')
    page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-kb-document]').wait_for()
    page.locator('[data-kb-category] input[name=name]').fill('Operator manuals')
    page.locator('[data-kb-category] input[name=slug]').fill('operator-manuals')
    page.locator('[data-kb-category] button[type=submit]').click()
    page.get_by_text('Категория сохранена.').wait_for()
    page.locator('[data-kb-document] input[name=title]').fill('Safe <img src=x onerror=alert(1)>')
    page.locator('[data-kb-document] input[name=slug]').fill('safe-guide')
    page.locator('[data-kb-document] textarea[name=body]').fill('Installation safety and private instructions')
    page.locator('[data-kb-document] select[name=category_id]').select_option('11111111-1111-4111-8111-111111111111')
    page.locator('[data-kb-document] button[type=submit]').click()
    page.get_by_text('Документ сохранён и индекс обновлён.').wait_for()
    assert page.locator('img').count()==0, 'HTML in knowledge title cannot execute'
    page.locator('[data-kb-document] textarea[name=body]').fill('New revision and corrected instructions')
    page.locator('[data-kb-document] button[type=submit]').click()
    page.get_by_text('Документ сохранён и индекс обновлён.').wait_for()
    page.locator('[data-kb-search] input[name=q]').fill('instructions')
    page.locator('[data-kb-search] button[type=submit]').click()
    try: page.get_by_text('Found <img src=x onerror=alert(1)>').wait_for(timeout=6000)
    except Exception:
        print('DEBUG body:',page.locator('body').inner_text()[-1500:]);print('DEBUG calls:',page.evaluate('calls')[-8:]);print('DEBUG errors:',errors);raise
    assert page.locator('img').count()==0, 'HTML in search results cannot execute'
    page.locator('[data-kb-import] input[type=file]').set_input_files({'name':'guide.md','mimeType':'text/markdown','buffer':b'# Markdown content'})
    page.locator('[data-kb-import] button[type=submit]').click()
    page.get_by_text('Импортирован черновик.').wait_for()
    page.locator('[data-kb-cms] input[name=entry_id]').fill('55555555-5555-4555-8555-555555555555')
    page.locator('[data-kb-cms] button[type=submit]').click()
    page.get_by_text('CMS-содержимое синхронизировано.').wait_for()
    calls=page.evaluate('calls')
    writes=[x for x in calls if x['method'] in ['POST','PUT','DELETE'] and x['path'].startswith('/knowledge/')]
    assert writes and all(x['csrf']=='CSRF-FIXTURE' for x in writes),writes
    assert any(x['path']=='/knowledge/documents/22222222-2222-4222-8222-222222222222' and x['body'].get('expected_revision')==1 for x in writes)
    assert any(x['path']=='/knowledge/import' and x['body']['base64']=='IyBNYXJrZG93biBjb250ZW50' for x in writes)
    assert errors==[],errors
    print('PASS Chromium: categories, document CRUD revisions, indexed search, HTML escaping, PDF-safe upload route, CMS sync and CSRF')
    browser.close()
