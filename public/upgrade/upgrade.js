'use strict';
const statusNode=document.getElementById('status'), message=document.getElementById('message');
const apply=document.getElementById('apply'), rollback=document.getElementById('rollback');
let csrf='', staged=null;
async function api(path, body){
  const options={credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
  if(body){options.method='POST';options.headers['Content-Type']='application/json';options.headers['X-CSRF-Token']=csrf;options.body=JSON.stringify(body);}
  const response=await fetch('/api/v1'+path,options);
  let data; try{data=await response.json();}catch{throw new Error('Сервис недоступен. Проверьте CLI update:status.');}
  if(!response.ok)throw new Error(data.error?.message||`HTTP ${response.status}`);
  return data.data;
}
async function refresh(){
  apply.disabled=true;rollback.disabled=true;staged=null;
  try{
    const identity=await api('/auth/me');csrf=identity.csrf_token||'';
    const data=await api('/updates');staged=data.staged;
    statusNode.textContent=JSON.stringify({wizard:data.wizard,staged,requests:data.requests},null,2);
    apply.disabled=!(csrf&&staged?.id&&!data.wizard?.maintenance?.update_active);
    rollback.disabled=!(csrf&&data.wizard?.last_report?.state==='committed'&&!data.wizard?.maintenance?.update_active);
  }catch(e){statusNode.textContent=e.message;}
}
async function submit(action){
  if(!window.confirm(action==='rollback'?'Rollback восстановит БД и файлы, изменения после обновления будут потеряны. Продолжить?':'Запросить установку указанного подписанного релиза?'))return;
  apply.disabled=true;rollback.disabled=true;
  try{const data=await api('/updates/'+action,action==='apply'?{package:staged.id,sha256:staged.sha256,confirm:true}:{confirm:true});message.textContent='Запрос создан. Оператор: php bin/cajeer update:run --id='+data.id;}
  catch(e){message.textContent=e.message;}
  await refresh();
}
document.getElementById('refresh').addEventListener('click',refresh);apply.addEventListener('click',()=>submit('apply'));rollback.addEventListener('click',()=>submit('rollback'));refresh();
