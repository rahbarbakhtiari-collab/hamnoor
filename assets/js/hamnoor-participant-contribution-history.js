(() => {
  'use strict';

  const config = window.HamnoorContributionHistory || {};
  const labels = config.labels || {};

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (typeof text === 'string') node.textContent = text;
    return node;
  }

  function setHistoryUrl(page) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', 'contributions');
    if (page > 1) url.searchParams.set('contributions_page', String(page));
    else url.searchParams.delete('contributions_page');
    window.history.replaceState({}, '', url.toString());
  }

  function init() {
    const dashboard = document.querySelector('[data-hamnoor-dashboard="1"]');
    if (!dashboard || !config.ajax_url || !config.nonce) return;
    // Guest zero states are server-rendered and intentionally read-only.
    if (dashboard.classList.contains('hamnoor-dashboard-zero-state')) return;

    const panelsHost = dashboard.querySelector('.hamnoor-dashboard-panels');
    if (!panelsHost) return;
    // The unified My Shares adapter owns Quran/Dua/Dhikr presentation and routing.
    // Keep this legacy renderer available only for installations without that host.
    if (dashboard.querySelector('[data-unified-contribution-history]')) return;

    let panel = dashboard.querySelector('[data-tab-panel="contributions"]');
    const panelIsCanonical = Boolean(panel);
    if (!panel) {
      panel = el('section', 'hamnoor-dashboard-panel hamnoor-contribution-history-panel');
      panel.id = 'hamnoor-panel-contributions';
      panel.dataset.tabPanel = 'contributions';
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', 'hamnoor-tab-contributions');
      panel.hidden = true;
    }
    panel.classList.add('hamnoor-contribution-history-panel');
    panel.dataset.contributionHistoryPanel = '1';
    panel.replaceChildren();

    const heading = el('div', 'hamnoor-contribution-history-heading');
    const headingCopy = el('div');
    headingCopy.append(el('h3', '', labels.title || 'مشارکت‌های من در دعا و ذکر'));
    headingCopy.append(el('p', '', labels.description || 'سابقه مشارکت‌های ثبت‌شده شما'));
    const totalBadge = el('span', 'hamnoor-contribution-history-total');
    heading.append(headingCopy, totalBadge);

    const state = el('div', 'hamnoor-contribution-history-state', labels.loading || 'در حال دریافت…');
    state.setAttribute('aria-live', 'polite');
    const list = el('div', 'hamnoor-contribution-history-list');
    const pager = el('nav', 'hamnoor-contribution-history-pagination');
    pager.setAttribute('aria-label', labels.title || 'مشارکت‌های من');
    panel.append(heading, state, list, pager);
    if (!panelIsCanonical) panelsHost.append(panel);

    const desktopNav = dashboard.querySelector('[data-dashboard-subnav="today"]');
    let desktopTab = dashboard.querySelector('#hamnoor-tab-contributions');
    if (desktopNav && !desktopTab) {
      desktopTab = el('button', '', labels.tab || 'مشارکت‌های من');
      desktopTab.id = 'hamnoor-tab-contributions';
      desktopTab.type = 'button';
      desktopTab.dataset.tabTarget = 'contributions';
      desktopTab.dataset.dashboardTabGroup = 'today';
      desktopTab.setAttribute('role', 'tab');
      desktopTab.setAttribute('aria-controls', panel.id);
      desktopTab.setAttribute('aria-selected', 'false');
      desktopTab.tabIndex = -1;
      desktopNav.append(desktopTab);
    }

    const mobileQuick = dashboard.querySelector('.hamnoor-mobile-quick-grid');
    let mobileButton = null;
    if (mobileQuick) {
      mobileButton = el('button', 'hamnoor-contribution-history-mobile-entry');
      mobileButton.type = 'button';
      mobileButton.dataset.contributionHistoryEntry = '1';
      const icon = el('span', 'hamnoor-mobile-quick-icon', '✓');
      const strong = el('strong', '', labels.tab || 'مشارکت‌های من');
      const small = el('small', '', labels.mobile || 'سابقه دعا و ذکر');
      mobileButton.append(icon, strong, small);
      mobileQuick.append(mobileButton);
    }

    let currentPage = Math.max(1, Number(new URL(window.location.href).searchParams.get('contributions_page')) || 1);
    let loadedPage = 0;
    let loading = false;

    function deactivateHistory() {
      panel.classList.remove('is-active');
      panel.hidden = true;
      if (desktopTab) {
        desktopTab.classList.remove('is-active');
        desktopTab.setAttribute('aria-selected', 'false');
        desktopTab.tabIndex = -1;
      }
      if (mobileButton) mobileButton.classList.remove('is-active');
    }

    function activateHistory(page = currentPage) {
      currentPage = Math.max(1, Number(page) || 1);
      dashboard.querySelectorAll('[data-tab-panel]').forEach(candidate => {
        candidate.classList.remove('is-active');
        candidate.hidden = true;
      });
      dashboard.querySelectorAll('[role="tab"]').forEach(tab => {
        tab.classList.remove('is-active');
        tab.setAttribute('aria-selected', 'false');
        tab.tabIndex = -1;
      });
      panel.hidden = false;
      panel.classList.add('is-active');
      if (desktopTab) {
        desktopTab.classList.add('is-active');
        desktopTab.setAttribute('aria-selected', 'true');
        desktopTab.tabIndex = 0;
      }
      if (mobileButton) mobileButton.classList.add('is-active');
      setHistoryUrl(currentPage);
      if (loadedPage !== currentPage) load(currentPage);
    }

    function render(data) {
      list.replaceChildren();
      pager.replaceChildren();
      const items = Array.isArray(data.items) ? data.items : [];
      const total = Number(data.total) || 0;
      const page = Math.max(1, Number(data.page) || 1);
      const pages = Math.max(1, Number(data.pages) || 1);
      currentPage = page;
      loadedPage = page;
      totalBadge.textContent = total ? `${total} ${labels.total || 'مشارکت ثبت‌شده'}` : '';

      if (!items.length) {
        state.hidden = false;
        state.className = 'hamnoor-contribution-history-state is-empty';
        state.textContent = labels.empty || 'هنوز مشارکتی ثبت نکرده‌اید.';
      } else {
        state.hidden = true;
        items.forEach(item => {
          const card = el('article', 'hamnoor-contribution-history-card');
          card.dataset.contributionPublicId = String(item.public_id || '');
          const head = el('div', 'hamnoor-contribution-history-card-head');
          const link = el('a', 'hamnoor-contribution-history-title', String(item.campaign_title || ''));
          link.href = String(item.campaign_url || '#');
          const type = el('span', 'hamnoor-contribution-history-type', item.content_type === 'dhikr' ? (labels.dhikr || 'ذکر') : (labels.dua || 'دعا'));
          head.append(link, type);

          const meta = el('div', 'hamnoor-contribution-history-meta');
          const pending = item.kind === 'dua_commitment' && item.status === 'pending';
          const quantity = pending
            ? (labels.pending || 'در انتظار قرائت')
            : item.kind === 'completion'
            ? (labels.completion || '۱ قرائت کامل')
            : `${Number(item.quantity) || 0} ${labels.times || 'مرتبه'}`;
          meta.append(el('strong', 'hamnoor-contribution-history-quantity', quantity));
          meta.append(el('time', 'hamnoor-contribution-history-date', String(item.display_date || item.occurred_at || '')));
          card.append(head, meta);
          if (pending) {
            const action = el('a', 'hamnoor-btn hamnoor-btn-primary', labels.continue || 'ادامه و تکمیل قرائت');
            action.href = String(item.campaign_url || '#');
            card.append(action);
          }
          list.append(card);
        });
      }

      if (pages > 1) {
        const previous = el('button', 'hamnoor-btn hamnoor-btn-secondary', labels.previous || 'قبلی');
        previous.type = 'button';
        previous.disabled = page <= 1;
        previous.addEventListener('click', () => activateHistory(page - 1));
        const pageLabel = el('span', 'hamnoor-contribution-history-page', `${page} / ${pages}`);
        const next = el('button', 'hamnoor-btn hamnoor-btn-secondary', labels.next || 'بعدی');
        next.type = 'button';
        next.disabled = page >= pages;
        next.addEventListener('click', () => activateHistory(page + 1));
        pager.append(previous, pageLabel, next);
      }
    }

    async function load(page) {
      if (loading) return;
      loading = true;
      state.hidden = false;
      state.className = 'hamnoor-contribution-history-state is-loading';
      state.textContent = labels.loading || 'در حال دریافت…';
      const body = new URLSearchParams({
        action: 'hamnoor_get_contribution_history',
        nonce: String(config.nonce),
        page: String(page),
      });
      try {
        const response = await fetch(String(config.ajax_url), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
        });
        const payload = await response.json();
        if (!response.ok || !payload || payload.success !== true || !payload.data) throw new Error('history_request_failed');
        render(payload.data);
      } catch (error) {
        state.hidden = false;
        state.className = 'hamnoor-contribution-history-state is-error';
        state.textContent = labels.error || 'دریافت سابقه مشارکت‌ها انجام نشد.';
      } finally {
        loading = false;
      }
    }

    [desktopTab, mobileButton].filter(Boolean).forEach(button => {
      button.addEventListener('click', event => {
        event.preventDefault();
        // Mobile quick-entry is integration-owned, not a core dashboard tab.
        // Prevent delegated core handlers from undoing this activation.
        if (button === mobileButton) event.stopPropagation();
        activateHistory(currentPage);
      });
    });

    document.addEventListener('click', event => {
      const target = event.target.closest('[data-tab-target]');
      if (target && target.dataset.tabTarget !== 'contributions') deactivateHistory();
    }, true);

    if (new URL(window.location.href).searchParams.get('tab') === 'contributions') {
      activateHistory(currentPage);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
