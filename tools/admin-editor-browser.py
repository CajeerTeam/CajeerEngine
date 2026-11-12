"""Browser-level regression of published Admin JS with controlled fetch responses.
Requires Python playwright and Chromium. Does not simulate a successful production HTTP/DB install.
"""
import os
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
fields=[{'handle':'body','label':'Содержимое','type':'richtext'},{'handle':'blocks','label':'Блоки','type':'blocks'},{'handle':'path','label':'Путь','type':'text'},{'handle':'seo_title','label':'SEO','type':'text'}]
types=[{'handle':'pages','name':'Страницы','fields':fields,'blocks':[{'handle':'text','name':'Текстовый блок','fields':[{'handle':'title','label':'Заголовок блока','type':'text'},{'handle':'content','label':'Текст','type':'richtext'}]}]}]
errors=[]
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_BINARY','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
 page=browser.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
 page.set_content('<div id="app"></div>')
 page.evaluate("""({types})=>{const store=new Map();for(const name of ['localStorage','sessionStorage'])Object.defineProperty(window,name,{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});location.hash='#/content';window.records=[];window.requests=[];window.fetch=async(url,opt={})=>{const path=url.split('/api/v1')[1].split('?')[0],method=opt.method||'GET',body=opt.body?JSON.parse(opt.body):null;requests.push([method,path,body]);let data={};if(path==='/auth/me')data={id:'admin',csrf_token:'token'};else if(path==='/content-types')data=types;else if(path==='/content/pages'&&method==='GET')data=records;else if(path==='/content/pages'&&method==='POST'){data={...body,id:'page-id',revision:1};records.push(data)}else if(path==='/content/pages/page-id'&&method==='PUT'){data={...records[0],...body,revision:2};records[0]=data}else if(path==='/cms/preview-token')data={preview_url:'/preview/test-token'};else if(path==='/cms/theme')data={active_theme:'default',locale:'ru',enabled_locales:['ru','en']};else if(['/cms/navigation','/cms/redirects','/cms/routes'].includes(path))data=[];return new Response(JSON.stringify({data}),{status:200})}}""",{'types':types})
 page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
 page.locator('.editor-fields').wait_for()
 form=page.locator('[data-form="entry"]');form.locator('[name=title]').fill('New page');form.locator('[name=slug]').fill('new-page');form.locator('.editor-fields textarea').first.fill('Hello world');
 form.get_by_role('button',name='Добавить блок').click();form.locator('.editor-block input').first.fill('Block title');form.locator('.editor-block textarea').fill('Block body');
 form.get_by_role('button',name='Сохранить',exact=True).click();page.get_by_text('Запись сохранена.',exact=True).wait_for()
 entries=page.evaluate('records');requests=page.evaluate('requests')
 assert entries[0]['data']['blocks'][0]['data']['title']=='Block title',entries
 assert entries[0]['data']['body']=='Hello world',entries
 page.locator('[data-form="entry"] [name=title]').fill('Edited page');page.locator('[data-form="entry"]').get_by_role('button',name='Сохранить',exact=True).click();page.wait_for_timeout(350)
 entries=page.evaluate('records');requests=page.evaluate('requests')
 assert entries[0]['title']=='Edited page' and any(x[0]=='PUT' and x[2]['expected_revision']==1 for x in requests),requests
 page.locator('[data-action="preview-entry"]').click();page.get_by_role('link',name='Открыть предпросмотр').wait_for();assert page.get_by_role('link',name='Открыть предпросмотр').get_attribute('href')=='/preview/test-token'
 # The production list requests 50 rows and retains the total from the API.
 page.evaluate("""()=>{for(let i=0;i<59;i++)records.push({id:'seed-'+i,title:'Seed '+i,slug:'seed-'+i,locale:'ru',status:'draft',data:{}});const previous=window.fetch;window.pageRequests=[];window.fetch=async(url,opt={})=>{const parsed=new URL(url,'https://test.example');if(parsed.pathname==='/api/v1/content/pages'&&(opt.method||'GET')==='GET'){const q=parsed.searchParams.get('q')||'',limit=Number(parsed.searchParams.get('limit')||50),offset=Number(parsed.searchParams.get('offset')||0);pageRequests.push({q,limit,offset});const matching=records.filter(r=>r.title.toLowerCase().includes(q.toLowerCase()));return new Response(JSON.stringify({data:matching.slice(offset,offset+limit),meta:{total:matching.length,limit,offset}}),{status:200})}return previous(url,opt)}}""")
 page.locator('[data-action="filter-entries"]').click();page.locator('[data-entry-page="next"]').wait_for();page.wait_for_function("document.querySelector('[data-entry-page=next]')&&!document.querySelector('[data-entry-page=next]').disabled")
 page.locator('[data-entry-page="next"]').click();page.wait_for_function("pageRequests.some(r=>r.limit===50&&r.offset===50)")
 page.wait_for_function("document.querySelector('[data-entry-page=next]').disabled")
 page.locator('[data-entry-page="prev"]').click();page.wait_for_function("!document.querySelector('[data-entry-page=next]').disabled")
 page.locator('[data-parent-q]').fill('Seed 58');page.locator('[data-parent-search]').click();page.wait_for_function("document.querySelector('[name=parent_id] option[value=seed-58]')!==null")
 assert page.evaluate("pageRequests.some(r=>r.q==='Seed 58'&&r.limit===50)")
 print('PASS Chromium bounded content pages, next/previous and parent search')
 page.locator('[data-route="cms"]').click();page.locator('select[name=active_theme]').wait_for();page.locator('select[name=active_theme]').select_option('editorial');page.locator('[data-form="theme"] button[type=submit]').click();page.wait_for_timeout(350)
 requests=page.evaluate('requests')
 assert any(x[0]=='PATCH' and x[1]=='/cms/theme' and x[2]['active_theme']=='editorial' for x in requests),requests
 assert not errors,errors
 print('PASS Chromium create structured block, update expected_revision, preview link, theme PATCH; no JS errors')
 browser.close()

