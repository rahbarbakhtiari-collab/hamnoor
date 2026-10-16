(function(){
  'use strict';

  var C = window.HamnoorContributionEdit || {};
  var H = window.HamnoorFrontend || {};
  // wp_localize_script serializes scalar payload values as strings in this UI.
  // Normalize the structural lock explicitly so localized "0" is not treated as truthy.
  C.structure_locked = C.structure_locked === true || C.structure_locked === 1 || C.structure_locked === '1';
  var form = document.querySelector('.hamnoor-create-form input[name="khatm_id"][value="' + String(C.campaign_id || '') + '"]');
  form = form ? form.closest('.hamnoor-create-form') : null;
  if (!form || !C.campaign_id || (C.content_type !== 'dua' && C.content_type !== 'dhikr')) { return; }

  function esc(value){
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
  }
  function typeLabel(){ return C.content_type === 'dua' ? 'دعا' : 'ذکر'; }
  function strategyCopy(){
    return C.content_type === 'dua'
      ? 'هر مشارکت، یک قرائت کامل دعا را در دفتر مشارکت ثبت می‌کند.'
      : 'هر مشارکت، تعداد انجام‌شده را تا رسیدن به هدف عددی در دفتر مشارکت ثبت می‌کند.';
  }
  function ajax(action, data){
    var body = new URLSearchParams();
    body.append('action', action);
    body.append('nonce', H.nonce || '');
    Object.keys(data || {}).forEach(function(key){ body.append(key, data[key] == null ? '' : data[key]); });
    return fetch(H.ajax_url || window.ajaxurl || '', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:body.toString()
    }).then(function(response){ return response.json(); }).then(function(json){
      if (!json || json.success === false) { throw new Error((json && json.data && json.data.message) || 'دریافت محتوا انجام نشد.'); }
      return json.data || {};
    });
  }
  function hideLegacyChildren(step, keep){
    Array.prototype.forEach.call(step.children, function(node){
      if (node.tagName === 'LEGEND' || keep.indexOf(node) !== -1) { return; }
      node.setAttribute('data-contribution-edit-legacy', '1');
      node.setAttribute('aria-hidden', 'true');
    });
  }
  function changeStepLabel(index, label){
    var el = form.querySelector('.hamnoor-wizard-steps span:nth-child(' + index + ')');
    if (!el) { return; }
    el.setAttribute('data-step-title', label);
    el.innerHTML = String(index) + ' <b>' + esc(label) + '</b>';
  }

  var contentHidden = form.querySelector('input[name="content_id"]');
  if (!contentHidden) {
    contentHidden = document.createElement('input');
    contentHidden.type = 'hidden';
    contentHidden.name = 'content_id';
    form.appendChild(contentHidden);
  }
  contentHidden.value = C.content_id || '';
  var typeHidden = form.querySelector('input[name="content_type"]');
  if (!typeHidden) {
    typeHidden = document.createElement('input');
    typeHidden.type = 'hidden';
    typeHidden.name = 'content_type';
    form.appendChild(typeHidden);
  }
  typeHidden.value = C.content_type;

  var step2 = form.querySelector('.hamnoor-wizard-step[data-step="2"]');
  var step3 = form.querySelector('.hamnoor-wizard-step[data-step="3"]');
  var step4 = form.querySelector('.hamnoor-wizard-step[data-step="4"]');
  var step5 = form.querySelector('.hamnoor-wizard-step[data-step="5"]');
  if (!step2 || !step4) { return; }

  changeStepLabel(2, 'محتوا');
  changeStepLabel(4, 'مشارکت');
  if (step2.querySelector('legend')) { step2.querySelector('legend').textContent = '۲. محتوا و هدف پویش'; }
  if (step4.querySelector('legend')) { step4.querySelector('legend').textContent = '۴. مدل مشارکت'; }

  var panel = document.createElement('section');
  panel.className = 'hamnoor-contribution-edit-panel';
  panel.setAttribute('data-contribution-edit-panel', '1');
  panel.innerHTML = '' +
    '<div class="hamnoor-contribution-edit-head">' +
      '<div><strong>پویش ' + esc(typeLabel()) + '</strong><span>ویرایش اختصاصی بر پایه دفتر مشارکت؛ بدون سهم‌بندی قرآن.</span></div>' +
      '<span class="hamnoor-contribution-edit-badge">' + esc(C.content_type === 'dua' ? 'قرائت کامل' : 'هدف عددی') + '</span>' +
    '</div>' +
    (C.structure_locked ? '<div class="hamnoor-notice hamnoor-notice-warning"><strong>ساختار این پویش قفل شده است.</strong> چون اولین مشارکت ثبت شده، محتوا و هدف دیگر قابل تغییر نیستند؛ اطلاعات عمومی و زمان‌بندی همچنان قابل ویرایش‌اند.</div>' : '') +
    '<div class="hamnoor-contribution-edit-selected" data-contribution-selected>' +
      '<span>محتوای انتخاب‌شده</span><strong>' + esc(C.content_title || 'محتوای فعلی') + '</strong>' +
      '<small>' + esc(C.content_id || '') + '</small>' +
    '</div>' +
    '<label class="hamnoor-contribution-edit-search"><span>تغییر محتوا</span>' +
      '<input type="search" data-contribution-search autocomplete="off" placeholder="نام ' + esc(typeLabel()) + ' را جست‌وجو کنید" ' + (C.structure_locked ? 'disabled aria-disabled="true"' : '') + '>' +
      '<small>' + (C.structure_locked ? 'پس از اولین مشارکت، تغییر محتوا مجاز نیست.' : 'فقط محتوای فعال و مجاز برای ایجاد پویش نمایش داده می‌شود.') + '</small>' +
    '</label>' +
    '<div class="hamnoor-contribution-edit-results" data-contribution-results aria-live="polite"></div>' +
    '<label class="hamnoor-contribution-edit-target"><span>' + esc(C.content_type === 'dua' ? 'هدف: تعداد قرائت کامل' : 'هدف: تعداد کل ذکر') + '</span>' +
      '<input type="number" min="1" step="1" inputmode="numeric" name="target" value="' + esc(C.target || 1) + '" ' + (C.structure_locked ? 'readonly aria-readonly="true"' : 'required') + '>' +
      '<small>' + esc(C.structure_locked ? 'هدف پس از شروع مشارکت ثابت می‌ماند.' : 'تا قبل از اولین مشارکت می‌توانید هدف را اصلاح کنید.') + '</small>' +
    '</label>';
  step2.insertBefore(panel, step2.querySelector('legend') ? step2.querySelector('legend').nextSibling : step2.firstChild);
  hideLegacyChildren(step2, [panel]);

  var participation = document.createElement('section');
  participation.className = 'hamnoor-contribution-edit-participation';
  participation.innerHTML = '<div class="hamnoor-notice hamnoor-notice-info"><strong>مدل مشارکت این پویش از زمان ایجاد مشخص است.</strong><br>' + esc(strategyCopy()) + '<br><small>تنظیمات سهم، Claim، آزادسازی خودکار و شیوه تکمیلِ مخصوص پویش‌های قرآنی در این ویرایشگر اعمال نمی‌شوند.</small></div>';
  step4.insertBefore(participation, step4.querySelector('legend') ? step4.querySelector('legend').nextSibling : step4.firstChild);
  hideLegacyChildren(step4, [participation]);

  if (step3) {
    Array.prototype.forEach.call(step3.querySelectorAll('.hamnoor-completion-grace'), function(node){
      node.setAttribute('data-contribution-edit-legacy', '1');
      node.setAttribute('aria-hidden', 'true');
    });
  }

  var selectedBox = panel.querySelector('[data-contribution-selected]');
  var searchInput = panel.querySelector('[data-contribution-search]');
  var resultBox = panel.querySelector('[data-contribution-results]');
  var targetInput = panel.querySelector('input[name="target"]');
  var serial = 0;
  var timer = null;

  function choose(item){
    if (!item || C.structure_locked) { return; }
    contentHidden.value = item.id || '';
    C.content_id = item.id || '';
    C.content_title = item.title || '';
    selectedBox.innerHTML = '<span>محتوای انتخاب‌شده</span><strong>' + esc(C.content_title) + '</strong><small>' + esc(C.content_id) + '</small>';
    if (searchInput) { searchInput.value = C.content_title; }
    resultBox.innerHTML = '';
    syncSummary();
    form.dispatchEvent(new Event('input', {bubbles:true}));
  }
  function loadResults(query){
    if (C.structure_locked || !resultBox) { return; }
    var current = ++serial;
    resultBox.innerHTML = '<div class="hamnoor-notice hamnoor-notice-info">در حال جست‌وجو…</div>';
    ajax('hamnoor_creator_content_search', {content_type:C.content_type, search:query || ''}).then(function(data){
      if (current !== serial) { return; }
      var items = Array.isArray(data.items) ? data.items : [];
      if (!items.length) {
        resultBox.innerHTML = '<div class="hamnoor-notice hamnoor-notice-info">محتوای فعالی با این عبارت پیدا نشد.</div>';
        return;
      }
      resultBox.innerHTML = '';
      items.forEach(function(item){
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'hamnoor-contribution-edit-result';
        button.innerHTML = '<strong>' + esc(item.title) + '</strong><small>' + esc(item.id) + (item.max_contribution ? ' · سقف هر مشارکت: ' + esc(item.max_contribution) : '') + '</small>';
        button.addEventListener('click', function(){ choose(item); });
        resultBox.appendChild(button);
      });
    }).catch(function(error){
      if (current !== serial) { return; }
      resultBox.innerHTML = '<div class="hamnoor-notice hamnoor-notice-error">' + esc(error && error.message ? error.message : 'جست‌وجوی محتوا انجام نشد.') + '</div>';
    });
  }
  if (searchInput && !C.structure_locked) {
    searchInput.addEventListener('input', function(){
      clearTimeout(timer);
      var q = searchInput.value.trim();
      timer = setTimeout(function(){ loadResults(q); }, 250);
    });
    searchInput.addEventListener('focus', function(){ if (!resultBox.children.length) { loadResults(searchInput.value.trim()); } });
  }

  var summary = null;
  if (step5) {
    summary = document.createElement('div');
    summary.className = 'hamnoor-contribution-edit-summary';
    summary.setAttribute('data-contribution-edit-summary', '1');
    var legend = step5.querySelector('legend');
    step5.insertBefore(summary, legend ? legend.nextSibling : step5.firstChild);
  }
  function syncSummary(){
    if (!summary) { return; }
    var privacy = form.querySelector('[name="privacy"]');
    var title = form.querySelector('[name="title"]');
    summary.innerHTML = '<strong>خلاصه ویرایش پویش ' + esc(typeLabel()) + '</strong>' +
      '<span><b>عنوان:</b> ' + esc(title ? title.value : '') + '</span>' +
      '<span><b>محتوا:</b> ' + esc(C.content_title || '—') + '</span>' +
      '<span><b>هدف:</b> ' + esc(targetInput ? targetInput.value : C.target) + '</span>' +
      '<span><b>دسترسی:</b> ' + esc(privacy && privacy.value === 'private' ? 'خصوصی با لینک' : 'عمومی') + '</span>' +
      (C.structure_locked ? '<small>محتوا و هدف به دلیل ثبت مشارکت قفل هستند.</small>' : '<small>محتوا و هدف تا پیش از اولین مشارکت قابل اصلاح‌اند.</small>');
  }
  form.addEventListener('input', syncSummary);
  form.addEventListener('change', syncSummary);
  syncSummary();
})();
