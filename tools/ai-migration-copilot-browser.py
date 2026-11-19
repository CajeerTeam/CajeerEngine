#!/usr/bin/env python3
"""Production SPA: migration report, escaped findings and CSRF-protected AI explanation."""
import os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parents[1]
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page()
    errors=[]
    page.on('pageerror',lambda err: errors.append(str(err)))
    page.set_content('<div id="app"></div>')
    page.evaluate('''() => {
      const store=new Map();
      Object.defineProperty(window,'localStorage',{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}})
      location.hash='#/migration';
      window.calls=[];window.id='a'.repeat(32);
      window.project={id,name:'WP demo',source:'wordpress-wxr',revision:1,status:'completed',report:null,execution:null,validation:null};
      window.result={project_id:id,name:'WP demo',source_type:'wordpress-wxr',status:'completed',import_complete:true,
        validation_available:true,validation_ready:false,validation_checked_at:'2026-11-18T20:00Z',cutover_confirmed:false,
        checked_at:'2026-11-19T08:00Z',finding_total:1,counts:{media_invalid:1},checks:{media_checked:1},
        finding_counts:{media_missing_or_corrupt:1},warnings:['Проверить файлы'],
        findings:[{severity:'critical',code:'media_missing_or_corrupt',source:'<img src=x onerror=alert(1)>',action:'Восстановить файл из копии'}],
        next_step:'php bin/cajeer migration:validate '+id};
      window.fetch=async(url,opts={})=>{
        const path=String(url).split('/api/v1')[1];calls.push({path,method:opts.method||'GET',headers:opts.headers});
        let data={};
        if(path==='/auth/me')data={id:'user',scopes:['migration:read','migration:run','migration:write','integrations:secrets']};
        else if(path==='/migration/projects')data={projects:[project]};
        else if(path==='/migration/catalog')data={types:[]};
        else if(path.endsWith('/report'))data=result;
        else if(path.endsWith('/explain'))data={report:result,explanation:'Проверьте резервную копию. <script>alert(1)</script>',usage:{input_tokens:50,output_tokens:30}};
        return new Response(JSON.stringify({data}),{status:200});
      }
    }''')
    page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-migration-open]').click()
    page.locator('[data-migration-copilot-check]').click()
    page.get_by_role('cell',name='media_missing_or_corrupt').wait_for()
    assert not page.locator('.card img').count(), 'Untrusted source rendered as HTML'
    assert page.get_by_text('<img src=x onerror=alert(1)>').count()>0
    assert page.get_by_text('Восстановить файл из копии').count()>0
    page.locator('[data-migration-copilot-explain]').click()
    page.get_by_text('Проверьте резервную копию. <script>alert(1)</script>').wait_for()
    assert not page.locator('script').count()>1, 'Untrusted AI output executed as HTML'
    calls=page.evaluate('calls')
    assert any(c['path'].endswith('/explain') and c['method']=='POST' for c in calls)
    assert not any('/migration/projects/' in c['path'] and c['method'] not in ('GET','POST') for c in calls)
    assert not errors, errors
    print('AI Migration Copilot production SPA: report, escaping and AI explanation passed')
    browser.close()
