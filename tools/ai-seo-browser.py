#!/usr/bin/env python3
"""Production static SPA SEO workflow in Chromium: real DOM, mocked API transport."""
import os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
with sync_playwright() as driver:
    browser=driver.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page()
    errors=[]
    page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content('<div id="app"></div>')
    page.evaluate(r'''() => {
      const cache=new Map();for(const key of ['localStorage','sessionStorage'])Object.defineProperty(window,key,{value:{getItem:k=>cache.get(k)||null,setItem:(k,v)=>cache.set(k,v),removeItem:k=>cache.delete(k)}});
      location.hash='#/seo';window.confirm=()=>true;window.prompt=()=>null;
      window.apiCalls=[];
      window.pageEntry={id:'11111111-1111-4111-8111-111111111111',title:'Example article',slug:'example',status:'published',locale:'en',revision:7,data:{body:'<p>Saved content</p>',seo_title:'',meta_description:''}};
      window.fetch=async(url,opt={})=>{
        const loc=new URL(String(url),'https://example.test'),path=loc.pathname.replace('/api/v1',''),method=opt.method||'GET',body=opt.body?JSON.parse(opt.body):{};
        const csrf=new Headers(opt.headers||{}).get('X-CSRF-Token');apiCalls.push({path,method,body,csrf});
        const send=(data,status=200)=>new Response(JSON.stringify({data}),{status,headers:{'Content-Type':'application/json'}});
        if(path==='/auth/oidc/status')return send({enabled:false});
        if(path==='/auth/me')return send({user_id:'admin',csrf_token:'csrf123',scopes:['*']});
        if(path==='/content-types')return send([{handle:'pages',name:'Pages'}]);
        if(path==='/content/pages')return send([pageEntry]);
        if(path==='/ai/seo/audit')return send({type:'pages',entry_id:pageEntry.id,revision:pageEntry.revision,status:'published',title:pageEntry.title,locale:'en',path:'/example',score:75,seo:{title:pageEntry.data.seo_title,description:pageEntry.data.meta_description,canonical_url:'',noindex:false},stats:{words:100,headings:{h1:1,h2:0,h3:0,h4:0,h5:0,h6:0},images_without_alt:0,internal_links_checked:0},issues:[{code:'missing_seo_title',severity:'high',message:'Missing <img src=x onerror=alert(1)> title',action:'Generate title'}],notes:'SEO heuristics only'});
        if(path==='/ai/seo/batch')return send({type:'pages',reports:[{type:'pages',entry_id:pageEntry.id,title:pageEntry.title,score:75,issues:[{code:'x'}]}],meta:{available:1}});
        if(path==='/ai/seo/proposals')return send({id:'22222222-2222-4222-8222-222222222222',field:body.field,original:pageEntry.data.seo_title,proposed:'Trusted <script>alert(1)</script>',expected_revision:pageEntry.revision,usage:{input_tokens:50,output_tokens:10}});
        if(path.startsWith('/ai/editor/proposals/')&&path.endsWith('/apply')){pageEntry.data.seo_title='Trusted title';pageEntry.revision++;return send({type:'pages',entry:pageEntry});}
        return send({});
      };
    }''')
    page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-seo-audit]').wait_for()
    page.locator('select[name="entry"]').select_option('11111111-1111-4111-8111-111111111111')
    page.locator('[data-seo-audit]').click()
    page.locator('[data-seo-propose]').wait_for()
    assert page.locator('img').count()==0
    page.locator('[data-seo-propose] select[name="field"]').select_option('seo_title')
    page.locator('[data-seo-propose] textarea[name="instructions"]').fill('Relevant installation title')
    page.locator('[data-seo-propose] button[type=submit]').click()
    page.locator('[data-seo-accept]').wait_for()
    assert page.locator('.ai-editor-text').nth(1).inner_text()=='Trusted <script>alert(1)</script>'
    assert page.locator('script').count()==1  # only the injected application script element
    calls=page.evaluate('apiCalls')
    assert any(c['path']=='/ai/seo/proposals' and c['csrf']=='csrf123' and c['body']['field']=='seo_title' and c['body']['entry_id']==page.evaluate('pageEntry.id') for c in calls),calls
    page.locator('[data-seo-accept]').click()
    page.get_by_text('SEO-правка применена как новая ревизия.').wait_for()
    calls=page.evaluate('apiCalls')
    assert any('/apply' in c['path'] and c['body'].get('confirm_published') is True for c in calls),calls
    assert page.evaluate('pageEntry.revision')==8
    page.locator('[data-seo-batch]').click()
    page.get_by_text('Проверено опубликованных страниц: 1 из 1').wait_for()
    assert errors==[],errors
    print('PASS Chromium: audit, safe rendering, SEO proposal, CSRF, published approval, revision and batch')
    browser.close()
