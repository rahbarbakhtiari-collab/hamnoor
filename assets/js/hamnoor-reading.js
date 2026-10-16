(function () {
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
    var root = document.querySelector('[data-hamnoor-reading]');
    if (!root || typeof HamnoorReading === 'undefined') { return; }

    var mode = String(HamnoorReading.mode || root.getAttribute('data-reading-mode') || 'part');
    var entityId = parseInt(root.getAttribute('data-entity-id') || root.getAttribute('data-part-id') || '0', 10);
    var idField = String(HamnoorReading.id_field || 'part_id');
    var actions = Object.assign({ save:'hamnoor_save_reading_progress', finish:'hamnoor_finish_reading', audio:'hamnoor_save_audio_position', state:'hamnoor_reading_state' }, HamnoorReading.actions || {});
    var startIndex = parseInt(root.getAttribute('data-start-index') || '0', 10);
    var endIndex = parseInt(root.getAttribute('data-end-index') || '0', 10);
    var pageEndIndex = parseInt(root.getAttribute('data-page-end-index') || String(endIndex), 10) || endIndex;
    var queueKey = 'hamnoorReadingQueueV2:' + mode + ':' + String(entityId);
    var queueFlushing = false;
    var message = root.querySelector('[data-reading-message]');
    var percentNodes = root.querySelectorAll('[data-reading-percent]');
    var progressBar = root.querySelector('[data-reading-progress-bar]');
    var finishButtons = root.querySelectorAll('[data-reading-finish]');
    var verses = Array.prototype.slice.call(root.querySelectorAll('[data-verse-index]'));
    var isPartReader = mode === 'part';
    var isReviewMode = isPartReader && root.classList.contains('is-review-mode');
    var completionPanel = isPartReader ? root.querySelector('[data-reading-completion]') : null;
    var completionReadyView = completionPanel ? completionPanel.querySelector('[data-reading-ready-view]') : null;
    var completionSuccessView = completionPanel ? completionPanel.querySelector('[data-reading-success-view]') : null;
    var successMessage = completionPanel ? completionPanel.querySelector('[data-reading-success-message]') : null;
    var nextAction = completionPanel ? completionPanel.querySelector('[data-reading-next-action]') : null;
    var campaignAction = completionPanel ? completionPanel.querySelector('[data-reading-campaign-action]') : null;
    var dashboardAction = completionPanel ? completionPanel.querySelector('[data-reading-dashboard-action]') : null;
    var progressElement = progressBar ? progressBar.parentElement : null;
    var stateNotice = isPartReader ? root.querySelector('[data-reading-state-notice]') : null;
    var stateNoticeTitle = stateNotice ? stateNotice.querySelector('[data-reading-state-title]') : null;
    var stateNoticeCopy = stateNotice ? stateNotice.querySelector('[data-reading-state-copy]') : null;
    var stateNoticeAction = stateNotice ? stateNotice.querySelector('[data-reading-state-action]') : null;
    var serverLastIndex = parseInt(root.getAttribute('data-last-index') || '0', 10) || 0;
    var syncSource = 'tab-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 9);
    var syncStorageKey = 'hamnoorReadingSyncV1:' + mode + ':' + String(entityId);
    var syncChannel = null;
    var stateRefreshBusy = false;
    var stateRefreshTimer = 0;
    var busy = false;
    var toolbar = root.querySelector('.hamnoor-reading-toolbar');
    var player = root.querySelector('[data-audio-player]');
    var storageKey = 'hamnoorReadingPreferencesV1';
    var defaultArabicSize = parseInt(root.getAttribute('data-default-arabic-size') || '38', 10);
    var defaultTranslationSize = parseInt(root.getAttribute('data-default-translation-size') || '18', 10);
    var defaultPreferences = { translation: true, autoScroll: true, dark: false, focus: false, arabicSize: defaultArabicSize, translationSize: defaultTranslationSize };
    var preferences = Object.assign({}, defaultPreferences);
    var settings = root.querySelector('[data-reading-settings]');
    var settingsToggle = settings && settings.querySelector('[data-reading-settings-toggle]');
    var settingsPanel = settings && settings.querySelector('[data-reading-settings-panel]');
    var readingWrap = root.closest('.hamnoor-wrap');
    var focusPlaceholder = null;
    var focusWindowScroll = 0;
    var settingsCloseTimer = 0;
    var settingsPanelPlaceholder = null;
    var settingsPortalHost = null;
    var settingsBackdrop = null;
    var focusExitButton = root.querySelector('[data-reading-focus-exit]');
    var resilienceNotice = isPartReader ? root.querySelector('[data-reading-resilience-notice]') : null;
    var resilienceCopy = resilienceNotice ? resilienceNotice.querySelector('[data-reading-resilience-copy]') : null;
    var retryStateButton = resilienceNotice ? resilienceNotice.querySelector('[data-reading-retry-state]') : null;
    var networkStatus = isPartReader ? root.querySelector('[data-reading-network-status]') : null;
    var contentRegion = isPartReader ? root.querySelector('[data-reading-content]') : null;
    var stateRefreshSlowTimer = 0;
    var stateRefreshFailures = 0;
    var audioWasPlayingOnPageHide = false;
    var prefersReducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function loadPreferences() {
        try {
            var saved = JSON.parse(window.localStorage.getItem(storageKey) || '{}');
            preferences = Object.assign({}, defaultPreferences, saved || {});
        } catch (e) { preferences = Object.assign({}, defaultPreferences); }
        // Focus mode is intentionally session-only, so a new visit never opens in an overlay unexpectedly.
        preferences.focus = false;
        preferences.arabicSize = Math.max(28, Math.min(56, parseInt(preferences.arabicSize || 38, 10)));
        preferences.translationSize = Math.max(14, Math.min(28, parseInt(preferences.translationSize || 18, 10)));
    }
    function savePreferences() {
        try {
            var persistent = Object.assign({}, preferences);
            delete persistent.focus;
            window.localStorage.setItem(storageKey, JSON.stringify(persistent));
        } catch (e) {}
    }
    function ensureSettingsPortal() {
        if (!settingsPanel || settingsPanelPlaceholder) { return; }
        settingsPanelPlaceholder = document.createComment('hamnoor-reading-settings-placeholder');
        if (settingsPanel.parentNode) { settingsPanel.parentNode.insertBefore(settingsPanelPlaceholder, settingsPanel); }
        settingsPortalHost = document.createElement('div');
        settingsPortalHost.className = 'hamnoor-wrap hamnoor-reading-settings-portal-host';
        settingsPortalHost.setAttribute('dir', 'rtl');
        document.body.appendChild(settingsPortalHost);
        settingsPortalHost.appendChild(settingsPanel);
        settingsPanel.classList.add('hamnoor-reading-settings-portal');
        if (!settingsBackdrop) {
            settingsBackdrop = document.createElement('button');
            settingsBackdrop.type = 'button';
            settingsBackdrop.className = 'hamnoor-reading-settings-backdrop';
            settingsBackdrop.setAttribute('aria-label', 'بستن تنظیمات مطالعه');
            settingsBackdrop.addEventListener('click', function () { closeSettings(0); });
        }
        if (!settingsBackdrop.parentNode) { document.body.appendChild(settingsBackdrop); }
    }
    function restoreSettingsPanel() {
        if (!settingsPanel || !settingsPanelPlaceholder) { return; }
        settingsPanel.classList.remove('hamnoor-reading-settings-portal');
        if (settingsPanelPlaceholder.parentNode) {
            settingsPanelPlaceholder.parentNode.insertBefore(settingsPanel, settingsPanelPlaceholder);
            settingsPanelPlaceholder.parentNode.removeChild(settingsPanelPlaceholder);
        }
        settingsPanelPlaceholder = null;
        if (settingsPortalHost && settingsPortalHost.parentNode) { settingsPortalHost.parentNode.removeChild(settingsPortalHost); }
        settingsPortalHost = null;
        if (settingsBackdrop) { settingsBackdrop.hidden = true; }
        document.body.classList.remove('hamnoor-reading-settings-open');
    }
    function positionSettingsPanel() {
        if (!settingsPanel || settingsPanel.hidden || !settingsToggle) { return; }
        settingsPanel.style.removeProperty('left');
        settingsPanel.style.removeProperty('right');
        settingsPanel.style.removeProperty('top');
        settingsPanel.style.removeProperty('bottom');
        settingsPanel.style.removeProperty('width');
        settingsPanel.style.removeProperty('max-height');
        var gap = 8;
        var mobile = window.matchMedia('(max-width: 700px)').matches;
        var viewport = window.visualViewport;
        var viewportWidth = viewport ? viewport.width : window.innerWidth;
        var viewportHeight = viewport ? viewport.height : window.innerHeight;
        var viewportLeft = viewport ? viewport.offsetLeft : 0;
        var viewportTop = viewport ? viewport.offsetTop : 0;
        if (mobile) {
            var mobileWidth = Math.max(240, viewportWidth - (gap * 2));
            settingsPanel.style.setProperty('left', Math.round(viewportLeft + gap) + 'px', 'important');
            settingsPanel.style.setProperty('right', 'auto', 'important');
            settingsPanel.style.setProperty('width', Math.round(mobileWidth) + 'px', 'important');
            settingsPanel.style.setProperty('max-height', Math.round(Math.min(viewportHeight * 0.72, 560)) + 'px', 'important');
            var measuredHeight = Math.min(settingsPanel.scrollHeight || 420, viewportHeight * 0.72, 560);
            var mobileTop = Math.max(viewportTop + gap, viewportTop + viewportHeight - measuredHeight - gap);
            settingsPanel.style.setProperty('top', Math.round(mobileTop) + 'px', 'important');
            settingsPanel.style.setProperty('bottom', 'auto', 'important');
            settingsPanel.scrollTop = 0;
            return;
        }
        var toggleRect = settingsToggle.getBoundingClientRect();
        var panelRect = settingsPanel.getBoundingClientRect();
        var panelWidth = Math.min(panelRect.width || 330, Math.max(240, viewportWidth - (gap * 2)));
        var left = toggleRect.right - panelWidth;
        left = Math.max(viewportLeft + gap, Math.min(left, viewportLeft + viewportWidth - panelWidth - gap));
        var top = toggleRect.bottom + gap;
        var panelHeight = Math.min(panelRect.height || 420, viewportHeight - (gap * 2));
        if (top + panelHeight > viewportTop + viewportHeight - gap) {
            top = Math.max(viewportTop + gap, toggleRect.top - panelHeight - gap);
        }
        settingsPanel.style.setProperty('left', Math.round(left) + 'px', 'important');
        settingsPanel.style.setProperty('right', 'auto', 'important');
        settingsPanel.style.setProperty('top', Math.round(top) + 'px', 'important');
        settingsPanel.style.setProperty('bottom', 'auto', 'important');
    }
    function openSettings() {
        if (!settings || !settingsPanel) { return; }
        window.clearTimeout(settingsCloseTimer);
        ensureSettingsPortal();
        settings.classList.add('is-open');
        settingsPanel.hidden = false;
        if (settingsBackdrop) { settingsBackdrop.hidden = false; }
        document.body.classList.add('hamnoor-reading-settings-open');
        if (settingsToggle) { settingsToggle.setAttribute('aria-expanded', 'true'); }
        window.requestAnimationFrame(function () {
            positionSettingsPanel(); window.setTimeout(positionSettingsPanel, 40);
            var focusables = focusableElements(settingsPanel);
            try { (focusables[0] || settingsPanel).focus({ preventScroll: true }); } catch (e) { try { (focusables[0] || settingsPanel).focus(); } catch (ignore) {} }
        });
    }
    function closeSettings(delay) {
        window.clearTimeout(settingsCloseTimer);
        settingsCloseTimer = window.setTimeout(function () {
            if (!settings || !settingsPanel) { return; }
            settings.classList.remove('is-open');
            settingsPanel.hidden = true;
            if (settingsToggle) { settingsToggle.setAttribute('aria-expanded', 'false'); }
            restoreSettingsPanel();
            if (settingsToggle && typeof settingsToggle.focus === 'function') { try { settingsToggle.focus({ preventScroll: true }); } catch (e) {} }
        }, Math.max(0, delay || 0));
    }
    function syncFocusMode() {
        var active = !!preferences.focus;
        if (active && readingWrap && !readingWrap.classList.contains('hamnoor-reading-focus-host')) {
            focusWindowScroll = window.scrollY || window.pageYOffset || 0;
            focusPlaceholder = document.createComment('hamnoor-reading-focus-placeholder');
            if (readingWrap.parentNode) { readingWrap.parentNode.insertBefore(focusPlaceholder, readingWrap); }
            document.body.appendChild(readingWrap);
            readingWrap.classList.add('hamnoor-reading-focus-host');
        } else if (!active && readingWrap && readingWrap.classList.contains('hamnoor-reading-focus-host')) {
            readingWrap.classList.remove('hamnoor-reading-focus-host');
            if (focusPlaceholder && focusPlaceholder.parentNode) {
                focusPlaceholder.parentNode.insertBefore(readingWrap, focusPlaceholder);
                focusPlaceholder.parentNode.removeChild(focusPlaceholder);
            }
            focusPlaceholder = null;
            window.requestAnimationFrame(function () { window.scrollTo(0, focusWindowScroll); });
        }
        root.classList.toggle('is-focus-mode', active);
        document.body.classList.toggle('hamnoor-reading-focus-active', active);
        document.documentElement.classList.toggle('hamnoor-reading-focus-active', active);
        if (focusExitButton) { focusExitButton.hidden = !active; focusExitButton.setAttribute('aria-hidden', active ? 'false' : 'true'); }
    }
    function updateReadingMetrics() {
        var focus = root.classList.contains('is-focus-mode');
        var adminBar = document.getElementById('wpadminbar');
        var adminHeight = (!focus && adminBar && window.getComputedStyle(adminBar).position === 'fixed') ? adminBar.getBoundingClientRect().height : 0;
        var stickyTop = focus ? 0 : adminHeight + 12;
        root.style.setProperty('--hamnoor-reading-sticky-top', stickyTop + 'px');
        var chrome = root.querySelector('[data-reading-chrome]');
        var stickyHeight = 0;
        [chrome, toolbar].forEach(function (element) {
            if (!element || element.hidden) { return; }
            var style = window.getComputedStyle(element);
            if (style.display === 'none' || style.visibility === 'hidden') { return; }
            if (style.position !== 'sticky' && style.position !== 'fixed') { return; }
            stickyHeight += element.getBoundingClientRect().height;
        });
        if (!stickyHeight && toolbar) {
            stickyHeight = toolbar.getBoundingClientRect().height || 0;
        }
        root.style.setProperty('--hamnoor-reading-scroll-offset', (stickyTop + stickyHeight + 12) + 'px');
        var playerHeight = player && !player.hidden ? player.getBoundingClientRect().height : 0;
        root.style.setProperty('--hamnoor-reading-player-height', playerHeight + 'px');
        root.style.setProperty('--hamnoor-reading-bottom-offset', (playerHeight ? playerHeight + 36 : 18) + 'px');
    }
    function scrollToVerse(verse, smooth, force) {
        if (!verse || (!force && !preferences.autoScroll)) { return; }
        updateReadingMetrics();
        var offset = parseFloat(window.getComputedStyle(root).getPropertyValue('--hamnoor-reading-scroll-offset')) || 110;
        var behavior = smooth && !prefersReducedMotion ? 'smooth' : 'auto';
        if (root.classList.contains('is-focus-mode')) {
            var rootRect = root.getBoundingClientRect();
            var target = root.scrollTop + verse.getBoundingClientRect().top - rootRect.top - offset;
            root.scrollTo({ top: Math.max(0, target), behavior: behavior });
        } else {
            var targetWindow = window.scrollY + verse.getBoundingClientRect().top - offset;
            window.scrollTo({ top: Math.max(0, targetWindow), behavior: behavior });
        }
    }
    function applyPreferences() {
        root.classList.toggle('is-translation-hidden', !preferences.translation);
        root.classList.toggle('is-dark', !!preferences.dark);
        syncFocusMode();
        root.style.setProperty('--hamnoor-reading-arabic-size', preferences.arabicSize + 'px');
        root.style.setProperty('--hamnoor-reading-translation-size', preferences.translationSize + 'px');
        var translation = root.querySelector('[data-reading-pref="translation"]'); if (translation) { translation.checked = !!preferences.translation; }
        var autoScroll = root.querySelector('[data-reading-pref="auto-scroll"]'); if (autoScroll) { autoScroll.checked = !!preferences.autoScroll; }
        var dark = root.querySelector('[data-reading-pref="dark"]'); if (dark) { dark.checked = !!preferences.dark; }
        var arabic = root.querySelector('[data-reading-pref="arabic-size"]'); if (arabic) { arabic.value = String(preferences.arabicSize); }
        var transSize = root.querySelector('[data-reading-pref="translation-size"]'); if (transSize) { transSize.value = String(preferences.translationSize); }
        var focusButton = root.querySelector('[data-reading-focus]'); if (focusButton) { focusButton.textContent = preferences.focus ? 'خروج از حالت بدون مزاحمت' : 'مطالعه بدون مزاحمت'; }
        updateReadingMetrics();
    }
    loadPreferences();

    function setMessage(text, type) {
        if (!message) { return; }
        message.textContent = text || '';
        message.classList.remove('is-success', 'is-error', 'is-loading');
        if (type) { message.classList.add('is-' + type); }
    }
    function request(action, values) {
        var body = new FormData();
        body.append('action', action); body.append('nonce', HamnoorReading.nonce); body.append(idField, String(entityId));
        if (HamnoorReading.access) { body.append('access', String(HamnoorReading.access)); }
        Object.keys(values || {}).forEach(function (key) { body.append(key, String(values[key])); });
        return fetch(HamnoorReading.ajax_url, { method: 'POST', credentials: 'same-origin', body: body }).then(function (response) { return response.json(); });
    }
    function responseError(result, fallback) {
        var error = new Error(result && result.data && result.data.message ? result.data.message : fallback);
        error.hamnoorServer = true;
        return error;
    }
    function focusableElements(container) {
        if (!container) { return []; }
        return Array.prototype.slice.call(container.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(function (el) { return !el.hidden && el.getAttribute('aria-hidden') !== 'true' && el.getClientRects().length > 0; });
    }
    function trapFocus(event, container) {
        if (event.key !== 'Tab' || !container) { return; }
        var focusables = focusableElements(container);
        if (!focusables.length) { event.preventDefault(); try { container.focus(); } catch (e) {} return; }
        var first = focusables[0], last = focusables[focusables.length - 1];
        if (event.shiftKey && (document.activeElement === first || !container.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
    function setResilienceNotice(text, mode) {
        if (!resilienceNotice) { return; }
        if (!text) { resilienceNotice.hidden = true; resilienceNotice.classList.remove('is-loading','is-error'); if (retryStateButton) { retryStateButton.hidden = true; } return; }
        resilienceNotice.hidden = false;
        resilienceNotice.classList.toggle('is-loading', mode === 'loading');
        resilienceNotice.classList.toggle('is-error', mode === 'error');
        if (resilienceCopy) { resilienceCopy.textContent = text; }
        if (retryStateButton) { retryStateButton.hidden = mode !== 'error'; }
    }
    function updateNetworkStatus() {
        if (!isPartReader || !networkStatus) { return; }
        var online = navigator.onLine;
        root.classList.toggle('is-offline', !online);
        networkStatus.hidden = online;
        networkStatus.textContent = online ? '' : ((HamnoorReading.texts && HamnoorReading.texts.offline_status) || 'آفلاین؛ محل مطالعه روی این دستگاه محفوظ می‌ماند.');
        networkStatus.classList.toggle('is-offline', !online);
        if (!online) { setResilienceNotice('', ''); }
    }
    function beginStateRefreshUi() {
        window.clearTimeout(stateRefreshSlowTimer);
        stateRefreshSlowTimer = window.setTimeout(function () {
            if (stateRefreshBusy) { setResilienceNotice((HamnoorReading.texts && HamnoorReading.texts.checking_state) || 'در حال بررسی آخرین وضعیت سهم…', 'loading'); }
        }, 450);
    }
    function finishStateRefreshUi(error) {
        window.clearTimeout(stateRefreshSlowTimer);
        stateRefreshSlowTimer = 0;
        if (error) {
            stateRefreshFailures += 1;
            setResilienceNotice((HamnoorReading.texts && HamnoorReading.texts.state_check_error) || 'ارتباط با هم‌نور برقرار نشد. متن برای مطالعه در دسترس است؛ وضعیت سهم هنوز تأیید نشده است.', 'error');
        } else {
            stateRefreshFailures = 0;
            setResilienceNotice('', '');
        }
    }
    function publishReadingSync(type, data) {
        if (!isPartReader) { return; }
        var envelope = { source:syncSource, type:type, data:data || {}, sent_at:Date.now(), id:syncSource + ':' + Date.now() + ':' + Math.random().toString(36).slice(2, 7) };
        if (syncChannel) { try { syncChannel.postMessage(envelope); } catch (e) {} }
        try { window.localStorage.setItem(syncStorageKey, JSON.stringify(envelope)); } catch (e) {}
    }
    function clearQueuedProgressUpTo(index) {
        var queue = readQueue();
        var queued = queue.progress ? parseInt(queue.progress.global_index || '0', 10) || 0 : 0;
        if (queued && queued <= index) { delete queue.progress; writeQueue(queue); }
    }
    function provisionalProgress(index) {
        var data = optimisticProgress(index);
        data.provisional = true;
        return data;
    }
    function applyServerProgress(data, broadcast) {
        if (!data) { return; }
        data = Object.assign({}, data);
        var incoming = parseInt(data.global_index || '0', 10) || 0;
        if (isPartReader && incoming) {
            if (incoming < serverLastIndex) {
                var monotonic = optimisticProgress(serverLastIndex);
                data.global_index = monotonic.global_index;
                data.percent = monotonic.percent;
                data.ready = monotonic.ready ? 1 : 0;
                incoming = serverLastIndex;
            }
            serverLastIndex = Math.max(serverLastIndex, incoming);
            clearQueuedProgressUpTo(serverLastIndex);
        }
        data.provisional = false;
        updateProgress(data);
        if (isPartReader && broadcast) { publishReadingSync('progress', data); }
    }
    function renderReaderUnavailable(data) {
        if (!isPartReader) { return; }
        root.classList.add('is-unavailable');
        clearQueuedProgressUpTo(endIndex);
        root.setAttribute('data-reading-state', 'unavailable');
        finishButtons.forEach(function (button) { button.disabled = true; });
        if (completionPanel) { completionPanel.hidden = true; }
        var resumeLink = root.querySelector('[data-reading-resume]'); if (resumeLink) { resumeLink.hidden = true; }
        if (stateNotice) {
            stateNotice.hidden = false;
            if (stateNoticeTitle) { stateNoticeTitle.textContent = 'این سهم دیگر فعال نیست'; }
            if (stateNoticeCopy) { stateNoticeCopy.textContent = (data && data.message) || ((HamnoorReading.texts && HamnoorReading.texts.unavailable) || 'این سهم دیگر در وضعیت قابل قرائت نیست.'); }
            if (stateNoticeAction && data && data.dashboard_url) { stateNoticeAction.href = data.dashboard_url; }
        }
        if (autoObserver) { try { autoObserver.disconnect(); } catch (e) {} }
        if (stateRefreshTimer) { window.clearInterval(stateRefreshTimer); stateRefreshTimer = 0; }
        if (typeof audio !== 'undefined' && audio) { try { if (!audio.paused) { audio.pause(); } audio.removeAttribute('src'); audio.load(); } catch (e) {} }
        if (player) { player.hidden = true; }
        root.querySelectorAll('[data-audio-play]').forEach(function (button) { button.disabled = true; button.setAttribute('aria-disabled', 'true'); });
        if (endObserver) { try { endObserver.disconnect(); } catch (e) {} }
        window.clearTimeout(autoSaveTimer); window.clearTimeout(autoCandidateTimer);
        autoSavePending = 0;
        setMessage('', '');
    }
    function reconcileReadingState(data, source) {
        if (!isPartReader || !data) { return; }
        if (data.state === 'unavailable') {
            renderReaderUnavailable(data);
            if (source !== 'sync') { publishReadingSync('unavailable', data); }
            return;
        }
        if (data.completed || data.state === 'completed') {
            serverLastIndex = endIndex;
            clearQueuedProgressUpTo(endIndex);
            if (renderCompletionSuccess(data, false)) {
                if (successMessage && source && source !== 'initial' && source !== 'finish') { successMessage.textContent = (HamnoorReading.texts && HamnoorReading.texts.completed_elsewhere) || data.message || 'این سهم در جای دیگری تکمیل شده است.'; }
                if (source !== 'sync' && source !== 'finish') { publishReadingSync('completed', data); }
            }
            return;
        }
        root.classList.remove('is-unavailable');
        root.querySelectorAll('[data-audio-play]').forEach(function (button) { button.disabled = false; button.removeAttribute('aria-disabled'); });
        if (stateNotice) { stateNotice.hidden = true; }
        var serverIndex = parseInt(data.global_index || '0', 10) || 0;
        serverLastIndex = Math.max(serverLastIndex, serverIndex);
        clearQueuedProgressUpTo(serverLastIndex);
        var queue = readQueue();
        var localIndex = queue.progress ? parseInt(queue.progress.global_index || '0', 10) || 0 : 0;
        if (localIndex > serverLastIndex) {
            updateProgress(provisionalProgress(localIndex));
            setMessage((HamnoorReading.texts && HamnoorReading.texts.sync_pending) || 'محل مطالعه در انتظار همگام‌سازی است.', 'loading');
            if (navigator.onLine) { window.setTimeout(flushQueuedReading, 50); }
        } else {
            applyServerProgress(data, false);
            if (source === 'sync') { setMessage((HamnoorReading.texts && HamnoorReading.texts.synced_elsewhere) || 'پیشرفت از تب دیگر همگام شد.', 'success'); }
        }
    }
    function handleReadingSync(envelope) {
        if (!isPartReader || !envelope || envelope.source === syncSource) { return; }
        if (envelope.type === 'progress') { reconcileReadingState(envelope.data || {}, 'sync'); }
        else if (envelope.type === 'completed') { reconcileReadingState(Object.assign({ completed:1, state:'completed' }, envelope.data || {}), 'sync'); }
        else if (envelope.type === 'unavailable') { reconcileReadingState(Object.assign({ state:'unavailable' }, envelope.data || {}), 'sync'); }
    }
    function initReadingSync() {
        if (!isPartReader) { return; }
        if ('BroadcastChannel' in window) {
            try { syncChannel = new BroadcastChannel('hamnoor-reading:' + String(entityId)); syncChannel.addEventListener('message', function (event) { handleReadingSync(event.data); }); } catch (e) { syncChannel = null; }
        }
        window.addEventListener('storage', function (event) {
            if (event.key === syncStorageKey && event.newValue) { try { handleReadingSync(JSON.parse(event.newValue)); } catch (e) {} }
            if (event.key === queueKey) {
                var queue = readQueue();
                var queuedIndex = queue.progress ? parseInt(queue.progress.global_index || '0', 10) || 0 : 0;
                if (queuedIndex > serverLastIndex) { updateProgress(provisionalProgress(queuedIndex)); setMessage((HamnoorReading.texts && HamnoorReading.texts.sync_pending) || 'محل مطالعه در انتظار همگام‌سازی است.', 'loading'); }
            }
        });
    }
    function refreshReadingState(source) {
        if (!isPartReader || !actions.state || !navigator.onLine || stateRefreshBusy) { return Promise.resolve(null); }
        stateRefreshBusy = true;
        beginStateRefreshUi();
        return request(actions.state, {}).then(function (result) {
            if (!result || !result.success) { throw responseError(result, HamnoorReading.texts.error); }
            reconcileReadingState(result.data || {}, source || 'refresh');
            finishStateRefreshUi(null);
            return result.data || {};
        }).catch(function (error) {
            finishStateRefreshUi(error || new Error('state_refresh_failed'));
            return null;
        }).finally(function () { stateRefreshBusy = false; });
    }
    function readQueue() {
        try { return JSON.parse(window.localStorage.getItem(queueKey) || '{}') || {}; } catch (e) { return {}; }
    }
    function writeQueue(queue) {
        try {
            if (!queue || (!queue.progress && !queue.audio)) { window.localStorage.removeItem(queueKey); }
            else { window.localStorage.setItem(queueKey, JSON.stringify(queue)); }
        } catch (e) {}
    }
    function queueProgress(index) {
        var queue = readQueue();
        var incoming = parseInt(index || '0', 10) || 0;
        var existing = queue.progress ? parseInt(queue.progress.global_index || '0', 10) || 0 : 0;
        var highest = Math.max(existing, incoming);
        if (!highest) { return; }
        queue.progress = { global_index: highest, queued_at: Date.now() };
        writeQueue(queue);
    }
    function queueAudio(index, seconds) {
        var queue = readQueue();
        queue.audio = { global_index: parseInt(index || '0', 10) || 0, seconds: Number(seconds || 0), queued_at: Date.now() };
        writeQueue(queue);
    }
    function optimisticProgress(index) {
        index = parseInt(index || '0', 10) || 0;
        var span = Math.max(1, endIndex - startIndex + 1);
        var done = Math.max(0, Math.min(span, index - startIndex + 1));
        return { global_index:index, percent:Math.round((done / span) * 100), ready:index >= endIndex };
    }
    function flushQueuedReading() {
        if (queueFlushing || !navigator.onLine) { return; }
        var queue = readQueue();
        if (!queue.progress && !queue.audio) { return; }
        queueFlushing = true;
        var chain = Promise.resolve();
        if (queue.progress) {
            (function(item){
                chain = chain.then(function(){
                    return request(actions.save, { global_index:item.global_index }).then(function(result){
                        if (!result || !result.success) { throw responseError(result, HamnoorReading.texts.error); }
                        var latest = readQueue();
                        if (latest.progress && latest.progress.queued_at === item.queued_at) { delete latest.progress; writeQueue(latest); }
                        applyServerProgress(result.data, true);
                        setMessage('پیشرفت ذخیره‌شده روی دستگاه با سرور همگام شد.', 'success');
                    });
                });
            }(queue.progress));
        }
        if (queue.audio && actions.audio) {
            (function(item){
                chain = chain.then(function(){
                    return request(actions.audio, { global_index:item.global_index, seconds:item.seconds }).then(function(result){
                        if (!result || !result.success) { throw new Error(result && result.data && result.data.message ? result.data.message : HamnoorReading.texts.error); }
                        var latest = readQueue();
                        if (latest.audio && latest.audio.queued_at === item.queued_at) { delete latest.audio; writeQueue(latest); }
                    });
                });
            }(queue.audio));
        }
        chain.catch(function(error){
            if (error && error.hamnoorServer && isPartReader) { refreshReadingState('queue_rejected'); }
            setMessage('اطلاعات مطالعه روی این دستگاه محفوظ است؛ برای همگام‌سازی، اتصال اینترنت و نشست هم‌نور را بررسی کنید.', error && error.hamnoorServer ? 'error' : 'loading');
        }).finally(function(){ queueFlushing = false; });
    }
    function setFinishEnabled(enabled) {
        finishButtons.forEach(function (button) {
            button.disabled = !enabled;
            if (!isPartReader) { button.hidden = !enabled; }
        });
        if (isPartReader && completionPanel && !root.classList.contains('is-completed')) {
            completionPanel.hidden = !enabled;
            root.setAttribute('data-reading-state', enabled ? 'ready' : (parseInt(root.getAttribute('data-last-index') || '0', 10) >= startIndex ? 'reading' : 'not_started'));
        }
    }
    var finishConfirmLayer = null;
    var finishConfirmTrigger = null;
    function closeFinishConfirmation() {
        if (!finishConfirmLayer) { return; }
        if (finishConfirmLayer.parentNode) { finishConfirmLayer.parentNode.removeChild(finishConfirmLayer); }
        finishConfirmLayer = null;
        if (finishConfirmTrigger && typeof finishConfirmTrigger.focus === 'function') {
            try { finishConfirmTrigger.focus({ preventScroll: true }); } catch (e) { try { finishConfirmTrigger.focus(); } catch (ignore) {} }
        }
        finishConfirmTrigger = null;
    }
    function renderCompletionSuccess(data) {
        var focusSuccess = arguments.length > 1 ? arguments[1] : true;
        if (!isPartReader || !completionPanel) { return false; }
        root.classList.remove('is-unavailable', 'is-sync-pending');
        root.classList.add('is-completed', 'is-review-mode');
        serverLastIndex = endIndex;
        clearQueuedProgressUpTo(endIndex);
        if (stateNotice) { stateNotice.hidden = true; }
        if (autoObserver) { try { autoObserver.disconnect(); } catch (e) {} }
        if (endObserver) { try { endObserver.disconnect(); } catch (e) {} }
        if (stateRefreshTimer) { window.clearInterval(stateRefreshTimer); stateRefreshTimer = 0; }
        window.clearTimeout(autoSaveTimer); window.clearTimeout(autoCandidateTimer); autoSavePending = 0;
        root.setAttribute('data-reading-state', 'completed');
        finishButtons.forEach(function (button) { button.disabled = true; });
        if (completionReadyView) { completionReadyView.hidden = true; }
        if (completionSuccessView) {
            completionSuccessView.hidden = false;
            if (successMessage && data && data.message) { successMessage.textContent = data.message; }
            if (nextAction && data && data.next_action) {
                if (data.next_action.url) { nextAction.href = data.next_action.url; }
                if (data.next_action.label) { nextAction.textContent = data.next_action.label; }
                nextAction.setAttribute('data-reading-next-action-type', data.next_action.type || 'choose');
            }
            if (campaignAction && data && data.campaign_url) { campaignAction.href = data.campaign_url; }
            if (dashboardAction && data && data.dashboard_url) { dashboardAction.href = data.dashboard_url; }
            if (nextAction && campaignAction && nextAction.href === campaignAction.href) { campaignAction.hidden = true; }
            if (focusSuccess !== false) { try { completionSuccessView.focus({ preventScroll: true }); } catch (e) { try { completionSuccessView.focus(); } catch (ignore) {} } }
        }
        percentNodes.forEach(function (node) { node.textContent = '100٪'; });
        if (progressBar) { progressBar.style.width = '100%'; }
        if (progressElement) { progressElement.setAttribute('aria-valuenow', '100'); }
        var label = root.querySelector('[data-reading-progress-label]');
        if (label) { label.textContent = 'تکمیل‌شده ✓'; }
        setMessage('', '');
        return true;
    }
    function submitFinishReading() {
        if (!isPartReader) { closeFinishConfirmation(); }
        if (busy) { return; }
        if (isPartReader && (!navigator.onLine || (readQueue().progress && readQueue().progress.global_index))) {
            setMessage('برای ثبت پایان، ابتدا محل مطالعه باید با سرور همگام شود.', 'error');
            flushQueuedReading();
            return;
        }
        busy = true;
        finishButtons.forEach(function (button) { button.disabled = true; button.classList.add('is-loading'); if (!button.getAttribute('data-finish-label')) { button.setAttribute('data-finish-label', button.textContent || 'قرائت تمام شد'); } button.textContent = HamnoorReading.texts.finishing || 'در حال ثبت پایان قرائت...'; });
        setMessage(HamnoorReading.texts.finishing, 'loading');
        request(actions.finish, {}).then(function (result) {
            if (!result || !result.success) { throw new Error(result && result.data && result.data.message ? result.data.message : HamnoorReading.texts.error); }
            dispatchCompletionExtensionFeedback(result.data && result.data.extension_feedback);
            if (isPartReader && renderCompletionSuccess(result.data || {})) { publishReadingSync('completed', result.data || {}); busy = false; return; }
            setMessage(result.data.message, 'success');
            var feedbackDelay = result.data && result.data.extension_feedback && Object.keys(result.data.extension_feedback).length ? 2200 : 800;
            window.setTimeout(function () { window.location.replace(result.data.redirect || HamnoorReading.dashboard_url); }, feedbackDelay);
        }).catch(function (error) {
            setMessage(error.message || HamnoorReading.texts.error, 'error');
            if (isPartReader) { refreshReadingState('finish_error'); }
            busy = false;
            finishButtons.forEach(function (button) { button.classList.remove('is-loading'); button.textContent = button.getAttribute('data-finish-label') || ((HamnoorReading.texts && HamnoorReading.texts.finish_button) || 'قرائت تمام شد'); });
            setFinishEnabled(true);
        });
    }
    function showFinishConfirmation(trigger) {
        closeFinishConfirmation();
        finishConfirmTrigger = trigger || null;
        var layer = document.createElement('div');
        layer.className = 'hamnoor-reading-finish-confirm-layer';
        layer.setAttribute('data-reading-finish-confirm-layer', '1');
        layer.innerHTML = '<div class="hamnoor-reading-finish-confirm-backdrop" data-reading-finish-cancel></div>' +
            '<div class="hamnoor-reading-finish-confirm" role="dialog" aria-modal="true" tabindex="-1" aria-labelledby="hamnoor-reading-finish-confirm-title">' +
                '<strong id="hamnoor-reading-finish-confirm-title">' + ((HamnoorReading.texts && HamnoorReading.texts.finish_title) || 'ثبت پایان قرائت سهم') + '</strong>' +
                '<p>' + ((HamnoorReading.texts && HamnoorReading.texts.finish_confirm) || 'قرائت این سهم به پایان رسیده است؟') + '</p>' +
                '<div class="hamnoor-reading-finish-confirm-actions">' +
                    '<button type="button" class="hamnoor-btn hamnoor-btn-success" data-reading-finish-confirm>' + ((HamnoorReading.texts && HamnoorReading.texts.finish_button) || 'بله، پایان سهم ثبت شود') + '</button>' +
                    '<button type="button" class="hamnoor-btn hamnoor-btn-secondary" data-reading-finish-cancel>انصراف</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(layer);
        finishConfirmLayer = layer;
        var confirmButton = layer.querySelector('[data-reading-finish-confirm]');
        if (confirmButton) { window.setTimeout(function () { try { confirmButton.focus(); } catch (e) {} }, 0); }
    }
    function updateProgress(data) {
        var percent = Number(data.percent || 0);
        percentNodes.forEach(function (node) { node.textContent = String(percent) + '٪'; });
        if (progressBar) { progressBar.style.width = String(percent) + '%'; }
        if (progressElement) { progressElement.setAttribute('aria-valuenow', String(percent)); }
        root.setAttribute('data-last-index', String(data.global_index || 0));
        var savedIndex = parseInt(data.global_index || 0, 10);
        var authoritativeIndex = data.provisional ? serverLastIndex : savedIndex;
        if (!data.provisional) { serverLastIndex = Math.max(serverLastIndex, authoritativeIndex); }
        verses.forEach(function (verse) {
            var index = parseInt(verse.getAttribute('data-verse-index') || '0', 10);
            var read = index <= authoritativeIndex;
            verse.classList.toggle('is-read', read);
            var badge = verse.querySelector('[data-saved-badge]');
            if (badge) { badge.classList.toggle('is-visible', read); badge.setAttribute('aria-hidden', read ? 'false' : 'true'); }
            var save = verse.querySelector('[data-reading-save]');
            if (save) {
                var defaultLabel = save.getAttribute('data-default-label') || 'تا اینجا خواندم';
                save.classList.remove('is-saved', 'is-past-saved', 'is-error', 'hamnoor-btn-success');
                save.classList.add('hamnoor-btn-secondary');
                if (index < savedIndex) {
                    save.disabled = true; save.classList.add('is-past-saved'); save.textContent = 'خوانده‌شده';
                } else if (index === savedIndex) {
                    save.disabled = true; save.classList.remove('hamnoor-btn-secondary'); save.classList.add('hamnoor-btn-success', 'is-saved'); save.textContent = '✓ تا این آیه ثبت شد';
                } else {
                    save.disabled = false; save.textContent = defaultLabel;
                }
            }
        });
        var savedIndexForResume = parseInt(data.global_index || 0, 10);
        var resume = isPartReader ? savedIndexForResume : savedIndexForResume + 1;
        var resumeLink = root.querySelector('[data-reading-resume]');
        if (resumeLink) {
            resumeLink.hidden = Boolean(data.ready);
            if (!data.ready && resume <= endIndex) {
                var resumeVerse = document.getElementById('hamnoor-verse-' + resume);
                if (resumeVerse && root.contains(resumeVerse)) { resumeLink.setAttribute('href', '#hamnoor-verse-' + resume); resumeLink.textContent = mode === 'personal_quran' ? 'ادامه از محل بعدی' : 'ادامه از محل ذخیره‌شده'; }
                else if (mode === 'personal_quran') { resumeLink.hidden = true; }
            }
        }
        var provisional = Boolean(data.provisional);
        root.classList.toggle('is-sync-pending', provisional);
        if (provisional) {
            setFinishEnabled(false);
            root.setAttribute('data-reading-state', 'sync_pending');
        } else {
            setFinishEnabled(Boolean(data.ready));
        }
    }

    document.addEventListener('change', function (event) {
        var control = event.target.closest('[data-reading-pref]');
        if (!control || !settingsPanel || !settingsPanel.contains(control)) { return; }
        var key = control.getAttribute('data-reading-pref');
        if (key === 'translation') { preferences.translation = control.checked; }
        else if (key === 'auto-scroll') { preferences.autoScroll = control.checked; }
        else if (key === 'dark') { preferences.dark = control.checked; }
        else if (key === 'arabic-size') { preferences.arabicSize = parseInt(control.value || '38', 10); }
        else if (key === 'translation-size') { preferences.translationSize = parseInt(control.value || '18', 10); }
        savePreferences(); applyPreferences();
        closeSettings(control.matches('input[type=range]') ? 500 : 260);
    });
    document.addEventListener('input', function (event) {
        var control = event.target.closest('input[type="range"][data-reading-pref]');
        if (!control || !settingsPanel || !settingsPanel.contains(control)) { return; }
        if (control.getAttribute('data-reading-pref') === 'arabic-size') { preferences.arabicSize = parseInt(control.value || '38', 10); }
        if (control.getAttribute('data-reading-pref') === 'translation-size') { preferences.translationSize = parseInt(control.value || '18', 10); }
        savePreferences(); applyPreferences();
    });
    document.addEventListener('click', function (event) {
        var focusExit = event.target.closest('[data-reading-focus-exit]');
        if (focusExit) {
            event.preventDefault();
            closeSettings(0);
            preferences.focus = false;
            savePreferences();
            applyPreferences();
            return;
        }
        var settingsOpen = event.target.closest('[data-reading-settings-toggle]');
        if (settingsOpen) {
            event.preventDefault();
            if (settings && settings.classList.contains('is-open')) { closeSettings(0); } else { openSettings(); }
            return;
        }
        var settingsClose = event.target.closest('[data-reading-settings-close], [data-reading-settings-done]');
        if (settingsClose) { event.preventDefault(); closeSettings(0); return; }
        var focusButton = event.target.closest('[data-reading-focus]');
        if (focusButton) {
            event.preventDefault(); closeSettings(0); preferences.focus = !preferences.focus; savePreferences(); applyPreferences();
            window.setTimeout(function(){ var currentVerse = root.querySelector('.hamnoor-reading-verse.is-playing') || root.querySelector('.hamnoor-reading-verse.is-resume'); if(currentVerse){scrollToVerse(currentVerse,false,true);} }, 100);
            return;
        }
        var resetButton = event.target.closest('[data-reading-reset]');
        if (resetButton) {
            event.preventDefault(); preferences = Object.assign({}, defaultPreferences); savePreferences(); applyPreferences(); closeSettings(250); return;
        }
        var resumeLink = event.target.closest('[data-reading-resume]');
        if (resumeLink && root.contains(resumeLink)) {
            var selector = resumeLink.getAttribute('href') || ''; var target = selector.charAt(0) === '#' ? document.querySelector(selector) : null;
            if (target) { event.preventDefault(); scrollToVerse(target, true, true); }
        }
    });

    document.addEventListener('click', function (event) {
        var confirmFinish = event.target.closest('[data-reading-finish-confirm]');
        if (confirmFinish && finishConfirmLayer && finishConfirmLayer.contains(confirmFinish)) {
            event.preventDefault();
            submitFinishReading();
            return;
        }
        var cancelFinish = event.target.closest('[data-reading-finish-cancel]');
        if (cancelFinish && finishConfirmLayer && finishConfirmLayer.contains(cancelFinish)) {
            event.preventDefault();
            closeFinishConfirmation();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (finishConfirmLayer) {
            var dialog = finishConfirmLayer.querySelector('.hamnoor-reading-finish-confirm');
            trapFocus(event, dialog);
            if (event.key === 'Escape') { event.preventDefault(); closeFinishConfirmation(); }
            return;
        }
        if (settings && settings.classList.contains('is-open') && settingsPanel && !settingsPanel.hidden) {
            trapFocus(event, settingsPanel);
        }
    });

    root.addEventListener('click', function (event) {
        var saveButton = event.target.closest('[data-reading-save]');
        if (saveButton) {
            event.preventDefault(); if (busy) { return; } busy = true;
            var index = parseInt(saveButton.getAttribute('data-reading-save') || '0', 10); var oldText = saveButton.textContent;
            saveButton.disabled = true; saveButton.textContent = HamnoorReading.texts.saving; setMessage(HamnoorReading.texts.saving, 'loading');
            request(actions.save, { global_index: index }).then(function (result) {
                if (!result || !result.success) { throw new Error(result && result.data && result.data.message ? result.data.message : HamnoorReading.texts.error); }
                if (isPartReader) { applyServerProgress(result.data, true); } else { updateProgress(result.data); } setMessage(result.data.message || HamnoorReading.texts.saved, 'success');
                var savedVerse = saveButton.closest('[data-verse-index]');
                if (savedVerse) { savedVerse.classList.add('is-just-saved'); window.setTimeout(function () { savedVerse.classList.remove('is-just-saved'); }, 1800); }
            }).catch(function (error) {
                queueProgress(index);
                updateProgress(isPartReader ? provisionalProgress(index) : optimisticProgress(index));
                setMessage('این پیشرفت روی دستگاه شما ذخیره شد و پس از برقراری اتصال به هم‌نور ارسال می‌شود.', 'loading');
                saveButton.classList.remove('is-error');
            }).finally(function () { busy = false; });
            return;
        }
        var finishButton = event.target.closest('[data-reading-finish]');
        if (!finishButton || finishButton.disabled) { return; }
        event.preventDefault();
        if (busy) { return; }
        if (isPartReader) { submitFinishReading(); }
        else { showFinishConfirmation(finishButton); }
    });

    // Shared Quran progress: a verse is saved only after it remains in the
    // reading band long enough to count as a meaningful reading position. This
    // is presentation/position tracking only; reaching the end never completes
    // domain assignment. Public shares and Personal Khatm reuse this queue;
    // their server-side progress services remain authoritative.
    var autoSaveBusy = false;
    var autoSavePending = 0;
    var autoSaveTimer = 0;
    var autoCandidateTimer = 0;
    var autoCandidate = 0;
    var autoLastRequestAt = 0;
    var autoObserver = null;
    var endObserver = null;
    function saveAutomaticProgress(index, force) {
        if (isReviewMode || root.classList.contains('is-completed') || root.classList.contains('is-paused')) { return; }
        index = parseInt(index || '0', 10) || 0;
        var currentSaved = parseInt(root.getAttribute('data-last-index') || '0', 10) || 0;
        if (index < startIndex || index > endIndex || index <= currentSaved) { return; }
        autoSavePending = Math.max(autoSavePending, index);
        if (autoSaveBusy) { return; }
        var now = Date.now();
        var delay = force ? 0 : Math.max(0, 5200 - (now - autoLastRequestAt));
        window.clearTimeout(autoSaveTimer);
        autoSaveTimer = window.setTimeout(function () {
            var target = autoSavePending;
            autoSavePending = 0;
            if (!target) { return; }
            autoSaveBusy = true;
            autoLastRequestAt = Date.now();
            request(actions.save, { global_index: target }).then(function (result) {
                if (!result || !result.success) { throw responseError(result, HamnoorReading.texts.error); }
                if (isPartReader) { applyServerProgress(result.data, true); }
                else { updateProgress(result.data); }
                setMessage((result.data && result.data.message) || (HamnoorReading.texts && HamnoorReading.texts.saved) || 'محل قرائت ذخیره شد.', 'success');
                if (result.data && result.data.ready) { setMessage(HamnoorReading.texts.ready || result.data.message || '', 'success'); }
                else if (message && message.classList.contains('is-error')) { setMessage('', ''); }
            }).catch(function (error) {
                if (error && error.hamnoorServer) {
                    queueProgress(target);
                    updateProgress(provisionalProgress(target));
                    setMessage(error.message || HamnoorReading.texts.error, 'error');
                    if (isPartReader) { refreshReadingState('save_rejected'); }
                    return;
                }
                queueProgress(target);
                updateProgress(provisionalProgress(target));
                setMessage((HamnoorReading.texts && HamnoorReading.texts.offline) || 'محل مطالعه روی این دستگاه محفوظ است و پس از اتصال همگام می‌شود.', 'loading');
            }).finally(function () {
                autoSaveBusy = false;
                if (autoSavePending) { saveAutomaticProgress(autoSavePending, autoSavePending >= endIndex); }
            });
        }, delay);
    }
    function scheduleAutomaticCandidate(index, delay) {
        index = parseInt(index || '0', 10) || 0;
        if (!index || index <= parseInt(root.getAttribute('data-last-index') || '0', 10)) { return; }
        if (autoCandidate === index && autoCandidateTimer) { return; }
        autoCandidate = index;
        window.clearTimeout(autoCandidateTimer);
        autoCandidateTimer = window.setTimeout(function () {
            var target = autoCandidate;
            autoCandidateTimer = 0;
            saveAutomaticProgress(target, target >= endIndex);
        }, delay || (index >= endIndex ? 1250 : 780));
    }
    function initAutomaticReadingPosition() {
        if (isReviewMode || !('IntersectionObserver' in window)) { return; }
        autoObserver = new IntersectionObserver(function (entries) {
            var visible = entries.filter(function (entry) { return entry.isIntersecting; }).map(function (entry) { return parseInt(entry.target.getAttribute('data-verse-index') || '0', 10); }).filter(Boolean);
            if (!visible.length) { return; }
            scheduleAutomaticCandidate(Math.max.apply(Math, visible), 780);
        }, { root: null, rootMargin: '-18% 0px -58% 0px', threshold: 0 });
        verses.forEach(function (verse) { autoObserver.observe(verse); });
        var endMarker = root.querySelector('[data-reading-end-marker]');
        if (endMarker) {
            endObserver = new IntersectionObserver(function (entries) {
                if (entries.some(function (entry) { return entry.isIntersecting; })) { scheduleAutomaticCandidate(isPartReader ? endIndex : pageEndIndex, 1250); }
            }, { root: null, rootMargin: '0px 0px -8% 0px', threshold: 0.35 });
            endObserver.observe(endMarker);
        }
    }

    // Audio: ayah-by-ayah recitation from manahel.ir. Audio progress never marks an ayah as read.
    var audio = new Audio(); audio.preload = 'metadata';
    var current = -1, repeat = false, wakeLock = null, lastSavedAt = 0, pendingAudioIndex = -1, pendingAudioPosition = 0;
    var main = player && player.querySelector('[data-audio-main]');
    var title = player && player.querySelector('[data-audio-title]');
    var timeline = player && player.querySelector('[data-audio-timeline]');
    var progress = player && player.querySelector('[data-audio-progress]');
    var time = player && player.querySelector('[data-audio-time]');
    var duration = player && player.querySelector('[data-audio-duration]');
    function faNumber(value) { return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[parseInt(d,10)]; }); }
    function formatTime(seconds) { seconds = isFinite(seconds) ? Math.max(0, Math.floor(seconds)) : 0; return faNumber(Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0')); }
    function setActive(index) {
        verses.forEach(function (verse, i) {
            verse.classList.toggle('is-playing', i === index);
            var btn=verse.querySelector('[data-audio-play]');
            if(btn){
                var active = i === index && !audio.paused;
                btn.classList.toggle('is-playing', i === index);
                var label = btn.querySelector('span'); if (label) { label.textContent = active ? 'توقف' : 'پخش'; }
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            }
        });
        syncAudioControls();
    }
    function syncAudioControls() {
        if (!player) { return; }
        var prev = player.querySelector('[data-audio-prev]'), next = player.querySelector('[data-audio-next]');
        if (prev) { prev.disabled = current <= 0; prev.setAttribute('aria-disabled', prev.disabled ? 'true' : 'false'); }
        if (next) { next.disabled = current < 0 || current >= verses.length - 1; next.setAttribute('aria-disabled', next.disabled ? 'true' : 'false'); }
        if (main) {
            var playing = current >= 0 && !audio.paused;
            main.setAttribute('aria-pressed', playing ? 'true' : 'false');
            main.setAttribute('aria-label', playing ? 'توقف صوت' : 'پخش صوت');
        }
    }
    function setAudioPositionFromRatio(ratio) {
        if (!audio.duration) { return; }
        ratio = Math.max(0, Math.min(1, Number(ratio) || 0));
        audio.currentTime = ratio * audio.duration;
        saveAudio(true);
    }
    function syncTimelineAccessibility() {
        if (!timeline) { return; }
        var ratio = audio.duration ? Math.max(0, Math.min(1, audio.currentTime / audio.duration)) : 0;
        timeline.setAttribute('aria-valuenow', String(Math.round(ratio * 100)));
        timeline.setAttribute('aria-valuetext', formatTime(audio.currentTime) + ' از ' + formatTime(audio.duration));
    }
    function wakeOn() { if ('wakeLock' in navigator && !wakeLock) { navigator.wakeLock.request('screen').then(function(lock){wakeLock=lock; lock.addEventListener('release',function(){wakeLock=null;});}).catch(function(){}); } }
    function wakeOff() { if (wakeLock) { wakeLock.release().catch(function(){}); wakeLock=null; } }
    function saveAudio(force) {
        if (!actions.audio || current < 0 || !verses[current]) { return; }
        var now = Date.now(); if (!force && now - lastSavedAt < 12000) { return; } lastSavedAt = now;
        var audioIndex = verses[current].getAttribute('data-verse-index');
        var audioSeconds = audio.currentTime || 0;
        request(actions.audio, { global_index: audioIndex, seconds: audioSeconds }).then(function(result){
            if (!result || !result.success) { throw new Error('audio_save_failed'); }
        }).catch(function(){
            queueAudio(audioIndex, audioSeconds);
            if (!navigator.onLine) { setMessage('موقعیت صوت روی این دستگاه ذخیره شد و پس از اتصال همگام می‌شود.', 'loading'); }
        });
    }
    function load(index, position, autoplay) {
        if (index < 0 || index >= verses.length) { return; }
        if (current >= 0) { saveAudio(true); }
        current = index; var verse = verses[index]; audio.src = verse.getAttribute('data-audio') || ''; audio.load();
        player.hidden = false; setActive(index); syncAudioControls();
        if (title) { title.textContent = 'سوره ' + verse.getAttribute('data-surah') + '، آیه ' + verse.getAttribute('data-ayah') + ' · پرهیزکار'; }
        audio.addEventListener('loadedmetadata', function seekOnce(){ audio.removeEventListener('loadedmetadata',seekOnce); if(position>0 && position<audio.duration-1){audio.currentTime=position;} if(autoplay){audio.play().catch(function(){setMessage(HamnoorReading.texts.audio_error,'error');});} }, {once:true});
        window.setTimeout(function(){ scrollToVerse(verse, true, false); }, 30);
    }
    function toggle() { if (current < 0 || !audio.src) { load(pendingAudioIndex >= 0 ? pendingAudioIndex : 0, pendingAudioPosition, true); pendingAudioPosition = 0; return; } if (audio.paused) { audio.play().catch(function(){setMessage(HamnoorReading.texts.audio_error,'error');}); } else { audio.pause(); } }
    if (player) {
        root.addEventListener('click', function(e){ var btn=e.target.closest('[data-audio-play]'); if(!btn){return;} e.preventDefault(); var index=verses.indexOf(btn.closest('[data-verse-index]')); if(index===current){toggle();}else{load(index,0,true);} });
        if (main) { main.addEventListener('click', toggle); }
        player.querySelector('[data-audio-prev]').addEventListener('click',function(){if(current>0){load(current-1,0,true);}});
        player.querySelector('[data-audio-next]').addEventListener('click',function(){if(current>=0&&current<verses.length-1){load(current+1,0,true);}});
        player.querySelector('[data-audio-repeat]').addEventListener('click',function(e){repeat=!repeat;e.currentTarget.setAttribute('aria-pressed',repeat?'true':'false');e.currentTarget.classList.toggle('is-active',repeat);});
        player.querySelector('[data-audio-close]').addEventListener('click',function(){saveAudio(true);audio.pause();audio.removeAttribute('src');audio.load();player.hidden=true;setActive(-1);current=-1;wakeOff();});
        timeline.addEventListener('click',function(e){if(!audio.duration){return;}var r=timeline.getBoundingClientRect();setAudioPositionFromRatio((e.clientX-r.left)/Math.max(1,r.width));});
        timeline.addEventListener('keydown',function(e){
            if(!audio.duration){return;}
            var step = e.shiftKey ? 30 : 5;
            if(e.key==='ArrowLeft'||e.key==='ArrowDown'){e.preventDefault();audio.currentTime=Math.max(0,audio.currentTime-step);saveAudio(true);syncTimelineAccessibility();}
            else if(e.key==='ArrowRight'||e.key==='ArrowUp'){e.preventDefault();audio.currentTime=Math.min(audio.duration,audio.currentTime+step);saveAudio(true);syncTimelineAccessibility();}
            else if(e.key==='Home'){e.preventDefault();setAudioPositionFromRatio(0);syncTimelineAccessibility();}
            else if(e.key==='End'){e.preventDefault();setAudioPositionFromRatio(1);syncTimelineAccessibility();}
        });
        audio.addEventListener('play',function(){if(main){main.textContent='⏸';}setActive(current);syncAudioControls();wakeOn();});
        audio.addEventListener('pause',function(){if(main){main.textContent='▶';}setActive(current);syncAudioControls();saveAudio(true);wakeOff();});
        audio.addEventListener('timeupdate',function(){var ratio=audio.duration?audio.currentTime/audio.duration:0;if(progress){progress.style.width=(ratio*100)+'%';}if(time){time.textContent=formatTime(audio.currentTime);}if(duration){duration.textContent=formatTime(audio.duration);}syncTimelineAccessibility();saveAudio(false);});
        audio.addEventListener('ended',function(){saveAudio(true);if(repeat){load(current,0,true);}else if(current<verses.length-1){load(current+1,0,true);}else{if(main){main.textContent='▶';}wakeOff();setMessage((HamnoorReading.texts && HamnoorReading.texts.audio_end_message) || (isPartReader ? 'صوت سهم به پایان رسید. برای ثبت پایان، تا انتهای متن سهم پیش بروید.' : 'صوت به پایان رسید. آخرین محل قرائت را ثبت کنید.'),'success');}});
        audio.addEventListener('error',function(){setMessage(HamnoorReading.texts.audio_error,'error');if(main){main.textContent='▶';}syncAudioControls();});
        document.addEventListener('visibilitychange',function(){if(document.hidden){saveAudio(true);}else if(!audio.paused){wakeOn();}});
        window.addEventListener('pagehide',function(){audioWasPlayingOnPageHide=!audio.paused;saveAudio(true);if(audioWasPlayingOnPageHide){try{audio.pause();}catch(e){}}});
        var audioResume=parseInt(root.getAttribute('data-audio-resume-index')||'0',10), audioPos=parseFloat(root.getAttribute('data-audio-position')||'0');
        var audioResumeVerse=document.getElementById('hamnoor-verse-'+audioResume); var audioResumeArrayIndex=verses.indexOf(audioResumeVerse);
        if(audioResumeArrayIndex>=0){pendingAudioIndex=audioResumeArrayIndex;pendingAudioPosition=audioPos;player.hidden=false;if(title){title.textContent='ادامه صوت از سوره '+audioResumeVerse.getAttribute('data-surah')+'، آیه '+audioResumeVerse.getAttribute('data-ayah');}}
        syncAudioControls(); syncTimelineAccessibility();
    }

    applyPreferences();
    initReadingSync();
    initAutomaticReadingPosition();

    // Constant sticky geometry prevents a feedback loop with browser scroll
    // anchoring. The former scroll-driven height toggle caused visible jitter.

    if (retryStateButton) { retryStateButton.addEventListener('click', function () { setResilienceNotice((HamnoorReading.texts && HamnoorReading.texts.checking_state) || 'در حال بررسی آخرین وضعیت سهم…', 'loading'); refreshReadingState('manual_retry'); }); }
    var skipLink = root.querySelector('.hamnoor-reading-skip-link');
    if (skipLink && contentRegion) { skipLink.addEventListener('click', function () { window.setTimeout(function () { try { contentRegion.focus({ preventScroll:true }); } catch (e) {} }, 0); }); }
    updateNetworkStatus();

    var queuedAtLoad = readQueue();
    if (queuedAtLoad.progress && queuedAtLoad.progress.global_index) {
        updateProgress(isPartReader ? provisionalProgress(queuedAtLoad.progress.global_index) : optimisticProgress(queuedAtLoad.progress.global_index));
        setMessage('یک پیشرفت ذخیره‌شده روی این دستگاه در انتظار همگام‌سازی است.', 'loading');
    }
    window.addEventListener('online', function () { updateNetworkStatus(); if (networkStatus) { networkStatus.hidden=false; networkStatus.textContent=(HamnoorReading.texts&&HamnoorReading.texts.online_status)||'اتصال برقرار شد؛ در حال همگام‌سازی…'; window.setTimeout(function(){updateNetworkStatus();},1400); } flushQueuedReading(); refreshReadingState('online'); });
    window.addEventListener('offline', function () { updateNetworkStatus(); });
    window.addEventListener('focus', function () { refreshReadingState('focus'); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { refreshReadingState('visible'); } });
    window.setTimeout(function () { flushQueuedReading(); refreshReadingState('initial'); }, 350);
    if (isPartReader) { stateRefreshTimer = window.setInterval(function () { if (!document.hidden) { refreshReadingState('interval'); } }, 45000); }
    document.addEventListener('click', function (event) {
        if (settings && settings.classList.contains('is-open') && !settings.contains(event.target) && !(settingsPanel && settingsPanel.contains(event.target))) { closeSettings(0); }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') { return; }
        if (settings && settings.classList.contains('is-open')) { closeSettings(0); return; }
        if (preferences.focus) { preferences.focus = false; applyPreferences(); }
    });
    window.addEventListener('resize', function () { updateReadingMetrics(); positionSettingsPanel(); }, { passive: true });
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', positionSettingsPanel, { passive: true });
        window.visualViewport.addEventListener('scroll', positionSettingsPanel, { passive: true });
    }
    if (window.ResizeObserver) {
        var metricsObserver = new ResizeObserver(updateReadingMetrics);
        if (toolbar) { metricsObserver.observe(toolbar); }
        if (player) { metricsObserver.observe(player); }
    }
    if (player && window.MutationObserver) {
        new MutationObserver(updateReadingMetrics).observe(player, { attributes: true, attributeFilter: ['hidden', 'class'] });
    }
    window.setTimeout(updateReadingMetrics, 80);
    window.addEventListener('pagehide', function () {
        if (autoSavePending) { queueProgress(autoSavePending); }
    });
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) { return; }
        closeFinishConfirmation();
        if (settings && settings.classList.contains('is-open')) { closeSettings(0); }
        updateNetworkStatus();
        updateReadingMetrics();
        flushQueuedReading();
        refreshReadingState('pageshow');
        if (audioWasPlayingOnPageHide) { audioWasPlayingOnPageHide=false; setMessage((HamnoorReading.texts&&HamnoorReading.texts.audio_paused_restore)||'پخش صوت پس از بازگشت به صفحه متوقف مانده است؛ برای ادامه دوباره پخش را بزنید.','success'); }
    });

    if (!window.location.hash) {
        var resumeIndex = parseInt(root.getAttribute('data-resume-index') || '0', 10), resumeVerse = document.getElementById('hamnoor-verse-' + resumeIndex);
        var lastIndex = parseInt(root.getAttribute('data-last-index') || '0', 10);
        if (resumeVerse && lastIndex >= startIndex) { window.setTimeout(function () { scrollToVerse(resumeVerse, true, true); }, 250); }
    } else {
        window.setTimeout(function () {
            var hashTarget = null;
            try { hashTarget = document.querySelector(window.location.hash); } catch (e) {}
            if (hashTarget && root.contains(hashTarget)) { scrollToVerse(hashTarget, false, true); }
        }, 180);
    }
}());
