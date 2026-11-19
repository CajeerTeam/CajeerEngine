#!/usr/bin/env python3
"""Real Chromium SPA flow: prepare -> review -> approve, token/CSRF and safe HTML."""
import os,shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page()
    errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
    page.on('dialog',lambda dialog:dialog.accept())
    page.set_content('<div id="app"></div>')
    page.evaluate('''() => {
      const store=new Map();Object.defineProperty(window,'localStorage',{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});
      location.hash='#/migration';window.calls=[];window.id='a'.repeat(32);window.pending=[];window.fixHistory=[];window.applied=false;window.rolledBack=false;
      window.project={id,name:'WordPress',source:'wordpress-wxr',revision:4,status:'completed',report:null,execution:null,validation:null};
      window.report={project_id:id,name:'WordPress',source_type:'wordpress-wxr',status:'completed',import_complete:true,
        validation_available:true,validation_ready:false,validation_checked_at:'2026-11-18T20:00Z',cutover_confirmed:false,
        checked_at:'2026-11-19T08:00Z',finding_total:1,counts:{},checks:{route_index_ok:true},finding_counts:{legacy_path_redirect_missing_or_changed:1},
        findings:[{severity:'high',code:'legacy_path_redirect_missing_or_changed',source:'/old',action:'Restore'}],warnings:[],next_step:'php bin/cajeer migration:validate '+id};
      window.fetch=async(url,opts={})=>{
        const path=String(url).split('/api/v1')[1].split('?')[0],method=opts.method||'GET';
        const headers=opts.headers;calls.push({path,method,csrf:headers?.get('X-CSRF-Token')||''});let data={};
        if(path==='/auth/oidc/status')data={enabled:false};
        else if(path==='/auth/me')data={user_id:'staff',scopes:['migration:read','migration:write','migration:run','integrations:secrets'],csrf_token:'csrf-verified'};
        else if(path==='/migration/projects')data={projects:[project]};
        else if(path==='/migration/catalog')data={types:[]};
        else if(path.endsWith('/report'))data=report;
        else if(path.endsWith('/remediation')){
          if(method==='POST'){
            const body=JSON.parse(opts.body||'{}');
            if(body.kind==='restore_redirect')pending.push({id:'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',kind:body.kind,state:'pending',expires_at:1893456000,
              preview:{kind:body.kind,description:'<img src=x onerror=alert(1)>',source_path:body.source_path,target_path:'/target'}});
            data={id:'proposal'};
          }else data={options:{candidates:applied?[]:[{kind:'restore_redirect',description:'Restore missing 301',source_path:'/old',target_path:'/target'}],warnings:[],manual_only:[]},pending,history:fixHistory};
        }else if(path.endsWith('/apply')) {applied=true;fixHistory.push({...pending[0],state:'applied'});pending=[];report.finding_total=0;data={state:'applied'}}
        else if(path.endsWith('/rollback')) {rolledBack=true;fixHistory[0].state='rolled_back';report.finding_total=1;data={state:'rolled_back'}};
        return new Response(JSON.stringify({data}),{status:200});
      }
    }''')
    page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-migration-open]').click(timeout=8000)
    page.locator('[data-migration-copilot-check]').click()
    page.locator('[data-migration-remediation-prepare]').first.wait_for()
    page.locator('[data-migration-remediation-prepare]').first.click()
    page.locator('[data-migration-remediation-apply]').first.wait_for()
    assert page.get_by_text('<img src=x onerror=alert(1)>').count()>0, 'Preview not rendered safely'
    assert page.locator('img').count()==0,'Untrusted proposal HTML executed'
    page.locator('[data-migration-remediation-apply]').first.click()
    page.wait_for_function('window.applied === true')
    calls=page.evaluate('calls')
    expected=[c for c in calls if c['path'].endswith('/remediation') and c['method']=='POST']
    accepted=[c for c in calls if c['path'].endswith('/apply') and c['method']=='POST']
    assert len(expected)==1 and len(accepted)==1, calls
    assert expected[0]['csrf']=='csrf-verified' and accepted[0]['csrf']=='csrf-verified',calls
    page.locator('[data-migration-remediation-rollback]').first.click()
    page.wait_for_function('window.rolledBack === true')
    assert any(c['path'].endswith('/rollback') and c['method']=='POST' and c['csrf']=='csrf-verified' for c in page.evaluate('calls'))
    assert not errors,errors
    print('PASS SPA operator preview, approval and rollback, XSS escaping, CSRF and report refresh')
    browser.close()
