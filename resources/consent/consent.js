/* CajeerEngine consent runtime. Optional scripts must be inert descriptors, never live src tags. */
(() => {
  'use strict';
  const boot = document.currentScript || document.querySelector('script[data-ce-consent-config]');
  if (!boot || window.CajeerConsent) return;
  const categories = ['analytics','marketing','functional'];
  const denied = () => ({necessary:true,analytics:false,marketing:false,functional:false});
  const key = 'ce.consent.v1';
  let policy, current = denied(), record = null, ready = false, active = new Map(), panel, manage, fields, status, opener;
  let refreshTask = null, storageError = false;
  const dynamic = boot.dataset.ceConsentDynamic === '1';
  const ru = !document.documentElement.lang || document.documentElement.lang.toLowerCase().startsWith('ru');
  const t = (r,e) => ru ? r : e;
  const google = (action, c) => {
    const values = {analytics_storage:c.analytics?'granted':'denied',ad_storage:c.marketing?'granted':'denied',ad_user_data:c.marketing?'granted':'denied',ad_personalization:c.marketing?'granted':'denied',functionality_storage:c.functional?'granted':'denied',security_storage:'granted'};
    window.dataLayer = Array.isArray(window.dataLayer) ? window.dataLayer : [];
    if (typeof window.gtag !== 'function') window.gtag = function(){window.dataLayer.push(arguments);};
    // Default-denied is queued locally, not a request to Google.
    window.gtag('consent',action,values);
  };
  google('default',current);
  const read = () => {
    try { const raw = localStorage.getItem(key); return raw ? JSON.parse(raw) : null; } catch { return null; }
  };
  const valid = r => !!(r && policy && r.version===policy.version && Number.isFinite(r.at) && Number.isFinite(r.expires) && r.at<=Date.now()+60000 && r.expires>Date.now() && r.expires<=r.at+policy.ttl_days*86400000 && r.categories?.necessary===true && categories.every(k=>typeof r.categories[k]==='boolean'));
  const safePolicy = p => p && /^[a-f0-9]{64}$/.test(p.version) && Number.isInteger(p.revision) && Number.isInteger(p.ttl_days) && p.ttl_days>=1 && p.ttl_days<=365 && ['title_ru','title_en','message_ru','message_en','policy_url'].every(k=>typeof p[k]==='string') && Array.isArray(p.script_origins);
  const publicState = () => ({ready,version:policy?.version||null,revision:policy?.revision||null,decided:valid(record),categories:{...current},at:record?.at||null,expires:record?.expires||null});
  window.CajeerConsent = Object.freeze({allows:k=>k==='necessary'||(ready&&valid(record)&&categories.includes(k)&&current[k]===true),getState:publicState,open:()=>openPanel(true),withdraw:()=>{if(ready)save(denied());else clearStored();}});
  const emit = () => document.dispatchEvent(new CustomEvent('cajeer:consent-change',{detail:publicState()}));
  // Counter libraries are never inserted until the current, versioned consent permits them.
  // Only this runtime owns these tags; GTM is exclusive and requires all optional categories.
  const counters = new Map();
  let stopping = false;
  const trackingAllowed = name => !stopping && ready && valid(record) && current.analytics && (name!=='gtm'||(current.marketing&&current.functional));
  function trackingConfig() {
    const c=policy?.tracking;
    if(!c || c.enabled!==true || !Array.isArray(c.hosts) || !c.hosts.includes(location.hostname.toLowerCase()))return null;
    if(!['http:','https:'].includes(location.protocol)||/^\/(?:admin|api|preview|install|upgrade|assets|uploads|extensions)(?:\/|$)/i.test(location.pathname))return null;
    if(typeof c.metrika_id!=='string'||typeof c.ga4_id!=='string'||typeof c.gtm_id!=='string'||typeof c.ga4_debug!=='boolean')return null;
    if((c.metrika_id&&!/^[1-9][0-9]{0,11}$/.test(c.metrika_id))||(c.ga4_id&&!/^G-[A-Z0-9]{4,20}$/.test(c.ga4_id))||(c.gtm_id&&!/^GTM-[A-Z0-9]{4,20}$/.test(c.gtm_id))||(c.gtm_id&&(c.ga4_id||c.metrika_id)))return null;
    return c;
  }
  const trackingStatus = () => Object.fromEntries([...counters].map(([name,c])=>[name,{id:c.id,status:c.status,reason:c.reason||null}]));
  function publishTracking(){document.dispatchEvent(new CustomEvent('cajeer:tracking-status',{detail:trackingStatus()}));}
  function locationData(value=location.origin+location.pathname) {
    try {const url=new URL(value,location.origin);if(url.origin!==location.origin||url.username||url.password||/^\/(?:admin|api|preview|install|upgrade|assets|uploads|extensions)(?:\/|$)/i.test(url.pathname))return null;
      const data={page_location:url.origin+url.pathname,page_referrer:''};
      // Do not forward query strings, fragments, titles or external referrer paths explicitly.
      if(document.referrer){const r=new URL(document.referrer);if(['https:','http:'].includes(r.protocol))data.page_referrer=r.origin+(r.origin===location.origin?r.pathname:'/');}
      return data;
    }catch{return null;}
  }
  function view(value) {
    const data=locationData(value);if(!data)return false;
    let sent=false;
    for(const [name,c] of counters){
      if(!trackingAllowed(name)||c.status==='error'||c.last===data.page_location)continue;
      c.last=data.page_location;
      if(name==='ga4')window.gtag('event','page_view',{...data,send_to:c.id});
      if(name==='metrika')window.ym(Number(c.id),'hit',data.page_location,{referer:data.page_referrer||''});
      if(name==='gtm')window.dataLayer.push({event:'ce_page_view',...data});
      sent=true;
    }
    return sent;
  }
  function counterEvent(name,params={}) {
    if(typeof name!=='string'||! /^[a-zA-Z][a-zA-Z0-9_]{0,39}$/.test(name)||/^(?:page_view|ce_|google_|ga_|firebase_|gtm_)/i.test(name)||!params||typeof params!=='object'||Array.isArray(params))return false;
    const safe={};
    for(const [k,v] of Object.entries(params)){
      if(k==='value'&&typeof v==='number'&&Number.isFinite(v))safe.value=v;
      else if(k==='currency'&&typeof v==='string'&&/^[A-Z]{3}$/.test(v))safe.currency=v;
      else return false;
    }
    let sent=false;
    for(const [provider,c] of counters){
      if(!trackingAllowed(provider)||c.status==='error')continue;
      if(provider==='ga4')window.gtag('event',name,{...safe,send_to:c.id});
      if(provider==='metrika')window.ym(Number(c.id),'reachGoal',name,safe);
      if(provider==='gtm')window.dataLayer.push({event:'ce_'+name,...safe});
      sent=true;
    }
    return sent;
  }
  function startCounter(name,id,src,initialize) {
    if(counters.has(name)||!trackingAllowed(name))return;
    const script=document.createElement('script');script.async=true;script.src=src;script.referrerPolicy='strict-origin-when-cross-origin';script.dataset.ceTracking=name;
    const c={id,script,status:'loading',last:null};counters.set(name,c);
    // Existing tags installed by themes are not owned by this runtime; fail rather than duplicate.
    const conflict=[...document.querySelectorAll('script[src]')].some(s=>{try{const u=new URL(s.src);return name==='metrika'?u.hostname==='mc.yandex.ru'&&u.pathname.startsWith('/metrika/'):u.hostname==='www.googletagmanager.com'&&/\/(gtag\/js|gtm\.js)$/.test(u.pathname);}catch{return false;}});
    if(conflict){c.status='error';c.reason='existing-tag';publishTracking();return;}
    script.addEventListener('load',()=>{if(!trackingAllowed(name))return;c.status='loaded';publishTracking();},{once:true});
    script.addEventListener('error',()=>{c.status='error';c.reason='load-failed';publishTracking();},{once:true});
    try {initialize();document.head.append(script);}catch{c.status='error';c.reason='initialization-failed';}
    publishTracking();
  }
  function activateTracking(){
    const c=trackingConfig();if(!c||!trackingAllowed('metrika'))return;
    if(c.gtm_id){
      startCounter('gtm',c.gtm_id,'https://www.googletagmanager.com/gtm.js?id='+encodeURIComponent(c.gtm_id),()=>{
        window.dataLayer.push({'gtm.start':Date.now(),event:'gtm.js'});
      });
    }else{
      if(c.metrika_id)startCounter('metrika',c.metrika_id,'https://mc.yandex.ru/metrika/tag.js',()=>{
        if(typeof window.ym!=='function'){window.ym=function(){(window.ym.a=window.ym.a||[]).push(arguments);};window.ym.l=Date.now();}
        window.ym(Number(c.metrika_id),'init',{defer:true,clickmap:false,trackLinks:false,accurateTrackBounce:false,webvisor:false});
      });
      if(c.ga4_id)startCounter('ga4',c.ga4_id,'https://www.googletagmanager.com/gtag/js?id='+encodeURIComponent(c.ga4_id),()=>{
        window['ga-disable-'+c.ga4_id]=false;
        window.gtag('js',new Date());window.gtag('config',c.ga4_id,{send_page_view:false,allow_google_signals:false,allow_ad_personalization_signals:false,...(c.ga4_debug?{debug_mode:true}:{}),...(locationData()||{})});
      });
    }
    view();
  }
  function stopTracking(next){
    const revoke=[...counters].some(([name])=>!next.analytics||(name==='gtm'&&(!next.marketing||!next.functional)));
    if(!revoke)return false;
    stopping=true;
    for(const [name,c] of counters){
      if(name==='ga4')window['ga-disable-'+c.id]=true;
      if(name==='metrika'&&c.status==='loaded'&&typeof window.ym==='function')try{window.ym(Number(c.id),'destruct');}catch{}
      c.script.remove();c.status='stopped';
    }
    publishTracking();return true;
  }
  window.CajeerTracking=Object.freeze({pageView:view,event:counterEvent,getStatus:trackingStatus});
  const cookiePatterns = {analytics:/^(?:_ga(?:_|$)|_gid$|_gat(?:_|$)|_ym_|_pk_|_clck$|_clsk$)/,marketing:/^(?:_fbp$|_fbc$|_gcl_|_ttp$|ttcsid(?:_|$))/,functional:/^ce_optional_/};
  function clean(category) {
    const names = document.cookie.split(';').map(v=>v.split('=')[0].trim()).filter(v=>cookiePatterns[category].test(v));
    const paths = new Set(['/']); let path=''; for(const part of location.pathname.split('/').filter(Boolean)){path+='/'+part;paths.add(path);paths.add(path+'/');}
    const domains=['']; const labels=location.hostname.split('.'); if(labels.length>1)for(let i=0;i<labels.length-1;i++)domains.push('; Domain='+labels.slice(i).join('.'));
    for(const name of names)for(const p of paths)for(const d of domains)document.cookie=`${name}=; Max-Age=0; Path=${p}${d}; SameSite=Lax${location.protocol==='https:'?'; Secure':''}`;
  }
  function permittedUrl(value) {
    if(typeof value!=='string'||!value||/[\x00-\x20\\]/.test(value))return null;
    try { const u=new URL(value,location.origin); if(u.username||u.password||u.hash)return null;
      if(u.origin===location.origin && (u.protocol==='https:'||u.protocol==='http:'))return u.href;
      if(u.protocol==='https:'&&policy.script_origins.includes(u.origin))return u.href;
    } catch {} return null;
  }
  function activate() {
    if(!ready||!valid(record))return;
    for(const node of document.querySelectorAll('script[type="text/plain"][data-ce-consent][data-ce-src]')){
      const category=node.dataset.ceConsent;
      if(!categories.includes(category)||!current[category]||active.has(node))continue;
      const src=permittedUrl(node.dataset.ceSrc); if(!src)continue;
      const script=document.createElement('script');script.src=src;script.async=false;
      if(node.dataset.ceIntegrity){script.integrity=node.dataset.ceIntegrity;script.crossOrigin='anonymous';}
      active.set(node,{script,category}); node.after(script);
    }
  }
  function apply(next, reload=true) {
    const before=current; current=next; const trackingStopped=stopTracking(next); google('update',current);
    const revoked=categories.filter(k=>before[k]&&!current[k]);
    let loaded=false;
    for(const [node,item] of active)if(revoked.includes(item.category)){item.script.remove();active.delete(node);loaded=true;}
    for(const k of categories)if(!current[k])clean(k);
    emit();
    // Removing a script cannot undo executed JavaScript. Reload into a denied document.
    if((loaded||trackingStopped)&&reload){location.reload();return;}
    activate();
    activateTracking();
  }
  function clearStored(){
    record=null;try{localStorage.removeItem(key);}catch{}
    apply(denied());
    if(status)status.textContent=t('Согласие отозвано.','Consent withdrawn.');
  }
  function save(next) {
    if(!ready||!policy)return;
    const now=Date.now(); const previous=read();
    const history=Array.isArray(previous?.history)?previous.history.filter(h=>h&&typeof h.version==='string'&&Number.isFinite(h.at)&&h.at>now-365*86400000&&categories.every(k=>typeof h.categories?.[k]==='boolean')).slice(-19):[];
    const snapshot={version:policy.version,at:now,categories:{...next}};
    record={...snapshot,expires:now+policy.ttl_days*86400000,history:[...history,snapshot]};
    storageError=false;
    try { localStorage.setItem(key,JSON.stringify(record)); } catch { storageError=true;try{localStorage.removeItem(key);}catch{} }
    closePanel(); apply(next);
    if(storageError&&status){status.textContent=t('Настройки действуют только на этой странице: хранилище недоступно.','Preferences apply only to this page: storage is unavailable.');panel.hidden=false;}
  }
  function el(tag, text, cls) { const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e; }
  function button(text, action) { const b=el('button',text);b.type='button';b.addEventListener('click',action);return b; }
  function closePanel(){if(panel)panel.hidden=true;if(opener?.isConnected)opener.focus();opener=null;}
  function openPanel(custom=false){if(!panel)return;opener=document.activeElement;panel.hidden=false;fields.hidden=!ready||!custom;for(const k of categories)fields.querySelector(`[name="${k}"]`).checked=current[k];panel.querySelector('h2').focus();}
  function build(){
    if(panel)return;
    manage=button(t('Приватность','Privacy preferences'),()=>openPanel(true));manage.className='ce-consent-open';document.body.append(manage);
    panel=el('section',undefined,'ce-consent-panel');panel.hidden=true;panel.setAttribute('role','dialog');panel.setAttribute('aria-labelledby','ce-consent-title');
    const title=el('h2');title.id='ce-consent-title';title.tabIndex=-1;panel.append(title,el('p'));
    const link=el('a',t('Политика приватности','Privacy policy'));link.hidden=true;link.dataset.policyLink='1';panel.append(link);
    fields=el('fieldset');fields.hidden=true;fields.append(el('legend',t('Категории','Categories')));
    const labels={necessary:t('Необходимые (всегда включены)','Necessary (always enabled)'),analytics:t('Аналитика','Analytics'),marketing:t('Маркетинг','Marketing'),functional:t('Функциональные','Functional')};
    for(const k of ['necessary',...categories]){const label=el('label'),input=el('input');input.type='checkbox';input.name=k;input.disabled=k==='necessary';input.checked=k==='necessary';label.append(input,document.createTextNode(labels[k]));fields.append(label);}
    const actions=el('div',undefined,'ce-consent-actions');
    const accept=button(t('Принять все','Accept all'),()=>save({necessary:true,analytics:true,marketing:true,functional:true}));accept.dataset.grant='1';
    const customize=button(t('Настроить','Customize'),()=>{fields.hidden=false;fields.querySelector('input:not(:disabled)').focus();});customize.dataset.grant='1';
    actions.append(button(t('Отклонить все','Reject all'),()=>ready?save(denied()):clearStored()),accept,customize);
    fields.append(button(t('Сохранить выбор','Save choices'),()=>{const next=denied();for(const k of categories)next[k]=fields.querySelector(`[name="${k}"]`).checked;save(next);}));
    panel.append(fields,actions,button(t('Закрыть','Close'),closePanel));status=el('small');status.setAttribute('role','status');panel.append(status);document.body.append(panel);
    panel.addEventListener('keydown',e=>{if(e.key==='Escape')closePanel();});
  }
  function updateText(){panel.querySelector('h2').textContent=policy[ru?'title_ru':'title_en'];panel.querySelector('p').textContent=policy[ru?'message_ru':'message_en'];const link=panel.querySelector('[data-policy-link]');link.hidden=!policy.policy_url;if(policy.policy_url)link.href=policy.policy_url;}
  async function refresh(){
    if(refreshTask)return refreshTask;
    refreshTask=(async()=>{
      try{
        let p=JSON.parse(boot.dataset.ceConsentConfig);
        if(dynamic){const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),8000);try{const r=await fetch('/api/v1/public/consent',{cache:'no-store',credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});if(!r.ok)throw Error('policy unavailable');p=(await r.json()).data;}finally{clearTimeout(timeout);}}
        if(!safePolicy(p))throw Error('invalid policy');
        const changed=policy?.version!==p.version;const mustReload=changed&&(active.size>0||counters.size>0);policy=p;ready=true;build();manage.disabled=false;updateText();panel.querySelectorAll('[data-grant]').forEach(b=>b.disabled=false);status.textContent='';
        if(mustReload){record=null;apply(denied());return;}
        if(changed||!valid(record)){const stored=read();record=valid(stored)?stored:null;apply(record?{...record.categories}:denied());if(!record)openPanel(false);else closePanel();}
      }catch{ready=false;record=null;apply(denied());
        // Keep withdrawal available during outages, but never permit a new grant from stale policy.
        try{const fallback=JSON.parse(boot.dataset.ceConsentConfig);if(safePolicy(fallback)){policy=fallback;build();updateText();fields.hidden=true;panel.querySelectorAll('[data-grant]').forEach(b=>b.disabled=true);status.textContent=t('Политика временно недоступна. Необязательные технологии отключены.','Policy temporarily unavailable. Optional technologies are disabled.');openPanel(false);}}catch{}
      }
    })().finally(()=>{refreshTask=null;}); return refreshTask;
  }
  window.addEventListener('storage',e=>{if(e.key!==key&&e.key!==null)return;record=read();if(!valid(record))record=null;apply(record?{...record.categories}:denied());if(!record&&panel)openPanel(false);else closePanel();});
  window.addEventListener('pageshow',e=>{if(e.persisted)refresh();});
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')refresh();});
  setInterval(()=>{if(record&&!valid(record)){record=null;apply(denied());if(panel)openPanel(false);}if(dynamic&&document.visibilityState==='visible')refresh();},60000);
  new MutationObserver(()=>activate()).observe(document.documentElement,{childList:true,subtree:true});
  // Deferred script runs after parser; no optional script can load before refresh succeeds.
  refresh();
})();
