(function(){
  'use strict';

  var C = window.HamnoorAdminContributionReview || {};
  var rows = C.rows || {};

  function esc(value){
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
  }
  function label(type){ return type === 'dua' ? 'دعا' : (type === 'dhikr' ? 'ذکر' : ''); }
  function closestRow(control){ return control && control.closest ? control.closest('tr') : null; }
  function hideRowByName(form, name){
    var control = form.querySelector('[name="' + name + '"]');
    var row = closestRow(control);
    if (row) {
      row.hidden = true;
      row.setAttribute('data-contribution-admin-legacy', '1');
      row.setAttribute('aria-hidden', 'true');
      Array.prototype.forEach.call(row.querySelectorAll('input,select,textarea'), function(field){ field.disabled = true; });
    }
  }

  function enhanceList(){
    var checks = document.querySelectorAll('.hamnoor-bulk-form input.hamnoor-row-check[name="khatm_ids[]"]');
    Array.prototype.forEach.call(checks, function(check){
      var id = String(parseInt(check.value || '0', 10) || 0);
      var meta = rows[id] || rows[parseInt(id,10)];
      if (!meta) { return; }
      var tr = closestRow(check);
      if (!tr) { return; }
      tr.setAttribute('data-hamnoor-content-type', meta.content_type || '');
      tr.classList.add('hamnoor-admin-contribution-row');
      var cells = tr.querySelectorAll('td');
      if (cells[3]) {
        var badge = cells[3].querySelector('.hamnoor-badge');
        if (badge) { badge.textContent = label(meta.content_type); }
        var small = document.createElement('small');
        small.className = 'hamnoor-admin-contribution-kind';
        small.textContent = meta.content_type === 'dua' ? 'دفتر مشارکت · قرائت کامل' : 'دفتر مشارکت · هدف عددی';
        cells[3].appendChild(small);
      }
      var actions = cells.length ? cells[cells.length - 1] : null;
      if (actions) {
        Array.prototype.forEach.call(actions.querySelectorAll('a[href*="view=parts"],a[href*="view=participants"]'), function(link){ link.remove(); });
        var note = document.createElement('div');
        note.className = 'hamnoor-admin-contribution-action-note';
        note.textContent = 'بدون سهم‌بندی قرآن';
        actions.appendChild(note);
      }
    });
  }

  function ajax(action, data){
    var body = new URLSearchParams();
    body.append('action', action);
    body.append('nonce', C.nonce || '');
    Object.keys(data || {}).forEach(function(key){ body.append(key, data[key] == null ? '' : data[key]); });
    return fetch(C.ajax_url || window.ajaxurl || '', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:body.toString()
    }).then(function(response){ return response.json(); }).then(function(json){
      if (!json || json.success === false) { throw new Error((json && json.data && json.data.message) || 'دریافت محتوا انجام نشد.'); }
      return json.data || {};
    });
  }

  function enhanceEdit(){
    var campaign = C.campaign;
    if (!campaign || !campaign.id) { return; }
    var form = document.querySelector('.hamnoor-admin-form input[name="khatm_id"][value="' + String(campaign.id) + '"]');
    form = form ? form.closest('.hamnoor-admin-form') : null;
    if (!form) { return; }

    form.classList.add('hamnoor-admin-contribution-form');
    form.setAttribute('data-content-type', campaign.content_type || '');

    var ownerName = form.querySelector('[name="owner_name"]');
    var ownerPhone = form.querySelector('[name="owner_phone"]');
    [ownerName, ownerPhone].forEach(function(field){
      if (!field) { return; }
      field.readOnly = true;
      field.setAttribute('aria-readonly', 'true');
      var td = field.closest('td');
      if (td && !td.querySelector('.hamnoor-admin-owner-source-note')) {
        var note = document.createElement('p');
        note.className = 'description hamnoor-admin-owner-source-note';
        note.textContent = 'هویت مالک از حساب هم‌نور خوانده می‌شود و در ویرایش پویش تغییر نمی‌کند.';
        td.appendChild(note);
      }
    });

    [
      'khatm_type','khatm_count','selected_surah_number','repeat_count','max_repeat_count',
      'max_active_claims_per_participant','completion_grace_days','completion_mode',
      'auto_release_mode','auto_release_after_days'
    ].forEach(function(name){ hideRowByName(form, name); });

    var tableBody = form.querySelector('.form-table tbody');
    if (!tableBody) { return; }
    var privacyRow = closestRow(form.querySelector('[name="privacy"]'));

    var header = document.createElement('tr');
    header.className = 'hamnoor-admin-contribution-section';
    header.innerHTML = '<th colspan="2"><div class="hamnoor-admin-contribution-head">' +
      '<div><h3>بررسی پویش ' + esc(label(campaign.content_type)) + '</h3><p>این پویش بر پایه Contribution Ledger است و هیچ Part/Claim قرآنی ندارد.</p></div>' +
      '<span>' + esc(campaign.content_type === 'dua' ? 'قرائت کامل' : 'هدف عددی') + '</span></div>' +
      (campaign.structure_locked ? '<div class="notice notice-warning inline"><p><strong>ساختار قفل است.</strong> پس از اولین مشارکت، محتوا و هدف قابل تغییر نیست؛ بررسی وضعیت و اطلاعات عمومی همچنان مجاز است.</p></div>' : '') +
      '</th>';
    tableBody.insertBefore(header, privacyRow || tableBody.firstChild);

    var contentRow = document.createElement('tr');
    contentRow.className = 'hamnoor-admin-contribution-content-row';
    contentRow.innerHTML = '<th scope="row">محتوا و هدف</th><td>' +
      '<input type="hidden" name="content_id" value="' + esc(campaign.content_id || '') + '">' +
      '<div class="hamnoor-admin-current-content"><span>محتوای فعلی</span><strong data-admin-current-title>' + esc(campaign.content_title || '—') + '</strong><code data-admin-current-id>' + esc(campaign.content_id || '') + '</code></div>' +
      '<label class="hamnoor-admin-content-search"><span>تغییر محتوا</span><input type="search" data-admin-content-search autocomplete="off" placeholder="جست‌وجوی ' + esc(label(campaign.content_type)) + '" ' + (campaign.structure_locked ? 'disabled aria-disabled="true"' : '') + '></label>' +
      '<div class="hamnoor-admin-content-results" data-admin-content-results aria-live="polite"></div>' +
      '<label class="hamnoor-admin-target"><span>' + esc(campaign.content_type === 'dua' ? 'هدف تعداد قرائت کامل' : 'هدف تعداد ذکر') + '</span><input type="number" name="target" min="1" step="1" value="' + esc(campaign.target || 1) + '" ' + (campaign.structure_locked ? 'readonly aria-readonly="true"' : 'required') + '></label>' +
      '<p class="description">' + (campaign.structure_locked ? 'محتوا و هدف به دلیل ثبت اولین مشارکت فقط‌خواندنی هستند.' : 'مدیر تا پیش از اولین مشارکت می‌تواند محتوا یا هدف را اصلاح کند.') + '</p>' +
      '</td>';
    tableBody.insertBefore(contentRow, privacyRow || header.nextSibling);

    var contentId = contentRow.querySelector('[name="content_id"]');
    var titleBox = contentRow.querySelector('[data-admin-current-title]');
    var idBox = contentRow.querySelector('[data-admin-current-id]');
    var search = contentRow.querySelector('[data-admin-content-search]');
    var results = contentRow.querySelector('[data-admin-content-results]');
    var serial = 0;
    var timer = null;

    function choose(item){
      if (!item || campaign.structure_locked) { return; }
      contentId.value = item.id || '';
      campaign.content_id = item.id || '';
      campaign.content_title = item.title || '';
      titleBox.textContent = campaign.content_title;
      idBox.textContent = campaign.content_id;
      search.value = campaign.content_title;
      results.innerHTML = '';
    }
    function load(query){
      if (campaign.structure_locked) { return; }
      var current = ++serial;
      results.innerHTML = '<p class="description">در حال جست‌وجو…</p>';
      ajax('hamnoor_admin_contribution_content_search', {content_type:campaign.content_type, search:query || ''}).then(function(data){
        if (current !== serial) { return; }
        var items = Array.isArray(data.items) ? data.items : [];
        if (!items.length) { results.innerHTML = '<p class="description">محتوای فعال دیگری پیدا نشد.</p>'; return; }
        results.innerHTML = '';
        items.forEach(function(item){
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'button hamnoor-admin-content-result';
          button.innerHTML = '<strong>' + esc(item.title) + '</strong><small>' + esc(item.id) + (item.max_contribution ? ' · سقف هر مشارکت: ' + esc(item.max_contribution) : '') + '</small>';
          button.addEventListener('click', function(){ choose(item); });
          results.appendChild(button);
        });
      }).catch(function(error){
        if (current !== serial) { return; }
        results.innerHTML = '<div class="notice notice-error inline"><p>' + esc(error && error.message ? error.message : 'جست‌وجو انجام نشد.') + '</p></div>';
      });
    }
    if (search && !campaign.structure_locked) {
      search.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(function(){ load(search.value.trim()); }, 250); });
      search.addEventListener('focus', function(){ if (!results.children.length) { load(search.value.trim()); } });
    }

    var status = form.querySelector('[name="status"]');
    var review = form.querySelector('[name="review_note"]');
    function reviewHint(){
      if (!status || !review) { return; }
      var required = status.value === 'needs_revision' || status.value === 'rejected';
      review.required = required;
      review.setAttribute('aria-required', required ? 'true' : 'false');
      var td = review.closest('td');
      if (!td) { return; }
      var hint = td.querySelector('.hamnoor-admin-review-required');
      if (!hint) {
        hint = document.createElement('p');
        hint.className = 'description hamnoor-admin-review-required';
        td.appendChild(hint);
      }
      hint.textContent = required ? 'برای «نیازمند اصلاح» یا «تأیید نشد»، درج دلیل الزامی است.' : '';
    }
    if (status) { status.addEventListener('change', reviewHint); }
    reviewHint();
  }

  enhanceList();
  enhanceEdit();
})();
