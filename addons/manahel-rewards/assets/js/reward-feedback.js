(function () {
  'use strict';

  var seen = Object.create(null);
  var region = null;

  function faNumber(value) {
    try { return new Intl.NumberFormat(document.documentElement.lang || 'fa-IR').format(Number(value) || 0); }
    catch (e) { return String(Number(value) || 0); }
  }

  function ensureRegion() {
    if (region && document.body.contains(region)) { return region; }
    region = document.createElement('div');
    region.className = 'mr-feedback-region';
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('aria-atomic', 'false');
    region.setAttribute('aria-label', 'اعلان امتیاز');
    document.body.appendChild(region);
    return region;
  }

  function dismiss(toast) {
    if (!toast || toast.classList.contains('is-leaving')) { return; }
    toast.classList.add('is-leaving');
    window.setTimeout(function () {
      if (toast.parentNode) { toast.parentNode.removeChild(toast); }
    }, 220);
  }

  function updateDashboard(feedback) {
    var points = parseInt(feedback.points || 0, 10) || 0;
    var balance = feedback.balance;
    Array.prototype.forEach.call(document.querySelectorAll('.mr-hamnoor-dashboard-card'), function (card) {
      if (balance !== null && typeof balance !== 'undefined' && feedback.balance_display !== '') {
        card.setAttribute('data-mr-balance', String(balance));
        var balanceNode = card.querySelector('.mr-hamnoor-dashboard-card__balance strong');
        if (balanceNode) { balanceNode.textContent = feedback.balance_display || faNumber(balance); }
      }
      if (points > 0) {
        var currentToday = parseInt(card.getAttribute('data-mr-today-earned') || '0', 10) || 0;
        var nextToday = currentToday + points;
        card.setAttribute('data-mr-today-earned', String(nextToday));
        var todayNode = card.querySelector('.mr-hamnoor-dashboard-card__today strong');
        if (todayNode) { todayNode.textContent = '+' + faNumber(nextToday); }
      }
    });

    if (balance !== null && typeof balance !== 'undefined' && feedback.balance_display !== '') {
      Array.prototype.forEach.call(document.querySelectorAll('[data-manahel-rewards-balance]'), function (node) {
        node.textContent = feedback.balance_display || faNumber(balance);
      });
    }
  }

  function show(feedback) {
    if (!feedback || Number(feedback.announce || 0) !== 1) { return; }
    var id = String(feedback.feedback_id || '');
    if (id && seen[id]) { return; }
    if (id) { seen[id] = true; }

    updateDashboard(feedback);

    var toast = document.createElement('section');
    toast.className = 'mr-feedback-toast mr-feedback-toast--' + (feedback.tone === 'success' ? 'success' : 'info');
    toast.setAttribute('role', 'status');
    toast.setAttribute('dir', 'rtl');
    toast.innerHTML =
      '<button type="button" class="mr-feedback-toast__close" aria-label="بستن اعلان">×</button>' +
      '<div class="mr-feedback-toast__body">' +
        '<strong class="mr-feedback-toast__title"></strong>' +
        '<span class="mr-feedback-toast__message"></span>' +
        (feedback.balance_display ? '<small class="mr-feedback-toast__balance"></small>' : '') +
      '</div>';

    var title = toast.querySelector('.mr-feedback-toast__title');
    var message = toast.querySelector('.mr-feedback-toast__message');
    var balanceNode = toast.querySelector('.mr-feedback-toast__balance');
    if (title) { title.textContent = String(feedback.title || ''); }
    if (message) { message.textContent = String(feedback.message || ''); }
    if (balanceNode) { balanceNode.textContent = 'موجودی: ' + String(feedback.balance_display) + ' امتیاز'; }

    var close = toast.querySelector('.mr-feedback-toast__close');
    if (close) { close.addEventListener('click', function () { dismiss(toast); }); }

    ensureRegion().appendChild(toast);
    window.setTimeout(function () { toast.classList.add('is-visible'); }, 10);
    window.setTimeout(function () { dismiss(toast); }, 6500);
  }

  document.addEventListener('hamnoor:completion-feedback', function (event) {
    var detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
    show(detail.manahel_rewards || null);
  });
})();
