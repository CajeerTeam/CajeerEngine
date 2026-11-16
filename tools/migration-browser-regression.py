#!/usr/bin/env python3
"""Published production SPA in Chromium, controlled API and actual multipart form handling."""
import os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.set_content('<div id="app"></div>')
    page.evaluate('''()=>{const store=new Map();for(const name of ['localStorage','sessionStorage'])Object.defineProperty(window,name,{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});location.hash='#/migration';window.requests=[];window.projects=[];window.readOnly=false;window.fetch=async(url,opt={})=>{const path=url.split('/api/v1')[1],method=opt.method||'GET',body=opt.body instanceof FormData?{revision:opt.body.get('revision'),file:opt.body.get('file')?.name}:opt.body?JSON.parse(opt.body):null;requests.push({path,method,body});let data={};if(path==='/auth/me')data={id:'admin',scopes:readOnly?['migration:read']:['migration:read','migration:write','migration:run','integrations:secrets'],csrf_token:'test'};else if(path==='/migration/projects'&&method==='GET')data={projects};else if(path==='/migration/projects'&&method==='POST'){data={id:'a'.repeat(32),name:body.name,source:body.source,config:body.config,revision:1,status:'draft',report:null,error:null};projects=[data]}else if(path.endsWith('/wxr')){projects[0]={...projects[0],revision:2,status:'discovered',report:{scanned_at:'2026-11-16T00:00:00Z',counts:{posts:3,media:null},types:{post:3},statuses:{publish:3},fields:['field_one'],details:{sha256:'abc'},compatibility:{posts:'mapping_required',media:'unavailable'},warnings:['Export only'],target_modified:false}};data=projects[0]}else if(path.endsWith('/scan')){projects[0]={...projects[0],revision:3,status:'failed',error:'Source unavailable'};data=projects[0]}else if(method==='DELETE'){projects=[];data={deleted:true}};return new Response(JSON.stringify({data}),{status:200})}}''')
    page.add_script_tag(content=(ROOT/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-migration-form] [name=name]').fill('WP <safe>')
    assert page.locator('[data-migration-db]').is_hidden()
    page.locator('[data-migration-form] [name=source]').select_option('dle-db')
    assert page.locator('[data-migration-db]').is_visible()
    page.locator('[data-migration-form] [name=source]').select_option('wordpress-wxr')
    page.locator('[data-migration-form] button[type=submit]').click()
    page.locator('[data-migration-upload]').wait_for()
    assert page.get_by_text('WP <safe>',exact=True).count()==2
    assert page.evaluate("requests.some(r=>r.method==='POST'&&r.body.source==='wordpress-wxr'&&Object.keys(r.body.config).length===0)")
    print('PASS project wizard, source selection and escaped project name',flush=True)
    page.locator('[data-migration-upload] input[type=file]').set_input_files({'name':'site.xml','mimeType':'application/xml','buffer':b'<rss/>'})
    page.locator('[data-migration-upload] button').click();page.get_by_text('3. Отчёт совместимости').wait_for()
    assert page.evaluate("requests.some(r=>r.path.endsWith('/wxr')&&r.body.file==='site.xml'&&r.body.revision==='1')")
    assert page.get_by_text('Недоступно',exact=True).count()==1
    print('PASS multipart WXR upload and real report rendering',flush=True)
    page.locator('[data-migration-scan]').click();page.get_by_text('Source unavailable (ниже предыдущий успешный отчёт)').wait_for()
    assert page.evaluate("requests.some(r=>r.path.endsWith('/scan')&&r.body.revision===2)")
    assert page.get_by_text('3. Отчёт совместимости').count()==1
    print('PASS rescan revision and last successful report retention',flush=True)
    page.on('dialog',lambda d:d.accept());page.locator('[data-migration-delete]').click();page.get_by_text('Нет проектов.').wait_for()
    assert page.evaluate("requests.some(r=>r.method==='DELETE'&&r.body.revision===3)")
    assert not errors,errors
    print('PASS revision guarded deletion and no JavaScript errors',flush=True)
    page.evaluate("state.identity.scopes=['migration:read'];render()")
    assert not page.locator('[data-migration-form]').count()
    print('PASS read-only operator cannot edit projects',flush=True)
    browser.close()
