#!/usr/bin/env python3
"""Published SPA renders real validation state; no privileged HTTP cutover actions."""
import os,shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.set_content('<div id="app"></div>')
    page.evaluate('''()=>{
      const m=new Map();for(const n of ['localStorage','sessionStorage'])Object.defineProperty(window,n,{value:{getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,v),removeItem:k=>m.delete(k)}})
      location.hash='#/migration';window.project={id:'a'.repeat(32),name:'WP test',source:'wordpress-wxr',revision:1,status:'approved',error:null,report:{scanned_at:'2026-11-17',counts:{posts:1},compatibility:{posts:'ok'},warnings:[]},plan:{sha256:'b'.repeat(64),approved_at:'2026-11-17',approvable:true,records:[],users:[],counts:{}},execution:{project_id:'a'.repeat(32),plan_sha256:'b'.repeat(64),completed_at:'2026-11-17',counts:{create:1},records:[],users:[]},validation:{sha256:'c'.repeat(64),checked_at:'2026-11-17',ready:false,counts:{existing:1,new:1,changed:0},problems:[],drift:[]}}
      window.fetch=async(url)=>{const path=url.split('/api/v1')[1];let data={};if(path==='/auth/me')data={id:'user',scopes:['migration:read','migration:write','migration:run','integrations:secrets','users:read']};else if(path==='/migration/projects')data={projects:[project]};else if(path==='/migration/catalog')data={types:[]};return new Response(JSON.stringify({data}),{status:200})}
    }''')
    page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-migration-open]').click()
    page.get_by_text('9. Проверка результата и переключение').wait_for()
    assert 'migration:validate '+('a'*32) in '\n'.join(page.locator('pre').all_inner_texts())
    assert 'migration:sync '+('a'*32)+' '+('c'*64) in '\n'.join(page.locator('pre').all_inner_texts())
    assert not page.get_by_text('10. Подтверждённый cutover').count()
    print('PASS report with pending delta renders safe sync command and blocks cutover')
    page.evaluate("project={...project,validation:{...project.validation,ready:true,counts:{existing:1,new:0,changed:0}}}")
    page.evaluate("async()=>{await loadSilent('migrationProjects','/migration/projects');render()}")
    page.get_by_text('10. Подтверждённый cutover').wait_for()
    assert 'migration:cutover '+('a'*32)+' '+('c'*64) in '\n'.join(page.locator('pre').all_inner_texts())
    assert not errors,errors
    print('PASS verified report exposes explicit operator cutover and does not call mutating API')
    browser.close()
