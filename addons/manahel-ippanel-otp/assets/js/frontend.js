(function () {
  'use strict';

  function init(root) {
    const sendForm = root.querySelector('[data-mio-send-form]');
    const verifyForm = root.querySelector('[data-mio-verify-form]');
    const statusBox = root.querySelector('[data-mio-status]');
    const registerFields = root.querySelector('[data-mio-register-fields]');
    const maskedMobile = root.querySelector('[data-mio-masked-mobile]');
    const resendButton = root.querySelector('[data-mio-resend]');
    const countdown = root.querySelector('[data-mio-countdown]');
    const editButton = root.querySelector('[data-mio-edit-mobile]');
    let resendSeconds = 0;
    let timer = null;

    const showStatus = (message, type) => {
      statusBox.hidden = false;
      statusBox.textContent = message;
      statusBox.className = 'mio-status mio-status--' + (type || 'info');
    };

    const setLoading = (button, loading) => {
      button.disabled = loading;
      if (!button.dataset.originalText) button.dataset.originalText = button.textContent;
      button.textContent = loading ? 'لطفاً صبر کنید…' : button.dataset.originalText;
    };

    const post = async (url, data) => {
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-Manahel-Nonce': ManahelOTP.nonce
        },
        body: JSON.stringify(data)
      });
      const json = await response.json().catch(() => ({}));
      if (!response.ok) {
        const error = new Error(json.message || 'خطایی رخ داد. دوباره تلاش کنید.');
        error.data = json.data || {};
        throw error;
      }
      return json;
    };

    const startCountdown = (seconds) => {
      resendSeconds = Number(seconds || 60);
      resendButton.disabled = true;
      clearInterval(timer);
      const tick = () => {
        if (resendSeconds <= 0) {
          countdown.textContent = '';
          resendButton.disabled = false;
          clearInterval(timer);
          return;
        }
        countdown.textContent = '(' + resendSeconds + ' ثانیه)';
        resendSeconds -= 1;
      };
      tick();
      timer = setInterval(tick, 1000);
    };

    const sendCode = async () => {
      const formData = new FormData(sendForm);
      const data = Object.fromEntries(formData.entries());
      const button = sendForm.querySelector('[data-mio-send-button]');
      setLoading(button, true);
      try {
        const result = await post(ManahelOTP.sendUrl, data);
        verifyForm.elements.mobile.value = data.mobile;
        verifyForm.elements.request_token.value = result.request_token || '';
        maskedMobile.textContent = result.masked_mobile || data.mobile;
        sendForm.hidden = true;
        verifyForm.hidden = false;
        verifyForm.elements.code.focus();
        showStatus(result.message || 'کد تأیید ارسال شد.', 'success');
        startCountdown(result.resend_in);
      } catch (error) {
        if (error.data && error.data.needs_registration) {
          registerFields.open = true;
          root.querySelector('#mio-full-name').focus();
        }
        showStatus(error.message, 'error');
      } finally {
        setLoading(button, false);
      }
    };

    sendForm.addEventListener('submit', function (event) {
      event.preventDefault();
      sendCode();
    });

    verifyForm.addEventListener('submit', async function (event) {
      event.preventDefault();
      const data = Object.fromEntries(new FormData(verifyForm).entries());
      const button = verifyForm.querySelector('[data-mio-verify-button]');
      setLoading(button, true);
      try {
        const result = await post(ManahelOTP.verifyUrl, data);
        showStatus(result.message || 'ورود انجام شد.', 'success');
        window.location.assign(result.redirect || '/');
      } catch (error) {
        showStatus(error.message, 'error');
        verifyForm.elements.code.select();
      } finally {
        setLoading(button, false);
      }
    });

    editButton.addEventListener('click', function () {
      clearInterval(timer);
      verifyForm.hidden = true;
      sendForm.hidden = false;
      statusBox.hidden = true;
      sendForm.elements.mobile.focus();
    });

    resendButton.addEventListener('click', function () {
      if (!resendButton.disabled) {
        verifyForm.hidden = true;
        sendForm.hidden = false;
        sendCode();
      }
    });
  }

  document.querySelectorAll('[data-mio-root]').forEach(init);
})();
