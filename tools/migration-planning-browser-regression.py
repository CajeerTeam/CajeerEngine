#!/usr/bin/env python3
"""Production SPA with controlled API: mapping form, dry-run, confirmation and read-only gating."""
import os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page(); errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.set_content('<div id="app"></div>')
    page.evaluate('''()=>{const store=new Map();for(const n of ['localStorage','sessionStorage'])Object.defineProperty(window,n,{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});location.hash='#/migration';window.requests=[];window.project={id:'a'.repeat(32),name:'WP Test',source:'wordpress-wxr',config:{},revision:2,status:'discovered',error:null,report:{scanned_at:'2026-11-16',counts:{posts:1},types:{post:1},statuses:{publish:1},fields:['rating'],details:{},compatibility:{posts:'mapping_required'},warnings:[]}};window.fetch=async(url,opt={})=>{const path=url.split('/api/v1')[1],method=opt.method||'GET',body=opt.body?JSON.parse(opt.body):null;requests.push({path,method,body});let data={};if(path==='/auth/me')data={id:'admin',scopes:['migration:read','migration:write','migration:run','integrations:secrets','users:read'],csrf_token:'test'};else if(path==='/migration/projects')data={projects:[project]};else if(path==='/migration/catalog')data={types:[{handle:'articles',fields:[{handle:'body',type:'richtext',required:true},{handle:'rating',type:'number',required:false}]}]};else if(path.endsWith('/mapping')){project={...project,mapping:body.mapping,revision:3,status:'mapped'};data=project}else if(path.endsWith('/plan')){project={...project,revision:4,status:'planned',plan:{sha256:'b'.repeat(64),approvable:true,approved_at:null,counts:{create:1},user_counts:{skip:1},warnings:[],records:[{source_type:'post',source_id:'1',target_type:'articles',slug:'hello',action:'create'}],users:[{source_id:'writer',action:'skip',target_id:null,reason:'Excluded'}]}};data=project}else if(path.endsWith('/approve')){project={...project,revision:5,status:'approved',plan:{...project.plan,approved_at:'2026-11-16'}};data=project}return new Response(JSON.stringify({data}),{status:200})}}''')
    page.add_script_tag(content=(ROOT/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-migration-open]').click(); form=page.locator('[data-migration-mapping]');form.wait_for()
    form.locator('[data-plan-target]').select_option('articles')
    form.locator('[data-plan-field="data:rating"] [data-plan-from]').select_option('meta:rating');form.locator('[data-plan-field="data:rating"] [data-plan-transform]').select_option('number')
    form.locator('[name=users]').fill('{"writer":"create"}');form.locator('[name=user_roles]').fill('{"writer":"editor"}');form.locator('[data-plan-status=publish]').select_option('published');form.locator('button[type=submit]').click();page.locator('[data-migration-plan]').wait_for()
    assert page.evaluate("requests.some(r=>r.path.endsWith('/mapping')&&r.body.revision===2&&r.body.mapping.types.post.fields['data:rating'].transform==='number'&&r.body.mapping.users.writer==='create'&&r.body.mapping.user_roles.writer==='editor')")
    print('PASS mapping editor sends actual selected fields, status, transform and user rule')
    page.locator('[data-migration-plan]').click();page.locator('[data-migration-approve]').wait_for();assert page.get_by_text('Готов к подтверждению').count()==1
    assert page.evaluate("requests.some(r=>r.path.endsWith('/plan')&&r.body.revision===3)")
    print('PASS dry run and report rendering')
    page.on('dialog',lambda d:d.accept());page.locator('[data-migration-approve]').click();page.get_by_text('Подтверждён: 2026-11-16').wait_for();assert page.evaluate("requests.some(r=>r.path.endsWith('/approve')&&r.body.revision===4&&r.body.sha256==='b'.repeat(64))")
    print('PASS revision and hash guarded approval')
    assert page.get_by_text('php bin/cajeer migration:wordpress-import '+'a'*32+' '+'b'*64+' --yes',exact=True).count()==1
    print('PASS WordPress approved plan shows correct executable command')
    page.evaluate("state.identity.scopes=['migration:read'];render()");assert page.locator('[data-migration-mapping]').count()==0 and page.locator('[data-migration-plan]').count()==0 and page.locator('[data-migration-approve]').count()==0
    assert not errors,errors;print('PASS read-only access and no JavaScript errors')
    page.evaluate("state.identity.scopes=['migration:read','migration:write','migration:run','integrations:secrets','users:read'];project={...project,source:'dle-db'};")
    page.evaluate("async()=>{await loadSilent('migrationProjects','/migration/projects');render()}")
    page.get_by_text('6. Выполнение DLE').wait_for()
    assert page.get_by_text('php bin/cajeer migration:dle-import '+'a'*32+' '+'b'*64+' --yes',exact=True).count()==1
    print('PASS approved DLE plan exposes exact operator command')
    page.evaluate("project={...project,status:'completed',execution:{project_id:project.id,plan_sha256:project.plan.sha256,completed_at:'2026-11-16',counts:{create:1,update:0,skip:0},records:[{source_type:'post',source_id:'42',target_type:'articles',target_id:'12345678-1234-1234-1234-123456789abc',action:'create'}],users:[{source_id:'writer',target_id:'23456789-1234-1234-1234-123456789abc',action:'create',role:'editor',activation:'pending'}],warnings:[]}}")
    page.locator('[data-migration-refresh]').click();page.get_by_text('6. Перенос DLE завершён').wait_for()
    assert page.get_by_text('post:42',exact=True).count()==1 and page.locator('[data-migration-result-export]').count()==1
    assert page.locator('[data-migration-edit]').count()==0 and page.locator('[data-migration-delete]').count()==0 and page.locator('[data-migration-mapping]').count()==0
    assert page.get_by_text('23456789-1234-1234-1234-123456789abc',exact=True).count()==1 and page.get_by_text('pending',exact=True).count()==1
    assert not errors,errors;print('PASS completed receipt refreshed, identity status and mutation controls removed');browser.close()
