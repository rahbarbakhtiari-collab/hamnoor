(function(){
  'use strict';

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
  function validEmail(v){ return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v || '').trim()); }
  function validPhone(v){ return /^09[0-9]{9}$/.test(normalizePhone(v)); }
  function isEmailLike(v){ return String(v || '').indexOf('@') > -1; }

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

  function setClaimModal(mode, trigger){
    var modal = qs('#hamnoor-claim-modal');
    if (!modal) { return; }
    rememberClaimModalBody(modal);
    restoreClaimModalBody(modal);
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
        el.innerHTML = '<div class="hamnoor-notice hamnoor-notice-success">' + text('complete_success', 'این ختم با همراهی شرکت‌کنندگان تکمیل شد.') + '</div>';
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
    card.classList.remove('hamnoor-part-claimed', 'hamnoor-part-available');
    card.classList.add('hamnoor-part-done');
    card.setAttribute('data-status', 'done');
    qsa('.hamnoor-status', card).forEach(function(el){
      el.classList.remove('hamnoor-status-claimed', 'hamnoor-status-available');
      el.classList.add('hamnoor-status-done');
      el.textContent = 'خوانده‌شده';
    });
    qsa('.hamnoor-complete-form, .hamnoor-release-form, .hamnoor-choose-part', card).forEach(function(form){ if (form.parentNode) { form.parentNode.removeChild(form); } });
    var participant = qs('.hamnoor-participant', card);
    if (data && data.show_participant_name && data.participant_name) {
      if (!participant) {
        participant = document.createElement('span');
        (qs('.hamnoor-part-card-main', card) || card).appendChild(participant);
      }
      participant.className = 'hamnoor-participant hamnoor-reader-name';
      participant.textContent = 'خوانده‌شده توسط: ' + data.participant_name;
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
      markCardDone(card, msg, false, data);
      var cardBox = ensureMessageBox(card);
      showNotice(cardBox, msg, 'success');
    } else {
      var box = ensureMessageBox(closest(form, '.hamnoor-card, .hamnoor-dashboard-panel') || form.parentNode);
      showNotice(box, msg, 'success');
    }
    updateProgress(data.progress, data.khatm_status);
  }

  function partTotal(form){
    var typeEl = qs('[name="khatm_type"]', form);
    var type = typeEl ? typeEl.value : 'page';
    var count = parseInt(normalizeDigits((qs('[name="khatm_count"]', form) || {}).value || '1'), 10) || 1;
    var repeat = parseInt(normalizeDigits((qs('[name="repeat_count"]', form) || {}).value || '0'), 10) || 0;
    var unit = type === 'juz' ? 30 : (type === 'surah' ? 114 : (type === 'repeated_surah' ? repeat : 604));
    return unit * (type === 'repeated_surah' ? 1 : Math.max(1, count));
  }

  function hamnoorDesignClass(value){
    value = String(value || 'default').replace(/[^a-z0-9_-]/g, '') || 'default';
    return 'hamnoor-style-' + value;
  }

  function updateDesignPreview(form){
    if (!form) { return; }
    var select = qs('[name="design_style"]', form);
    if (!select) { return; }
    var styles = ['default','blue-quran','golden','memorial','ramadan','heyati'];
    qsa('.hamnoor-preview-card', form).forEach(function(card){
      styles.forEach(function(style){ card.classList.remove('hamnoor-style-' + style); });
      card.classList.add(hamnoorDesignClass(select.value));
    });
  }

  function updateSummary(form){
    if (!form) { return; }
    var val = function(name){
      var el = qs('[name="' + name + '"]', form);
      if (!el) { return ''; }
      if (el.tagName === 'SELECT') { return el.options[el.selectedIndex] ? el.options[el.selectedIndex].text : el.value; }
      return el.value;
    };
    var set = function(key, value){ qsa('[data-summary="' + key + '"]', form).forEach(function(el){ el.textContent = value || '—'; }); };
    set('title', val('title'));
    set('type', val('khatm_type'));
    set('khatm_count', val('khatm_count') || '1');
    set('owner_name', val('owner_name'));
    set('total_parts', String(partTotal(form)));
    var privacy = qs('[name="privacy"]', form);
    set('privacy', privacy && privacy.value === 'private' ? 'خصوصی' : 'عمومی');
    var indexable = qs('[name="indexable"][type="checkbox"]', form);
    set('indexable', indexable && indexable.checked ? 'بله' : 'خیر');
    var ds = qs('#hamnoor-design-style', form);
    set('design_style', ds && ds.options[ds.selectedIndex] ? ds.options[ds.selectedIndex].text : val('design_style'));
    var completion = qs('[name="completion_mode"]:checked', form);
    var autoDone = completion && completion.value === 'auto_done_on_claim';
    set('completion_mode', autoDone ? 'خیر؛ با انتخاب سهم انجام‌شده محسوب می‌شود' : 'بله؛ پس از قرائت باید ثبت شود');
    var rel = qs('[name="auto_release_mode"]', form);
    var relText = 'آزادسازی خودکار انجام نشود';
    if (!autoDone && rel && rel.value === 'after_days') { relText = 'پس از چند روز'; }
    else if (!autoDone && rel && rel.value === 'fixed_date') { relText = 'در تاریخ مشخص'; }
    set('auto_release_mode', relText);
    updateDesignPreview(form);
  }

  function updateTypeFields(form){
    var type = qs('[name="khatm_type"]', form);
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
    form.classList.toggle('is-release-fixed-date', manual && mode && mode.value === 'fixed_date');
  }

  function validateStep(form, step){
    clearFieldErrors(step);
    var ok = true;
    var first = null;
    var maxCount = parseInt(form.getAttribute('data-max-khatm-count') || '100', 10) || 100;
    var maxRepeat = parseInt(form.getAttribute('data-max-repeat-count') || '1000', 10) || 1000;
    var hardLimit = parseInt(form.getAttribute('data-hard-limit') || '12000', 10) || 12000;
    var maxMessage = form.getAttribute('data-max-khatm-message') || ('تعداد ختم کامل قرآن باید بین ۱ تا ' + maxCount + ' باشد.');
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
      if (title && !title.value.trim()) { fail('title', 'عنوان ختم الزامی است.'); }
    }
    if (no === 2) {
      var type = (qs('[name="khatm_type"]', form) || {}).value || 'page';
      var count = parseInt(normalizeDigits((qs('[name="khatm_count"]', form) || {}).value || '1'), 10) || 0;
      var repeat = parseInt(normalizeDigits((qs('[name="repeat_count"]', form) || {}).value || '0'), 10) || 0;
      var selectedSurah = parseInt(normalizeDigits((qs('[name="selected_surah_number"]', form) || {}).value || '0'), 10) || 0;
      if (type === 'page' || type === 'juz' || type === 'surah') { if (count < 1 || count > maxCount) { fail('khatm_count', maxMessage); } }
      if (type === 'repeated_surah' && (selectedSurah < 1 || selectedSurah > 114)) { fail('selected_surah_number', 'لطفاً یک سوره معتبر انتخاب کنید.'); }
      if (type === 'repeated_surah') { if (repeat < 1 || repeat > maxRepeat) { fail('repeat_count', maxRepeatMessage); } }
      if (partTotal(form) > hardLimit) { fail(type === 'repeated_surah' ? 'repeat_count' : 'khatm_count', 'تعداد سهم‌های این ختم از حد مجاز پردازش هم‌زمان بیشتر است.'); }
    }
    if (no === 4) {
      var name = qs('[name="owner_name"]', step);
      var phone = qs('[name="owner_phone"]', step);
      var email = qs('[name="owner_email"]', step);
      if (name && !name.value.trim()) { fail('owner_name', 'نام ایجادکننده الزامی است.'); }
      if (phone && !validPhone(phone.value)) { fail('owner_phone', 'شماره موبایل را مانند 09123456789 وارد کنید.'); }
      if (email && !validEmail(email.value.trim())) { fail('owner_email', 'ایمیل ایجادکننده معتبر نیست.'); }
    }
    if (no === 5) {
      var st = normalizeDigits((qs('[name="starts_at_jalali"]', form) || {}).value || '');
      var en = normalizeDigits((qs('[name="ends_at_jalali"]', form) || {}).value || '');
      if (st && en && en < st) { fail('ends_at_jalali', 'تاریخ پایان نباید قبل از تاریخ شروع باشد.'); }
    }
    if (no === 6) {
      var completion = qs('[name="completion_mode"]:checked', form);
      var needsManualDone = !completion || completion.value !== 'auto_done_on_claim';
      var releaseMode = needsManualDone ? ((qs('[name="auto_release_mode"]', form) || {}).value || 'none') : 'none';
      if (releaseMode === 'after_days') {
        var days = parseInt(normalizeDigits((qs('[name="auto_release_after_days"]', form) || {}).value || '0'), 10) || 0;
        if (days < 1) { fail('auto_release_after_days', 'تعداد روز آزادسازی باید عددی مثبت باشد.'); }
      }
      if (releaseMode === 'fixed_date') {
        var releaseAtVal = normalizeDigits((qs('[name="auto_release_at_jalali"]', form) || {}).value || '');
        if (!releaseAtVal) { fail('auto_release_at_jalali', 'تاریخ آزادسازی سهم‌های خوانده‌نشده را وارد کنید.'); }
        else {
          var releaseTs = jalaliInputTimestamp(releaseAtVal);
          var minTs = parseInt(form.getAttribute('data-release-min-ts') || '0', 10) || Math.floor(Date.now() / 1000);
          if (releaseTs && releaseTs <= minTs) { fail('auto_release_at_jalali', (closest(form, '.is-editing')) ? 'تاریخ آزادسازی باید بعد از تاریخ ایجاد ختم باشد.' : 'تاریخ آزادسازی باید بعد از زمان فعلی باشد.'); }
        }
      }
    }
    if (!ok) { formSummary(step || form); if (first) { first.focus(); } }
    return ok;
  }

  function initWizard(form){
    if (!form || form.dataset.hamnoorWizardReady === '1') { return; }
    form.dataset.hamnoorWizardReady = '1';
    form.classList.add('js-ready');
    qsa('.hamnoor-surah-search', form).forEach(initSurahSearch);
    var steps = qsa('.hamnoor-wizard-step', form);
    var current = 1;
    var initialError = qs('.hamnoor-field-has-error, [aria-invalid="true"]', form);
    if (initialError) {
      var initialStep = closest(initialError, '.hamnoor-wizard-step');
      current = initialStep ? (parseInt(initialStep.getAttribute('data-step'), 10) || 1) : 1;
    }
    function show(index, keepMessages){
      current = Math.max(1, Math.min(steps.length, index));
      if (!keepMessages) { clearWizardGeneralMessages(form); }
      steps.forEach(function(step){
        var active = parseInt(step.getAttribute('data-step'), 10) === current;
        step.hidden = !active;
        step.classList.toggle('is-active', active);
      });
      qsa('.hamnoor-wizard-steps [data-go-step], .hamnoor-wizard-steps [data-step-title]', form).forEach(function(btn, idx){
        var stepNo = parseInt(btn.getAttribute('data-go-step') || btn.getAttribute('data-step') || (idx + 1), 10);
        btn.classList.toggle('is-active', stepNo === current);
        btn.classList.toggle('is-done', stepNo < current);
      });
      var prevBtn = qs('[data-prev-step], [data-wizard-prev]', form);
      var nextBtn = qs('[data-next-step], [data-wizard-next]', form);
      var submitBtn = qs('[data-wizard-submit]', form);
      if (prevBtn) { prevBtn.hidden = current <= 1; }
      if (nextBtn) { nextBtn.hidden = current >= steps.length; }
      if (submitBtn) { submitBtn.hidden = current < steps.length; }
      updateSummary(form);
    }
    form.addEventListener('click', function(e){
      var next = e.target.closest('[data-next-step], [data-wizard-next]');
      var prev = e.target.closest('[data-prev-step], [data-wizard-prev]');
      var go = e.target.closest('[data-go-step]');
      if (next) { e.preventDefault(); var step = closest(next, '.hamnoor-wizard-step') || steps[current - 1]; if (validateStep(form, step)) { show(current + 1); } }
      if (prev) { e.preventDefault(); show(current - 1); }
      if (go) { e.preventDefault(); var requested = parseInt(go.getAttribute('data-go-step'), 10); if (requested <= current || validateStep(form, steps[current - 1])) { show(requested); } }
    });
    form.addEventListener('submit', function(e){
      var valid = true;
      steps.forEach(function(step){ if (!validateStep(form, step)) { valid = false; } });
      if (!valid) {
        e.preventDefault();
        var errorEl = qs('.hamnoor-field-has-error, [aria-invalid="true"]', form);
        var errorStep = closest(errorEl, '.hamnoor-wizard-step') || steps[0];
        show(parseInt(errorStep.getAttribute('data-step'), 10) || 1, true);
        var submitBtn = qs('[data-wizard-submit]', form);
        if (submitBtn) { submitBtn.disabled = false; submitBtn.removeAttribute('aria-busy'); }
      }
    });
    form.addEventListener('input', function(e){ clearSingleFieldError(e.target); updateTypeFields(form); updateReleaseFields(form); updateSummary(form); });
    form.addEventListener('change', function(e){ clearSingleFieldError(e.target); updateTypeFields(form); updateReleaseFields(form); updateSummary(form); });
    updateTypeFields(form);
    updateReleaseFields(form);
    show(current, true);
  }

  function handleClaimSubmit(e){
    var form = e.target;
    if (!form.matches('.hamnoor-claim-form')) { return; }
    e.preventDefault();
    clearFieldErrors(form);
    var name = qs('[name="name"]', form);
    var phone = qs('[name="phone"]', form);
    var email = qs('[name="email"]', form);
    var ok = true, first = null;
    if (!name || !name.value.trim()) { setFieldError(name, 'نام و نام خانوادگی را وارد کنید.'); ok = false; first = first || name; }
    if (!phone || !validPhone(phone.value)) { setFieldError(phone, text('mobile_invalid', 'شماره موبایل واردشده معتبر نیست.')); ok = false; first = first || phone; }
    if (email && email.value.trim() && !validEmail(email.value.trim())) { setFieldError(email, text('email_invalid', 'ایمیل واردشده معتبر نیست.')); ok = false; first = first || email; }
    if (!ok) { formSummary(form); if (first) { first.focus(); } return; }

    var btn = qs('button[type="submit"]', form);
    var restore = disableButton(btn, text('loading', 'در حال ثبت...'));
    var msgBox = qs('.hamnoor-modal-feedback', closest(form, '.hamnoor-modal')) || ensureMessageBox(form.parentNode);
    showNotice(msgBox, 'در حال ثبت انتخاب سهم...', 'info');
    hamnoorAjax('hamnoor_claim_part', {
      khatm_token: (qs('[name="khatm_token"]', form) || {}).value || '',
      claim_mode: (qs('[name="claim_mode"]', form) || {}).value || 'manual',
      part_id: (qs('[name="part_id"]', form) || {}).value || '',
      name: name.value,
      phone: normalizePhone(phone.value),
      email: email ? email.value : '',
      website: (qs('[name="website"]', form) || {}).value || ''
    }).then(function(res){
      restore();
      var modal = closest(form, '.hamnoor-modal');
      var body = claimModalBody(modal);
      if (res.data && res.data.part_id) { markCardClaimed(res.data.part_id, res.data); }
      if (body && res.data && res.data.html) { body.innerHTML = res.data.html; }
      updateProgress(res.data && res.data.progress, res.data && res.data.khatm_status);
    }).catch(function(error){
      restore();
      applyFieldErrors(form, error && error.data && error.data.errors);
      showNotice(msgBox, messageFromError(error, 'ثبت سهم انجام نشد.'), 'error');
    });
  }

  function handleCompleteSubmit(e){
    var form = e.target;
    if (!form.matches('.hamnoor-complete-form, [data-hamnoor-complete]')) { return; }
    e.preventDefault();
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
      completeSuccessUI(form, res.data || {});
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
      if (card) { markCardAvailable(card, res.data || {}); }
      showNotice(box, (res.data && res.data.message) || text('release_success', 'سهم با موفقیت آزاد شد و دوباره برای انتخاب دیگران در دسترس قرار گرفت.'), 'success');
      updateProgress(res.data && res.data.progress, res.data && res.data.khatm_status);
    }).catch(function(error){
      restore();
      showNotice(box, messageFromError(error, 'آزادسازی سهم انجام نشد.'), 'error');
    });
  }

  function findTrackResults(form){
    var scope = closest(form, '.hamnoor-track-page, .hamnoor-dashboard-panel, .hamnoor-card, .hamnoor-wrap') || form.parentNode;
    var box = qs('[data-hamnoor-track-results]', scope) || qs('.hamnoor-track-results', scope);
    if (!box) {
      box = document.createElement('div');
      box.className = 'hamnoor-track-results';
      box.setAttribute('data-hamnoor-track-results', '1');
      box.setAttribute('aria-live', 'polite');
      form.insertAdjacentElement('afterend', box);
    }
    return box;
  }

  function handleTrackSubmit(e){
    var form = e.target;
    if (!form.matches('[data-hamnoor-track]')) { return; }
    e.preventDefault();
    clearFieldErrors(form);
    var input = qs('[name="hamnoor_contact"]', form) || qs('[name="contact"]', form);
    var raw = input ? input.value : '';
    var value = isEmailLike(raw) ? raw.trim() : normalizePhone(raw);
    var ok = isEmailLike(raw) ? validEmail(value) : validPhone(value);
    if (!ok) {
      setFieldError(input, isEmailLike(raw) ? text('email_invalid', 'ایمیل واردشده معتبر نیست.') : text('mobile_invalid', 'شماره موبایل واردشده معتبر نیست.'));
      formSummary(form);
      if (input) { input.focus(); }
      return;
    }
    if (input && !isEmailLike(raw)) { input.value = value; }
    var out = findTrackResults(form);
    var btn = qs('button[type="submit"]', form);
    var restore = disableButton(btn, text('checking', 'در حال بررسی...'));
    showNotice(out, 'در حال جستجوی سهم‌ها...', 'info');
    hamnoorAjax('hamnoor_track_parts', {contact: value}).then(function(res){
      restore();
      out.innerHTML = (res.data && res.data.html) ? res.data.html : '<p class="hamnoor-empty">با این موبایل یا ایمیل سهمی ثبت نشده است.</p>';
    }).catch(function(error){
      restore();
      applyFieldErrors(form, error && error.data && error.data.errors);
      showNotice(out, messageFromError(error, 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.'), 'error');
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
      email: (qs('[name="email"]', form) || {}).value || '',
      password: (qs('[name="password"]', form) || {}).value || ''
    }).then(function(res){
      restore();
      showNotice(box, (res.data && res.data.message) || 'مشخصات شما ذخیره شد.', 'success');
    }).catch(function(error){
      restore();
      applyFieldErrors(form, error && error.data && error.data.errors);
      showNotice(box, messageFromError(error, text('form_incomplete', 'لطفاً خطاها را اصلاح کنید.')), 'error');
    });
  }

  function initDashboard(dashboard){
    if (!dashboard || dashboard.dataset.hamnoorDashboardReady === '1') { return; }
    dashboard.dataset.hamnoorDashboardReady = '1';
    var allowed = qsa('[data-tab-panel]', dashboard).map(function(panel){ return panel.getAttribute('data-tab-panel'); });
    function activate(tab, push){
      if (allowed.indexOf(tab) === -1) { tab = 'summary'; }
      qsa('[data-tab-target]', dashboard).forEach(function(btn){
        var active = btn.getAttribute('data-tab-target') === tab;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      qsa('[data-tab-panel]', dashboard).forEach(function(panel){
        var active = panel.getAttribute('data-tab-panel') === tab;
        panel.hidden = !active;
        panel.classList.toggle('is-active', active);
      });
      dashboard.setAttribute('data-active-tab', tab);
      if (push && window.history && window.history.pushState) {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.pushState({hamnoorTab: tab}, '', url.toString());
      }
    }
    var urlTab = new URL(window.location.href).searchParams.get('tab');
    activate(urlTab || dashboard.getAttribute('data-active-tab') || 'summary', false);
    dashboard.addEventListener('click', function(e){
      var btn = e.target.closest('[data-tab-target]');
      if (!btn || !dashboard.contains(btn)) { return; }
      e.preventDefault();
      activate(btn.getAttribute('data-tab-target') || 'summary', true);
    });
    window.addEventListener('popstate', function(){ activate(new URL(window.location.href).searchParams.get('tab') || 'summary', false); });
  }

  function handleDocumentClick(e){
    var manual = e.target.closest('.hamnoor-choose-part');
    var next = e.target.closest('[data-hamnoor-next]');
    var random = e.target.closest('[data-hamnoor-random]');
    var close = e.target.closest('[data-hamnoor-modal-close], [data-hamnoor-close-success]');
    var later = e.target.closest('[data-hamnoor-complete-later]');
    var copy = e.target.closest('.hamnoor-copy');
    var copyInvite = e.target.closest('.hamnoor-copy-invite');
    var share = e.target.closest('.hamnoor-web-share');

    if (manual) { e.preventDefault(); setClaimModal('manual', manual); return; }
    if (next) { e.preventDefault(); setClaimModal('next', next); return; }
    if (random) { e.preventDefault(); setClaimModal('random', random); return; }
    if (later) {
      e.preventDefault();
      var laterBox = ensureMessageBox(closest(later, '.hamnoor-claim-success, .hamnoor-modal-body') || later.parentNode);
      showNotice(laterBox, later.getAttribute('data-warning') || 'سهم برای شما رزرو شد. لطفاً پس از قرائت، آن را از صفحه پیگیری سهم‌ها یا داشبورد ثبت کنید.', 'warning');
      later.textContent = 'بستن';
      later.setAttribute('data-hamnoor-modal-close', '1');
      later.removeAttribute('data-hamnoor-complete-later');
      return;
    }
    if (close) { e.preventDefault(); closeModal(closest(close, '.hamnoor-modal')); return; }
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

  document.addEventListener('DOMContentLoaded', function(){
    try {
      document.addEventListener('submit', handleClaimSubmit);
      document.addEventListener('submit', handleCompleteSubmit);
      document.addEventListener('submit', handleTrackSubmit);
      document.addEventListener('submit', handleReleaseSubmit);
      document.addEventListener('submit', handleProfileSubmit);
      document.addEventListener('click', handleDocumentClick);
      document.addEventListener('click', handleModalBackdrop);
      document.addEventListener('keydown', handleKeydown);
      qsa('.hamnoor-modal').forEach(rememberClaimModalBody);
      qsa('.hamnoor-wizard').forEach(initWizard);
      qsa('[data-hamnoor-dashboard]').forEach(initDashboard);
      initDateInputs();
      var auto = qs('#hamnoor-claim-modal[data-hamnoor-auto-open="1"]');
      if (auto) { openModal(auto, null); }
    } catch (e) {
      debug('initialization failed', e);
    }
  });
})();
