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
  const adProviders=['google_ads','meta','tiktok','vk','yandex_ads'];
  const adEvents=['generate_lead','form_submit','sign_up'];
  const adLabels={generate_lead:'ads_lead_label',form_submit:'ads_form_label',sign_up:'ads_signup_label'};
  const yandexGoals={generate_lead:'ads_yandex_lead_goal',form_submit:'ads_yandex_form_goal',sign_up:'ads_yandex_signup_goal'};
  const trackingAllowed = name => !stopping && ready && valid(record) && current.analytics && (!adProviders.includes(name)||current.marketing) && (name!=='gtm'||(current.marketing&&current.functional));
  function trackingConfig() {
    const c=policy?.tracking;
    if(!c || c.enabled!==true || !Array.isArray(c.hosts) || !c.hosts.includes(location.hostname.toLowerCase()))return null;
    if(!['http:','https:'].includes(location.protocol)||/^\/(?:admin|api|preview|install|upgrade|assets|uploads|extensions)(?:\/|$)/i.test(location.pathname))return null;
    if(typeof c.metrika_id!=='string'||typeof c.ga4_id!=='string'||typeof c.gtm_id!=='string'||typeof c.ga4_debug!=='boolean')return null;
    if((c.metrika_id&&!/^[1-9][0-9]{0,11}$/.test(c.metrika_id))||(c.ga4_id&&!/^G-[A-Z0-9]{4,20}$/.test(c.ga4_id))||(c.gtm_id&&!/^GTM-[A-Z0-9]{4,20}$/.test(c.gtm_id))||(c.gtm_id&&(c.ga4_id||c.metrika_id)))return null;
    const alt={...c,matomo_id:c.matomo_id??'',matomo_url:c.matomo_url??'',plausible_id:c.plausible_id??'',plausible_url:c.plausible_url??'',cloudflare_token:c.cloudflare_token??''};
    if(typeof alt.cloudflare_token!=='string'||(alt.cloudflare_token&&(!/^[a-f0-9]{32}$/.test(alt.cloudflare_token)||c.gtm_id)))return null;
    for(const p of ['matomo','plausible']){
      const id=alt[p+'_id'],url=alt[p+'_url'];if(typeof id!=='string'||typeof url!=='string'||Boolean(id)!==Boolean(url))return null;
      if(!id)continue;
      if(c.gtm_id||!(p==='matomo'?/^[1-9][0-9]{0,19}$/.test(id):/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z][a-z0-9-]*$/.test(id)))return null;
      try{const u=new URL(url);if(!/^https:\/\/[a-z0-9.-]+(?:\/[a-zA-Z0-9_-]+)*\/?$/.test(url)||!((p==='plausible'&&url.replace(/\/$/,'')==='https://plausible.io')||policy.script_origins.includes(u.origin))) {alt[p+'_id']='';alt[p+'_url']='';}}
      catch{return null;}
    }
    const patterns={google_ads_id:/^AW-[0-9]{5,20}$/,meta_id:/^[1-9][0-9]{5,24}$/,tiktok_id:/^[A-Z0-9]{10,40}$/,vk_id:/^[1-9][0-9]{0,19}$/,ads_lead_label:/^[A-Za-z0-9_-]{1,100}$/,ads_form_label:/^[A-Za-z0-9_-]{1,100}$/,ads_signup_label:/^[A-Za-z0-9_-]{1,100}$/,ads_yandex_lead_goal:/^[A-Za-z0-9_-]{1,64}$/,ads_yandex_form_goal:/^[A-Za-z0-9_-]{1,64}$/,ads_yandex_signup_goal:/^[A-Za-z0-9_-]{1,64}$/};
    for(const [k,pattern] of Object.entries(patterns)){alt[k]=c[k]??'';if(typeof alt[k]!=='string'||(alt[k]&&!pattern.test(alt[k])))return null;}
    alt.ads_events=c.ads_events??[];alt.ads_attribution=c.ads_attribution??false;
    if(typeof alt.ads_attribution!=='boolean')return null;
    if(!Array.isArray(alt.ads_events)||alt.ads_events.some(n=>!adEvents.includes(n))||new Set(alt.ads_events).size!==alt.ads_events.length||c.gtm_id&&adProviders.some(n=>alt[n+'_id']))return null;
    for(const n of adEvents)if(alt[adLabels[n]]&&(!alt.google_ads_id||!alt.ads_events.includes(n)))return null;
    for(const n of adEvents)if(alt[yandexGoals[n]]&&(!alt.metrika_id||!alt.ads_events.includes(n)))return null;
    return alt;
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
  // A single event pipeline: validate, resolve rule, deduplicate, route, diagnose.
  // No raw form values, search terms, URLs, identifiers or arbitrary strings in event parameters.
  const eventJournal=[], eventIds=new Map(), scrollSeen=new Set();
  const automaticNames=['search','file_download','outbound_click','scroll'];
  const providers=['ga4','metrika','gtm','matomo','plausible'];
  const reserved=new Set(['purchase','refund','add_to_cart','begin_checkout','view_item']);
  let eventWindow=[], searchSeen=false, diagnosticPending=0, scrollScheduled=false;
  const eventName=n=>typeof n==='string'&&/^[a-z][a-z0-9_]{0,39}$/.test(n)&&! /^(?:ce_|google_|ga_|firebase_|gtm_|session_|first_)/.test(n)&&!reserved.has(n);
  const defaultEvents=()=>({enabled:true,allow_custom:true,collect_diagnostics:false,rules:[...['page_view',...automaticNames,'form_submit','generate_lead','sign_up','login']].map(name=>({name,enabled:true,automatic:automaticNames.includes(name),ga4:automaticNames.includes(name)?'site_'+name:name,metrika:name,gtm:name,matomo:name,plausible:name==='pageview'?'':name}))});
  function eventConfig(){
    const source=policy?.events??defaultEvents();
    const c={...source,rules:Array.isArray(source.rules)?source.rules.map(r=>r?{matomo:r.name,plausible:r.name==='pageview'?'':r.name,...r}:r):source.rules};
    if(!c||typeof c.enabled!=='boolean'||typeof c.allow_custom!=='boolean'||typeof c.collect_diagnostics!=='boolean'||!Array.isArray(c.rules)||c.rules.length>100)return null;
    const names=new Set();
    for(const r of c.rules){if(!r||!eventName(r.name)||names.has(r.name)||typeof r.enabled!=='boolean'||typeof r.automatic!=='boolean'||(r.automatic&&!automaticNames.includes(r.name))||providers.some(p=>typeof r[p]!=='string'||(p==='plausible'&&r[p]==='pageview')||(r[p]!==''&&(!eventName(r[p])||(r.name==='page_view'?r[p]!=='page_view':r[p]==='page_view')))))return null;names.add(r.name);}
    return names.has('page_view')?c:null;
  }
  function eventRule(name,automatic=false){
    const config=eventConfig();if(!config?.enabled||!eventName(name))return null;
    const rule=config.rules.find(r=>r.name===name);
    if(rule)return rule.enabled&&(!automatic||rule.automatic)?rule:null;
    return !automatic&&config.allow_custom?{name,ga4:name,metrika:name,gtm:name,matomo:name,plausible:name==='pageview'?'':name}:null;
  }
  function eventId(){return Array.from(crypto.getRandomValues(new Uint8Array(16)),v=>v.toString(16).padStart(2,'0')).join('');}
  function safeParams(name,params){
    if(!params||typeof params!=='object'||Array.isArray(params))return null;
    const safe={};
    for(const [k,v] of Object.entries(params)){
      if(k==='value'&&typeof v==='number'&&Number.isFinite(v)&&Math.abs(v)<=1e12)safe.value=v;
      else if(k==='currency'&&typeof v==='string'&&/^[A-Z]{3}$/.test(v))safe.currency=v;
      else if(name==='scroll'&&k==='percent_scrolled'&&[25,50,75,90].includes(v))safe[k]=v;
      else if(name==='search'&&k==='result_count'&&Number.isInteger(v)&&v>=0&&v<=1000000)safe[k]=v;
      else if(name==='file_download'&&k==='file_extension'&&['pdf','zip','gz','doc','docx','xls','xlsx','ppt','pptx','csv','txt','mp3','mp4','other'].includes(v))safe[k]=v;
      else return null;
    }
    return safe;
  }
  function diagnostic(row){
    if(!dynamic||!eventConfig()?.collect_diagnostics||!eventConfig().rules.some(r=>r.name===row.name)||!trackingAllowed('metrika')||diagnosticPending>=4)return;
    const body=JSON.stringify({id:row.id,name:row.name,version:policy.version,outcomes:row.outcomes});
    const version=policy.version;diagnosticPending++;
    const attempt=async()=>{if(!trackingAllowed('metrika')||policy.version!==version)return;const r=await fetch('/api/v1/public/analytics/events',{method:'POST',credentials:'omit',cache:'no-store',keepalive:true,headers:{'Content-Type':'application/json'},body});if(!r.ok)return;};
    // A retry only for a transport failure, with the same id. Never retry after revocation.
    (async()=>{try{await attempt();}catch{await new Promise(resolve=>setTimeout(resolve,500));try{await attempt();}catch{}}finally{diagnosticPending--;}})();
  }
  function dispatchEvent(name,params={},options={},data=null){
    const rule=eventRule(name,options.automatic===true);if(!rule||!trackingConfig()||!trackingAllowed('metrika'))return false;
    const safe=safeParams(name,params);if(!safe)return false;
    const id=options.id??eventId();if(typeof id!=='string'||! /^[a-f0-9]{32}$/.test(id))return false;
    const now=Date.now();eventWindow=eventWindow.filter(t=>t>now-60000);if(eventWindow.length>=120||eventIds.has(id))return false;
    const outcomes={};const config=trackingConfig();
    for(const [provider,c] of counters){
      const isAd=adProviders.includes(provider);
      const target=isAd?adTarget(config,provider,name):rule[provider];if(!target||!trackingAllowed(provider)||(provider==='metrika'&&adEvents.some(n=>target===config[yandexGoals[n]])))continue;
      if(name==='page_view'&&c.last===data.page_location)continue;
      if(c.status==='error'||c.status==='stopped'){outcomes[provider]='failed';continue;}
      try{
        if(isAd){
          dispatchAd(provider,c,target,name,safe,id,data);
          if(name==='page_view')c.last=data.page_location;
        }else if(name==='page_view'){
          if(provider==='ga4')window.gtag('event','page_view',{...data,send_to:c.id});
          if(provider==='metrika')window.ym(Number(c.id),'hit',data.page_location,{referer:data.page_referrer||''});
          if(provider==='gtm')window.dataLayer.push({event:'ce_page_view',...data,ce_event_id:id});
          if(provider==='matomo'){window._paq.push(['setCustomUrl',data.page_location],['setReferrerUrl',data.page_referrer||''],['setDocumentTitle',new URL(data.page_location).pathname],['trackPageView']);}
          if(provider==='plausible')sendPlausible(c,'pageview',data,{});
          c.last=data.page_location;
        }else{
          if(provider==='ga4')window.gtag('event',target,{...safe,event_id:id,send_to:c.id});
          if(provider==='metrika')window.ym(Number(c.id),'reachGoal',target,safe);
          if(provider==='gtm')window.dataLayer.push({event:'ce_'+target,...safe,ce_event_id:id});
          if(provider==='matomo'){const loc=locationData();if(!loc)throw new Error('Invalid event URL');window._paq.push(['setCustomUrl',loc.page_location],['setReferrerUrl',loc.page_referrer||''],['setDocumentTitle',new URL(loc.page_location).pathname],['trackEvent','CajeerEngine',target,...(typeof safe.value==='number'?[target,safe.value]:[])]);}
          if(provider==='plausible')sendPlausible(c,target,locationData(),safe);
        }
        outcomes[provider]=c.status==='loaded'?'submitted':'queued';
      }catch{outcomes[provider]='failed';}
    }
    if(!Object.keys(outcomes).length)return false;
    eventWindow.push(now);eventIds.set(id,now);while(eventIds.size>512)eventIds.delete(eventIds.keys().next().value);
    const row={id,name,at:now,outcomes};eventJournal.push(row);if(eventJournal.length>100)eventJournal.shift();
    diagnostic(row);
    document.dispatchEvent(new CustomEvent('cajeer:analytics-event',{detail:{...row,outcomes:{...outcomes}}}));
    return Object.values(outcomes).some(v=>v!=='failed');
  }
  function view(value){const data=locationData(value);return data?dispatchEvent('page_view',{}, {},data):false;}
  function counterEvent(name,params={},options={}){
    if(name==='page_view'||!options||typeof options!=='object'||Array.isArray(options)||Object.keys(options).some(k=>k!=='id'))return false;
    return dispatchEvent(name,params,options);
  }
  function autoEvent(name,params={}){return dispatchEvent(name,params,{automatic:true});}
  function automaticPage(){
    const marker=document.querySelector('[data-ce-analytics-search]');
    if(marker&&!searchSeen){const count=Number(marker.dataset.ceAnalyticsSearch);if(Number.isInteger(count)&&count>=0&&count<=1000000&&autoEvent('search',{result_count:count}))searchSeen=true;}
  }
  document.addEventListener('click',e=>{
    if(!e.isTrusted||e.defaultPrevented||!trackingAllowed('metrika'))return;
    const a=e.target instanceof Element?e.target.closest('a[href]'):null;if(!a||a.closest('.ce-consent-panel')||a.closest('[data-ce-analytics-ignore]'))return;
    try{const u=new URL(a.href,location.origin);if(!['http:','https:'].includes(u.protocol))return;
      const ext=(u.pathname.match(/\.([a-z0-9]{1,8})$/i)?.[1]||'').toLowerCase();const files=['pdf','zip','gz','doc','docx','xls','xlsx','ppt','pptx','csv','txt','mp3','mp4'];
      if(a.hasAttribute('download')||files.includes(ext))autoEvent('file_download',{file_extension:files.includes(ext)?ext:'other'});
      else if(u.origin!==location.origin)autoEvent('outbound_click');
    }catch{}
  });
  window.addEventListener('scroll',()=>{
    if(scrollScheduled||!trackingAllowed('metrika'))return;scrollScheduled=true;
    requestAnimationFrame(()=>{scrollScheduled=false;if(!trackingAllowed('metrika'))return;
      const height=document.documentElement.scrollHeight-window.innerHeight;if(height<=0)return;
      const percent=100*window.scrollY/height;for(const n of [25,50,75,90])if(percent>=n&&!scrollSeen.has(n)&&autoEvent('scroll',{percent_scrolled:n}))scrollSeen.add(n);
    });
  },{passive:true});
  function sendPlausible(c,name,data,props){
    if(!data||!trackingAllowed('plausible'))throw new Error('Tracking disabled');
    const payload={name,domain:c.id,url:data.page_location,referrer:data.page_referrer||undefined,props};
    // A simple browser request preserves the visitor's real network/user-agent attributes.
    fetch(c.endpoint+'/api/event',{method:'POST',mode:'no-cors',credentials:'omit',referrerPolicy:'no-referrer',keepalive:true,headers:{'Content-Type':'text/plain'},body:JSON.stringify(payload)}).catch(()=>{if(!trackingAllowed('plausible'))return;c.status='error';c.reason='transport-failed';publishTracking();});
  }
  function adTarget(config,provider,name){
    if(name==='page_view')return ['google_ads','yandex_ads'].includes(provider)?'':'PageView';
    if(!config.ads_events.includes(name))return '';
    if(provider==='google_ads')return config[adLabels[name]]||'';
    if(provider==='yandex_ads')return config[yandexGoals[name]]||'';
    if(provider==='meta')return {generate_lead:'Lead',form_submit:'Contact',sign_up:'CompleteRegistration'}[name]||'';
    if(provider==='tiktok')return {generate_lead:'Contact',form_submit:'SubmitForm',sign_up:'CompleteRegistration'}[name]||'';
    return {generate_lead:'generatelead',form_submit:'formsubmit',sign_up:'signup'}[name]||'';
  }
  function dispatchAd(provider,c,target,name,safe,id,data){
    if(!trackingAllowed(provider))throw Error('Marketing consent required');
    const params=typeof safe.value==='number'&&safe.value>=0&&safe.currency?{value:safe.value,currency:safe.currency}:{};
    if(provider==='yandex_ads')window.ym(Number(c.id),'reachGoal',target,params);
    if(provider==='google_ads')window.gtag('event','conversion',{send_to:c.id+'/'+target,...params,transaction_id:id});
    if(provider==='meta')window.fbq('trackSingle',c.id,target,params,{eventID:id});
    if(provider==='tiktok'){
      if(name==='page_view')window.ttq.page();else window.ttq.track(target,params,{event_id:id});
    }
    if(provider==='vk')window._tmr.push(name==='page_view'?{id:c.id,type:'pageView',start:Date.now(),url:data.page_location}:{id:c.id,type:'reachGoal',goal:target,...(typeof params.value==='number'?{value:params.value}:{})});
  }
  // Session-only attribution. No click IDs or raw query strings, no automatic forwarding.
  const attributionKey='ce.ads.attribution.v1';let attribution=null;
  function captureAttribution(c){
    if(!c.ads_attribution||!trackingAllowed('google_ads'))return;
    try{
      const old=JSON.parse(sessionStorage.getItem(attributionKey)||'null');
      const valid=t=>t&&typeof t==='object'&&Object.keys(t).every(k=>['source','medium','campaign'].includes(k)&&typeof t[k]==='string'&&/^[A-Za-z0-9_.-]{1,80}$/.test(t[k]));
      if(old&&old.version===policy.version&&valid(old.first)&&valid(old.last))attribution=old;
      const query=new URLSearchParams(location.search||'');const touch={};
      for(const k of ['source','medium','campaign']){const v=query.get('utm_'+k);if(v&&/^[A-Za-z0-9_.-]{1,80}$/.test(v))touch[k]=v;}
      if(Object.keys(touch).length){attribution={version:policy.version,first:attribution?.first||touch,last:touch};sessionStorage.setItem(attributionKey,JSON.stringify(attribution));}
    }catch{} // blocked storage does not prevent conversion delivery
  }
  function clearAttribution(){attribution=null;try{sessionStorage.removeItem(attributionKey);}catch{}}
  function activateAds(c){
    if(!trackingAllowed('google_ads'))return;
    captureAttribution(c);
    if(c.metrika_id&&adEvents.some(n=>c[yandexGoals[n]])&&!counters.has('yandex_ads')){
      const m=counters.get('metrika');if(m){const ad={id:m.id,status:m.status,reason:m.reason,last:null};counters.set('yandex_ads',ad);m.script?.addEventListener('load',()=>{if(trackingAllowed('yandex_ads')){ad.status='loaded';publishTracking();}},{once:true});m.script?.addEventListener('error',()=>{ad.status='error';ad.reason='load-failed';publishTracking();},{once:true});publishTracking();}
    }
    if(c.google_ads_id&&!counters.has('google_ads')){
      const configure=()=>{window.gtag('config',c.google_ads_id,{send_page_view:false,...(locationData()||{})});};
      const ga=counters.get('ga4');
      if(ga){
        // One Google library; a second destination is configured only after marketing consent.
        const ad={id:c.google_ads_id,status:ga.status,reason:ga.reason,last:null};counters.set('google_ads',ad);
        if(ga.status!=='error'){configure();ga.script?.addEventListener('load',()=>{if(trackingAllowed('google_ads')){ad.status='loaded';publishTracking();}},{once:true});ga.script?.addEventListener('error',()=>{ad.status='error';ad.reason='load-failed';publishTracking();},{once:true});}publishTracking();
      }else startCounter('google_ads',c.google_ads_id,'https://www.googletagmanager.com/gtag/js?id='+encodeURIComponent(c.google_ads_id),()=>{window.gtag('js',new Date());configure();});
    }
    if(c.meta_id)startCounter('meta',c.meta_id,'https://connect.facebook.net/en_US/fbevents.js',()=>{
      if(window.fbq||window._fbq)throw Error('Existing Meta pixel');
      const fbq=function(){fbq.callMethod?fbq.callMethod.apply(fbq,arguments):fbq.queue.push(arguments);};fbq.queue=[];fbq.push=fbq;fbq.loaded=true;fbq.version='2.0';window.fbq=window._fbq=fbq;
      fbq('consent','grant');fbq('init',c.meta_id);
    });
    if(c.tiktok_id)startCounter('tiktok',c.tiktok_id,'https://analytics.tiktok.com/i18n/pixel/events.js?sdkid='+encodeURIComponent(c.tiktok_id)+'&lib=ttq',()=>{
      if(window.ttq)throw Error('Existing TikTok pixel');
      window.TiktokAnalyticsObject='ttq';const ttq=window.ttq=[];
      ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie','holdConsent','revokeConsent','grantConsent'];
      ttq.setAndDefer=(t,e)=>{t[e]=function(){t.push([e,...arguments]);};};for(const m of ttq.methods)ttq.setAndDefer(ttq,m);
      ttq._i={[c.tiktok_id]:[]};ttq._i[c.tiktok_id]._u='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._t={[c.tiktok_id]:Date.now()};ttq._o={[c.tiktok_id]:{}};
      ttq.instance=id=>{const t=ttq._i[id]||[];for(const m of ttq.methods)ttq.setAndDefer(t,m);return t;};
    });
    if(c.vk_id)startCounter('vk',c.vk_id,'https://top-fwz1.mail.ru/js/code.js',()=>{if(window._tmr)throw Error('Existing VK pixel');window._tmr=[];});
  }
  function startCounter(name,id,src,initialize) {
    if(counters.has(name)||!trackingAllowed(name))return;
    const script=document.createElement('script');script.async=true;script.src=src;script.referrerPolicy='strict-origin-when-cross-origin';script.dataset.ceTracking=name;
    const c={id,script,status:'loading',last:null};counters.set(name,c);
    // Existing tags installed by themes are not owned by this runtime; fail rather than duplicate.
    const conflict=[...document.querySelectorAll('script[src]')].some(s=>{try{const u=new URL(s.src);return name==='meta'?u.hostname==='connect.facebook.net'&&u.pathname.endsWith('/fbevents.js'):name==='tiktok'?u.hostname==='analytics.tiktok.com'&&u.pathname.includes('/pixel/'):name==='vk'?u.hostname==='top-fwz1.mail.ru'&&u.pathname==='/js/code.js':name==='cloudflare'?u.hostname==='static.cloudflareinsights.com'&&u.pathname==='/beacon.min.js':name==='matomo'?u.href===src||/\/(?:matomo|piwik)\.js$/.test(u.pathname):name==='metrika'?u.hostname==='mc.yandex.ru'&&u.pathname.startsWith('/metrika/'):u.hostname==='www.googletagmanager.com'&&/\/(gtag\/js|gtm\.js)$/.test(u.pathname);}catch{return false;}});
    if(conflict){c.status='error';c.reason='existing-tag';publishTracking();return;}
    script.addEventListener('load',()=>{if(!trackingAllowed(name))return;c.status='loaded';publishTracking();},{once:true});
    script.addEventListener('error',()=>{c.status='error';c.reason='load-failed';publishTracking();},{once:true});
    try {initialize(script);document.head.append(script);}catch{c.status='error';c.reason='initialization-failed';}
    publishTracking();
  }
  function activateTracking(){
    const c=trackingConfig();if(!c||!eventConfig()?.enabled||!trackingAllowed('metrika'))return;
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
      if(c.matomo_id)startCounter('matomo',c.matomo_id,c.matomo_url.replace(/\/$/,'')+'/matomo.js',()=>{
        if(window._paq)throw new Error('Existing Matomo tracker');
        window._paq=[['disableCookies'],['setTrackerUrl',c.matomo_url.replace(/\/$/,'')+'/matomo.php'],['setSiteId',c.matomo_id],['setRequestMethod','POST'],['requireConsent'],['setConsentGiven']];
      });
      if(c.cloudflare_token)startCounter('cloudflare',c.cloudflare_token,'https://static.cloudflareinsights.com/beacon.min.js?token='+encodeURIComponent(c.cloudflare_token),script=>{script.type='module';});
      if(c.plausible_id&&!counters.has('plausible')){
        const conflict=typeof window.plausible==='function'||[...document.querySelectorAll('script[src]')].some(s=>s.src.startsWith(c.plausible_url.replace(/\/$/,'')+'/js/'));
        counters.set('plausible',{id:c.plausible_id,endpoint:c.plausible_url.replace(/\/$/,''),status:conflict?'error':'loaded',reason:conflict?'existing-tag':null,last:null});publishTracking();
      }
    }
    if(!c.gtm_id)activateAds(c);
    view();
    automaticPage();
  }
  function stopTracking(next){
    const revoke=[...counters].some(([name])=>!next.analytics||(adProviders.includes(name)&&!next.marketing)||(name==='gtm'&&(!next.marketing||!next.functional)));
    if(!next.analytics||!next.marketing)clearAttribution();
    if(!revoke)return false;
    stopping=true;eventJournal.length=0;eventIds.clear();eventWindow=[];scrollSeen.clear();searchSeen=false;
    for(const [name,c] of counters){
      if(name==='ga4'||name==='google_ads')window['ga-disable-'+c.id]=true;
      if(name==='meta'&&typeof window.fbq==='function')try{if(c.status!=='loaded'&&Array.isArray(window.fbq.queue))window.fbq.queue.length=0;window.fbq('consent','revoke');}catch{}
      if(name==='tiktok'&&typeof window.ttq?.revokeConsent==='function')try{if(c.status!=='loaded'&&Array.isArray(window.ttq))window.ttq.length=0;window.ttq.revokeConsent();}catch{}
      if(name==='vk'&&c.status!=='loaded'&&Array.isArray(window._tmr))window._tmr.length=0;
      if(name==='metrika'&&c.status==='loaded'&&typeof window.ym==='function')try{window.ym(Number(c.id),'destruct');}catch{}
      if(name==='matomo'&&window._paq){if(c.status!=='loaded'&&Array.isArray(window._paq))window._paq.length=0;window._paq.push(['forgetConsentGiven']);}
      c.script?.remove();c.status='stopped';
    }
    publishTracking();return true;
  }
  window.CajeerTracking=Object.freeze({pageView:view,event:counterEvent,getStatus:trackingStatus});
  window.CajeerAnalytics=Object.freeze({emit:counterEvent,pageView:view,getJournal:()=>trackingAllowed('metrika')?eventJournal.map(r=>({...r,outcomes:{...r.outcomes}})):[],getStatus:trackingStatus,getAttribution:()=>trackingAllowed('google_ads')&&trackingConfig()?.ads_attribution&&attribution?{first:{...attribution.first},last:{...attribution.last}}:null});
  const cookiePatterns = {analytics:/^(?:_ga(?:_|$)|_gid$|_gat(?:_|$)|_ym_|_pk_|_clck$|_clsk$)/,marketing:/^(?:_fbp$|_fbc$|_gcl_|_ttp$|_tt_enable_cookie$|_tmr_|ttcsid(?:_|$))/,functional:/^ce_optional_/};
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
