(function(){
  'use strict';
  function dispatchCompletionExtensionFeedback(feedback){
    if (!feedback || typeof feedback !== 'object' || !Object.keys(feedback).length) { return; }
    try {
      document.dispatchEvent(new CustomEvent('hamnoor:completion-feedback',{detail:feedback}));
    } catch (error) {
      try {
        var evt = document.createEvent('CustomEvent');
        evt.initCustomEvent('hamnoor:completion-feedback',false,false,feedback);
        document.dispatchEvent(evt);
      } catch (ignore) {}
    }
  }

  var H = window.HamnoorFrontend || {};
  function qs(selector, context){ return (context || document).querySelector(selector); }
  function qsa(selector, context){ return Array.prototype.slice.call((context || document).querySelectorAll(selector)); }
  function normalizeDigits(value){ return String(value || '').replace(/[۰-۹]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);}).replace(/[٠-٩]/g,function(d){return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);}); }
  function normalizePhone(value){
    var v = normalizeDigits(value).replace(/[^0-9+]/g,'');
    if (v.indexOf('+98') === 0) { v = '0' + v.substring(3); }
    else if (v.indexOf('0098') === 0) { v = '0' + v.substring(4); }
    else if (v.indexOf('98') === 0 && v.length === 12) { v = '0' + v.substring(2); }
    else if (v.indexOf('9') === 0 && v.length === 10) { v = '0' + v; }
    return v.replace(/[^0-9]/g,'');
  }
  function validPhone(value){ return /^09[0-9]{9}$/.test(normalizePhone(value)); }
  function freshKey(){
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      try { return window.crypto.randomUUID(); } catch(e) {}
    }
    if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
      try {
        var bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40; bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = Array.prototype.map.call(bytes,function(b){return b.toString(16).padStart(2,'0');}).join('');
        return hex.slice(0,8)+'-'+hex.slice(8,12)+'-'+hex.slice(12,16)+'-'+hex.slice(16,20)+'-'+hex.slice(20);
      } catch(e) {}
    }
    return 'ctr-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + '-' + Math.random().toString(36).slice(2);
  }
  function ajax(action, data){
    var url = H.ajax_url || window.ajaxurl || '';
    if (!url) { return Promise.reject(new Error('نشانی ارتباط با سرور پیدا نشد.')); }
    var body = new URLSearchParams(); body.append('action', action); body.append('nonce', H.nonce || '');
    Object.keys(data || {}).forEach(function(key){ body.append(key, data[key] == null ? '' : data[key]); });
    return fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()})
      .then(function(response){ return response.text().then(function(raw){
        var json; try { json = JSON.parse(raw); } catch(e) { throw new Error('پاسخ سرور نامعتبر بود.'); }
        if (!response.ok || !json || json.success === false) {
          var message = json && json.data && json.data.message ? json.data.message : 'درخواست انجام نشد. لطفاً دوباره تلاش کنید.';
          var error = new Error(message); error.data = json && json.data ? json.data : null; throw error;
        }
        return json;
      }); });
  }
  function notice(target, message, type){
    if (!target) { return; }
    target.innerHTML = '';
    var box = document.createElement('div'); box.className = 'hamnoor-notice hamnoor-notice-' + (type || 'info'); box.textContent = message || ''; target.appendChild(box);
  }
  function idempotency(form){
    var field = qs('[name="idempotency_key"]', form); if (!field) { return ''; }
    if (!field.value) { field.value = freshKey(); }
    return field.value;
  }
  function rotateIdempotency(form){ var field = qs('[name="idempotency_key"]', form); if (field) { field.value = freshKey(); } }
  function setIdentity(form, data){
    var holder = qs('[data-contribution-identity]', form); if (!holder) { return; }
    holder.innerHTML = '';
    if (data && data.logged_in) {
      form.setAttribute('data-contribution-identity-mode','session');
      var card = document.createElement('div'); card.className = 'hamnoor-contribution-identity-card';
      var strong = document.createElement('strong'); strong.textContent = data.display_name || 'همراه هم‌نور';
      var phone = document.createElement('span'); phone.textContent = data.phone || '';
      var help = document.createElement('small'); help.textContent = 'این مشارکت به پنل فعلی شما متصل می‌شود.';
      card.appendChild(strong); card.appendChild(phone); card.appendChild(help); holder.appendChild(card);
    } else {
      form.setAttribute('data-contribution-identity-mode','guest');
      var nameLabel = document.createElement('label'); nameLabel.textContent = 'نام و نام خانوادگی';
      var name = document.createElement('input'); name.type = 'text'; name.name = 'name'; name.autocomplete = 'name'; name.required = true; nameLabel.appendChild(name);
      var phoneLabel = document.createElement('label'); phoneLabel.textContent = 'شماره موبایل';
      var phoneInput = document.createElement('input'); phoneInput.type = 'tel'; phoneInput.name = 'phone'; phoneInput.inputMode = 'tel'; phoneInput.autocomplete = 'tel'; phoneInput.placeholder = '09123456789'; phoneInput.required = true; phoneLabel.appendChild(phoneInput);
      holder.appendChild(nameLabel); holder.appendChild(phoneLabel);
    }
    qsa('button[type="submit"]', form).forEach(function(button){ button.disabled = false; });
  }
  function loadIdentity(form){
    var holder = qs('[data-contribution-identity]', form); var button = qs('button[type="submit"]', form);
    if (button) { button.disabled = true; }
    notice(holder,'در حال شناسایی پنل هم‌نور…','info');
    ajax('hamnoor_claim_identity',{fresh:Date.now()}).then(function(res){ setIdentity(form,res.data || {}); }).catch(function(error){
      notice(holder,error.message || 'شناسایی پنل انجام نشد. صفحه را تازه‌سازی کنید.','error');
    });
  }
  function formatPercent(value){ var n = Number(value || 0); return (Math.round(n * 100) / 100).toString().replace(/\.0+$/,'') + '%'; }
  function updateProgress(page, data){
    if (!page || !data || !data.progress) { return; }
    var p = data.progress, strategy = (qs('[data-contribution-campaign]',page) || {}).getAttribute ? qs('[data-contribution-campaign]',page).getAttribute('data-contribution-strategy') : '';
    var unit = strategy === 'numeric_contribution' ? 'مرتبه' : 'قرائت کامل';
    var percent = qs('[data-contribution-percent]',page); if (percent) { percent.textContent = formatPercent(p.percentage); }
    var bar = qs('[data-contribution-bar]',page); if (bar) { bar.style.width = Math.max(0,Math.min(100,Number(p.percentage || 0))) + '%'; }
    var text = qs('[data-contribution-progress-text]',page); if (text) { text.textContent = (p.current || 0) + ' از ' + (p.target || 0) + ' ' + unit + ' ثبت شده است'; }
    var quantity = qs('[name="quantity"]',page); var limit = qs('[data-contribution-limit]',page);
    if (quantity && strategy === 'numeric_contribution' && data.next_max > 0) { quantity.max = String(data.next_max); quantity.value = '1'; }
    if (limit && data.next_max > 0) { limit.textContent = 'حداکثر قابل ثبت در این نوبت: ' + data.next_max; }
    if (data.completed) {
      var status = qs('[data-contribution-status]',page); if (status) { status.className = 'hamnoor-badge hamnoor-status-completed'; status.textContent = 'تکمیل شد'; }
      var statusHolder = qs('[data-contribution-status-notice]',page); notice(statusHolder,'هدف این پویش تکمیل شده است. خداوند از همه همراهان قبول فرماید.','success');
      var action = qs('.hamnoor-contribution-action-card',page); if (action) { action.remove(); }
    }
  }
  function submit(form, submitter){
    var mode = form.getAttribute('data-contribution-identity-mode') || '';
    var name = '', phone = '';
    if (mode === 'guest') {
      var nameField = qs('[name="name"]',form), phoneField = qs('[name="phone"]',form);
      name = nameField ? nameField.value.trim() : ''; phone = phoneField ? normalizePhone(phoneField.value) : '';
      if (!name) { if (nameField) { nameField.focus(); } notice(qs('.hamnoor-ajax-message',form),'نام و نام خانوادگی را وارد کنید.','error'); return; }
      if (!validPhone(phone)) { if (phoneField) { phoneField.focus(); } notice(qs('.hamnoor-ajax-message',form),'شماره موبایل واردشده معتبر نیست.','error'); return; }
    }
    var quantityField = qs('[name="quantity"]',form); var quantity = parseInt(normalizeDigits(quantityField ? quantityField.value : '1'),10) || 0;
    if (quantity < 1) { if (quantityField) { quantityField.focus(); } notice(qs('.hamnoor-ajax-message',form),'تعداد مشارکت باید بیشتر از صفر باشد.','error'); return; }
    var button = submitter || qs('button[type="submit"]',form), old = button ? button.textContent : '';
    var intent = button && button.getAttribute('data-contribution-intent') ? button.getAttribute('data-contribution-intent') : ((qs('[name="intent"]',form) || {}).value || 'record');
    var buttons = qsa('button[type="submit"]',form);
    buttons.forEach(function(item){ item.disabled = true; });
    if (button) { button.setAttribute('aria-busy','true'); button.textContent = 'در حال ثبت…'; }
    var box = qs('.hamnoor-ajax-message',form); notice(box,'در حال ثبت مشارکت…','info');
    ajax('hamnoor_record_contribution',{
      khatm_token:(qs('[name="khatm_token"]',form) || {}).value || '',
      quantity:quantity,
      idempotency_key:idempotency(form),
      name:name,
      phone:phone,
      website:(qs('[name="website"]',form) || {}).value || ''
      ,intent:intent
      ,contribution_public_id:(qs('[name="contribution_public_id"]',form) || {}).value || ''
    }).then(function(res){
      dispatchCompletionExtensionFeedback(res.data && res.data.extension_feedback);
      var page = form.closest('[data-hamnoor-contribution-page]');
      notice(box,res.data && res.data.message ? res.data.message : 'مشارکت ثبت شد.','success');
      updateProgress(page,res.data || {});
      if (res.data && res.data.pending) {
        var intentField = qs('[name="intent"]',form), publicField = qs('[name="contribution_public_id"]',form);
        if (intentField) { intentField.value = 'complete_dua'; }
        if (publicField) { publicField.value = res.data.contribution_public_id || ''; }
        qsa('[data-contribution-intent="select_dua"]',form).forEach(function(item){ item.remove(); });
        var primary = qs('[data-contribution-intent="record"]',form);
        if (primary) { primary.setAttribute('data-contribution-intent','complete_dua'); primary.textContent = 'قرائت کامل شد؛ ثبت کن'; }
        document.dispatchEvent(new CustomEvent('hamnoor:dua-pending',{detail:{contribution_public_id:res.data.contribution_public_id||''}}));
      }
      if (!(res.data && res.data.completed)) {
        rotateIdempotency(form);
        setIdentity(form,{logged_in:true,display_name:res.data && res.data.user ? res.data.user.display_name : '',phone:res.data && res.data.user ? res.data.user.phone : ''});
        buttons = qsa('button[type="submit"]',form); buttons.forEach(function(item){ item.disabled = false; });
        if (button && document.body.contains(button)) { button.removeAttribute('aria-busy'); if (!(res.data && res.data.pending)) { button.textContent = old; } }
      }
    }).catch(function(error){
      notice(box,error.message || 'ثبت مشارکت انجام نشد.','error');
      buttons.forEach(function(item){ item.disabled = false; });
      if (button) { button.removeAttribute('aria-busy'); button.textContent = old; }
      if (error.data && error.data.code === 'hamnoor_session_mobile_mismatch') { loadIdentity(form); }
    });
  }
  function init(form){
    if (!form || form.dataset.hamnoorContributionReady === '1') { return; }
    form.dataset.hamnoorContributionReady = '1'; rotateIdempotency(form); loadIdentity(form);
    form.addEventListener('submit',function(event){ event.preventDefault(); submit(form,event.submitter || null); });
  }
  document.addEventListener('DOMContentLoaded',function(){ qsa('.hamnoor-contribution-form').forEach(init); });
})();
