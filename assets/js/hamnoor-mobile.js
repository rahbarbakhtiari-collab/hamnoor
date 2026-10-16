(function(){
  'use strict';

  function qsa(sel, root){ return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function isMobile(){ return window.matchMedia && window.matchMedia('(max-width: 782px)').matches; }

  function initMobilePartCards(dashboard){
    if (!dashboard || !isMobile()) { return; }
    qsa('.hamnoor-my-parts-grid .hamnoor-part-card', dashboard).forEach(function(card){
      if (card.dataset.hamnoorMobileCardReady === '1') { return; }
      card.dataset.hamnoorMobileCardReady = '1';

      var engagement = card.querySelector('.hamnoor-part-engagement');
      var actions = card.querySelector('.hamnoor-part-actions');
      if (!actions && !engagement) { return; }

      var primary = actions ? actions.querySelector('.hamnoor-reading-link') : null;
      var secondary = [];
      if (actions) {
        Array.prototype.slice.call(actions.children).forEach(function(child){
          if (child !== primary) { secondary.push(child); }
        });
      }
      if (!engagement && !secondary.length) { return; }

      var details = document.createElement('details');
      details.className = 'hamnoor-mobile-part-more';
      var summary = document.createElement('summary');
      summary.textContent = 'تنظیمات و گزینه‌های بیشتر';
      var body = document.createElement('div');
      body.className = 'hamnoor-mobile-part-more-body';
      details.appendChild(summary);
      details.appendChild(body);

      if (engagement) { body.appendChild(engagement); }
      if (secondary.length) {
        var secondaryWrap = document.createElement('div');
        secondaryWrap.className = 'hamnoor-mobile-part-secondary-actions';
        secondary.forEach(function(node){ secondaryWrap.appendChild(node); });
        body.appendChild(secondaryWrap);
      }
      card.appendChild(details);
    });
  }

  function initMobileDashboard(dashboard){
    if (!dashboard || dashboard.dataset.hamnoorMobileReady === '1') { return; }
    dashboard.dataset.hamnoorMobileReady = '1';
    if (isMobile()) { document.body.classList.add('hamnoor-mobile-dashboard-active'); }

    var layer = dashboard.querySelector('[data-hamnoor-mobile-more]');
    var toggles = qsa('[data-hamnoor-mobile-more-toggle]', dashboard);
    var title = dashboard.querySelector('[data-hamnoor-mobile-title]');
    var titles = {
      'summary': 'خانه',
      'my-khatms': 'پویش‌های من',
      'progress': 'مرکز پیشرفت',
      'my-parts': 'سهم‌های من',
      'notifications': 'اعلان‌ها',
      'reminders': 'یادآوری‌ها',
      'profile': 'مشخصات من',
      'help': 'راهنما'
    };
    var moreTabs = ['my-khatms','progress','reminders','profile','help'];

    function currentTab(){ return dashboard.getAttribute('data-active-tab') || 'summary'; }
    function sync(){
      var tab = currentTab();
      if (title) {
        var extensionPanel = dashboard.querySelector('[data-tab-panel="' + tab + '"] [data-hamnoor-panel-title]');
        title.textContent = titles[tab] || (extensionPanel ? extensionPanel.textContent.trim() : 'هم‌نور');
      }
      qsa('.hamnoor-mobile-bottom-nav [data-tab-target]', dashboard).forEach(function(btn){
        var active = btn.getAttribute('data-tab-target') === tab;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      var extensionMore = !!dashboard.querySelector('[data-hamnoor-more-tab="' + tab + '"]');
      toggles.forEach(function(btn){ btn.classList.toggle('is-active', moreTabs.indexOf(tab) !== -1 || extensionMore); });
      initMobilePartCards(dashboard);
    }
    function openMore(){
      if (!layer || !isMobile()) { return; }
      layer.hidden = false;
      toggles.forEach(function(btn){ btn.setAttribute('aria-expanded','true'); });
      var close = layer.querySelector('[data-hamnoor-mobile-more-close]');
      if (close) { close.focus({preventScroll:true}); }
    }
    function closeMore(restoreFocus){
      if (!layer) { return; }
      layer.hidden = true;
      toggles.forEach(function(btn){ btn.setAttribute('aria-expanded','false'); });
      if (restoreFocus && toggles[0]) { toggles[0].focus({preventScroll:true}); }
    }

    dashboard.addEventListener('click', function(e){
      var toggle = e.target.closest('[data-hamnoor-mobile-more-toggle]');
      if (toggle && dashboard.contains(toggle)) {
        e.preventDefault();
        if (layer && layer.hidden) { openMore(); } else { closeMore(true); }
        return;
      }
      var closer = e.target.closest('[data-hamnoor-mobile-more-close]');
      if (closer && layer && layer.contains(closer)) {
        e.preventDefault();
        closeMore(true);
        return;
      }
      var tab = e.target.closest('[data-tab-target]');
      if (tab && dashboard.contains(tab)) {
        closeMore(false);
        window.setTimeout(sync, 0);
      }
    });

    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && layer && !layer.hidden) { closeMore(true); }
    });

    if ('MutationObserver' in window) {
      new MutationObserver(sync).observe(dashboard, {attributes:true, attributeFilter:['data-active-tab']});
    }
    window.addEventListener('popstate', function(){ window.setTimeout(sync, 0); });
    sync();
    try {
      var params = new URLSearchParams(window.location.search);
      if (params.get('mobile_more') === '1' && isMobile()) {
        window.setTimeout(openMore, 60);
        params.delete('mobile_more');
        var clean = window.location.pathname + (params.toString() ? ('?' + params.toString()) : '') + window.location.hash;
        window.history.replaceState(window.history.state, '', clean);
      }
    } catch (err) {}
  }

  function initMobileReading(root){
    if (!root || !isMobile() || root.dataset.hamnoorMobileReaderReady === '1') { return; }
    root.dataset.hamnoorMobileReaderReady = '1';
    document.body.classList.add('hamnoor-mobile-reading-active');

    var verses = qsa('.hamnoor-reading-verse', root);
    var saveButtons = qsa('[data-reading-save]', root);
    var audio = root.querySelector('[data-audio-player]');
    if (!verses.length || !saveButtons.length) { return; }

    var dock = document.createElement('div');
    dock.className = 'hamnoor-mobile-reading-dock';
    dock.setAttribute('aria-live', 'polite');
    dock.innerHTML = '<div class="hamnoor-mobile-reading-dock-copy"><strong data-mobile-reading-ref>محل قرائت</strong><small data-mobile-reading-state>برای ثبت پیشرفت، آیه را بخوانید.</small></div><button type="button" data-mobile-reading-save>تا این آیه خواندم</button>';
    root.appendChild(dock);

    var ref = dock.querySelector('[data-mobile-reading-ref]');
    var state = dock.querySelector('[data-mobile-reading-state]');
    var action = dock.querySelector('[data-mobile-reading-save]');
    var activeVerse = null;
    var finishMode = false;

    function visibleFinish(){
      var candidates = qsa('[data-reading-finish]', root);
      for (var i=0; i<candidates.length; i++) {
        if (!candidates[i].hidden && !candidates[i].disabled) { return candidates[i]; }
      }
      return null;
    }

    function sourceButton(verse){ return verse ? verse.querySelector('[data-reading-save]') : null; }

    function syncDock(){
      var finish = visibleFinish();
      if (finish) {
        finishMode = true;
        dock.classList.add('is-finish');
        ref.textContent = 'پایان سهم';
        state.textContent = 'همه آیات این سهم ثبت شده‌اند.';
        action.disabled = false;
        action.textContent = 'ثبت پایان سهم';
        return;
      }
      finishMode = false;
      dock.classList.remove('is-finish');
      if (!activeVerse) { activeVerse = verses[0]; }
      var source = sourceButton(activeVerse);
      var surah = activeVerse.getAttribute('data-surah') || '';
      var ayah = activeVerse.getAttribute('data-ayah') || '';
      ref.textContent = 'سوره ' + surah + '، آیه ' + ayah;
      if (!source) {
        action.disabled = true;
        action.textContent = 'ثبت پیشرفت';
        state.textContent = 'این آیه قابل ثبت نیست.';
        return;
      }
      var already = source.disabled || source.classList.contains('is-saved') || source.classList.contains('is-past-saved');
      action.disabled = already;
      action.textContent = already ? 'این آیه ثبت شده' : 'تا این آیه خواندم';
      state.textContent = already ? 'پیشرفت شما تا اینجا ذخیره شده است.' : 'پس از خواندن این آیه، پیشرفت را ثبت کنید.';
    }

    action.addEventListener('click', function(){
      if (finishMode) {
        var finish = visibleFinish();
        if (finish) { finish.click(); }
        return;
      }
      var source = sourceButton(activeVerse);
      if (source && !source.disabled) {
        action.disabled = true;
        state.textContent = 'در حال ثبت پیشرفت…';
        source.click();
        window.setTimeout(syncDock, 350);
      }
    });

    function chooseVisible(){
      var targetY = Math.max(120, window.innerHeight * .42);
      var best = null, bestDistance = Infinity;
      verses.forEach(function(verse){
        var r = verse.getBoundingClientRect();
        if (r.bottom <= 0 || r.top >= window.innerHeight) { return; }
        var center = Math.max(r.top, 0) + Math.min(r.height, window.innerHeight) * .45;
        var distance = Math.abs(center - targetY);
        if (distance < bestDistance) { best = verse; bestDistance = distance; }
      });
      if (best && best !== activeVerse) { activeVerse = best; syncDock(); }
    }

    var ticking = false;
    function onScroll(){
      if (ticking) { return; }
      ticking = true;
      window.requestAnimationFrame(function(){ ticking = false; chooseVisible(); });
    }
    window.addEventListener('scroll', onScroll, {passive:true});
    window.addEventListener('resize', onScroll);

    if ('MutationObserver' in window) {
      var observer = new MutationObserver(function(){ syncDock(); });
      saveButtons.forEach(function(btn){ observer.observe(btn, {attributes:true, childList:true, subtree:true}); });
      qsa('[data-reading-finish]', root).forEach(function(btn){ observer.observe(btn, {attributes:true}); });
      if (audio) {
        new MutationObserver(function(){
          root.classList.toggle('is-mobile-audio-open', !audio.hidden);
        }).observe(audio, {attributes:true, attributeFilter:['hidden']});
        root.classList.toggle('is-mobile-audio-open', !audio.hidden);
      }
    }

    activeVerse = root.querySelector('.hamnoor-reading-verse.is-resume') || verses[0];
    syncDock();
    window.setTimeout(chooseVisible, 120);
  }



  function initMobilePublicKhatm(){
    if (!isMobile() || !document.body.classList.contains('hamnoor-public-khatm-page')) { return; }
    var root = document.querySelector('#hamnoor-khatm-app .hamnoor-wrap');
    if (!root || root.dataset.hamnoorMobilePublicReady === '1') { return; }
    root.dataset.hamnoorMobilePublicReady = '1';

    function foldPanel(selector, label){
      var panel = root.querySelector(selector);
      if (!panel || panel.closest('.hamnoor-mobile-khatm-fold')) { return; }
      var details = document.createElement('details');
      details.className = 'hamnoor-mobile-khatm-fold';
      var summary = document.createElement('summary');
      summary.textContent = label;
      panel.parentNode.insertBefore(details, panel);
      details.appendChild(summary);
      details.appendChild(panel);
    }

    foldPanel('.hamnoor-share-panel', 'اشتراک‌گذاری و دعوت');
    foldPanel('.hamnoor-part-filter-panel', 'جستجو و فیلتر سهم‌ها');

    var grid = root.querySelector('.hamnoor-parts-grid');
    if (!grid) { return; }
    var cards = qsa(':scope > .hamnoor-part-card', grid);
    if (!cards.length) { return; }
    var batch = 10;
    var visible = Math.min(batch, cards.length);
    var footer = document.createElement('div');
    footer.className = 'hamnoor-mobile-parts-footer';
    var count = document.createElement('small');
    count.className = 'hamnoor-mobile-parts-count';
    footer.appendChild(count);
    var more = null;

    if (cards.length > batch) {
      more = document.createElement('button');
      more.type = 'button';
      more.className = 'hamnoor-mobile-parts-more';
      footer.appendChild(more);
      more.addEventListener('click', function(){
        var oldVisible = visible;
        visible = Math.min(cards.length, visible + batch);
        syncCards();
        var firstNew = cards[oldVisible];
        if (firstNew) { firstNew.scrollIntoView({block:'nearest', behavior:'smooth'}); }
      });
    }
    grid.insertAdjacentElement('afterend', footer);

    function syncCards(){
      cards.forEach(function(card, index){
        var collapsed = index >= visible;
        card.classList.toggle('hamnoor-mobile-part-collapsed', collapsed);
        card.setAttribute('aria-hidden', collapsed ? 'true' : 'false');
      });
      count.textContent = 'نمایش ' + visible + ' از ' + cards.length + ' سهم این صفحه';
      if (more) {
        var remaining = Math.max(0, cards.length - visible);
        more.hidden = remaining === 0;
        more.textContent = remaining ? ('نمایش ' + Math.min(batch, remaining) + ' سهم دیگر') : '';
      }
    }
    syncCards();
  }

  function initMobileShell(){
    if (!isMobile()) { return; }
    if (document.querySelector('.hamnoor-wrap')) {
      document.body.classList.add('hamnoor-mobile-ui-active');
    }
    if (document.querySelector('.hamnoor-create-template')) {
      document.body.classList.add('hamnoor-mobile-create-active');
    }
  }

  function boot(){
    initMobileShell();
    qsa('[data-hamnoor-dashboard]').forEach(initMobileDashboard);
    qsa('[data-hamnoor-reading]').forEach(initMobileReading);
    initMobilePublicKhatm();
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
  else { boot(); }
})();
