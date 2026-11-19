#!/usr/bin/env python3
"""Live Chromium DOM acceptance: AI spending caps, per-module report, XSS and CSRF."""
import os
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parents[1]
script=(root/'public/admin/assets/admin-app.js').read_text()
with sync_playwright() as playwright:
    browser=playwright.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'), headless=True, args=['--no-sandbox'])
    page=browser.new_page()
    errors=[]
    page.on('pageerror',lambda error:errors.append(str(error)))
    page.set_content('<div id="app"></div>')
    page.evaluate('''() => {
      window.calls=[]; window.sentSettings=null; window.windowInjected=false;
      location.hash='#/ai';
      const map=new Map();Object.defineProperty(window,'localStorage',{value:{getItem:key=>map.get(key)||null,setItem:(key,val)=>map.set(key,val),removeItem:key=>map.delete(key)}});
      window.fakeStatus={configured:true,credential_source:'environment',model:'gpt-4.1-mini',max_output_tokens:1024,monthly_token_limit:100000,
        global_monthly_limit_cents:500,global_daily_limit_cents:200,user_monthly_limit_cents:100,user_daily_limit_cents:50,
        model_pricing:{input:40,cached:10,output:160}};
      window.fetch=async (url, options={})=>{
        const path=String(url).replace('/api/v1','');
        calls.push({path, method:options.method||'GET', headers:Object.fromEntries(new Headers(options.headers||{}).entries())});
        const json=(data)=>({ok:true,status:200,json:async()=>({data}),text:async()=>JSON.stringify({data})});
        if(path==='/auth/oidc/status')return json({enabled:false});
        if(path==='/auth/me')return json({user_id:'u1',name:'Admin',email:'admin@example.test',csrf_token:'csrf-test',scopes:['*']});
        if(path==='/ai/status')return json(fakeStatus);
        if(path==='/ai/tools'||path==='/ai/conversations'||path==='/ai/actions')return json([]);
        if(path==='/ai/costs?days=30')return json({currency:'USD',days:30,units:'micro_usd',global_balances:{'2026-11':{used_micro_usd:230000,reserved_micro_usd:10000}},
          alerts:[{window:'monthly',threshold_percent:50,utilization_percent:55}],
          by_module:[{label:'editor <img src=x onerror="windowInjected=true">',requests:2,cost_micro_usd:230000}],
          by_model:[{label:'gpt-4.1-mini',requests:2,cost_micro_usd:230000}],
          by_user:[{label:'user1',requests:2,cost_micro_usd:230000}],
          notice:'Estimates only'});
        if(path==='/ai/settings'&&options.method==='PUT') { sentSettings=JSON.parse(options.body); Object.assign(fakeStatus,sentSettings);return json(fakeStatus); }
        return json({});
      };
    }''')
    page.add_script_tag(content=script)
    page.locator('[data-ai-costs-refresh]').wait_for(timeout=15000)
    assert page.get_by_text('AI Budget & Cost Control').count()==1
    assert page.get_by_text('editor <img src=x onerror="windowInjected=true">',exact=True).count()==1
    assert page.locator('img').count()==0
    assert not page.evaluate('window.windowInjected')
    assert page.get_by_text('55% бюджета',exact=False).count()>=1
    page.locator('[data-ai-settings]').evaluate('(el)=>el.closest("details").open=true')
    page.locator('[data-ai-settings] [name="global_monthly_limit_cents"]').fill('900')
    page.locator('[data-ai-settings] [name="user_daily_limit_cents"]').fill('70')
    page.locator('[data-ai-settings] [name="price_override"]').check()
    page.locator('[data-ai-settings] [name="price_output"]').fill('200')
    page.locator('[data-ai-settings] button[type="submit"]').click()
    page.wait_for_function('window.sentSettings!==null')
    sent=page.evaluate('window.sentSettings')
    assert sent['global_monthly_limit_cents']==900 and sent['user_daily_limit_cents']==70
    assert sent['model_pricing']=={'input':40,'cached':10,'output':200}
    calls=page.evaluate('window.calls')
    assert any(call['path']=='/ai/settings' and call['headers'].get('x-csrf-token')=='csrf-test' for call in calls)
    assert not errors, errors
    print('PASS Chromium AI Cost Control: report, alerts, XSS, hard caps, custom model prices, CSRF')
    browser.close()
