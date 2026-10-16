(function () {
    'use strict';

    var cfg = window.HamnoorEitaaApp || {};
    var webApp = window.Eitaa && window.Eitaa.WebApp ? window.Eitaa.WebApp : null;
    if (!webApp) { return; }

    document.documentElement.classList.add('hamnoor-eitaa-app');
    if (document.body) { document.body.classList.add('hamnoor-eitaa-app-page'); }

    function samePath(a, b) {
        try {
            var aa = new URL(a, window.location.href);
            var bb = new URL(b, window.location.href);
            return aa.origin === bb.origin && aa.pathname.replace(/\/+$/, '') === bb.pathname.replace(/\/+$/, '');
        } catch (e) { return false; }
    }

    function isBridgeReferrer() {
        if (!document.referrer || !cfg.bridgeUrl) { return false; }
        return samePath(document.referrer, cfg.bridgeUrl);
    }

    function isDashboardRoot() {
        if (!cfg.dashboardUrl || !samePath(window.location.href, cfg.dashboardUrl)) { return false; }
        try {
            var current = new URL(window.location.href);
            return !current.searchParams.get('tab') && !current.searchParams.get('hamnoor_reading');
        } catch (e) { return true; }
    }

    function goBack() {
        // Dashboard tabs/readers are deterministic: native Back returns to the
        // dashboard root rather than to the onboarding bridge.
        if (cfg.dashboardUrl && samePath(window.location.href, cfg.dashboardUrl) && !isDashboardRoot()) {
            window.location.replace(cfg.dashboardUrl);
            return;
        }
        // location.replace() from the bridge intentionally prevents onboarding
        // from becoming the user's Back destination. For direct/deep launches,
        // dashboard is the deterministic fallback.
        if (document.referrer && !isBridgeReferrer()) {
            try {
                var ref = new URL(document.referrer);
                if (ref.origin === window.location.origin) {
                    window.history.back();
                    return;
                }
            } catch (e) {}
        }
        if (cfg.dashboardUrl && !isDashboardRoot()) {
            window.location.replace(cfg.dashboardUrl);
        }
    }

    try {
        if (typeof webApp.ready === 'function') { webApp.ready(); }
        if (typeof webApp.expand === 'function') { webApp.expand(); }
        if (typeof webApp.setHeaderColor === 'function') { webApp.setHeaderColor('bg_color'); }
        if (typeof webApp.setBackgroundColor === 'function') { webApp.setBackgroundColor('bg_color'); }

        if (webApp.BackButton) {
            if (isDashboardRoot()) {
                if (typeof webApp.BackButton.hide === 'function') { webApp.BackButton.hide(); }
            } else {
                if (typeof webApp.BackButton.show === 'function') { webApp.BackButton.show(); }
                if (typeof webApp.BackButton.onClick === 'function') { webApp.BackButton.onClick(goBack); }
            }
        }
    } catch (e) {}
}());
