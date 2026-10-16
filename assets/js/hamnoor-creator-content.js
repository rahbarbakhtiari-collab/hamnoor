(function(){
  'use strict';

  var H = window.HamnoorFrontend || {};
  var form = document.querySelector('.hamnoor-create-form');
  if (!form || form.querySelector('input[name="khatm_id"]')) { return; }

  var step2 = form.querySelector('.hamnoor-wizard-step[data-step="2"]');
  var step4 = form.querySelector('.hamnoor-wizard-step[data-step="4"]');
  if (!step2 || !step4) { return; }

  function esc(value){
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
  }
  function digits(value){
    return String(value || '').replace(/[۰-۹]/g, function(d){ return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
      .replace(/[٠-٩]/g, function(d){ return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
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

  var kind = document.createElement('section');
  kind.className = 'hamnoor-creator-content-kind';
  kind.innerHTML = '' +
    '<div class="hamnoor-content-kind-head"><strong>نوع محتوای پویش</strong><span>پویش قرآن، دعا یا ذکر را انتخاب کنید.</span></div>' +
    '<div class="hamnoor-content-kind-options" role="radiogroup" aria-label="نوع محتوای پویش">' +
      '<label><input type="radio" name="content_type" value="quran" checked><span><strong>قرآن</strong><small>تقسیم صفحه، جزء، سوره یا تکرار سوره</small></span></label>' +
      '<label><input type="radio" name="content_type" value="dua"><span><strong>دعا</strong><small>هر مشارکت برابر یک قرائت کامل دعا</small></span></label>' +
      '<label><input type="radio" name="content_type" value="dhikr"><span><strong>ذکر</strong><small>ثبت تعداد انجام‌شده تا رسیدن به هدف</small></span></label>' +
    '</div>';

  var registry = document.createElement('section');
  registry.className = 'hamnoor-creator-registry';
  registry.hidden = true;
  registry.innerHTML = '' +
    '<div class="hamnoor-creator-registry-copy"><strong data-content-picker-title>انتخاب محتوا</strong><span>فقط محتوای فعال و مجاز به ایجاد پویش نمایش داده می‌شود.</span></div>' +
    '<label class="hamnoor-creator-search"><span>جست‌وجوی محتوا</span><input type="search" data-content-search autocomplete="off" placeholder="نام دعا یا ذکر را بنویسید"></label>' +
    '<input type="hidden" name="content_id" value="">' +
    '<div class="hamnoor-creator-selected" data-content-selected hidden></div>' +
    '<div class="hamnoor-creator-results" data-content-results aria-live="polite"></div>' +
    '<label class="hamnoor-creator-target"><span data-target-label>هدف پویش</span><input type="number" name="target" min="1" step="1" inputmode="numeric" value="1" required><small data-target-help></small></label>';

  var firstNonLegend = Array.prototype.find.call(step2.children, function(node){ return node.tagName !== 'LEGEND'; });
  step2.insertBefore(kind, firstNonLegend || null);
  step2.insertBefore(registry, kind.nextSibling);

  var quranNodes = Array.prototype.filter.call(step2.children, function(node){
    return node !== kind && node !== registry && node.tagName !== 'LEGEND';
  });
  quranNodes.forEach(function(node){ node.setAttribute('data-quran-creator-only', '1'); });

  var step4Legend = step4.querySelector('legend');
  var step4OriginalLegend = step4Legend ? step4Legend.textContent : '';
  var step4Nodes = Array.prototype.filter.call(step4.children, function(node){ return node.tagName !== 'LEGEND'; });
  step4Nodes.forEach(function(node){ node.setAttribute('data-quran-creator-only', '1'); });
  var contributionStep = document.createElement('div');
  contributionStep.className = 'hamnoor-creator-contribution-step';
  contributionStep.hidden = true;
  step4.appendChild(contributionStep);

  var step4Indicator = form.querySelector('.hamnoor-wizard-steps span:nth-child(4)');
  var step4IndicatorTitle = step4Indicator ? step4Indicator.getAttribute('data-step-title') : '';
  var step4IndicatorHtml = step4Indicator ? step4Indicator.innerHTML : '';

  var searchInput = registry.querySelector('[data-content-search]');
  var resultBox = registry.querySelector('[data-content-results]');
  var selectedBox = registry.querySelector('[data-content-selected]');
  var contentId = registry.querySelector('input[name="content_id"]');
  var target = registry.querySelector('input[name="target"]');
  var targetLabel = registry.querySelector('[data-target-label]');
  var targetHelp = registry.querySelector('[data-target-help]');
  var pickerTitle = registry.querySelector('[data-content-picker-title]');
  var lastMode = '';
  var selectedMeta = null;
  var timer = null;
  var requestSerial = 0;

  function mode(){
    var checked = form.querySelector('input[name="content_type"]:checked');
    return checked ? checked.value : 'quran';
  }

  function typeLabel(value){ return value === 'dua' ? 'دعا' : (value === 'dhikr' ? 'ذکر' : 'قرآن'); }

  function renderSelected(item){
    selectedMeta = item || null;
    if (!item) {
      selectedBox.hidden = true;
      selectedBox.innerHTML = '';
      return;
    }
    selectedBox.hidden = false;
    selectedBox.innerHTML = '<strong>' + esc(item.title) + '</strong><small>' +
      (item.source_type === 'mafatih' ? 'منبع: مفاتیح' : 'منبع: محتوای دستی') +
      (item.max_contribution ? ' · سقف هر مشارکت: ' + esc(item.max_contribution) : '') + '</small>';
  }

  function clearCreatorFieldError(field){
    if (!field) { return; }
    field.removeAttribute('aria-invalid');
    var holder = field.closest ? field.closest('label') : field.parentNode;
    if (!holder) { return; }
    holder.classList.remove('hamnoor-field-has-error');
    Array.prototype.forEach.call(holder.querySelectorAll('.hamnoor-field-error'), function(error){
      if (error.parentNode) { error.parentNode.removeChild(error); }
    });
  }

  function creatorFieldError(field, message){
    if (!field) { return; }
    clearCreatorFieldError(field);
    field.setAttribute('aria-invalid', 'true');
    var holder = field.closest ? field.closest('label') : field.parentNode;
    if (!holder) { return; }
    holder.classList.add('hamnoor-field-has-error');
    var error = document.createElement('span');
    error.className = 'hamnoor-field-error';
    error.setAttribute('aria-live', 'polite');
    error.textContent = message || 'این فیلد را بررسی کنید.';
    holder.appendChild(error);
  }

  function clearCreatorValidation(){
    clearCreatorFieldError(searchInput);
    clearCreatorFieldError(target);
    var summary = registry.querySelector('[data-creator-validation]');
    if (summary && summary.parentNode) { summary.parentNode.removeChild(summary); }
  }

  function validateContributionStep(shouldFocus){
    if (mode() === 'quran') { return true; }
    clearCreatorValidation();
    var id = String(contentId.value || '').trim().toUpperCase();
    var quantity = parseInt(digits(target.value || ''), 10) || 0;
    var first = null;
    var messages = [];

    if (!/^CNT-[A-Z0-9]{12,28}$/.test(id)) {
      creatorFieldError(searchInput, 'یک دعا یا ذکر فعال را از فهرست انتخاب کنید.');
      first = first || searchInput;
      messages.push('انتخاب محتوای فعال الزامی است.');
    }
    if (quantity < 1) {
      creatorFieldError(target, 'هدف پویش باید عددی بزرگ‌تر از صفر باشد.');
      first = first || target;
      messages.push('هدف پویش باید مشخص باشد.');
    }
    if (!messages.length) { return true; }

    var summary = document.createElement('div');
    summary.className = 'hamnoor-notice hamnoor-notice-error';
    summary.setAttribute('data-creator-validation', '1');
    summary.setAttribute('aria-live', 'polite');
    summary.textContent = messages.join(' ');
    registry.insertBefore(summary, registry.firstChild);
    if (shouldFocus !== false && first && first.focus) { first.focus(); }
    return false;
  }

  function choose(item){
    contentId.value = item.id || '';
    renderSelected(item);
    resultBox.innerHTML = '';
    searchInput.value = item.title || '';
    clearCreatorValidation();
    syncSummary();
  }

  function loadResults(query, preferExact){
    var currentMode = mode();
    if (currentMode === 'quran') { return; }
    var serial = ++requestSerial;
    resultBox.innerHTML = '<div class="hamnoor-notice hamnoor-notice-info">در حال دریافت محتوا…</div>';
    ajax('hamnoor_creator_content_search', {content_type:currentMode, search:query || ''})
      .then(function(data){
        if (serial !== requestSerial || currentMode !== mode()) { return; }
        var items = Array.isArray(data.items) ? data.items : [];
        if (preferExact && contentId.value) {
          var exact = items.filter(function(item){ return String(item.id) === String(contentId.value); })[0];
          if (exact) { renderSelected(exact); clearCreatorValidation(); return; }
          contentId.value = '';
          renderSelected(null);
        }
        if (!items.length) {
          resultBox.innerHTML = '<div class="hamnoor-notice hamnoor-notice-warning">محتوای فعالی با این جست‌وجو پیدا نشد.</div>';
          return;
        }
        resultBox.innerHTML = items.map(function(item){
          var meta = item.source_type === 'mafatih' ? 'مفاتیح' : 'دستی';
          return '<button type="button" class="hamnoor-creator-result" data-content-id="' + esc(item.id) + '">' +
            '<strong>' + esc(item.title) + '</strong><small>' + esc(meta) + (item.max_contribution ? ' · سقف: ' + esc(item.max_contribution) : '') + '</small></button>';
        }).join('');
        Array.prototype.forEach.call(resultBox.querySelectorAll('[data-content-id]'), function(button, index){
          button.addEventListener('click', function(){ choose(items[index]); });
        });
      })
      .catch(function(error){
        if (serial !== requestSerial) { return; }
        resultBox.innerHTML = '<div class="hamnoor-notice hamnoor-notice-error">' + esc(error.message || 'دریافت محتوا انجام نشد.') + '</div>';
      });
  }

  function setNodeControlsDisabled(nodes, disabled){
    nodes.forEach(function(node){
      Array.prototype.forEach.call(node.querySelectorAll('input,select,textarea'), function(control){
        if (!control.hasAttribute('data-creator-original-disabled')) {
          control.setAttribute('data-creator-original-disabled', control.disabled ? '1' : '0');
        }
        control.disabled = disabled ? true : control.getAttribute('data-creator-original-disabled') === '1';
      });
    });
  }

  function setRegistryControlsDisabled(disabled){
    Array.prototype.forEach.call(registry.querySelectorAll('input,select,textarea,button'), function(control){
      control.disabled = !!disabled;
    });
  }

  function temporaryLegacyDefaults(stepNo){
    var snapshots = [];
    function saveField(field){
      if (!field) { return; }
      snapshots.push({field:field, value:field.value, checked:field.checked});
    }
    function setValue(name, value){
      var field = form.querySelector('[name="' + name + '"]');
      if (!field) { return; }
      saveField(field);
      field.value = value;
    }
    function setRadio(name, value){
      Array.prototype.forEach.call(form.querySelectorAll('[name="' + name + '"]'), function(field){
        saveField(field);
        field.checked = String(field.value) === String(value);
      });
    }

    if (stepNo === 2) {
      setRadio('khatm_type', 'page');
      setValue('khatm_count', '1');
      setValue('selected_surah_number', '1');
      setValue('repeat_count', '1');
    } else if (stepNo === 4) {
      setValue('max_active_claims_per_participant', '3');
      setRadio('completion_mode', 'manual_done');
      setValue('auto_release_mode', 'none');
      setValue('auto_release_after_days', '1');
    }
    if (!snapshots.length) { return; }
    window.setTimeout(function(){
      snapshots.forEach(function(snapshot){
        snapshot.field.value = snapshot.value;
        snapshot.field.checked = snapshot.checked;
      });
      if (mode() !== 'quran') { syncSummary(); }
    }, 0);
  }

  function clearCreatorLocalDraft(){
    var user = parseInt(form.getAttribute('data-draft-user') || H.user_id || '0', 10) || 0;
    var scope = parseInt(form.getAttribute('data-draft-scope') || '0', 10) || 0;
    if (!user) { return; }
    try { window.localStorage.removeItem('hamnoor_khatm_draft_v2_' + user + '_' + scope); } catch(e) {}
  }

  function syncStep4(currentMode){
    var nonQuran = currentMode !== 'quran';
    step4Nodes.forEach(function(node){ node.hidden = nonQuran; });
    contributionStep.hidden = !nonQuran;
    if (!nonQuran) {
      if (step4Legend) { step4Legend.textContent = step4OriginalLegend; }
      if (step4Indicator) {
        step4Indicator.setAttribute('data-step-title', step4IndicatorTitle || 'تنظیمات قرائت');
        step4Indicator.innerHTML = step4IndicatorHtml;
      }
      return;
    }
    if (step4Legend) { step4Legend.textContent = '۴. تنظیمات مشارکت'; }
    if (step4Indicator) {
      step4Indicator.setAttribute('data-step-title', 'مشارکت');
      step4Indicator.innerHTML = '۴ <b>مشارکت</b>';
    }
    contributionStep.innerHTML = currentMode === 'dua'
      ? '<div class="hamnoor-notice hamnoor-notice-info"><strong>مدل مشارکت دعا:</strong> هر ثبت برابر یک قرائت کامل از دعای انتخاب‌شده است. این پویش سهم‌بندی ندارد.</div>'
      : '<div class="hamnoor-notice hamnoor-notice-info"><strong>مدل مشارکت ذکر:</strong> هر شخص تعداد انجام‌شده را ثبت می‌کند. هم‌نور سقف هر نوبت و باقیمانده هدف را سمت سرور اعمال می‌کند.</div>';
  }

  var summaryGroups = form.querySelectorAll('.hamnoor-summary-group');
  var summaryOriginal = [];
  Array.prototype.forEach.call(summaryGroups, function(group){
    summaryOriginal.push({
      heading: group.querySelector('h3') ? group.querySelector('h3').textContent : '',
      terms: Array.prototype.map.call(group.querySelectorAll('dt'), function(dt){ return dt.textContent; })
    });
  });

  function restoreSummaryLabels(){
    Array.prototype.forEach.call(summaryGroups, function(group, groupIndex){
      var snapshot = summaryOriginal[groupIndex];
      if (!snapshot) { return; }
      var heading = group.querySelector('h3');
      if (heading) { heading.textContent = snapshot.heading; }
      Array.prototype.forEach.call(group.querySelectorAll('dt'), function(dt, index){
        if (snapshot.terms[index] != null) { dt.textContent = snapshot.terms[index]; }
      });
    });
  }

  function syncSummary(){
    var currentMode = mode();
    if (currentMode === 'quran') { restoreSummaryLabels(); return; }
    var summaryType = form.querySelector('[data-summary="type"]');
    if (summaryType) { summaryType.textContent = typeLabel(currentMode) + (selectedMeta && selectedMeta.title ? ' — ' + selectedMeta.title : ''); }
    var count = form.querySelector('[data-summary="khatm_count"]');
    if (count) { count.textContent = 'ندارد'; }
    var total = form.querySelector('[data-summary="total_parts"]');
    if (total) { total.textContent = digits(target.value || '0'); }

    if (summaryGroups[1]) {
      var heading = summaryGroups[1].querySelector('h3');
      if (heading) { heading.textContent = 'محتوا و هدف'; }
      var terms = summaryGroups[1].querySelectorAll('dt');
      if (terms[0]) { terms[0].textContent = 'نوع و محتوای پویش'; }
      if (terms[1]) { terms[1].textContent = 'تقسیم سهم'; }
      if (terms[2]) { terms[2].textContent = currentMode === 'dua' ? 'هدف قرائت کامل' : 'هدف تعداد ذکر'; }
    }
    if (summaryGroups[3]) {
      var h = summaryGroups[3].querySelector('h3');
      if (h) { h.textContent = 'مدل مشارکت'; }
      var terms4 = summaryGroups[3].querySelectorAll('dt');
      if (terms4[0]) { terms4[0].textContent = 'روش ثبت'; }
      if (terms4[1]) { terms4[1].textContent = 'سقف سهم ناتمام'; }
      if (terms4[2]) { terms4[2].textContent = 'آزادسازی سهم'; }
      var completion = summaryGroups[3].querySelector('[data-summary="completion_mode"]');
      if (completion) { completion.textContent = currentMode === 'dua' ? 'هر مشارکت = یک قرائت کامل' : 'ثبت تعداد انجام‌شده'; }
      var active = summaryGroups[3].querySelector('[data-summary="max_active_claims_per_participant"]');
      if (active) { active.textContent = 'نامرتبط؛ سهم‌بندی ندارد'; }
      var release = summaryGroups[3].querySelector('[data-summary="auto_release_mode"]');
      if (release) { release.textContent = 'نامرتبط؛ سهم‌بندی ندارد'; }
    }
  }

  function sync(force){
    var currentMode = mode();
    if (!force && currentMode === lastMode) { syncSummary(); return; }
    lastMode = currentMode;
    var nonQuran = currentMode !== 'quran';
    quranNodes.forEach(function(node){ node.hidden = nonQuran; });
    registry.hidden = !nonQuran;
    setNodeControlsDisabled(quranNodes.concat(step4Nodes), nonQuran);
    setRegistryControlsDisabled(!nonQuran);
    syncStep4(currentMode);

    if (!nonQuran) {
      contentId.value = '';
      renderSelected(null);
      clearCreatorValidation();
      resultBox.innerHTML = '';
      return;
    }

    pickerTitle.textContent = currentMode === 'dua' ? 'انتخاب دعا' : 'انتخاب ذکر';
    searchInput.placeholder = currentMode === 'dua' ? 'نام دعا را بنویسید' : 'نام ذکر را بنویسید';
    targetLabel.textContent = currentMode === 'dua' ? 'هدف تعداد قرائت کامل' : 'هدف تعداد ذکر';
    targetHelp.textContent = currentMode === 'dua'
      ? 'مثلاً ۱۰۰ یعنی مجموعاً ۱۰۰ بار دعای انتخاب‌شده به‌طور کامل خوانده شود.'
      : 'مثلاً ۱۰۰۰۰ یعنی مجموع ثبت مشارکت‌ها تا ۱۰٬۰۰۰ مرتبه ادامه پیدا کند.';
    if (!contentId.value) { loadResults('', false); }
    else { loadResults(contentId.value, true); }
    syncSummary();
  }

  Array.prototype.forEach.call(form.querySelectorAll('input[name="content_type"]'), function(input){
    input.addEventListener('change', function(){
      if (mode() !== 'quran') { contentId.value = ''; renderSelected(null); searchInput.value = ''; }
      clearCreatorValidation();
      sync(true);
    });
  });

  searchInput.addEventListener('input', function(){
    clearTimeout(timer);
    clearCreatorFieldError(searchInput);
    timer = setTimeout(function(){ loadResults(searchInput.value, false); }, 250);
  });
  target.addEventListener('input', function(){ clearCreatorFieldError(target); setTimeout(syncSummary, 0); });
  form.addEventListener('input', function(){ if (mode() !== 'quran') { setTimeout(syncSummary, 0); } });
  form.addEventListener('change', function(){ if (mode() !== 'quran') { setTimeout(syncSummary, 0); } });

  // The legacy wizard listener is registered by hamnoor-frontend.js before this
  // adapter. Capture phase lets contribution campaigns validate their own step
  // before the Quran-only step validator runs in bubble phase.
  form.addEventListener('click', function(event){
    if (mode() === 'quran') { return; }
    var current = parseInt(form.getAttribute('data-current-step') || '1', 10) || 1;
    var next = event.target.closest ? event.target.closest('[data-next-step], [data-wizard-next]') : null;
    var go = event.target.closest ? event.target.closest('[data-go-step]') : null;
    var requested = next ? current + 1 : (go ? (parseInt(go.getAttribute('data-go-step'), 10) || current) : current);
    if ((!next && !go) || requested <= current) { return; }

    if (current === 2 && !validateContributionStep(true)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    if (current === 2 || current === 4) { temporaryLegacyDefaults(current); }
  }, true);

  // At final submit, bypass only the legacy Quran client validator. The default
  // browser submit still proceeds and the server adapter revalidates the full
  // canonical command, including content eligibility, dates, target and cover.
  form.addEventListener('submit', function(event){
    if (mode() === 'quran') { return; }
    if (!validateContributionStep(true)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    clearCreatorLocalDraft();
    form.dataset.hamnoorSubmitting = '1';
    event.stopImmediatePropagation();
  }, true);

  sync(true);

  // Core draft restoration assigns radio/input properties without firing change
  // events. Observe that brief bootstrap window and resync when restored values land.
  var ticks = 0;
  var draftWatcher = setInterval(function(){
    ticks++;
    var current = mode();
    if (current !== lastMode) { sync(true); }
    else if (current !== 'quran' && contentId.value && !selectedMeta) { loadResults(contentId.value, true); }
    if (ticks >= 20) { clearInterval(draftWatcher); }
  }, 250);
})();
