#!/usr/bin/env python3
"""Published Admin SPA in real Chromium, controlled API responses; no live credentials."""
import os, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
source={'id':'a'*32,'provider':'ga4','name':'Site <safe>','resource':'123','goal':None,'enabled':True,'revision':1,'last_success':'2026-01-20T00:00:00Z','last_attempt':'2026-01-20T00:00:00Z','last_error':None,'syncing':False}
report={'provider':'ga4','resource':'123','from':'2026-01-11','to':'2026-01-15','metrics':['activeUsers'],'labels':['Users'],'timezone':'UTC','sections':{'overview':{'rows':[{'label':'Total','values':[20]}],'truncated':False,'quality':{}}},'fetched_at':'2026-01-20T00:00:00Z'}
comparison={'from':'2026-01-06','to':'2026-01-10','status':'missing','fetched_at':None,'sampled':False,'metrics':[]}
history=[{'date_from':'2026-01-11','date_to':'2026-01-15','status':'failed','attempts':2,'started_at':'2026-01-20T00:00:00Z','finished_at':'2026-01-20T00:00:01Z','error':'Analytics provider HTTP 503'}]
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.set_content('<div id="app"></div>')
    page.evaluate('''({source,report,comparison,history})=>{const store=new Map();for(const name of ['localStorage','sessionStorage'])Object.defineProperty(window,name,{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});location.hash='#/analytics-hub';window.requests=[];window.completed=false;window.fetch=async(url,opt={})=>{const path=url.split('/api/v1')[1],method=opt.method||'GET',body=opt.body?JSON.parse(opt.body):null;requests.push({method,path,body});let data={};if(path==='/auth/me')data={id:'admin',scopes:['analytics:read','analytics:run'],csrf_token:'test'};else if(path==='/analytics/reports')data={enabled:true,items:[source]};else if(path.includes('/data'))data={connection:source,report,periods:[],sync_history:history,comparison:completed?{...comparison,status:'ready',sampled:true,metrics:[{metric:'activeUsers',label:'Users',current:20,previous:10,delta:10,percent:100}]}:comparison};else if(path.includes('/sync')){completed=true;data={job_id:'test',from:body.from,to:body.to}};return new Response(JSON.stringify({data}),{status:200})}}''',dict(source=source,report=report,comparison=comparison,history=history))
    page.add_script_tag(content=(ROOT/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-hub-open]').click();page.get_by_text('Предыдущий период ещё не синхронизирован.').wait_for()
    assert page.get_by_text('Site <safe>',exact=True).count()==1
    assert page.get_by_text('Analytics provider HTTP 503',exact=True).count()==1
    assert not page.locator('[data-hub-create]').count()
    print('PASS overview, escaped source, sync failure and read/run permissions',flush=True)
    page.locator('[data-hub-compare-sync]').click();page.get_by_text('Сравнение использует семплированные данные.').wait_for()
    assert page.evaluate("requests.some(r=>r.method==='POST'&&r.body.from==='2026-01-06'&&r.body.to==='2026-01-10')")
    assert page.get_by_text('100%',exact=True).count()==1
    assert page.locator('[data-hub-period] [name=from]').input_value()=='2026-01-11'
    print('PASS exact previous-period sync, retained selection and delta',flush=True)
    page.locator('[data-hub-refresh]').click();page.wait_for_function("requests.filter(r=>r.path.includes('/data')).length>=3")
    assert not errors,errors
    print('PASS refresh and no JavaScript errors',flush=True)
    browser.close()
