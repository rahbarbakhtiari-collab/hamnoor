(function () {
    'use strict';

    var cfg = window.HamnoorEitaaBridge || {};
    var statusEl = document.getElementById('hn-eitaa-status');
    var connectBtn = document.getElementById('hn-eitaa-connect');
    var dashboardLink = document.getElementById('hn-eitaa-dashboard');
    var unsubscribeBtn = document.getElementById('hn-eitaa-unsubscribe');
    var enableBtn = document.getElementById('hn-eitaa-enable');
    var retryBtn = document.getElementById('hn-eitaa-retry');
    var introEl = document.getElementById('hn-eitaa-intro');
    var flowToken = '';
    var linked = false;
    var webApp = window.Eitaa && window.Eitaa.WebApp ? window.Eitaa.WebApp : null;

    function text(key, fallback) {
        return cfg.strings && cfg.strings[key] ? cfg.strings[key] : fallback;
    }

    function setStatus(message, type, busy) {
        if (!statusEl) { return; }
        statusEl.className = 'hn-eitaa-status' + (type === 'error' ? ' is-error' : type === 'success' ? ' is-success' : '');
        statusEl.innerHTML = '';
        if (busy) {
            var spinner = document.createElement('span');
            spinner.className = 'hn-eitaa-spinner';
            statusEl.appendChild(spinner);
        }
        statusEl.appendChild(document.createTextNode(message || ''));
    }

    function show(el, yes) {
        if (el) { el.hidden = !yes; }
    }

    function disableActions(disabled) {
        [connectBtn, retryBtn, unsubscribeBtn, enableBtn].forEach(function (el) {
            if (el) { el.disabled = !!disabled; }
        });
    }

    function api(action, data) {
        var payload = new URLSearchParams();
        payload.set('action', action);
        payload.set('_ajax_nonce', cfg.nonce || '');
        Object.keys(data || {}).forEach(function (key) {
            payload.set(key, data[key] == null ? '' : String(data[key]));
        });
        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: payload.toString()
        }).then(function (response) {
            return response.json();
        });
    }

    function messageOf(result, fallback) {
        return result && result.data && result.data.message ? result.data.message : fallback;
    }

    function finish(result) {
        var target = result && result.data && result.data.redirect ? result.data.redirect : cfg.dashboardUrl;
        if (dashboardLink) { dashboardLink.href = target || cfg.dashboardUrl || '/'; }
        setStatus(result && result.data && result.data.message ? result.data.message : 'ورود ایتا تأیید شد.', 'success');
        show(introEl, false);
        show(connectBtn, false);
        show(dashboardLink, true);
        var notificationsEnabled = !(result && result.data && result.data.notifications_enabled === false);
        show(unsubscribeBtn, notificationsEnabled);
        show(enableBtn, !notificationsEnabled);
        disableActions(false);
        // Normal use must stay frictionless. Only a user who explicitly opted
        // out is kept here so re-enabling remains possible and understandable.
        if (notificationsEnabled) {
            window.setTimeout(function () {
                if (target) { window.location.replace(target); }
            }, 180);
        }
    }

    function requestContactAndConnect() {
        if (!webApp || typeof webApp.requestContact !== 'function') {
            setStatus('نسخه ایتای شما از دریافت امن شماره موبایل پشتیبانی نمی‌کند. ایتا را به‌روزرسانی کنید.', 'error');
            disableActions(false);
            return;
        }
        setStatus(text('contact', 'شماره موبایل حساب ایتای خود را تأیید کنید.'), '', true);
        webApp.requestContact(function (ok, data) {
            var response = data && typeof data.response === 'string' ? data.response : '';
            if (!ok || !response) {
                setStatus(text('contactDenied', 'شماره موبایل به اشتراک گذاشته نشد.'), 'error');
                show(connectBtn, true);
                disableActions(false);
                return;
            }
            setStatus('در حال اعتبارسنجی شماره و اتصال پنل هم‌نور…', '', true);
            api('hamnoor_eitaa_connect_contact', {
                flow_token: flowToken,
                contact_data: response
            }).then(function (result) {
                if (!result || !result.success) {
                    setStatus(messageOf(result, 'اتصال ایتا انجام نشد.'), 'error');
                    if (result && result.data && result.data.dashboard_url && dashboardLink) {
                        dashboardLink.href = result.data.dashboard_url;
                        show(dashboardLink, true);
                    }
                    show(connectBtn, true);
                    disableActions(false);
                    return;
                }
                linked = true;
                setStatus(messageOf(result, text('done', 'اتصال با موفقیت انجام شد.')), 'success');
                finish(result);
            }).catch(function () {
                setStatus('ارتباط با سرور هم‌نور برقرار نشد. دوباره تلاش کنید.', 'error');
                show(connectBtn, true);
                disableActions(false);
            });
        });
    }

    function startNewConnection() {
        disableActions(true);
        show(connectBtn, false);
        requestContactAndConnect();
        setTimeout(function () {
            if (!linked) {
                show(connectBtn, true);
                if (connectBtn) { connectBtn.disabled = false; }
            }
        }, 1800);
    }

    function validateInit() {
        show(connectBtn, false);
        show(dashboardLink, false);
        show(unsubscribeBtn, false);
        show(enableBtn, false);
        show(retryBtn, false);
        disableActions(true);

        if (!cfg.programConfigured) {
            setStatus('توکن اختصاصی برنامه ایتا هنوز در مدیریت هم‌نور تنظیم نشده است.', 'error');
            return;
        }
        if (!webApp || !webApp.initData) {
            setStatus(text('outside', 'این صفحه را از داخل ایتا باز کنید.'), 'error');
            show(retryBtn, true);
            disableActions(false);
            return;
        }
        try {
            if (typeof webApp.ready === 'function') { webApp.ready(); }
            if (typeof webApp.expand === 'function') { webApp.expand(); }
        } catch (e) {}

        setStatus(text('loading', 'در حال اعتبارسنجی ورود ایتا…'), '', true);
        var sdkStartParam = '';
        try {
            sdkStartParam = webApp.initDataUnsafe && webApp.initDataUnsafe.start_param ? String(webApp.initDataUnsafe.start_param) : '';
        } catch (e) {}
        api('hamnoor_eitaa_validate_init', {
            init_data: webApp.initData,
            launch_param: cfg.launchParam || sdkStartParam || ''
        }).then(function (result) {
            if (!result || !result.success) {
                setStatus(messageOf(result, 'اعتبارسنجی ورود ایتا ناموفق بود.'), 'error');
                show(retryBtn, true);
                disableActions(false);
                return;
            }
            flowToken = result.data.flow_token || '';
            linked = !!result.data.linked;
            setStatus(result.data.message || 'هویت ایتا تأیید شد.', 'success');

            if (linked) {
                finish(result);
                return;
            }
            // A first launch should immediately request the verified phone.
            // If the client blocks automatic native prompts, the visible
            // button remains as a user-gesture fallback.
            show(connectBtn, true);
            disableActions(false);
            startNewConnection();
        }).catch(function () {
            setStatus('ارتباط با سرور هم‌نور برقرار نشد. دوباره تلاش کنید.', 'error');
            show(retryBtn, true);
            disableActions(false);
        });
    }

    if (connectBtn) { connectBtn.addEventListener('click', startNewConnection); }
    if (unsubscribeBtn) { unsubscribeBtn.addEventListener('click', function () {
        if (!window.confirm('اطلاع‌رسانی هم‌نور در ایتا متوقف شود؟ حساب و سوابق شما حذف نمی‌شود.')) { return; }
        disableActions(true);
        setStatus('در حال توقف اطلاع‌رسانی ایتا…', '', true);
        api('hamnoor_eitaa_unsubscribe', {flow_token: flowToken}).then(function (result) {
            if (!result || !result.success) {
                setStatus(messageOf(result, 'توقف اطلاع‌رسانی انجام نشد.'), 'error');
                disableActions(false); return;
            }
            setStatus(messageOf(result, 'اطلاع‌رسانی ایتا متوقف شد.'), 'success');
            show(unsubscribeBtn, false); show(dashboardLink, true); disableActions(false);
        }).catch(function () {
            setStatus('ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید.', 'error'); disableActions(false);
        });
    }); }
    if (enableBtn) { enableBtn.addEventListener('click', function () {
        disableActions(true);
        setStatus('در حال فعال‌سازی مجدد اطلاع‌رسانی ایتا…', '', true);
        api('hamnoor_eitaa_confirm_write_access', {flow_token: flowToken}).then(function (result) {
            if (!result || !result.success) {
                setStatus(messageOf(result, 'فعال‌سازی اطلاع‌رسانی انجام نشد.'), 'error');
                disableActions(false); return;
            }
            setStatus(messageOf(result, 'اطلاع‌رسانی ایتا فعال شد.'), 'success');
            show(enableBtn, false); show(unsubscribeBtn, true); disableActions(false);
            var target = result.data && result.data.redirect ? result.data.redirect : cfg.dashboardUrl;
            window.setTimeout(function () { if (target) { window.location.replace(target); } }, 350);
        }).catch(function () {
            setStatus('ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید.', 'error'); disableActions(false);
        });
    }); }
    if (retryBtn) { retryBtn.addEventListener('click', validateInit); }
    validateInit();
}());
