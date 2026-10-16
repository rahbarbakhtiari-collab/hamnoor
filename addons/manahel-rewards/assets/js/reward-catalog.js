(function(){
'use strict';
function fa(value){try{return new Intl.NumberFormat(document.documentElement.lang||'fa-IR').format(Number(value)||0);}catch(e){return String(Number(value)||0);}}
function init(catalog){
  var cards=Array.prototype.slice.call(catalog.querySelectorAll('.mr-reward-card'));
  var buttons=Array.prototype.slice.call(catalog.querySelectorAll('[data-mr-filter]'));
  var empty=catalog.querySelector('.mr-reward-filter-empty');
  function apply(filter){
    var visible=0;
    cards.forEach(function(card){
      var state=card.getAttribute('data-mr-state')||'';
      var mode=card.getAttribute('data-mr-mode')||'';
      var show=filter==='all'||filter===state||filter===mode;
      card.hidden=!show;if(show){visible++;}
    });
    if(empty){empty.hidden=visible!==0;}
    buttons.forEach(function(b){var active=(b.getAttribute('data-mr-filter')===filter);b.classList.toggle('is-active',active);b.setAttribute('aria-pressed',active?'true':'false');});
  }
  buttons.forEach(function(b){b.addEventListener('click',function(){apply(b.getAttribute('data-mr-filter')||'all');});});
  apply('all');

  var dialog=catalog.querySelector('[data-mr-redeem-dialog]');
  if(!dialog){return;}
  var title=dialog.querySelector('[data-mr-dialog-title]');
  var costNode=dialog.querySelector('[data-mr-dialog-cost]');
  var balanceNode=dialog.querySelector('[data-mr-dialog-balance]');
  var afterNode=dialog.querySelector('[data-mr-dialog-after]');
  var confirm=dialog.querySelector('[data-mr-redeem-confirm]');
  var activeForm=null;
  var lastFocus=null;
  function focusables(){
    return Array.prototype.slice.call(dialog.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')).filter(function(node){return !node.hidden&&node.getClientRects().length>0;});
  }
  function close(restoreFocus){
    dialog.hidden=true;document.body.classList.remove('mr-dialog-open');activeForm=null;
    if(restoreFocus!==false&&lastFocus&&typeof lastFocus.focus==='function'&&document.contains(lastFocus)){lastFocus.focus();}
    lastFocus=null;
  }
  function open(form){
    activeForm=form;
    lastFocus=document.activeElement&&typeof document.activeElement.focus==='function'?document.activeElement:null;
    var cost=parseInt(form.getAttribute('data-reward-cost')||'0',10)||0;
    var balance=parseInt(form.getAttribute('data-current-balance')||'0',10)||0;
    var reward=form.getAttribute('data-reward-title')||'جایزه';
    if(title){title.textContent=reward;}
    if(costNode){costNode.textContent=fa(cost)+' امتیاز';}
    if(balanceNode){balanceNode.textContent=fa(balance)+' امتیاز';}
    if(afterNode){afterNode.textContent=fa(Math.max(0,balance-cost))+' امتیاز';}
    dialog.hidden=false;document.body.classList.add('mr-dialog-open');
    if(confirm){window.setTimeout(function(){confirm.focus();},10);}
  }
  catalog.querySelectorAll('[data-mr-redeem-form]').forEach(function(form){
    form.addEventListener('submit',function(event){
      if(form.getAttribute('data-mr-confirmed')==='1'){form.removeAttribute('data-mr-confirmed');return;}
      if(!form.reportValidity()){event.preventDefault();return;}
      event.preventDefault();open(form);
    });
  });
  dialog.querySelectorAll('[data-mr-redeem-close]').forEach(function(node){node.addEventListener('click',function(){close(true);});});
  if(confirm){confirm.addEventListener('click',function(){if(!activeForm){close(true);return;}var form=activeForm;form.setAttribute('data-mr-confirmed','1');close(false);if(typeof form.requestSubmit==='function'){form.requestSubmit();}else{form.submit();}});}
  document.addEventListener('keydown',function(e){
    if(dialog.hidden){return;}
    if(e.key==='Escape'){e.preventDefault();close(true);return;}
    if(e.key!=='Tab'){return;}
    var nodes=focusables();
    if(!nodes.length){e.preventDefault();if(confirm){confirm.focus();}return;}
    var first=nodes[0],last=nodes[nodes.length-1];
    if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
    else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
  });
}
document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('[data-mr-reward-catalog]').forEach(init);});
})();
