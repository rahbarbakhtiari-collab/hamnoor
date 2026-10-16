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
  var lastFocusByModal = new WeakMap();
  var activeModal = null;

  function qs(selector, context){ return (context || document).querySelector(selector); }
  function qsa(selector, context){ return Array.prototype.slice.call((context || document).querySelectorAll(selector)); }
  function closest(el, selector){ return el && el.closest ? el.closest(selector) : null; }
  function text(key, fallback){ return (H.texts && H.texts[key]) || fallback || ''; }
  function debug(){
    if (!H.debug) { return; }
    try { console.warn.apply(console, ['[Hamnoor AJAX]'].concat(Array.prototype.slice.call(arguments))); } catch(e) {}
  }

  function normalizeDigits(v){
    return String(v || '')
      .replace(/[۰-۹]/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
      .replace(/[٠-٩]/g, function(d){ return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
  }
  function normalizePhone(v){
    v = normalizeDigits(v).replace(/[^0-9+]/g, '');
    if (v.indexOf('+98') === 0) { v = '0' + v.substring(3); }
    else if (v.indexOf('0098') === 0) { v = '0' + v.substring(4); }
    else if (v.indexOf('98') === 0 && v.length === 12) { v = '0' + v.substring(2); }
    else if (v.indexOf('9') === 0 && v.length === 10) { v = '0' + v; }
    return v.replace(/[^0-9]/g, '');
  }
  function validPhone(v){ return /^09[0-9]{9}$/.test(normalizePhone(v)); }

  function div(a, b){ return ~~(a / b); }
  function jalaliToGregorian(jy, jm, jd){
    jy = parseInt(jy, 10); jm = parseInt(jm, 10); jd = parseInt(jd, 10);
    if (!jy || !jm || !jd || jm < 1 || jm > 12 || jd < 1 || jd > 31) { return null; }
    jy += 1595;
    var days = -355668 + (365 * jy) + (div(jy, 33) * 8) + div(((jy % 33) + 3), 4) + jd + (jm < 7 ? ((jm - 1) * 31) : (((jm - 7) * 30) + 186));
    var gy = 400 * div(days, 146097); days %= 146097;
    if (days > 36524) { gy += 100 * div(--days, 36524); days %= 36524; if (days >= 365) { days++; } }
    gy += 4 * div(days, 1461); days %= 1461;
    if (days > 365) { gy += div((days - 1), 365); days = (days - 1) % 365; }
    var gd = days + 1;
    var sal_a = [0,31,((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
    var gm = 1;
    while (gm <= 12 && gd > sal_a[gm]) { gd -= sal_a[gm]; gm++; }
    return {gy: gy, gm: gm, gd: gd};
  }
  function jalaliInputTimestamp(value){
    value = normalizeDigits(value || '').trim();
    var m = value.match(/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})(?:\s+(\d{1,2}):(\d{1,2}))?/);
    if (!m) { return 0; }
    var g = jalaliToGregorian(m[1], m[2], m[3]);
    if (!g) { return 0; }
    var h = m[4] ? parseInt(m[4], 10) : 23;
    var mi = m[5] ? parseInt(m[5], 10) : 59;
    return Math.floor(new Date(g.gy, g.gm - 1, g.gd, h, mi, 0).getTime() / 1000);
  }

  function clearFieldErrors(scope){
    scope = scope || document;
    qsa('.hamnoor-field-has-error', scope).forEach(function(el){ el.classList.remove('hamnoor-field-has-error'); });
    qsa('[aria-invalid="true"]', scope).forEach(function(el){ el.removeAttribute('aria-invalid'); });
    qsa('.hamnoor-field-error', scope).forEach(function(el){ if (el.parentNode) { el.parentNode.removeChild(el); } });
    qsa('.hamnoor-form-summary', scope).forEach(function(el){ if (el.parentNode) { el.parentNode.removeChild(el); } });
  }

  function setFieldError(field, msg){
    if (!field) { return; }
    var holder = closest(field, 'label') || field.parentNode;
    if (!holder) { return; }
    holder.classList.add('hamnoor-field-has-error');
    field.setAttribute('aria-invalid', 'true');
    var error = qs('.hamnoor-field-error', holder);
    if (!error) {
      error = document.createElement('span');
      error.className = 'hamnoor-field-error';
      error.setAttribute('aria-live', 'polite');
      holder.appendChild(error);
    }
    error.textContent = msg || text('form_incomplete', 'لطفاً خطاهای مشخص‌شده را اصلاح کنید.');
  }


  function clearSingleFieldError(field){
    if (!field) { return; }
    field.removeAttribute('aria-invalid');
    var holder = closest(field, 'label') || field.parentNode;
    if (!holder) { return; }
    holder.classList.remove('hamnoor-field-has-error');
    qsa('.hamnoor-field-error', holder).forEach(function(el){ if (el.parentNode) { el.parentNode.removeChild(el); } });
  }

  function applyFieldErrors(form, errors){
    if (!form || !errors) { return; }
    Object.keys(errors).forEach(function(name){
      var field = qs('[name="' + name + '"]', form) || qs('[name="hamnoor_' + name + '"]', form);
      setFieldError(field, errors[name]);
    });
  }

  function formSummary(form, msg){
    if (!form) { return null; }
    var old = qs('.hamnoor-form-summary', form);
    if (old && old.parentNode) { old.parentNode.removeChild(old); }
    var box = document.createElement('div');
    box.className = 'hamnoor-notice hamnoor-notice-error hamnoor-form-summary';
    box.setAttribute('aria-live', 'polite');
    box.textContent = msg || text('form_incomplete', 'لطفاً خطاهای مشخص‌شده را اصلاح کنید.');
    form.insertBefore(box, form.firstChild);
    return box;
  }

  function clearWizardGeneralMessages(form){
    if (!form) { return; }
    qsa('.hamnoor-form-summary', form).forEach(function(el){ if (el.parentNode) { el.parentNode.removeChild(el); } });
    qsa('.hamnoor-ajax-message', form).forEach(function(el){ el.innerHTML = ''; });
  }

  function ensureMessageBox(scope){
    scope = scope || document;
    var box = qs('.hamnoor-ajax-message', scope);
    if (!box) {
      box = document.createElement('div');
      box.className = 'hamnoor-ajax-message';
      box.setAttribute('aria-live', 'polite');
      if (scope.appendChild) { scope.appendChild(box); }
    }
    return box;
  }

  function showNotice(target, msg, type){
    if (!target) { return null; }
    target.innerHTML = '';
    var box = document.createElement('div');
    box.className = 'hamnoor-notice hamnoor-notice-' + (type || 'info');
    box.setAttribute('aria-live', 'polite');
    box.textContent = msg || '';
    target.appendChild(box);
    return box;
  }

  function disableButton(btn, loadingText){
    if (!btn) { return function(){}; }
    var oldText = btn.textContent;
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    btn.textContent = loadingText || btn.getAttribute('data-loading-text') || text('loading', 'در حال ثبت...');
    return function(){
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
      btn.textContent = oldText;
    };
  }

  function hamnoorAjax(action, data, options){
    options = options || {};
    data = data || {};
    var ajaxUrl = H.ajax_url || (window.ajaxurl || '');
    if (!ajaxUrl) {
      return Promise.reject({message: 'نشانی ارتباط با سرور پیدا نشد.'});
    }
    var body = new URLSearchParams();
    body.append('action', action);
    body.append('nonce', H.nonce || '');
    Object.keys(data).forEach(function(key){
      if (key === 'action' || key === 'nonce') { return; }
      var value = data[key];
      if (value === undefined || value === null) { value = ''; }
      body.append(key, value);
    });

    var controller = window.AbortController ? new AbortController() : null;
    var timeoutMs = options.timeout || 20000;
    var timer = controller ? setTimeout(function(){ controller.abort(); }, timeoutMs) : null;

    return fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString(),
      signal: controller ? controller.signal : undefined
    }).then(function(response){
      if (timer) { clearTimeout(timer); }
      return response.text().then(function(raw){
        var json = null;
        try { json = JSON.parse(raw); }
        catch(e) {
          debug('Invalid JSON for action ' + action, raw, e);
          throw {message: 'پاسخ سرور نامعتبر بود. لطفاً دوباره تلاش کنید.', raw: raw, error: e};
        }
        if (!response.ok) {
          debug('HTTP error for action ' + action, response.status, json);
          throw {message: (json && json.data && json.data.message) || 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.', response: response, json: json};
        }
        if (!json || json.success === false) {
          debug('JSON error for action ' + action, json);
          throw {message: (json && json.data && json.data.message) || 'درخواست انجام نشد. لطفاً دوباره تلاش کنید.', data: json ? json.data : null, json: json};
        }
        return json;
      });
    }).catch(function(error){
      if (timer) { clearTimeout(timer); }
      if (error && error.name === 'AbortError') { error = {message: 'زمان ارتباط با سرور تمام شد. لطفاً دوباره تلاش کنید.'}; }
      debug('AJAX failure for action ' + action, error);
      throw error;
    });
  }

  function messageFromError(error, fallback){
    return (error && error.message) || (error && error.data && error.data.message) || fallback || 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.';
  }

  function copyText(value, done){
    function fallback(){
      var area = document.createElement('textarea');
      area.value = value || '';
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.appendChild(area);
      area.select();
      try { document.execCommand('copy'); } catch(e) { debug('copy fallback failed', e); }
      document.body.removeChild(area);
      if (done) { done(); }
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value || '').then(done || function(){}).catch(fallback);
    } else { fallback(); }
  }

  function shareClipboardText(box){
    var ready = box.getAttribute('data-hamnoor-clipboard') || '';
    if (ready) { return ready; }
    var textValue = box.getAttribute('data-hamnoor-text') || '';
    var urlValue = box.getAttribute('data-hamnoor-url') || '';
    return [textValue, urlValue].filter(Boolean).join('\n\n');
  }

  function prepareShareImage(imageUrl){
    if (!imageUrl || !window.fetch || !window.File || !navigator.canShare) { return Promise.resolve(null); }
    return fetch(imageUrl, {credentials: 'same-origin'}).then(function(response){
      if (!response.ok) { throw new Error('share image fetch failed'); }
      return response.blob();
    }).then(function(blob){
      if (!blob || !blob.size || (blob.type && blob.type.indexOf('image/') !== 0)) { return null; }
      var cleanUrl = imageUrl.split('#')[0].split('?')[0];
      var fileName = cleanUrl.substring(cleanUrl.lastIndexOf('/') + 1) || 'hamnoor-khatm-image';
      if (fileName.indexOf('.') === -1) {
        var extension = (blob.type.split('/')[1] || 'jpg').replace('jpeg', 'jpg').replace(/[^a-z0-9]/gi, '');
        fileName += '.' + extension;
      }
      var file = new File([blob], fileName, {type: blob.type || 'image/jpeg'});
      try { return navigator.canShare({files: [file]}) ? file : null; }
      catch (error) { debug('share files unsupported', error); return null; }
    }).catch(function(error){
      debug('share image unavailable; using link preview', error);
      return null;
    });
  }

  function shareKhatm(button, box, data){
    if (!navigator.share) {
      copyText(shareClipboardText(box), function(){
        var old = button.textContent;
        button.textContent = 'کپی شد';
        setTimeout(function(){ button.textContent = old || 'اشتراک‌گذاری'; }, 1600);
      });
      return;
    }
    button.disabled = true;
    prepareShareImage(box.getAttribute('data-hamnoor-image') || '').then(function(file){
      var payload = {title: data.title, text: data.text, url: data.url};
      if (file) {
        var filePayload = {title: data.title, text: data.text, url: data.url, files: [file]};
        try { if (navigator.canShare(filePayload)) { payload = filePayload; } }
        catch (error) { debug('combined file share unsupported', error); }
      }
      var promise;
      try { promise = navigator.share(payload); }
      catch (error) { return Promise.reject(error); }
      return promise;
    }).catch(function(error){
      if (error && (error.name === 'AbortError' || /cancel/i.test(error.message || ''))) { return; }
      debug('share failed', error);
      showNotice(ensureMessageBox(box), 'اشتراک‌گذاری انجام نشد. لطفاً دوباره تلاش کنید یا متن دعوت را کپی کنید.', 'error');
    }).then(function(){ button.disabled = false; });
  }

  function openModal(modal, trigger){
    if (!modal) { return; }
    lastFocusByModal.set(modal, trigger || document.activeElement);
    activeModal = modal;
    modal.hidden = false;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('hamnoor-modal-open');
    document.body.classList.add('hamnoor-modal-open');
    var feedback = qs('.hamnoor-modal-feedback', modal);
    if (feedback) { feedback.innerHTML = ''; }
    var focusTarget = qs('[autofocus], input[name="name"], input, button, a[href]', modal);
    if (focusTarget) { setTimeout(function(){ try { focusTarget.focus(); } catch(e) {} }, 60); }
  }

  function closeModal(modal){
    if (!modal) { return; }
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.documentElement.classList.remove('hamnoor-modal-open');
    document.body.classList.remove('hamnoor-modal-open');
    modal.hidden = true;
    if (activeModal === modal) { activeModal = null; }
    var focus = lastFocusByModal.get(modal);
    if (focus && focus.focus) { try { focus.focus(); } catch(e) {} }
  }

  function claimModalBody(modal){
    return modal ? (qs('[data-hamnoor-modal-body]', modal) || qs('.hamnoor-modal-dialog', modal)) : null;
  }

  function rememberClaimModalBody(modal){
    var body = claimModalBody(modal);
    if (modal && body && !modal.dataset.hamnoorClaimTemplate) {
      modal.dataset.hamnoorClaimTemplate = body.innerHTML;
    }
  }

  function restoreClaimModalBody(modal){
    var body = claimModalBody(modal);
    if (modal && body && modal.dataset.hamnoorClaimTemplate && !qs('.hamnoor-claim-form', body)) {
      body.innerHTML = modal.dataset.hamnoorClaimTemplate;
    }
  }

  function claimIdentitySubmitButton(form){
    return form ? qs('button[type="submit"]', form) : null;
  }

  function claimIdentityHolder(form){
    if (!form) { return null; }
    /* Remove any identity fields left in an old cached 3.4.0 modal before it is shown. */
    qsa('[name="name"], [name="phone"]', form).forEach(function(field){
      var label = closest(field, 'label');
      var target = label || field;
      if (target && target.parentNode) { target.parentNode.removeChild(target); }
    });
    var holder = qs('[data-hamnoor-claim-identity]', form);
    if (!holder) {
      holder = document.createElement('div');
      holder.className = 'hamnoor-claim-identity';
      holder.setAttribute('data-hamnoor-claim-identity', '');
      holder.setAttribute('aria-live', 'polite');
      var actions = qs('.hamnoor-modal-actions', form);
      form.insertBefore(holder, actions || null);
    }
    return holder;
  }

  function claimIdentityLoading(form){
    if (!form) { return; }
    form.setAttribute('data-hamnoor-identity-mode', 'loading');
    var holder = claimIdentityHolder(form);
    if (holder) {
      holder.innerHTML = '';
      var loading = document.createElement('div');
      loading.className = 'hamnoor-identity-loading';
      loading.textContent = 'در حال شناسایی پنل هم‌نور...';
      holder.appendChild(loading);
    }
    var btn = claimIdentitySubmitButton(form);
    if (btn) { btn.disabled = true; }
  }

  function appendClaimGuestField(holder, name, labelText, type, autocomplete, placeholder){
    var label = document.createElement('label');
    label.textContent = labelText;
    var input = document.createElement('input');
    input.type = type;
    input.name = name;
    input.required = true;
    input.autocomplete = autocomplete;
    if (placeholder) { input.placeholder = placeholder; }
    if (name === 'phone') { input.inputMode = 'tel'; }
    label.appendChild(input);
    holder.appendChild(label);
    return input;
  }

  function renderClaimIdentity(form, data){
    if (!form) { return; }
    var holder = claimIdentityHolder(form);
    if (!holder) { return; }
    holder.innerHTML = '';
    if (data && data.logged_in) {
      form.setAttribute('data-hamnoor-identity-mode', 'locked');
      var card = document.createElement('div');
      card.className = 'hamnoor-identity-card';

      var nameRow = document.createElement('div');
      nameRow.className = 'hamnoor-identity-row';
      var nameLabel = document.createElement('span');
      nameLabel.textContent = 'مشارکت‌کننده:';
      var nameValue = document.createElement('strong');
      nameValue.textContent = data.display_name || 'کاربر هم‌نور';
      nameRow.appendChild(nameLabel);
      nameRow.appendChild(nameValue);

      var phoneRow = document.createElement('div');
      phoneRow.className = 'hamnoor-identity-row';
      var phoneLabel = document.createElement('span');
      phoneLabel.textContent = 'شماره موبایل:';
      var phoneValue = document.createElement('strong');
      phoneValue.setAttribute('dir', 'ltr');
      phoneValue.textContent = data.phone || 'ثبت‌شده در پنل';
      phoneRow.appendChild(phoneLabel);
      phoneRow.appendChild(phoneValue);

      var help = document.createElement('small');
      help.className = 'hamnoor-help';
      help.textContent = 'این سهم فقط به همین حساب متصل می‌شود. تغییر نام یا شماره از بخش مشخصات انجام می‌شود.';

      card.appendChild(nameRow);
      card.appendChild(phoneRow);
      card.appendChild(help);
      holder.appendChild(card);
    } else {
      form.setAttribute('data-hamnoor-identity-mode', 'guest');
      appendClaimGuestField(holder, 'name', 'نام و نام خانوادگی', 'text', 'name', '');
      appendClaimGuestField(holder, 'phone', 'شماره موبایل', 'tel', 'tel', '09123456789');
    }
    var btn = claimIdentitySubmitButton(form);
    if (btn) { btn.disabled = false; }
  }

  function renderClaimIdentityError(form, error){
    if (!form) { return; }
    form.setAttribute('data-hamnoor-identity-mode', 'error');
    var holder = claimIdentityHolder(form);
    if (!holder) { return; }
    holder.innerHTML = '';
    var box = document.createElement('div');
    box.className = 'hamnoor-notice hamnoor-notice-error';
    box.textContent = messageFromError(error, 'شناسایی پنل انجام نشد. دوباره تلاش کنید.');
    var retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'hamnoor-btn hamnoor-btn-secondary hamnoor-identity-retry';
    retry.textContent = 'تلاش دوباره';
    retry.addEventListener('click', function(){ loadClaimIdentity(closest(form, '.hamnoor-modal')); });
    holder.appendChild(box);
    holder.appendChild(retry);
    var btn = claimIdentitySubmitButton(form);
    if (btn) { btn.disabled = true; }
  }

  function loadClaimIdentity(modal){
    var form = modal ? qs('.hamnoor-claim-form', modal) : null;
    if (!form) { return; }
    var requestId = String(Date.now()) + String(Math.random());
    form.dataset.hamnoorIdentityRequest = requestId;
    claimIdentityLoading(form);
    hamnoorAjax('hamnoor_claim_identity', {fresh: Date.now(), khatm_token: (qs('[name="khatm_token"]', form) || {}).value || ''}).then(function(res){
      if (form.dataset.hamnoorIdentityRequest !== requestId) { return; }
      renderClaimIdentity(form, res.data || {});
      applyQuotaState(res.data && res.data.quota_state);
    }).catch(function(error){
      if (form.dataset.hamnoorIdentityRequest !== requestId) { return; }
      renderClaimIdentityError(form, error);
    });
  }

  function freshClaimIdempotencyKey(){
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      try { return window.crypto.randomUUID(); } catch (e) {}
    }
    if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
      try {
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = Array.prototype.map.call(bytes, function(b){ return b.toString(16).padStart(2, '0'); }).join('');
        return hex.slice(0,8) + '-' + hex.slice(8,12) + '-' + hex.slice(12,16) + '-' + hex.slice(16,20) + '-' + hex.slice(20);
      } catch (e) {}
    }
    return 'claim-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + '-' + Math.random().toString(36).slice(2);
  }

  function rotateClaimIdempotencyKey(modal){
    var input = modal ? qs('[name="idempotency_key"]', modal) : null;
    if (input) { input.value = freshClaimIdempotencyKey(); }
  }

  function setClaimModal(mode, trigger){
    var modal = qs('#hamnoor-claim-modal');
    if (!modal) { return; }
    rememberClaimModalBody(modal);
    restoreClaimModalBody(modal);
    // One modal open == one logical claim attempt. Retries inside the same open
    // keep the same key, while reopening the chooser starts a genuinely new
    // request so a previous successful claim is never replayed.
    rotateClaimIdempotencyKey(modal);
    var partInput = qs('[name="part_id"]', modal);
    var modeInput = qs('[name="claim_mode"]', modal);
    var randomInput = qs('[name="random_free"]', modal);
    var selectedTitle = qs('.hamnoor-selected-part-title', modal);

    if (mode === 'manual') {
      if (partInput) { partInput.value = trigger.getAttribute('data-part-id') || ''; }
      if (modeInput) { modeInput.value = 'manual'; }
      if (randomInput) { randomInput.value = ''; }
      if (selectedTitle) {
        selectedTitle.textContent = text('claim_manual_desc', 'شما در حال انتخاب این سهم هستید: {PART_TITLE}').replace('{PART_TITLE}', trigger.getAttribute('data-part-title') || '');
      }
    } else if (mode === 'next') {
      if (partInput) { partInput.value = ''; }
      if (modeInput) { modeInput.value = 'next'; }
      if (randomInput) { randomInput.value = ''; }
      if (selectedTitle) { selectedTitle.textContent = text('claim_next_desc', 'اولین سهم آزاد بعدی برای شما ثبت می‌شود.'); }
    } else if (mode === 'random') {
      if (partInput) { partInput.value = ''; }
      if (modeInput) { modeInput.value = 'random'; }
      if (randomInput) { randomInput.value = '1'; }
      if (selectedTitle) { selectedTitle.textContent = text('claim_random_desc', 'یک سهم آزاد به‌صورت تصادفی برای شما انتخاب می‌شود.'); }
    }
    loadClaimIdentity(modal);
    openModal(modal, trigger);
  }

  function updateProgress(progress, status){
    if (!progress) { return; }
    qsa('[data-progress-percent]').forEach(function(el){ el.textContent = (progress.percent || 0) + '%'; });
    qsa('[data-progress-bar]').forEach(function(el){ el.style.width = (progress.percent || 0) + '%'; });
    qsa('[data-progress-text]').forEach(function(el){ el.textContent = (progress.done || 0) + ' از ' + (progress.total || 0) + ' سهم خوانده شده است'; });
    qsa('[data-progress-available]').forEach(function(el){ el.textContent = 'سهم آزاد: ' + (progress.available || 0); });
    if (status === 'completed') {
      qsa('.hamnoor-primary-actions .hamnoor-action-buttons').forEach(function(el){
        el.innerHTML = '<div class="hamnoor-notice hamnoor-notice-success">' + text('complete_success', 'این پویش با همراهی شرکت‌کنندگان تکمیل شد.') + '</div>';
      });
      qsa('.hamnoor-status-active').forEach(function(el){
        el.classList.remove('hamnoor-status-active');
        el.classList.add('hamnoor-status-completed');
        el.textContent = 'تکمیل شد';
      });
    }
  }

  function markCardDone(card, msg, addMessage, data){
    if (!card) { return; }
    card.classList.remove('hamnoor-part-claimed', 'hamnoor-part-available', 'hamnoor-part-card--active', 'hamnoor-part-card--ready');
    card.classList.add('hamnoor-part-done', 'hamnoor-part-card--completed');
    card.setAttribute('data-status', 'done');
    qsa('.hamnoor-status', card).forEach(function(el){
      el.classList.remove('hamnoor-status-claimed', 'hamnoor-status-available');
      el.classList.add('hamnoor-status-done');
      el.textContent = 'خوانده‌شده';
    });
    qsa('.hamnoor-complete-form, .hamnoor-release-form, .hamnoor-choose-part', card).forEach(function(form){ if (form.parentNode) { form.parentNode.removeChild(form); } });
    var readingLink = qs('.hamnoor-reading-link', card); if (readingLink) { readingLink.textContent = 'مرور سهم'; }
    var readingBar = qs('.hamnoor-part-reading-progress span', card); if (readingBar) { readingBar.style.width = '100%'; }
    var readingText = qs('.hamnoor-part-reading-state small', card); if (readingText) { readingText.textContent = '✓ قرائت تکمیل شد'; }
    var participant = qs('.hamnoor-participant', card);
    if (data && data.show_participant_name && data.participant_name) {
      if (!participant) {
        participant = document.createElement('span');
        (qs('.hamnoor-part-card-main', card) || card).appendChild(participant);
      }
      participant.className = 'hamnoor-participant hamnoor-reader-name';
      participant.textContent = data.participant_name;
    } else if (participant && data && !data.show_participant_name && participant.parentNode) {
      participant.parentNode.removeChild(participant);
    }
    if (addMessage !== false) {
      var actions = qs('.hamnoor-part-actions', card) || card;
      if (!qs('.hamnoor-complete-inline-message', actions)) {
        var ok = document.createElement('div');
        ok.className = 'hamnoor-complete-inline-message hamnoor-notice hamnoor-notice-success';
        ok.textContent = msg || text('complete_success', 'قرائت این سهم با موفقیت ثبت شد. خداوند قبول فرماید.');
        actions.appendChild(ok);
      }
    }
  }

  function markCardAvailable(card, data){
    if (!card) { return; }
    card.classList.remove('hamnoor-part-claimed', 'hamnoor-part-done');
    card.classList.add('hamnoor-part-available');
    card.setAttribute('data-status', 'available');
    qsa('.hamnoor-status', card).forEach(function(el){
      el.classList.remove('hamnoor-status-claimed', 'hamnoor-status-done');
      el.classList.add('hamnoor-status-available');
      el.textContent = (data && data.part_status_label) || 'آزاد';
    });
    qsa('.hamnoor-participant, .hamnoor-release-form, .hamnoor-complete-form, .hamnoor-complete-inline-message', card).forEach(function(el){ if (el.parentNode) { el.parentNode.removeChild(el); } });
    var actions = qs('.hamnoor-part-actions', card) || card;
    if (closest(card, '.hamnoor-parts-section') && !qs('.hamnoor-choose-part', actions)) {
      var title = qs('.hamnoor-part-title', card);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'hamnoor-btn hamnoor-btn-primary hamnoor-choose-part';
      btn.setAttribute('data-part-id', card.getAttribute('data-part-card') || '');
      btn.setAttribute('data-part-title', title ? title.textContent : '');
      btn.textContent = 'انتخاب این سهم';
      actions.appendChild(btn);
    }
  }

  function markCardClaimed(partId, data){
    if (!partId) { return; }
    var card = qs('[data-part-card="' + partId + '"]');
    if (!card) { return; }
    if (data && data.part_status === 'done') {
      markCardDone(card, data.message || text('complete_success', 'قرائت این سهم با موفقیت ثبت شد. خداوند قبول فرماید.'), false, data);
      return;
    }
    card.classList.remove('hamnoor-part-available', 'hamnoor-part-done');
    card.classList.add('hamnoor-part-claimed');
    card.setAttribute('data-status', 'claimed');
    qsa('.hamnoor-status', card).forEach(function(el){
      el.classList.remove('hamnoor-status-available', 'hamnoor-status-done');
      el.classList.add('hamnoor-status-claimed');
      el.textContent = (data && data.part_status_label) || 'رزروشده';
    });
    qsa('.hamnoor-choose-part', card).forEach(function(btn){
      btn.disabled = true;
      btn.setAttribute('aria-disabled', 'true');
      btn.classList.add('is-disabled');
      if (btn.parentNode) { btn.parentNode.removeChild(btn); }
    });
    if (data && data.show_participant_name && data.participant_name && !qs('.hamnoor-participant', card)) {
      var name = document.createElement('span');
      name.className = 'hamnoor-participant';
      name.textContent = data.participant_name;
      var main = qs('.hamnoor-part-card-main', card) || card;
      main.appendChild(name);
    }
    if (data && data.reading_url) {
      card.classList.add('hamnoor-part-card--mine');
      var actions = qs('.hamnoor-part-actions', card) || card;
      var reading = qs('.hamnoor-reading-link', actions);
      if (!reading) {
        reading = document.createElement('a');
        reading.className = 'hamnoor-btn hamnoor-btn-primary hamnoor-reading-link';
        actions.insertBefore(reading, actions.firstChild || null);
      }
      reading.href = data.reading_url;
      reading.textContent = data.reading_button_label || text('reading_start_button', 'شروع قرائت سهم');
    }
  }

  function applyCanonicalCard(data){
    if (!data || !data.part_id || !data.card_html) { return null; }
    var oldCard = qs('[data-part-card="' + data.part_id + '"]');
    if (!oldCard) { return null; }
    var holder = document.createElement('div'); holder.innerHTML = data.card_html;
    var newCard = holder.firstElementChild;
    if (newCard) { oldCard.replaceWith(newCard); }
    return newCard;
  }

  function applyQuotaState(state){
    if (!state) { return; }
    qsa('[data-hamnoor-personal-quota]').forEach(function(el){
      var activeRemaining = Math.max(0, Number(state.active_claim_limit) - Number(state.active_claim_count));
      var windowRemaining = state.window_enabled ? Math.max(0, Number(state.window_claim_limit) - Number(state.window_claim_count)) : activeRemaining;
      var available = Math.min(activeRemaining, windowRemaining);
      el.replaceChildren();
      var grid = document.createElement('span'); grid.className = 'hamnoor-quota-grid';
      var active = document.createElement('span'); active.className = 'hamnoor-quota-item'; active.innerHTML = '<small>سهم‌های ناتمام</small><strong>' + state.active_claim_count + ' از ' + state.active_claim_limit + '</strong>';
      grid.appendChild(active);
      if (state.window_enabled) { var windowItem = document.createElement('span'); windowItem.className = 'hamnoor-quota-item'; windowItem.innerHTML = '<small>سهمیه انتخاب در بازه زمانی</small><strong>' + state.window_claim_count + ' از ' + state.window_claim_limit + ' استفاده شده</strong>'; grid.appendChild(windowItem); }
      var availableItem = document.createElement('span'); availableItem.className = 'hamnoor-quota-available' + (state.can_claim ? '' : ' is-blocked'); availableItem.textContent = state.can_claim ? available + ' انتخاب تازه اکنون در دسترس است' : (state.next_allowed_label ? 'انتخاب بعدی: ' + state.next_allowed_label : 'برای انتخاب تازه، یک سهم را تکمیل یا آزاد کنید');
      el.append(grid, availableItem);
    });
    qsa('.hamnoor-choose-part, [data-hamnoor-next], [data-hamnoor-random]').forEach(function(btn){ btn.disabled = !state.can_claim; btn.setAttribute('aria-disabled', state.can_claim ? 'false' : 'true'); });
  }

  function primeClaimQuotaState(){
    var form = qs('#hamnoor-claim-modal .hamnoor-claim-form');
    if (!form) { return; }
    hamnoorAjax('hamnoor_claim_identity', {fresh: Date.now(), khatm_token: (qs('[name="khatm_token"]', form) || {}).value || ''}).then(function(res){
      applyQuotaState(res.data && res.data.quota_state);
    }).catch(function(){
      /* Claim submission remains server-authoritative if this read-only preflight fails. */
    });
  }

  function completeSuccessUI(form, data){
    data = data || {};
    var msg = data.message || text('complete_success', 'قرائت این سهم با موفقیت ثبت شد. خداوند قبول فرماید.');
    var card = closest(form, '.hamnoor-part-card');
    if (!card && data.part_id) { card = qs('[data-part-card="' + data.part_id + '"]'); }

    var successCard = closest(form, '.hamnoor-claim-success, .hamnoor-claim-success-card');
    if (successCard) {
      if (card) { markCardDone(card, msg, false, data); }
      qsa('.hamnoor-ajax-message', successCard).forEach(function(el){ el.innerHTML = ''; });
      var notice = document.createElement('div');
      notice.className = 'hamnoor-notice hamnoor-notice-success hamnoor-complete-result';
      notice.textContent = msg;
      form.replaceWith(notice);
      qsa('[data-hamnoor-complete-later]', successCard).forEach(function(btn){ if (btn.parentNode) { btn.parentNode.removeChild(btn); } });
    } else if (card) {
      qsa('.hamnoor-ajax-message', card).forEach(function(el){ el.innerHTML = ''; });
      markCardDone(card, msg, false, data);
      card.classList.add('hamnoor-part-card--completed');
      var actions = qs('.hamnoor-part-actions', card) || card;
      var compact = document.createElement('div');
      compact.className = 'hamnoor-complete-inline-message';
      compact.textContent = text('complete_inline_success', '✓ قرائت ثبت شد');
      actions.appendChild(compact);
    } else {
      var box = ensureMessageBox(closest(form, '.hamnoor-card, .hamnoor-dashboard-panel') || form.parentNode);
      showNotice(box, msg, 'success');
    }
    updateProgress(data.progress, data.khatm_status);
  }

  function partTotal(form){
    var typeEl = qs('[name="khatm_type"]:checked', form) || qs('[name="khatm_type"]', form);
    var type = typeEl ? typeEl.value : 'page';
    var count = parseInt(normalizeDigits((qs('[name="khatm_count"]', form) || {}).value || '1'), 10) || 1;
    var repeat = parseInt(normalizeDigits((qs('[name="repeat_count"]', form) || {}).value || '0'), 10) || 0;
    var unit = type === 'juz' ? 30 : (type === 'surah' ? 114 : (type === 'repeated_surah' ? repeat : 604));
    return unit * (type === 'repeated_surah' ? 1 : Math.max(1, count));
  }

  function updatePublishVisibility(form){
    if (!form) { return; }
    var privacy = qs('[name="privacy"]', form);
    var indexable = qs('[name="indexable"][type="checkbox"]', form);
    var box = qs('[data-google-visibility]', form);
    var help = qs('[data-google-visibility-help]', form);
    if (!privacy || !indexable) { return; }
    var isPrivate = privacy.value === 'private';
    if (isPrivate) {
      if (!indexable.disabled) { indexable.dataset.publicChecked = indexable.checked ? '1' : '0'; }
      indexable.checked = false;
      indexable.disabled = true;
    } else {
      var restore = indexable.dataset.publicChecked;
      indexable.disabled = false;
      if (restore === '1' || restore === '0') { indexable.checked = restore === '1'; delete indexable.dataset.publicChecked; }
    }
    if (box) { box.classList.toggle('is-private', isPrivate); }
    if (help) {
      help.textContent = isPrivate
        ? 'پویش خصوصی در گوگل نمایش داده نمی‌شود و فقط با لینک دعوت قابل دسترسی است.'
        : text('google_visibility_desc', 'اگر فعال باشد، پویش عمومی در صورت مناسب بودن می‌تواند در نتایج گوگل نمایش داده شود.');
    }
  }

  function normalizeKhatmSearch(value){
    return normalizeDigits(value || '').toLowerCase()
      .replace(/[ًٌٍَُِّْـ]/g, '')
      .replace(/[يى]/g, 'ی')
      .replace(/ك/g, 'ک')
      .replace(/[أإٱآ]/g, 'ا')
      .replace(/ة/g, 'ه')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function initKhatmListTools(box){
    if (!box || box.dataset.hamnoorListReady === '1') { return; }
    box.dataset.hamnoorListReady = '1';
    var root = closest(box, '.hamnoor-wrap') || document;
    var grid = qs('[data-hamnoor-khatm-grid]', root);
    if (!grid) { return; }
    var cards = qsa('[data-hamnoor-khatm-card]', grid);
    var search = qs('[data-hamnoor-list-search]', box);
    var status = qs('[data-hamnoor-list-status]', box);
    var empty = qs('[data-hamnoor-list-empty]', grid);
    var activeFilter = box.getAttribute('data-default-filter') || 'active';
    var activeType = 'all';
    function matchesStatus(card){
      var state = card.getAttribute('data-status') || '';
      if (activeFilter === 'all') { return true; }
      if (activeFilter === 'active') { return state === 'active'; }
      if (activeFilter === 'ended') { return state === 'completed' || state === 'expired'; }
      return true;
    }
    function apply(){
      var query = normalizeKhatmSearch(search ? search.value : '');
      var shown = 0;
      cards.forEach(function(card){
        var haystack = normalizeKhatmSearch(card.getAttribute('data-search') || card.textContent || '');
        var matchesType = activeType === 'all' || card.getAttribute('data-campaign-type') === activeType;
        var visible = matchesStatus(card) && matchesType && (!query || haystack.indexOf(query) !== -1);
        card.hidden = !visible;
        if (visible) { shown++; }
      });
      if (empty) { empty.hidden = shown !== 0; }
      if (status) { status.textContent = shown ? (shown + ' پویش نمایش داده می‌شود.') : 'پویشی مطابق این انتخاب پیدا نشد.'; }
    }
    qsa('[data-khatm-filter]', box).forEach(function(btn){
      btn.addEventListener('click', function(){
        activeFilter = btn.getAttribute('data-khatm-filter') || 'all';
        qsa('[data-khatm-filter]', box).forEach(function(other){
          var selected = other === btn;
          other.classList.toggle('is-active', selected);
          other.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        apply();
      });
    });
    qsa('[data-khatm-type]', box).forEach(function(btn){
      btn.addEventListener('click', function(){
        activeType = btn.getAttribute('data-khatm-type') || 'all';
        qsa('[data-khatm-type]', box).forEach(function(other){
          var selected = other === btn;
          other.classList.toggle('is-active', selected);
          other.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        apply();
      });
    });
    if (search) { search.addEventListener('input', apply); }
    apply();
  }

  function updateSummary(form){
    if (!form) { return; }
    var val = function(name){
      var el = qs('[name="' + name + '"]', form);
      if (!el) { return ''; }
      if (el.type === 'radio') { var checked = qs('[name="' + name + '"]:checked', form); return checked ? checked.value : el.value; }
      if (el.tagName === 'SELECT') { return el.options[el.selectedIndex] ? el.options[el.selectedIndex].text : el.value; }
      return el.value;
    };
    var set = function(key, value){ qsa('[data-summary="' + key + '"]', form).forEach(function(el){ el.textContent = value || '—'; }); };
    set('title', val('title'));
    var typeLabels = {page:'صفحه‌ای', juz:'جزء', surah:'سوره', repeated_surah:'تکرار یک سوره'};
    var selectedType = (qs('[name="khatm_type"]:checked', form) || qs('[name="khatm_type"]', form));
    set('type', typeLabels[selectedType ? selectedType.value : 'page'] || val('khatm_type'));
    set('khatm_count', val('khatm_count') || '1');
    set('owner_name', val('owner_name'));
    set('total_parts', String(partTotal(form)));
    var privacy = qs('[name="privacy"]', form);
    set('privacy', privacy && privacy.value === 'private' ? 'خصوصی' : 'عمومی');
    var indexable = qs('[name="indexable"][type="checkbox"]', form);
    set('indexable', privacy && privacy.value === 'private' ? 'خیر؛ پویش خصوصی است' : (indexable && indexable.checked ? 'بله' : 'خیر'));
    var completion = qs('[name="completion_mode"]:checked', form);
    var autoDone = completion && completion.value === 'auto_done_on_claim';
    set('completion_mode', autoDone ? 'خیر؛ با انتخاب سهم انجام‌شده محسوب می‌شود' : 'بله؛ پس از قرائت باید ثبت شود');
    var activeClaimLimit = normalizeDigits((qs('[name="max_active_claims_per_participant"]', form) || {}).value || '3');
    set('max_active_claims_per_participant', activeClaimLimit || '3');
    var rel = qs('[name="auto_release_mode"]', form);
    var relText = text('release_keep_until_manual', 'سهم تا زمان تکمیل یا آزادسازی توسط سازنده محفوظ بماند');
    if (!autoDone && rel && rel.value === 'after_days') { relText = text('release_after_days_option', 'اگر تا چند روز پس از انتخاب تکمیل نشد، سهم آزاد شود'); }
    set('auto_release_mode', relText);
    var starts = normalizeDigits((qs('[name="starts_at_jalali"]', form) || {}).value || '');
    var ends = normalizeDigits((qs('[name="ends_at_jalali"]', form) || {}).value || '');
    var grace = normalizeDigits((qs('[name="completion_grace_days"]', form) || {}).value || '');
    set('starts_at', starts || 'از زمان انتشار');
    set('ends_at', ends || 'بدون محدودیت');
    set('completion_grace_days', ends ? (grace === '' ? 'بدون محدودیت' : (grace + ' روز')) : 'نامرتبط؛ پایان پذیرش تعیین نشده');
  }

  function updateTypeFields(form){
    var type = qs('[name="khatm_type"]:checked', form) || qs('[name="khatm_type"]', form);
    if (!type) { return; }
    if (type.value === 'repeated_surah') { form.classList.add('is-repeated'); form.classList.remove('is-multi-khatm'); }
    else { form.classList.remove('is-repeated'); form.classList.add('is-multi-khatm'); }
    var help = qs('.hamnoor-type-help', form);
    if (help) {
      var messages = {
        page: 'هر سهم یک صفحه قرآن است و کل قرآن بین شرکت‌کنندگان تقسیم می‌شود.',
        juz: 'هر سهم یک جزء قرآن است.',
        surah: 'هر سهم یک سوره قرآن است.',
        repeated_surah: 'یک سوره مشخص چند بار بین شرکت‌کنندگان تقسیم می‌شود.'
      };
      help.textContent = messages[type.value] || messages.page;
    }
  }

  function normalizeSurahSearch(value){
    return normalizeDigits(value || '').toLowerCase()
      .replace(/[ًٌٍَُِّْـ]/g, '')
      .replace(/[يى]/g, 'ی')
      .replace(/ك/g, 'ک')
      .replace(/[أإٱآ]/g, 'ا')
      .replace(/ة/g, 'ه')
      .replace(/[_-]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function initSurahSearch(input){
    if (!input || input.dataset.hamnoorSurahReady === '1') { return; }
    input.dataset.hamnoorSurahReady = '1';
    var selectId = input.getAttribute('data-hamnoor-surah-select') || '';
    var select = selectId ? document.getElementById(selectId) : null;
    if (!select) { return; }
    var source = Array.prototype.map.call(select.options, function(option){
      return {
        value: option.value,
        text: option.textContent,
        search: normalizeSurahSearch(option.getAttribute('data-search') || option.textContent)
      };
    });
    var lastValue = select.value;
    input.addEventListener('input', function(){
      var query = normalizeSurahSearch(input.value);
      var matches = source.filter(function(item){ return !query || item.search.indexOf(query) !== -1; });
      while (select.firstChild) { select.removeChild(select.firstChild); }
      if (!matches.length) {
        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = 'سوره‌ای با این عبارت پیدا نشد';
        empty.disabled = true;
        empty.selected = true;
        select.appendChild(empty);
        return;
      }
      var preferred = matches.filter(function(item){ return query && item.search.indexOf(query) === 0; })[0];
      var targetValue = preferred ? preferred.value : (matches.some(function(item){ return item.value === lastValue; }) ? lastValue : matches[0].value);
      matches.forEach(function(item){
        var option = document.createElement('option');
        option.value = item.value;
        option.textContent = item.text;
        option.setAttribute('data-search', item.search);
        select.appendChild(option);
      });
      select.value = targetValue;
      lastValue = select.value;
      clearSingleFieldError(select);
    });
    select.addEventListener('change', function(){ if (select.value) { lastValue = select.value; } });
  }


  function updateReleaseFields(form){
    var completion = qs('[name="completion_mode"]:checked', form);
    var manual = !completion || completion.value !== 'auto_done_on_claim';
    var mode = qs('[name="auto_release_mode"]', form);
    if (mode && !manual) { mode.value = 'none'; }
    form.classList.toggle('is-manual-completion', manual);
    form.classList.toggle('is-release-after-days', manual && mode && mode.value === 'after_days');
    var ends = qs('[name="ends_at_jalali"]', form);
    form.classList.toggle('has-participation-end', !!(ends && String(ends.value || '').trim()));
    var advancedStatus = qs('[data-advanced-status]', form);
    if (advancedStatus) {
      var defaultLimit = parseInt(form.getAttribute('data-default-active-claim-limit') || '3', 10) || 3;
      var activeLimit = parseInt(normalizeDigits((qs('[name="max_active_claims_per_participant"]', form) || {}).value || String(defaultLimit)), 10) || defaultLimit;
      var customAdvanced = activeLimit !== defaultLimit || (manual && mode && mode.value !== 'none');
      advancedStatus.textContent = customAdvanced ? 'تنظیم سفارشی' : 'پیش‌فرض هم‌نور';
    }

  }

  function validateStep(form, step){
    clearFieldErrors(step);
    var ok = true;
    var first = null;
    var maxCount = parseInt(form.getAttribute('data-max-khatm-count') || '100', 10) || 100;
    var maxRepeat = parseInt(form.getAttribute('data-max-repeat-count') || '1000', 10) || 1000;
    var hardLimit = parseInt(form.getAttribute('data-hard-limit') || '12000', 10) || 12000;
    var maxMessage = form.getAttribute('data-max-khatm-message') || ('تعداد دور کامل قرآن در پویش باید بین ۱ تا ' + maxCount + ' باشد.');
    var maxRepeatMessage = form.getAttribute('data-max-repeat-message') || ('تعداد تکرار سوره باید بین ۱ تا ' + maxRepeat + ' مرتبه باشد.');
    function fail(name, msg){
      var field = qs('[name="' + name + '"]', step) || qs('[name="' + name + '"]', form);
      setFieldError(field, msg);
      ok = false;
      if (!first && field) { first = field; }
    }
    var no = parseInt(step.getAttribute('data-step'), 10) || 0;
    if (no === 1) {
      var title = qs('[name="title"]', step);
      if (title && !title.value.trim()) { fail('title', 'عنوان پویش الزامی است.'); }
    }
    if (no === 2) {
      var type = (qs('[name="khatm_type"]:checked', form) || qs('[name="khatm_type"]', form) || {}).value || 'page';
      var count = parseInt(normalizeDigits((qs('[name="khatm_count"]', form) || {}).value || '1'), 10) || 0;
      var repeat = parseInt(normalizeDigits((qs('[name="repeat_count"]', form) || {}).value || '0'), 10) || 0;
      var selectedSurah = parseInt(normalizeDigits((qs('[name="selected_surah_number"]', form) || {}).value || '0'), 10) || 0;
      if (type === 'page' || type === 'juz' || type === 'surah') { if (count < 1 || count > maxCount) { fail('khatm_count', maxMessage); } }
      if (type === 'repeated_surah' && (selectedSurah < 1 || selectedSurah > 114)) { fail('selected_surah_number', 'لطفاً یک سوره معتبر انتخاب کنید.'); }
      if (type === 'repeated_surah') { if (repeat < 1 || repeat > maxRepeat) { fail('repeat_count', maxRepeatMessage); } }
      if (partTotal(form) > hardLimit) { fail(type === 'repeated_surah' ? 'repeat_count' : 'khatm_count', 'تعداد سهم‌های این پویش از حد مجاز پردازش هم‌زمان بیشتر است.'); }
    }
    if (no === 3) {
      var st = normalizeDigits((qs('[name="starts_at_jalali"]', form) || {}).value || '');
      var en = normalizeDigits((qs('[name="ends_at_jalali"]', form) || {}).value || '');
      if (st && en && en < st) { fail('ends_at_jalali', 'پایان پذیرش سهم جدید نباید قبل از شروع پذیرش باشد.'); }
      var graceRaw = normalizeDigits((qs('[name="completion_grace_days"]', form) || {}).value || '');
      if (en && graceRaw !== '') {
        var grace = parseInt(graceRaw, 10);
        if (isNaN(grace) || grace < 0 || grace > 3650) { fail('completion_grace_days', 'مهلت تکمیل باید بین صفر تا ۳۶۵۰ روز باشد یا خالی بماند.'); }
      }
    }
    if (no === 4) {
      var activeLimit = parseInt(normalizeDigits((qs('[name="max_active_claims_per_participant"]', form) || {}).value || '0'), 10) || 0;
      if (activeLimit < 1 || activeLimit > 50) { fail('max_active_claims_per_participant', 'سقف سهم ناتمام هم‌زمان باید بین ۱ تا ۵۰ باشد.'); }
      var completion = qs('[name="completion_mode"]:checked', form);
      var needsManualDone = !completion || completion.value !== 'auto_done_on_claim';
      var releaseMode = needsManualDone ? ((qs('[name="auto_release_mode"]', form) || {}).value || 'none') : 'none';
      if (releaseMode === 'after_days') {
        var days = parseInt(normalizeDigits((qs('[name="auto_release_after_days"]', form) || {}).value || '0'), 10) || 0;
        if (days < 1) { fail('auto_release_after_days', 'تعداد روز آزادسازی باید عددی مثبت باشد.'); }
      }
    }
    if (!ok) { formSummary(step || form); if (first) { first.focus(); } }
    return ok;
  }

  function wizardDraftKey(form){
    if (!form) { return ''; }
    var user = parseInt(form.getAttribute('data-draft-user') || H.user_id || '0', 10) || 0;
    var scope = parseInt(form.getAttribute('data-draft-scope') || '0', 10) || 0;
    if (!user) { return ''; }
    return 'hamnoor_khatm_draft_v2_' + user + '_' + scope;
  }

  function serializeWizardDraft(form){
    var data = {};
    if (!form || !form.elements) { return data; }
    Array.prototype.forEach.call(form.elements, function(field){
      if (!field || !field.name || field.disabled && field.type === 'file') { return; }
      var name = field.name;
      if (name === 'action' || name === '_wpnonce' || name === '_wp_http_referer' || name === 'website' || field.type === 'file' || field.type === 'submit' || field.type === 'button') { return; }
      if (field.type === 'radio') { if (field.checked) { data[name] = field.value; } return; }
      if (field.type === 'checkbox') { data[name] = field.checked ? (field.value || '1') : '0'; return; }
      data[name] = field.value == null ? '' : String(field.value);
    });
    return data;
  }

  function applyWizardDraft(form, data){
    if (!form || !data || typeof data !== 'object') { return; }
    Object.keys(data).forEach(function(name){
      var fields = qsa('[name="' + String(name).replace(/"/g, '\\"') + '"]', form);
      fields.forEach(function(field){
        if (!field || field.type === 'file' || field.name === 'action' || field.name === '_wpnonce') { return; }
        if (field.type === 'radio') { field.checked = String(field.value) === String(data[name]); return; }
        if (field.type === 'checkbox') { field.checked = !(['0','','false','off'].indexOf(String(data[name]).toLowerCase()) >= 0); return; }
        field.value = data[name] == null ? '' : String(data[name]);
      });
    });
    updateTypeFields(form); updateReleaseFields(form); updateSummary(form);
  }

  function wizardDraftStatus(form, message, mode){
    if (!form) { return null; }
    var box = qs('[data-hamnoor-draft-status]', form);
    if (!box) {
      box = document.createElement('div');
      box.className = 'hamnoor-notice hamnoor-notice-info hamnoor-draft-status';
      box.setAttribute('data-hamnoor-draft-status', '1');
      box.setAttribute('aria-live', 'polite');
      var anchor = qs('.hamnoor-wizard-steps', form);
      if (anchor && anchor.parentNode) { anchor.parentNode.insertBefore(box, anchor.nextSibling); }
      else { form.insertBefore(box, form.firstChild); }
    }
    box.className = 'hamnoor-notice hamnoor-draft-status hamnoor-notice-' + (mode || 'info');
    box.textContent = message || '';
    box.hidden = !message;
    return box;
  }

  function localWizardDraft(form){
    var key = wizardDraftKey(form); if (!key) { return null; }
    try { var raw = window.localStorage.getItem(key); return raw ? JSON.parse(raw) : null; } catch(e) { return null; }
  }

  function saveLocalWizardDraft(form, step){
    var key = wizardDraftKey(form); if (!key) { return null; }
    var payload = {data:serializeWizardDraft(form), step:parseInt(step || form.getAttribute('data-current-step') || '1',10)||1, saved_at:Date.now()};
    try { window.localStorage.setItem(key, JSON.stringify(payload)); return payload; } catch(e) { return null; }
  }

  function clearLocalWizardDraft(form){
    var key = wizardDraftKey(form); if (!key) { return; }
    try { window.localStorage.removeItem(key); } catch(e) {}
  }

  function saveServerWizardDraft(form, step){
    if (!form || !wizardDraftKey(form)) { return Promise.resolve(null); }
    return hamnoorPost({
      action:'hamnoor_save_khatm_draft',
      scope:parseInt(form.getAttribute('data-draft-scope') || '0',10)||0,
      step:parseInt(step || form.getAttribute('data-current-step') || '1',10)||1,
      draft:JSON.stringify(serializeWizardDraft(form))
    }).then(function(res){
      if (res && res.success) { wizardDraftStatus(form, 'پیش‌نویس ذخیره شد.', 'success'); }
      return res;
    }).catch(function(){
      wizardDraftStatus(form, 'پیش‌نویس روی این دستگاه ذخیره شد؛ همگام‌سازی با سرور فعلاً انجام نشد.', 'warning');
      return null;
    });
  }

  function loadServerWizardDraft(form, callback){
    if (!form || !wizardDraftKey(form)) { return; }
    hamnoorPost({action:'hamnoor_get_khatm_draft', scope:parseInt(form.getAttribute('data-draft-scope') || '0',10)||0})
      .then(function(res){
        var draft = res && res.success && res.data && res.data.draft ? res.data.draft : null;
        if (!draft || !draft.data) { return; }
        applyWizardDraft(form, draft.data);
        saveLocalWizardDraft(form, draft.step || 1);
        wizardDraftStatus(form, 'پیش‌نویس ذخیره‌شده بازیابی شد. می‌توانید از همان‌جا ادامه دهید.', 'info');
        if (callback) { callback(parseInt(draft.step || '1',10)||1); }
      }).catch(function(){});
  }

  function wizardHasFileSelection(form){
    var file = qs('input[type="file"]', form);
    return !!(file && file.files && file.files.length);
  }

  function wizardScrollToStep(step){
    if (!step || !step.getBoundingClientRect) { return; }
    window.requestAnimationFrame(function(){
      var offset = 16;
      var adminBar = document.getElementById('wpadminbar');
      if (adminBar && adminBar.offsetParent !== null) { offset += adminBar.getBoundingClientRect().height || 0; }
      var appHead = qs('.hamnoor-desktop-app-head');
      if (appHead && appHead.offsetParent !== null) {
        var pos = window.getComputedStyle ? window.getComputedStyle(appHead).position : '';
        if (pos === 'fixed' || pos === 'sticky') { offset += appHead.getBoundingClientRect().height || 0; }
      }
      var rect = step.getBoundingClientRect();
      var target = Math.max(0, (window.pageYOffset || document.documentElement.scrollTop || 0) + rect.top - offset);
      var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      try { window.scrollTo({top:target, behavior:reduceMotion ? 'auto' : 'smooth'}); }
      catch (e) { window.scrollTo(0, target); }
    });
  }

  function initWizard(form){
    if (!form || form.dataset.hamnoorWizardReady === '1') { return; }
    form.dataset.hamnoorWizardReady = '1';
    form.classList.add('js-ready');
    qsa('.hamnoor-surah-search', form).forEach(initSurahSearch);
    var steps = qsa('.hamnoor-wizard-step', form);
    var localDraft = localWizardDraft(form);
    if (localDraft && localDraft.data) {
      applyWizardDraft(form, localDraft.data);
      wizardDraftStatus(form, 'پیش‌نویس ذخیره‌شده روی این دستگاه بازیابی شد.', 'info');
    }
    var current = localDraft && localDraft.step ? (parseInt(localDraft.step, 10) || 1) : 1;
    var initialError = qs('.hamnoor-field-has-error, [aria-invalid="true"]', form);
    if (initialError) {
      var initialStep = closest(initialError, '.hamnoor-wizard-step');
      current = initialStep ? (parseInt(initialStep.getAttribute('data-step'), 10) || 1) : 1;
    }
    function show(index, keepMessages){
      current = Math.max(1, Math.min(steps.length, index));
      form.setAttribute('data-current-step', String(current));
      if (!keepMessages) { clearWizardGeneralMessages(form); }
      var activeStep = null;
      steps.forEach(function(step){
        var active = parseInt(step.getAttribute('data-step'), 10) === current;
        step.hidden = !active;
        step.classList.toggle('is-active', active);
        if (active) { activeStep = step; }
      });
      var currentTitle = '';
      qsa('.hamnoor-wizard-steps [data-go-step], .hamnoor-wizard-steps [data-step-title]', form).forEach(function(btn, idx){
        var stepNo = parseInt(btn.getAttribute('data-go-step') || btn.getAttribute('data-step') || (idx + 1), 10);
        var isActive = stepNo === current;
        btn.classList.toggle('is-active', isActive);
        btn.classList.toggle('is-done', stepNo < current);
        if (isActive) {
          btn.setAttribute('aria-current', 'step');
          currentTitle = btn.getAttribute('data-step-title') || '';
        } else {
          btn.removeAttribute('aria-current');
        }
      });
      var currentStepTitle = qs('.hamnoor-current-step-title', form);
      if (currentStepTitle) {
        currentStepTitle.textContent = 'مرحله ' + current + ' از ' + steps.length + (currentTitle ? ' — ' + currentTitle : '');
      }
      var prevBtn = qs('[data-prev-step], [data-wizard-prev]', form);
      var nextBtn = qs('[data-next-step], [data-wizard-next]', form);
      var submitBtn = qs('[data-wizard-submit]', form);
      if (prevBtn) { prevBtn.hidden = current <= 1; }
      if (nextBtn) { nextBtn.hidden = current >= steps.length; }
      if (submitBtn) { submitBtn.hidden = current < steps.length; }
      updateSummary(form);
      wizardScrollToStep(activeStep);
    }
    form.addEventListener('click', function(e){
      var next = e.target.closest('[data-next-step], [data-wizard-next]');
      var prev = e.target.closest('[data-prev-step], [data-wizard-prev]');
      var go = e.target.closest('[data-go-step]');
      var scheduleClear = e.target.closest('[data-schedule-clear]');
      if (scheduleClear) {
        e.preventDefault();
        var field = qs('[name="' + scheduleClear.getAttribute('data-schedule-clear') + '"]', form);
        if (field) { field.value = ''; field.dispatchEvent(new Event('input', {bubbles:true})); field.focus(); }
      }
      if (next) { e.preventDefault(); var step = closest(next, '.hamnoor-wizard-step') || steps[current - 1]; if (validateStep(form, step)) { show(current + 1); } }
      if (prev) { e.preventDefault(); show(current - 1); }
      if (go) { e.preventDefault(); var requested = parseInt(go.getAttribute('data-go-step'), 10); if (requested <= current || validateStep(form, steps[current - 1])) { show(requested); } }
    });
    var serverDraftTimer = null;
    function scheduleDraftSave(){
      saveLocalWizardDraft(form, current);
      wizardDraftStatus(form, 'پیش‌نویس روی این دستگاه ذخیره شد؛ در حال همگام‌سازی…', 'info');
      if (serverDraftTimer) { window.clearTimeout(serverDraftTimer); }
      serverDraftTimer = window.setTimeout(function(){ saveServerWizardDraft(form, current); }, 700);
    }
    form.addEventListener('submit', function(e){
      var valid = true;
      steps.forEach(function(step){ if (!validateStep(form, step)) { valid = false; } });
      if (!valid) {
        e.preventDefault();
        saveLocalWizardDraft(form, current);
        var errorEl = qs('.hamnoor-field-has-error, [aria-invalid="true"]', form);
        var errorStep = closest(errorEl, '.hamnoor-wizard-step') || steps[0];
        show(parseInt(errorStep.getAttribute('data-step'), 10) || 1, true);
        var submitBtn = qs('[data-wizard-submit]', form);
        if (submitBtn) { submitBtn.disabled = false; submitBtn.removeAttribute('aria-busy'); }
      } else {
        // Server draft remains until the PHP handler succeeds, so a network failure is still recoverable.
        clearLocalWizardDraft(form);
        form.dataset.hamnoorSubmitting = '1';
      }
    });
    form.addEventListener('input', function(e){ clearSingleFieldError(e.target); updateTypeFields(form); updateReleaseFields(form); updatePublishVisibility(form); updateSummary(form); scheduleDraftSave(); });
    form.addEventListener('change', function(e){ clearSingleFieldError(e.target); updateTypeFields(form); updateReleaseFields(form); updatePublishVisibility(form); updateSummary(form); scheduleDraftSave(); });
    window.addEventListener('beforeunload', function(e){
      // File inputs cannot be persisted by browser/server autosave; warn only for that unrecoverable state.
      if (form.dataset.hamnoorSubmitting !== '1' && wizardHasFileSelection(form)) { e.preventDefault(); e.returnValue = ''; }
    });
    updateTypeFields(form);
    updateReleaseFields(form);
    updatePublishVisibility(form);
    show(current, true);
    if (!localDraft) { loadServerWizardDraft(form, function(step){ show(step, true); }); }
  }

  function handleClaimSubmit(e){
    var form = e.target;
    if (!form.matches('.hamnoor-claim-form')) { return; }
    e.preventDefault();
    clearFieldErrors(form);

    var identityMode = form.getAttribute('data-hamnoor-identity-mode') || 'loading';
    if (identityMode === 'loading' || identityMode === 'error') {
      loadClaimIdentity(closest(form, '.hamnoor-modal'));
      return;
    }

    var name = qs('[name="name"]', form);
    var phone = qs('[name="phone"]', form);
    var submitName = '';
    var submitPhone = '';
    var ok = true, first = null;

    if (identityMode === 'guest') {
      if (!name || !name.value.trim()) { setFieldError(name, 'نام و نام خانوادگی را وارد کنید.'); ok = false; first = first || name; }
      if (!phone || !validPhone(phone.value)) { setFieldError(phone, text('mobile_invalid', 'شماره موبایل واردشده معتبر نیست.')); ok = false; first = first || phone; }
      if (!ok) { formSummary(form); if (first) { first.focus(); } return; }
      submitName = name.value;
      submitPhone = normalizePhone(phone.value);
    }

    var btn = qs('button[type="submit"]', form);
    var restore = disableButton(btn, text('loading', 'در حال ثبت...'));
    var msgBox = qs('.hamnoor-modal-feedback', closest(form, '.hamnoor-modal')) || ensureMessageBox(form.parentNode);
    showNotice(msgBox, 'در حال ثبت انتخاب سهم...', 'info');
    hamnoorAjax('hamnoor_claim_part', {
      khatm_token: (qs('[name="khatm_token"]', form) || {}).value || '',
      claim_mode: (qs('[name="claim_mode"]', form) || {}).value || 'manual',
      part_id: (qs('[name="part_id"]', form) || {}).value || '',
      name: submitName,
      phone: submitPhone,
      website: (qs('[name="website"]', form) || {}).value || '',
      idempotency_key: (qs('[name="idempotency_key"]', form) || {}).value || ''
    }).then(function(res){
      restore();
      dispatchCompletionExtensionFeedback(res.data && res.data.extension_feedback);
      var modal = closest(form, '.hamnoor-modal');
      var body = claimModalBody(modal);
       if (res.data && res.data.part_id) { if (!applyCanonicalCard(res.data)) { markCardClaimed(res.data.part_id, res.data); } }
       applyQuotaState(res.data && res.data.quota_state);
       if (body && res.data && res.data.html) { body.innerHTML = res.data.html; }
       var laterButton = body ? qs('[data-hamnoor-complete-later]', body) : null;
       if (laterButton && res.data && res.data.later_message) { laterButton.setAttribute('data-confirmation', res.data.later_message); }
      updateProgress(res.data && res.data.progress, res.data && res.data.khatm_status);
    }).catch(function(error){
      restore();
      applyFieldErrors(form, error && error.data && error.data.errors);
       showNotice(msgBox, messageFromError(error, 'ثبت سهم انجام نشد.'), 'error');
       if (error && error.data) { applyCanonicalCard(error.data); applyQuotaState(error.data.quota_state); }
      if (error && error.data && error.data.code === 'hamnoor_session_mobile_mismatch') {
        loadClaimIdentity(closest(form, '.hamnoor-modal'));
      }
    });
  }

  function handleCompleteSubmit(e){
    var form = e.target;
    if (!form.matches('.hamnoor-complete-form, [data-hamnoor-complete]')) { return; }
    e.preventDefault();
    var confirmMessage = form.getAttribute('data-hamnoor-confirm-complete') || '';
    if (confirmMessage && !window.confirm(confirmMessage)) { return; }
    var btn = qs('button[type="submit"]', form);
    var restore = disableButton(btn, text('loading', 'در حال ثبت...'));
    var container = closest(form, '.hamnoor-claim-success, .hamnoor-claim-success-card, .hamnoor-part-card, .hamnoor-card, .hamnoor-dashboard-panel, .hamnoor-track-results') || form.parentNode;
    var box = ensureMessageBox(container);
    showNotice(box, 'در حال ثبت قرائت...', 'info');
    hamnoorAjax('hamnoor_complete_part', {
      part_id: (qs('[name="part_id"]', form) || {}).value || '',
      access: (qs('[name="access"]', form) || {}).value || '',
      contact: (qs('[name="contact"]', form) || {}).value || ''
    }).then(function(res){
      restore();
      dispatchCompletionExtensionFeedback(res.data && res.data.extension_feedback);
      if (box) { box.innerHTML = ''; }
      var fresh = applyCanonicalCard(res.data || {});
      applyQuotaState(res.data && res.data.quota_state);
      if (!fresh) { completeSuccessUI(form, res.data || {}); } else { showNotice(ensureMessageBox(fresh), (res.data && res.data.message) || text('complete_success', 'قرائت ثبت شد.'), 'success'); updateProgress(res.data && res.data.progress, res.data && res.data.khatm_status); }
    }).catch(function(error){
      restore();
      showNotice(box, messageFromError(error, 'ثبت قرائت انجام نشد.'), 'error');
    });
  }


  function handleReleaseSubmit(e){
    var form = e.target;
    if (!form.matches('.hamnoor-release-form, [data-hamnoor-release]')) { return; }
    e.preventDefault();
    var btn = qs('button[type="submit"]', form);
    var restore = disableButton(btn, text('loading', 'در حال ثبت...'));
    var card = closest(form, '.hamnoor-part-card');
    var box = ensureMessageBox(card || form.parentNode);
    showNotice(box, 'در حال آزادسازی سهم...', 'info');
    hamnoorAjax('hamnoor_release_part', {
      part_id: (qs('[name="part_id"]', form) || {}).value || '',
      contact: (qs('[name="contact"]', form) || {}).value || '',
      access: (qs('[name="access"]', form) || {}).value || ''
    }).then(function(res){
      restore();
      var fresh = applyCanonicalCard(res.data || {});
      if (!fresh && card) { markCardAvailable(card, res.data || {}); }
      applyQuotaState(res.data && res.data.quota_state);
      showNotice(ensureMessageBox(fresh || card || form.parentNode), (res.data && res.data.message) || text('release_success', 'سهم با موفقیت آزاد شد و دوباره برای انتخاب دیگران در دسترس قرار گرفت.'), 'success');
      updateProgress(res.data && res.data.progress, res.data && res.data.khatm_status);
    }).catch(function(error){
      restore();
      showNotice(box, messageFromError(error, 'آزادسازی سهم انجام نشد.'), 'error');
    });
  }

  function handleProfileSubmit(e){
    var form = e.target;
    if (!form.matches('[data-hamnoor-profile]')) { return; }
    e.preventDefault();
    clearFieldErrors(form);
    var btn = qs('button[type="submit"]', form);
    var restore = disableButton(btn, text('loading', 'در حال ثبت...'));
    var box = ensureMessageBox(form.parentNode);
    showNotice(box, 'در حال ذخیره مشخصات...', 'info');
    hamnoorAjax('hamnoor_update_profile', {
      display_name: (qs('[name="display_name"]', form) || {}).value || '',
      phone: (qs('[name="phone"]', form) || {}).value || '',
      password: (qs('[name="password"]', form) || {}).value || ''
    }).then(function(res){
      restore();
      showNotice(box, (res.data && res.data.message) || 'مشخصات شما ذخیره شد.', 'success');
      if (res.data && res.data.redirect) { window.location.assign(res.data.redirect); }
    }).catch(function(error){
      restore();
      applyFieldErrors(form, error && error.data && error.data.errors);
      showNotice(box, messageFromError(error, text('form_incomplete', 'لطفاً خطاها را اصلاح کنید.')), 'error');
    });
  }

  function initMyPartsControls(dashboard){
    var controls = qs('[data-my-parts-controls]', dashboard);
    var list = qs('[data-my-parts-list]', dashboard);
    if (!controls || !list || controls.dataset.hamnoorReady === '1') { return; }
    controls.dataset.hamnoorReady = '1';

    var statusButtons = qsa('[data-my-parts-status-button]', controls);
    var khatm = qs('[data-my-parts-khatm]', controls);
    var sort = qs('[data-my-parts-sort]', controls);
    var count = qs('[data-my-parts-count]', controls);
    var empty = qs('[data-my-parts-empty]', dashboard);
    var statusValue = controls.getAttribute('data-default-status') || 'all';
    var cards = Array.prototype.filter.call(list.children || [], function(node){
      return node && node.nodeType === 1 && node.classList && node.classList.contains('hamnoor-part-card');
    });

    function numberAttr(card, name){ return parseInt(card.getAttribute(name) || '0', 10) || 0; }
    function syncStatusButtons(){
      statusButtons.forEach(function(button){
        var active = button.getAttribute('data-my-parts-status-button') === statusValue;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    }
    function apply(){
      var khatmValue = khatm ? khatm.value : 'all';
      var sortValue = sort ? sort.value : 'priority';
      var visible = [];

      cards.forEach(function(card){
        var cardStatus = card.getAttribute('data-filter-status') || 'active';
        var matchesStatus = statusValue === 'all' || (statusValue === 'open' ? cardStatus !== 'done' : cardStatus === statusValue);
        var matchesKhatm = khatmValue === 'all' || card.getAttribute('data-khatm-id') === khatmValue;
        var show = matchesStatus && matchesKhatm;
        card.hidden = !show;
        card.style.display = show ? '' : 'none';
        if (show) { visible.push(card); }
      });

      visible.sort(function(a,b){
        if (sortValue === 'priority') {
          var rank = { ready: 0, active: 1, done: 2 };
          var ar = Object.prototype.hasOwnProperty.call(rank, a.getAttribute('data-filter-status')) ? rank[a.getAttribute('data-filter-status')] : 1;
          var br = Object.prototype.hasOwnProperty.call(rank, b.getAttribute('data-filter-status')) ? rank[b.getAttribute('data-filter-status')] : 1;
          if (ar !== br) { return ar - br; }
          var ap = numberAttr(a,'data-deadline-ts');
          var bp = numberAttr(b,'data-deadline-ts');
          if (ap || bp) {
            if (!ap) { return 1; }
            if (!bp) { return -1; }
            if (ap !== bp) { return ap - bp; }
          }
          return numberAttr(b,'data-activity-ts') - numberAttr(a,'data-activity-ts');
        }
        if (sortValue === 'oldest') { return numberAttr(a,'data-claimed-ts') - numberAttr(b,'data-claimed-ts'); }
        if (sortValue === 'activity') { return numberAttr(b,'data-activity-ts') - numberAttr(a,'data-activity-ts'); }
        if (sortValue === 'deadline') {
          var ad = numberAttr(a,'data-deadline-ts');
          var bd = numberAttr(b,'data-deadline-ts');
          if (!ad && !bd) { return numberAttr(b,'data-claimed-ts') - numberAttr(a,'data-claimed-ts'); }
          if (!ad) { return 1; }
          if (!bd) { return -1; }
          return ad - bd;
        }
        return numberAttr(b,'data-claimed-ts') - numberAttr(a,'data-claimed-ts');
      });
      visible.forEach(function(card){ list.appendChild(card); });

      if (count) {
        count.textContent = visible.length === cards.length
          ? cards.length + ' سهم'
          : visible.length + ' از ' + cards.length + ' سهم';
      }
      if (empty) { empty.hidden = visible.length !== 0; }
      syncStatusButtons();
    }

    statusButtons.forEach(function(button){
      button.addEventListener('click', function(){
        statusValue = button.getAttribute('data-my-parts-status-button') || 'all';
        apply();
      });
    });
    [khatm, sort].forEach(function(input){
      if (input) { input.addEventListener('change', apply); }
    });

    controls.hidden = false;
    apply();
  }

  function initDashboard(dashboard){
    if (!dashboard || dashboard.dataset.hamnoorDashboardReady === '1') { return; }
    dashboard.dataset.hamnoorDashboardReady = '1';
    var allowed = qsa('[data-tab-panel]', dashboard).map(function(panel){ return panel.getAttribute('data-tab-panel'); });
    function dashboardTabTarget(btn){
      var target = btn ? (btn.getAttribute('data-tab-target') || '') : '';
      if (!target && btn && btn.tagName === 'A' && btn.getAttribute('href')) {
        try { target = new URL(btn.href, window.location.href).searchParams.get('tab') || ''; } catch (err) { target = ''; }
      }
      return target;
    }
    function activate(tab, push){
      if (tab === 'contributions') { tab = 'my-parts'; }
      if (allowed.indexOf(tab) === -1) { tab = 'summary'; }
      qsa('.hamnoor-dashboard-tabs button, .hamnoor-dashboard-tabs a', dashboard).forEach(function(btn){
        var target = dashboardTabTarget(btn);
        var active = target === tab;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
        btn.setAttribute('tabindex', active ? '0' : '-1');
      });
      qsa('[data-tab-panel]', dashboard).forEach(function(panel){
        var active = panel.getAttribute('data-tab-panel') === tab;
        panel.hidden = !active;
        panel.classList.toggle('is-active', active);
      });
      var activeGroup = 'today';
      qsa('[data-dashboard-tab-group]', dashboard).some(function(control){
        if (dashboardTabTarget(control) !== tab) { return false; }
        activeGroup = control.getAttribute('data-dashboard-tab-group') || 'today';
        return true;
      });
      qsa('[data-dashboard-group-target]', dashboard).forEach(function(groupButton){
        var active = groupButton.getAttribute('data-dashboard-group-target') === activeGroup;
        groupButton.classList.toggle('is-active', active);
        groupButton.setAttribute('aria-expanded', active ? 'true' : 'false');
      });
      qsa('[data-dashboard-subnav]', dashboard).forEach(function(subnav){
        subnav.hidden = subnav.getAttribute('data-dashboard-subnav') !== activeGroup;
      });
      dashboard.setAttribute('data-active-group', activeGroup);
      dashboard.setAttribute('data-active-tab', tab);
      if (tab === 'notifications' && dashboard.dataset.notificationsRead !== '1') {
        dashboard.dataset.notificationsRead = '1';
        hamnoorAjax('hamnoor_mark_notifications_read', {}).then(function(){
          qsa('.hamnoor-notification-badge', dashboard).forEach(function(badge){ badge.remove(); });
          qsa('.hamnoor-notification-item.is-unread', dashboard).forEach(function(item){ item.classList.remove('is-unread'); });
        }).catch(function(){ dashboard.dataset.notificationsRead = '0'; });
      }
      if (push && window.history && window.history.pushState) {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.pushState({hamnoorTab: tab}, '', url.toString());
      }
    }
    var urlTab = new URL(window.location.href).searchParams.get('tab');
    activate(urlTab || dashboard.getAttribute('data-active-tab') || 'summary', false);
    dashboard.addEventListener('click', function(e){
      var groupButton = e.target.closest('[data-dashboard-group-target]');
      if (groupButton && dashboard.contains(groupButton)) {
        e.preventDefault();
        activate(groupButton.getAttribute('data-dashboard-default-tab') || 'summary', true);
        return;
      }
      var btn = e.target.closest('[data-tab-target]');
      if (!btn || !dashboard.contains(btn)) { return; }
      e.preventDefault();
      activate(btn.getAttribute('data-tab-target') || 'summary', true);
    });
    qsa('.hamnoor-dashboard-tabs[role="tablist"]', dashboard).forEach(function(tablist){
      tablist.addEventListener('keydown', function(e){
        if (['ArrowRight','ArrowLeft','Home','End'].indexOf(e.key) === -1) { return; }
        var tabs = qsa('[role="tab"]', tablist);
        if (!tabs.length) { return; }
        var current = tabs.indexOf(document.activeElement);
        if (current < 0) { current = 0; }
        var next = current;
        if (e.key === 'Home') { next = 0; }
        else if (e.key === 'End') { next = tabs.length - 1; }
        else if (e.key === 'ArrowRight') { next = (current + 1) % tabs.length; }
        else if (e.key === 'ArrowLeft') { next = (current - 1 + tabs.length) % tabs.length; }
        e.preventDefault();
        tabs[next].focus();
      });
    });
    window.addEventListener('popstate', function(){ activate(new URL(window.location.href).searchParams.get('tab') || 'summary', false); });
    initMyPartsControls(dashboard);
  }

  function handleDocumentClick(e){
    var manual = e.target.closest('.hamnoor-choose-part');
    var next = e.target.closest('[data-hamnoor-next]');
    var random = e.target.closest('[data-hamnoor-random]');
    var close = e.target.closest('[data-hamnoor-modal-close], [data-hamnoor-close-success]');
    var later = e.target.closest('[data-hamnoor-complete-later]');
    var copy = e.target.closest('.hamnoor-copy');
    var ownerCopy = e.target.closest('[data-hamnoor-copy-value]');
    var copyInvite = e.target.closest('.hamnoor-copy-invite');
    var share = e.target.closest('.hamnoor-web-share');
    var verifyOpen = e.target.closest('[data-hamnoor-open-verify]');

    if (verifyOpen) {
      e.preventDefault();
      var selector = verifyOpen.getAttribute('data-hamnoor-open-verify') || '#hamnoor-verification-modal';
      openModal(qs(selector), verifyOpen);
      return;
    }
    if (manual) { e.preventDefault(); setClaimModal('manual', manual); return; }
    if (next) { e.preventDefault(); setClaimModal('next', next); return; }
    if (random) { e.preventDefault(); setClaimModal('random', random); return; }
    if (later) {
      e.preventDefault();
      if (later.dataset.hamnoorLaterAcknowledged === '1') { closeModal(closest(later, '.hamnoor-modal')); return; }
      var laterBox = ensureMessageBox(closest(later, '.hamnoor-claim-success, .hamnoor-modal-body') || later.parentNode);
      showNotice(laterBox, later.getAttribute('data-confirmation') || later.getAttribute('data-warning') || 'سهم برای شما رزرو شد. لطفاً پس از قرائت، آن را از داشبورد هم‌نور ثبت کنید.', 'success');
      later.textContent = 'متوجه شدم';
      later.dataset.hamnoorLaterAcknowledged = '1';
      return;
    }
    if (close) { e.preventDefault(); closeModal(closest(close, '.hamnoor-modal')); return; }
    if (ownerCopy) {
      e.preventDefault();
      var copyValue = ownerCopy.getAttribute('data-hamnoor-copy-value') || '';
      if (!copyValue) { return; }
      copyText(copyValue, function(){ var oldOwnerCopy = ownerCopy.textContent; ownerCopy.textContent = 'کپی شد'; setTimeout(function(){ ownerCopy.textContent = oldOwnerCopy || 'کپی لینک دعوت'; }, 1500); });
      return;
    }
    if (copy) {
      e.preventDefault();
      var box = copy.parentNode;
      var input = box ? qs('input, textarea', box) : null;
      if (!input) { return; }
      copyText(input.value, function(){ var old = copy.textContent; copy.textContent = 'کپی شد'; setTimeout(function(){ copy.textContent = old || 'کپی لینک'; }, 1500); });
      return;
    }
    if (copyInvite) {
      e.preventDefault();
      var inviteBox = closest(copyInvite, '.hamnoor-share-actions');
      if (!inviteBox) { return; }
      var inviteText = shareClipboardText(inviteBox);
      copyText(inviteText, function(){ var old = copyInvite.textContent; copyInvite.textContent = 'کپی شد'; setTimeout(function(){ copyInvite.textContent = old || 'کپی متن دعوت'; }, 1600); });
      return;
    }
    if (share) {
      e.preventDefault();
      var shareBox = closest(share, '.hamnoor-share-actions');
      if (!shareBox) { return; }
      var data = {title: shareBox.getAttribute('data-hamnoor-title') || '', text: shareBox.getAttribute('data-hamnoor-text') || '', url: shareBox.getAttribute('data-hamnoor-url') || window.location.href};
      shareKhatm(share, shareBox, data);
    }
  }

  function handleModalBackdrop(e){
    var modal = e.target.closest('.hamnoor-modal');
    if (!modal) { return; }
    if (e.target.classList.contains('hamnoor-modal') || e.target.hasAttribute('data-hamnoor-modal-close') || e.target.classList.contains('hamnoor-modal-backdrop')) {
      e.preventDefault();
      closeModal(modal);
    }
  }

  function handleKeydown(e){
    if (e.key === 'Escape' && activeModal && activeModal.classList.contains('is-open')) { closeModal(activeModal); }
    if (e.key === 'Tab' && activeModal && activeModal.classList.contains('is-open')) {
      var focusables = qsa('a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])', activeModal).filter(function(el){ return el.offsetParent !== null; });
      if (!focusables.length) { return; }
      var first = focusables[0], last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }

  function initDateInputs(){
    qsa('.hamnoor-jalali-input, .hamnoor-date-input').forEach(function(input){
      if (input.dataset.hamnoorDateReady === '1') { return; }
      input.dataset.hamnoorDateReady = '1';
      input.setAttribute('inputmode', 'numeric');
      input.addEventListener('input', function(){
        var v = normalizeDigits(input.value).replace(/[.\-]/g, '/').replace(/[^0-9/: ]/g, '');
        if (v.length > 16) { v = v.substring(0, 16); }
        input.value = v;
      });
    });
  }


  function hamnoorPost(data){
    data.nonce = HamnoorFrontend.nonce;
    return fetch(HamnoorFrontend.ajax_url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams(data).toString()}).then(function(r){return r.json();});
  }
  function initMobileAccess(){
    qsa('[data-hamnoor-begin-create="1"]').forEach(function(form){
      if (form.dataset.hamnoorReady === '1') { return; }
      form.dataset.hamnoorReady = '1';
      var card=closest(form,'.hamnoor-card'), msg=card ? card.querySelector('.hamnoor-ajax-message') : null;
      var identityStep=qs('[data-create-identity-step]',form), codeStep=qs('[data-create-code-step]',form);
      var nameField=qs('[name="name"]',form), phoneField=qs('[name="phone"]',form), codeField=qs('[name="otp_code"]',form);
      var sendButton=qs('[data-create-send]',form), verifyButton=qs('[data-verify-create-otp]',form), resendButton=qs('[data-resend-create-otp]',form), editButton=qs('[data-edit-create-identity]',form);
      var phoneDisplay=qs('[data-create-phone-display]',form), countdown=qs('[data-create-resend-countdown]',form);
      var requestToken='', activePhone='', timer=null, remaining=0;

      function setAccessMessage(text,type){ if(msg){ showNotice(msg,text,type||'info'); } }
      function startCountdown(seconds){
        remaining=Math.max(0,parseInt(seconds||0,10));
        if(timer){window.clearInterval(timer);timer=null;}
        if(resendButton){resendButton.disabled=remaining>0;}
        function render(){
          if(countdown){countdown.textContent=remaining>0 ? ('ارسال دوباره کد تا '+remaining+' ثانیه دیگر') : 'اکنون می‌توانید کد را دوباره ارسال کنید.';}
          if(resendButton){resendButton.disabled=remaining>0;}
          if(remaining<=0&&timer){window.clearInterval(timer);timer=null;return;}
          remaining-=1;
        }
        render();
        if(remaining>=0){timer=window.setInterval(render,1000);}
      }
      function sendCode(){
        var name=nameField ? nameField.value.trim() : '', phone=phoneField ? normalizePhone(phoneField.value) : '';
        if(!name){setAccessMessage('نام و نام خانوادگی را وارد کنید.','error');if(nameField){nameField.focus();}return;}
        if(!validPhone(phone)){setAccessMessage('شماره موبایل معتبر نیست.','error');if(phoneField){phoneField.focus();}return;}
        activePhone=phone;
        setAccessMessage('در حال ارسال کد تأیید...','info');
        if(sendButton){sendButton.disabled=true;} if(resendButton){resendButton.disabled=true;}
        hamnoorPost({action:'hamnoor_begin_create_khatm',name:name,phone:phone}).then(function(j){
          if(sendButton){sendButton.disabled=false;}
          if(!j.success){throw new Error((j.data&&j.data.message)||'ارسال کد انجام نشد.');}
          if(j.data&&j.data.verified){
            setAccessMessage(j.data.message||'شماره موبایل قبلاً تأیید شده است.','success');
            window.location.replace(j.data.redirect||window.location.href);
            return;
          }
          requestToken=(j.data&&j.data.request_token)||'';
          activePhone=(j.data&&j.data.phone)||phone;
          if(phoneDisplay){phoneDisplay.textContent=activePhone;}
          if(identityStep){identityStep.hidden=true;} if(codeStep){codeStep.hidden=false;}
          setAccessMessage((j.data&&j.data.message)||'کد تأیید ارسال شد.','success');
          startCountdown(j.data&&j.data.resend_in||60);
          if(codeField){codeField.value='';codeField.focus();}
        }).catch(function(err){
          if(sendButton){sendButton.disabled=false;} if(resendButton){resendButton.disabled=false;}
          setAccessMessage(err.message||'خطا در ارتباط با سرور.','error');
        });
      }
      function verifyCode(){
        var code=codeField ? normalizeDigits(codeField.value).replace(/\D+/g,'') : '';
        if(!code){setAccessMessage('کد تأیید را وارد کنید.','error');if(codeField){codeField.focus();}return;}
        setAccessMessage('در حال تأیید کد...','info');
        if(verifyButton){verifyButton.disabled=true;}
        hamnoorPost({action:'hamnoor_verify_create_otp',phone:activePhone,code:code,request_token:requestToken}).then(function(j){
          if(verifyButton){verifyButton.disabled=false;}
          if(!j.success){throw new Error((j.data&&j.data.message)||'تأیید کد انجام نشد.');}
          setAccessMessage((j.data&&j.data.message)||'شماره موبایل تأیید شد.','success');
          window.location.replace((j.data&&j.data.redirect)||window.location.href);
        }).catch(function(err){if(verifyButton){verifyButton.disabled=false;}setAccessMessage(err.message||'خطا در ارتباط با سرور.','error');});
      }
      form.addEventListener('submit',function(e){e.preventDefault();if(codeStep&&!codeStep.hidden){verifyCode();}else{sendCode();}});
      // The existing-user create-access form renders data-create-send as type=button.
      // Explicitly bind that button; submit buttons continue to be handled by the form
      // submit listener above so a click never sends the OTP twice.
      if(sendButton && String(sendButton.getAttribute('type') || 'submit').toLowerCase() === 'button'){
        sendButton.addEventListener('click',function(e){e.preventDefault();sendCode();});
      }
      if(verifyButton){verifyButton.addEventListener('click',verifyCode);}
      if(resendButton){resendButton.addEventListener('click',sendCode);}
      if(editButton){editButton.addEventListener('click',function(){
        if(timer){window.clearInterval(timer);timer=null;}
        requestToken='';activePhone='';
        if(codeStep){codeStep.hidden=true;}if(identityStep){identityStep.hidden=false;}
        if(countdown){countdown.textContent='';}setAccessMessage('نام یا شماره را اصلاح و دوباره ادامه دهید.','info');
        if(phoneField){phoneField.focus();}
      });}
      if(codeField){codeField.addEventListener('input',function(){codeField.value=normalizeDigits(codeField.value).replace(/\D+/g,'').slice(0,8);});}
    });
    qsa('[data-hamnoor-verify-box="1"]').forEach(function(box){
      var phoneStep=qs('[data-otp-phone-step]',box), codeStep=qs('[data-otp-code-step]',box), msg=qs('.hamnoor-ajax-message',box), token='', targetPhone='', changeMode=false;
      var send=qs('[data-send-otp]',box), verify=qs('[data-verify-otp]',box);
      var phoneInput=phoneStep?qs('input',phoneStep):null, codeInput=codeStep?qs('input',codeStep):null;
      var targetLabel=qs('[data-otp-target-label]',box), targetDisplay=qs('[data-otp-target-phone]',box);
      if(send) send.addEventListener('click',function(){
        targetPhone=phoneInput?normalizePhone(phoneInput.value):'';
        if(!validPhone(targetPhone)){showNotice(msg,'شماره موبایل معتبر نیست.','error');if(phoneInput){phoneInput.focus();}return;}
        var currentPhone=normalizePhone(box.getAttribute('data-current-phone')||'');
        changeMode=targetPhone!==currentPhone;
        showNotice(msg,'در حال ارسال کد...','info'); send.disabled=true;
        hamnoorPost({action:changeMode?'hamnoor_send_change_mobile_otp':'hamnoor_send_mobile_otp',phone:targetPhone}).then(function(j){
          send.disabled=false;
          if(!j.success){showNotice(msg,(j.data&&j.data.message)||'ارسال انجام نشد.','error');return;}
          token=j.data.request_token||''; phoneStep.hidden=true; codeStep.hidden=false;
          if(targetLabel){targetLabel.textContent=changeMode?'کد تأیید به شماره جدید ارسال شد:':'کد تأیید به شماره زیر ارسال شد:';}
          if(targetDisplay){targetDisplay.textContent=targetPhone;}
          showNotice(msg,j.data.message||'کد تأیید ارسال شد.','success'); if(codeInput){codeInput.focus();}
        }).catch(function(){send.disabled=false;showNotice(msg,'خطا در ارتباط با سرور.','error');});
      });
      if(verify) verify.addEventListener('click',function(){
        var code=codeInput?normalizeDigits(codeInput.value).replace(/\D+/g,''):'';
        var redirect=box.getAttribute('data-redirect')||window.location.href;
        if(!code){showNotice(msg,'کد تأیید را وارد کنید.','error');return;}
        showNotice(msg,'در حال تأیید...','info'); verify.disabled=true;
        hamnoorPost({action:changeMode?'hamnoor_verify_change_mobile_otp':'hamnoor_verify_mobile_otp',phone:targetPhone,code:code,request_token:token,redirect:redirect}).then(function(j){
          verify.disabled=false;
          if(j.success){showNotice(msg,j.data.message||'شماره موبایل تأیید شد.','success');if(j.data&&j.data.redirect){window.location.assign(j.data.redirect);}else{window.location.reload();}}
          else{showNotice(msg,(j.data&&j.data.message)||'تأیید انجام نشد.','error');}
        }).catch(function(){verify.disabled=false;showNotice(msg,'خطا در ارتباط با سرور.','error');});
      });
    });

    qsa('[data-hamnoor-change-mobile="1"]').forEach(function(box){
      if (box.dataset.hamnoorReady === '1') { return; }
      box.dataset.hamnoorReady = '1';
      var phoneStep=qs('[data-change-mobile-phone-step]',box), codeStep=qs('[data-change-mobile-code-step]',box), msg=qs('.hamnoor-ajax-message',box), token='', newPhone='';
      var send=qs('[data-send-change-mobile-otp]',box), verify=qs('[data-verify-change-mobile-otp]',box);
      var phoneInput=phoneStep ? qs('input',phoneStep) : null, codeInput=codeStep ? qs('input',codeStep) : null;
      if(send) send.addEventListener('click',function(){
        newPhone=phoneInput ? normalizePhone(phoneInput.value) : '';
        if(!validPhone(newPhone)){showNotice(msg,'شماره موبایل جدید معتبر نیست.','error');return;}
        showNotice(msg,'در حال ارسال کد به شماره جدید...','info');
        send.disabled=true;
        hamnoorPost({action:'hamnoor_send_change_mobile_otp',phone:newPhone}).then(function(j){
          send.disabled=false;
          if(j.success){
            token=j.data.request_token||'';
            phoneStep.hidden=true;
            codeStep.hidden=false;
            showNotice(msg,j.data.message||'کد تأیید ارسال شد.','success');
            if(codeInput){codeInput.focus();}
          }else{showNotice(msg,(j.data&&j.data.message)||'ارسال انجام نشد.','error');}
        }).catch(function(){send.disabled=false;showNotice(msg,'خطا در ارتباط با سرور.','error');});
      });
      if(verify) verify.addEventListener('click',function(){
        var code=codeInput ? normalizeDigits(codeInput.value).replace(/\D+/g,'') : '';
        if(!code){showNotice(msg,'کد تأیید را وارد کنید.','error');return;}
        showNotice(msg,'در حال تأیید و تغییر شماره...','info');
        verify.disabled=true;
        hamnoorPost({action:'hamnoor_verify_change_mobile_otp',phone:newPhone,code:code,request_token:token}).then(function(j){
          verify.disabled=false;
          if(j.success){
            showNotice(msg,j.data.message||'شماره موبایل تغییر کرد.','success');
            if(j.data&&j.data.redirect){window.location.assign(j.data.redirect);}else{window.location.reload();}
          }else{showNotice(msg,(j.data&&j.data.message)||'تغییر شماره انجام نشد.','error');}
        }).catch(function(){verify.disabled=false;showNotice(msg,'خطا در ارتباط با سرور.','error');});
      });
    });
  }

  function initRecovery(){
    qsa('[data-hamnoor-recovery="1"]').forEach(function(box){
      if(box.dataset.hamnoorReady==='1'){return;} box.dataset.hamnoorReady='1';
      var phoneInput=qs('input[type="tel"]',box), send=qs('[data-recovery-send]',box);
      var msg=qs('.hamnoor-ajax-message',box);
      var requestedPart=(new URL(window.location.href)).searchParams.get('hamnoor_reading')||'';
      function message(text,type){showNotice(msg,text,type||'info');}
      function login(){
        var phone=phoneInput?normalizePhone(phoneInput.value):'';
        if(!validPhone(phone)){message('شماره موبایل معتبر نیست.','error');if(phoneInput){phoneInput.focus();}return;}
        message('در حال ورود به هم‌نور...','info'); if(send){send.disabled=true;}
        hamnoorPost({action:'hamnoor_begin_recovery',phone:phone,part_id:requestedPart}).then(function(j){
          if(send){send.disabled=false;}
          if(!j.success){throw new Error((j.data&&j.data.message)||'ورود انجام نشد.');}
          if(!j.data || j.data.requires_otp !== false){throw new Error('پاسخ ورود عادی معتبر نیست.');}
          message((j.data&&j.data.message)||'ورود انجام شد.','success');
          window.location.assign((j.data&&j.data.redirect)||HamnoorFrontend.dashboard_url);
        }).catch(function(err){if(send){send.disabled=false;}message(err.message||'خطا در ارتباط با سرور.','error');});
      }
      if(send){send.addEventListener('click',login);}
      if(phoneInput){phoneInput.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();login();}});}
    });
  }

  function initPersonalReminderForms(){
    qsa('.hamnoor-personal-reminder-form').forEach(function(form){
      if(form.dataset.hamnoorReady==='1'){return;} form.dataset.hamnoorReady='1';
      var enabled=qs('input[name="enabled"]',form), timezone=qs('[data-browser-timezone]',form);
      if(timezone && window.Intl && Intl.DateTimeFormat){
        try{var resolved=Intl.DateTimeFormat().resolvedOptions().timeZone;if(resolved){timezone.value=resolved;}}catch(e){}
      }
      function sync(){if(!enabled){return;} form.classList.toggle('is-disabled',!enabled.checked);}
      if(enabled){enabled.addEventListener('change',sync);sync();}
    });
  }

  function initEngagement(){
    qsa('[data-part-reminder-toggle]').forEach(function(input){
      if(input.dataset.hamnoorReady==='1'){return;} input.dataset.hamnoorReady='1';
      input.addEventListener('change',function(){
        var partId=input.getAttribute('data-part-id')||'';
        hamnoorPost({action:'hamnoor_toggle_part_reminder',part_id:partId,enabled:input.checked?'1':'0'}).then(function(j){
          if(!j.success){input.checked=!input.checked; alert((j.data&&j.data.message)||'تغییر یادآوری انجام نشد.');}
        }).catch(function(){input.checked=!input.checked;alert('خطا در ارتباط با سرور.');});
      });
    });
    qsa('[data-owner-reminder]').forEach(function(btn){
      if(btn.dataset.hamnoorReady==='1'){return;} btn.dataset.hamnoorReady='1';
      btn.addEventListener('click',function(){
        var partId=btn.getAttribute('data-part-id')||'', old=btn.textContent; btn.disabled=true; btn.textContent='در حال ثبت...';
        hamnoorPost({action:'hamnoor_owner_send_reminder',part_id:partId}).then(function(j){
          btn.disabled=false; if(!j.success){throw new Error((j.data&&j.data.message)||'یادآوری ثبت نشد.');}
          btn.textContent='✓ یادآوری ثبت شد'; window.setTimeout(function(){btn.textContent=old;},2500);
        }).catch(function(err){btn.disabled=false;btn.textContent=old;alert(err.message||'خطا در ارتباط با سرور.');});
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    initMobileAccess();
    initRecovery();
    initEngagement();
    initPersonalReminderForms();
    try {
      document.addEventListener('submit', handleClaimSubmit);
      document.addEventListener('submit', handleCompleteSubmit);
      document.addEventListener('submit', handleReleaseSubmit);
      document.addEventListener('submit', handleProfileSubmit);
      document.addEventListener('click', handleDocumentClick);
      document.addEventListener('click', handleModalBackdrop);
      document.addEventListener('keydown', handleKeydown);
      qsa('.hamnoor-modal').forEach(rememberClaimModalBody);
      primeClaimQuotaState();
      qsa('.hamnoor-wizard').forEach(initWizard);
      qsa('[data-hamnoor-list-tools]').forEach(initKhatmListTools);
      qsa('[data-hamnoor-dashboard]').forEach(initDashboard);
      initDateInputs();
      var auto = qs('#hamnoor-claim-modal[data-hamnoor-auto-open="1"]');
      if (auto) { loadClaimIdentity(auto); openModal(auto, null); }
    } catch (e) {
      debug('initialization failed', e);
    }
  });
})();
