(function(){
'use strict';
if (typeof HamnoorWebPush === 'undefined') { return; }
var root = document.querySelector('[data-hamnoor-webpush]');
if (!root) { return; }
var statusEl = root.querySelector('[data-webpush-status]');
var enableBtn = root.querySelector('[data-webpush-enable]');
var disableBtn = root.querySelector('[data-webpush-disable]');
var testBtn = root.querySelector('[data-webpush-test]');
var messageEl = root.querySelector('[data-webpush-message]');

function msg(text, type){
  if (!messageEl) return;
  messageEl.textContent = text || '';
  messageEl.className = 'hamnoor-ajax-message' + (type ? ' is-' + type : '');
}
function setBusy(b){ [enableBtn,disableBtn,testBtn].forEach(function(el){ if(el) el.disabled = !!b; }); }
function setStatus(text, active){
  if(statusEl){ statusEl.textContent=text; statusEl.classList.toggle('is-active',!!active); }
  if(enableBtn) enableBtn.hidden=!!active;
  if(disableBtn) disableBtn.hidden=!active;
  if(testBtn) testBtn.hidden=!active;
}
function post(action, data){
  var body = new URLSearchParams(); body.set('action','hamnoor_webpush_'+action); body.set('nonce',HamnoorWebPush.nonce);
  Object.keys(data||{}).forEach(function(k){ body.set(k,data[k]); });
  return fetch(HamnoorWebPush.ajax_url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(function(r){return r.json();});
}
function b64ToUint8(base64String){
  var padding='='.repeat((4-base64String.length%4)%4); var base64=(base64String+padding).replace(/-/g,'+').replace(/_/g,'/');
  var raw=atob(base64); var arr=new Uint8Array(raw.length); for(var i=0;i<raw.length;i++)arr[i]=raw.charCodeAt(i); return arr;
}
function sameBytes(a,b){
  if(!a || !b || a.length!==b.length) return false;
  for(var i=0;i<a.length;i++){ if(a[i]!==b[i]) return false; }
  return true;
}
function subscriptionKeyMatches(sub){
  try{
    if(!sub || !sub.options || !sub.options.applicationServerKey) return true; // Browser does not expose it: don't create a false stale state.
    var existing = new Uint8Array(sub.options.applicationServerKey);
    var current = b64ToUint8(HamnoorWebPush.public_key || '');
    return current.length > 0 && sameBytes(existing,current);
  }catch(e){ return true; }
}
function supported(){ return window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window; }
async function registration(){ return navigator.serviceWorker.register(HamnoorWebPush.service_worker_url,{scope:'/'}); }
async function removeOldSubscription(sub){
  if(!sub) return;
  var endpoint=sub.endpoint||'';
  try{ await sub.unsubscribe(); }catch(e){}
  try{ await post('unsubscribe',{endpoint:endpoint}); }catch(e){}
}
async function sync(){
  if(!supported()){ setStatus(HamnoorWebPush.texts.unsupported,false); if(enableBtn) enableBtn.disabled=true; return; }
  try{
    var reg=await registration(); var sub=await reg.pushManager.getSubscription();
    if(sub && Notification.permission==='granted'){
      if(!subscriptionKeyMatches(sub)){
        setStatus(HamnoorWebPush.texts.stale||HamnoorWebPush.texts.inactive,false);
        msg(HamnoorWebPush.texts.reconnect_required||HamnoorWebPush.texts.failed,'error');
      }else{
        setStatus(HamnoorWebPush.texts.active,true);
      }
    }else setStatus(HamnoorWebPush.texts.inactive,false);
  }catch(e){ setStatus(HamnoorWebPush.texts.inactive,false); msg(e.message||HamnoorWebPush.texts.failed,'error'); }
}
if(enableBtn) enableBtn.addEventListener('click',async function(){
  setBusy(true); msg(HamnoorWebPush.texts.connecting,'info');
  try{
    if(!supported()) throw new Error(HamnoorWebPush.texts.unsupported);
    var permission=Notification.permission;
    if(permission!=='granted') permission=await Notification.requestPermission();
    if(permission!=='granted') throw new Error(HamnoorWebPush.texts.permission_denied);
    var reg=await registration(); var sub=await reg.pushManager.getSubscription();
    if(sub && !subscriptionKeyMatches(sub)){
      msg(HamnoorWebPush.texts.reconnecting||HamnoorWebPush.texts.connecting,'info');
      await removeOldSubscription(sub); sub=null;
    }
    if(!sub){ sub=await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:b64ToUint8(HamnoorWebPush.public_key)}); }
    var result=await post('subscribe',{subscription:JSON.stringify(sub.toJSON())});
    if(!result.success) throw new Error(result.data&&result.data.message?result.data.message:HamnoorWebPush.texts.failed);
    setStatus(HamnoorWebPush.texts.active,true); msg(result.data.message||HamnoorWebPush.texts.active,'success');
  }catch(e){ msg(e.message||HamnoorWebPush.texts.failed,'error'); }
  setBusy(false);
});
if(disableBtn) disableBtn.addEventListener('click',async function(){
  setBusy(true); msg(HamnoorWebPush.texts.disconnecting,'info');
  try{
    var reg=await navigator.serviceWorker.getRegistration('/'); var sub=reg?await reg.pushManager.getSubscription():null; var endpoint=sub?sub.endpoint:'';
    if(sub) await sub.unsubscribe();
    var result=await post('unsubscribe',{endpoint:endpoint}); if(!result.success) throw new Error(result.data&&result.data.message?result.data.message:HamnoorWebPush.texts.failed);
    setStatus(HamnoorWebPush.texts.inactive,false); msg(result.data.message||HamnoorWebPush.texts.inactive,'success');
  }catch(e){ msg(e.message||HamnoorWebPush.texts.failed,'error'); }
  setBusy(false);
});
if(testBtn) testBtn.addEventListener('click',async function(){
  setBusy(true); msg(HamnoorWebPush.texts.testing,'info');
  try{ var result=await post('test',{}); if(!result.success) throw new Error(result.data&&result.data.message?result.data.message:HamnoorWebPush.texts.failed); msg(result.data.message||HamnoorWebPush.texts.sent,'success'); }
  catch(e){ msg(e.message||HamnoorWebPush.texts.failed,'error'); }
  setBusy(false);
});
sync();
})();
