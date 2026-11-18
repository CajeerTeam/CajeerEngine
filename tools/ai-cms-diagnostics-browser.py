#!/usr/bin/env python3
"""Production static Admin SPA diagnostic report and OpenAI explanation in Chromium (mocked transport)."""
import os,shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
with sync_playwright() as driver:
    browser=driver.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'), headless=True, args=['--no-sandbox'])
    page=browser.new_page();errors=[]
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.set_content('<div id="app"></div>')
    page.evaluate(r'''() => {
      const cache=new Map();for(const key of ['localStorage','sessionStorage'])Object.defineProperty(window,key,{value:{getItem:k=>cache.get(k)||null,setItem:(k,v)=>cache.set(k,v),removeItem:k=>cache.delete(k)}});
      location.hash='#/diagnostics'; window.calls=[];
      window.fetch=async(url,opt={})=>{
        const p=new URL(String(url),'https://test.local'),path=p.pathname.replace('/api/v1','');
        const method=opt.method||'GET';const body=opt.body?JSON.parse(opt.body):{};
        calls.push({path,method,body,csrf:new Headers(opt.headers||{}).get('X-CSRF-Token')});
        const send=(data,status=200)=>new Response(JSON.stringify({data}),{status,headers:{'Content-Type':'application/json'}});
        if(path==='/auth/me')return send({user_id:'admin',csrf_token:'CSRF-FIXTURE',scopes:['*']});
        if(path==='/auth/oidc/status')return send({enabled:false});
        const report={status:'degraded',generated_at:'2026-11-18T14:00:00Z',window_minutes:Number(p.searchParams.get('window_minutes')||body.window_minutes||60),
          readiness:{database:true},checks:{queue:{ok:false,counts:{pending:9,reserved:1,processed:10,failed:4},oldest_ready_age_seconds:600},database:{ok:true,driver:'pgsql',latency_ms:4}},
          http:{requests:50,errors_5xx:5,average_ms:11,p95_bucket_ms:50},runtime_events:{'Unhandled HTTP exception':2},
          issues:[{code:'queue_failed',component:'queue',severity:'high',title:'Queue <img src=x onerror=alert(1)>',reason:'Failure <script>danger</script>',action:'Check tasks',command:'php bin/cajeer queue:failed'}],
          summary:{total:1,critical:0,high:1},method:'Safe checks only'};
        if(path==='/ai/diagnostics'&&method==='GET')return send(report);
        if(path==='/ai/diagnostics/explain'&&method==='POST')return send({diagnostics:report,explanation:'AI explains <img src=x onerror=alert(1)> and <script>bad</script>',usage:{input_tokens:90,output_tokens:50},model:'gpt-4.1-mini'});
        return send({});
      };
    }''')
    page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
    page.locator('[data-diagnostics-explain]').wait_for()
    assert page.get_by_text('queue_failed').count()>0
    assert page.locator('img').count()==0 and page.locator('script').count()==1, 'Injected check fields must render as text'
    page.locator('[data-diagnostics-focus]').select_option('queue_failed')
    page.locator('[data-diagnostics-explain]').click()
    page.get_by_text('AI объяснение получено.').wait_for()
    assert 'AI explains <img' in page.locator('pre').last.inner_text()
    assert page.locator('img').count()==0 and page.locator('script').count()==1, 'Injected AI text must render as text'
    calls=page.evaluate('calls')
    assert any(c['path']=='/ai/diagnostics/explain' and c['method']=='POST' and c['body']=={'window_minutes':60,'focus':'queue_failed'} and c['csrf']=='CSRF-FIXTURE' for c in calls), calls
    page.locator('[data-diagnostics-window]').select_option('360')
    page.locator('[data-diagnostics-explain]').wait_for()
    calls=page.evaluate('calls')
    assert any(c['path']=='/ai/diagnostics' and c['method']=='GET' for c in calls)
    assert errors==[], errors
    print('PASS Chromium: diagnostics, issue focus, rate window, CSRF, escaped untrusted evidence and AI response')
    browser.close()
