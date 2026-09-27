/* Authenticated possession verification never creates a login or recovery session. */
(() => {
  'use strict';
  const digits = (value) => value.replace(/[۰-۹٠-٩]/g, (digit) => String(digit.charCodeAt(0) % 16));
  document.querySelectorAll('.pinova-mobile-proof').forEach((root) => {
    const form = root.querySelector('form');
    const content = root.querySelector('.pinova-mobile-proof-content');
    const heading = root.querySelector('h2');
    const mobile = form.elements.mobile;
    const code = form.elements.code;
    const codeWrap = root.querySelector('.pinova-mobile-code');
    const submit = root.querySelector('[type="submit"]');
    const status = root.querySelector('.pinova-mobile-status');
    const loader = root.querySelector('.pinova-mobile-busy');
    const resend = root.querySelector('.pinova-mobile-resend');
    const edit = root.querySelector('.pinova-mobile-edit');
    const countdown = root.querySelector('.pinova-mobile-countdown');
    const length = Number(root.dataset.length);
    let token = '';
    let busy = false;
    let deadline = 0;
    let submittedCode = '';
    let complete = false;
    let webOtp = null;
    let transport = null;
    let alive = true;
    let pendingAutofill = false;
    code.pattern = `[0-9]{${length}}`;
    const stopWebOtp = () => { if (webOtp) webOtp.abort(); webOtp = null; };
    const tick = () => {
      const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      countdown.textContent = token && remaining ? `ارسال دوباره تا ${remaining} ثانیه دیگر` : '';
      resend.hidden = !token || remaining > 0 || complete;
    };
    const setBusy = (value) => {
      busy = value;
      content.inert = value;
      loader.hidden = !value;
      form.setAttribute('aria-busy', String(value));
    };
    const request = async (action, data) => {
      transport = new AbortController();
      const timeout = setTimeout(() => transport?.abort(), 20000);
      try {
        const response = await fetch(root.dataset.endpoint + action, {
          method: 'POST', credentials: 'same-origin', signal: transport.signal,
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': root.dataset.nonce },
          body: JSON.stringify(data),
        });
        const body = await response.json();
        if (!body || typeof body !== 'object') throw new Error();
        if (!response.ok || body.success !== true) {
          const error = new Error(typeof body.message === 'string' ? body.message : 'امکان پردازش درخواست وجود ندارد.');
          error.serverResponse = true;
          error.retryAfter = Number(response.headers.get('Retry-After'));
          error.invalidCode = response.status === 401 && action === 'verify';
          throw error;
        }
        if (action === 'request' && (!body.data || typeof body.data.jwt !== 'string' || body.data.jwt.length > 2048 ||
          !Number.isInteger(body.data.ttl) || body.data.ttl < 1 || body.data.ttl > 3600)) throw new Error();
        return body;
      } catch (error) {
        if (error.serverResponse) throw error;
        throw new Error('ارتباط با سرور برقرار نشد. دوباره تلاش کنید.');
      } finally {
        clearTimeout(timeout);
        transport = null;
      }
    };
    const autoSubmit = () => {
      code.value = digits(code.value).replace(/[^0-9]/g, '').slice(0, length);
      code.setCustomValidity('');
      if (!busy && token && code.value.length === length && code.value !== submittedCode) {
        submittedCode = code.value;
        form.requestSubmit();
      }
    };
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (busy || complete) return;
      mobile.value = digits(mobile.value);
      if (!form.reportValidity()) return;
      const verifying = Boolean(token);
      setBusy(true);
      status.textContent = '';
      try {
        const body = await request(verifying ? 'verify' : 'request', verifying ? { jwt: token, code: code.value } : { mobile: mobile.value });
        if (!alive) return;
        status.textContent = typeof body.message === 'string' ? body.message : '';
        if (verifying) {
          complete = true;
          token = '';
          code.value = '';
          stopWebOtp();
          content.hidden = true;
          status.tabIndex = -1;
          status.focus();
        } else {
          token = body.data.jwt;
          deadline = Date.now() + body.data.ttl * 1000;
          codeWrap.hidden = false;
          code.disabled = false;
          code.required = true;
          mobile.readOnly = true;
          edit.hidden = false;
          submit.textContent = 'تأیید شماره';
          tick();
          if ('OTPCredential' in window && navigator.credentials) {
            stopWebOtp();
            webOtp = new AbortController();
            navigator.credentials.get({ otp: { transport: ['sms'] }, signal: webOtp.signal }).then((credential) => {
              if (credential && token && !complete && alive) {
                code.value = credential.code;
                if (busy) pendingAutofill = true; else autoSubmit();
              }
            }).catch(() => {});
          }
        }
      } catch (error) {
        if (!alive) return;
        status.textContent = error instanceof Error ? error.message : 'ارتباط با سرور برقرار نشد.';
        if (error.retryAfter > 0) deadline = Date.now() + error.retryAfter * 1000;
        if (error.invalidCode) code.setCustomValidity('کد را بررسی کنید یا پس از پایان مهلت کد تازه بگیرید.');
      } finally {
        if (alive) {
          setBusy(false);
          if (!complete) heading.focus();
          if (pendingAutofill) { pendingAutofill = false; autoSubmit(); }
        }
      }
    });
    code.addEventListener('input', autoSubmit);
    resend.addEventListener('click', () => {
      if (busy || deadline > Date.now()) return;
      token = ''; code.value = ''; code.setCustomValidity(''); code.disabled = true;
      submittedCode = ''; stopWebOtp(); form.requestSubmit();
    });
    edit.addEventListener('click', () => {
      if (busy) return;
      token = ''; code.value = ''; code.setCustomValidity(''); code.disabled = true;
      submittedCode = ''; stopWebOtp(); codeWrap.hidden = true;
      mobile.readOnly = false; edit.hidden = true; resend.hidden = true;
      countdown.textContent = ''; status.textContent = 'برای شمارهٔ متفاوت، تا پایان مهلت کد قبلی صبر کنید.';
      submit.textContent = 'دریافت کد تأیید'; heading.focus();
    });
    const timer = setInterval(tick, 1000);
    window.addEventListener('pagehide', () => { alive = false; clearInterval(timer); stopWebOtp(); transport?.abort(); token = ''; code.value = ''; }, { once: true });
  });
})();
