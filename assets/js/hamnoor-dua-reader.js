(function(){
  'use strict';
  var reader=document.querySelector('[data-dua-reader]'); if(!reader){return;}
  var themes=['light','sepia','dark'], scale=parseFloat(localStorage.getItem('hamnoor-dua-scale')||'1')||1;
  function theme(value){value=themes.indexOf(value)>=0?value:'light';reader.setAttribute('data-dua-theme',value);localStorage.setItem('hamnoor-dua-theme',value);}
  function applyScale(){scale=Math.max(.78,Math.min(1.38,scale));reader.style.setProperty('--hamnoor-dua-scale',String(scale));localStorage.setItem('hamnoor-dua-scale',String(scale));}
  theme(localStorage.getItem('hamnoor-dua-theme')||'light'); applyScale();
  var wake=null,wakeWanted=false;
  function wakeButton(){var b=reader.querySelector('[data-dua-reader-action="wake"]');if(b){b.setAttribute('aria-pressed',wakeWanted?'true':'false');}}
  async function requestWake(){if(!('wakeLock' in navigator)){return;}try{wakeWanted=true;wake=await navigator.wakeLock.request('screen');wake.addEventListener('release',function(){wake=null;wakeButton();});}catch(e){wakeWanted=false;}wakeButton();}
  async function releaseWake(){wakeWanted=false;try{if(wake){await wake.release();}}catch(e){}wake=null;wakeButton();}
  reader.addEventListener('click',function(event){
    var button=event.target.closest('[data-dua-reader-action]');
    if(button){var action=button.getAttribute('data-dua-reader-action');if(action==='theme'){var i=themes.indexOf(reader.getAttribute('data-dua-theme'));theme(themes[(i+1)%themes.length]);}else if(action==='translation'){var hidden=reader.classList.toggle('is-translation-hidden');button.setAttribute('aria-pressed',hidden?'false':'true');}else if(action==='font-up'||action==='font-down'){scale+=action==='font-up'?.08:-.08;applyScale();}else if(action==='focus'){var active=reader.classList.toggle('is-focus-mode');document.body.classList.toggle('hamnoor-dua-focus',active);button.setAttribute('aria-pressed',active?'true':'false');}else if(action==='wake'){wakeWanted?releaseWake():requestWake();}return;}
    var proxy=event.target.closest('[data-dua-pending-proxy]');if(proxy&&proxy.tagName==='BUTTON'){var real=reader.querySelector('[data-contribution-intent="select_dua"]');if(real&&!real.disabled){real.click();}else{reader.querySelector('.hamnoor-contribution-action-card')?.scrollIntoView({behavior:'smooth'});}}
  });
  document.addEventListener('visibilitychange',function(){if(document.visibilityState==='visible'&&wakeWanted&&!wake){requestWake();}});
  var bar=reader.querySelector('.hamnoor-dua-reading-progress span'),ticking=false;
  function frame(){ticking=false;var max=Math.max(1,document.documentElement.scrollHeight-innerHeight),ratio=Math.max(0,Math.min(1,scrollY/max));if(bar){bar.style.transform='scaleX('+ratio+')';}}
  addEventListener('scroll',function(){if(!ticking){ticking=true;requestAnimationFrame(frame);}},{passive:true});frame();
  document.addEventListener('hamnoor:dua-pending',function(){reader.querySelectorAll('[data-dua-pending-proxy]').forEach(function(el){el.textContent='برای بعد انتخاب شده است';el.classList.add('is-pending');el.disabled=true;});});
}());
