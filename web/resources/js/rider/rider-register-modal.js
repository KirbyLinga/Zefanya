import '../registration-validation.js';
import '../buyer/address-lookup.js';

document.addEventListener('DOMContentLoaded', () => {
    const overlay = document.getElementById('riderModalOverlay');
    if (!overlay || overlay.dataset.jsInit) return;
    overlay.dataset.jsInit = '1';
    const form = document.getElementById('riderRegisterForm');
    const otpForm = document.getElementById('riderOtpForm');
    const panels = [...overlay.querySelectorAll('.rider-panel')];
    const summary = document.getElementById('riderErrorSummary');
    const helper = window.__zfReg;
    const address = window.zfAddressLookup.init(overlay);
    let verificationUrl = '';
    let resendUrl = '';
    let currentStep = 1;
    let previousFocus;
    function error(message = '') {
        summary.textContent = message;
        summary.classList.toggle('hidden', !message);
    }
    function showStep(step) {
        currentStep = step;
        panels.forEach(panel => panel.classList.toggle('hidden', Number(panel.dataset.panel) !== step));
        overlay.querySelectorAll('[data-step-circle]').forEach(circle => {
            const active = Number(circle.dataset.stepCircle) <= step;
            ['bg-[#9c5c68]', 'border-[#9c5c68]', 'text-white'].forEach(cls => circle.classList.toggle(cls, active));
            ['bg-white', 'border-neutral-200', 'text-neutral-400'].forEach(cls => circle.classList.toggle(cls, !active));
        });
        overlay.querySelectorAll('[data-step-line]').forEach(line => {
            line.classList.toggle('bg-[#9c5c68]', Number(line.dataset.stepLine) < step);
            line.classList.toggle('bg-neutral-200', Number(line.dataset.stepLine) >= step);
        });
        const icons = ['user-round', 'map-pin', 'bike', 'mail-check'];
        document.getElementById('riderModalIcon').innerHTML = `<i data-lucide="${icons[step - 1]}" width="20" height="20"></i>`;
        window.lucide?.createIcons();
    }
    function open(event) {
        event?.preventDefault();
        previousFocus = document.activeElement;
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        address?.ensureLoaded();
        document.getElementById('riderModalClose').focus();
    }
    function close() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        previousFocus?.focus();
    }
    document.querySelectorAll('[data-rider-register-trigger]').forEach(button => button.addEventListener('click', open));
    document.getElementById('riderModalClose').addEventListener('click', close);
    overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
    document.addEventListener('keydown', event => {
        if (!overlay.classList.contains('is-open')) return;
        if (event.key === 'Escape') close();
        if (event.key === 'Tab') {
            const focusable = [...overlay.querySelectorAll('button, input, select, a[href]')].filter(el => !el.disabled && el.getClientRects().length);
            const first = focusable[0]; const last = focusable.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        }
    });
    // Shared capture-phase navigation validates the panel. Update this modal's stepper afterwards.
    overlay.addEventListener('click', event => {
        if (event.target.closest('.next-step-btn')) showStep(2);
    });
    document.getElementById('riderNextStep2').addEventListener('click', () => {
        const panel = panels.find(item => item.dataset.panel === '2');
        const valid = [...panel.querySelectorAll('[data-validate-rule]')].map(field => helper.validateField(field)).every(Boolean);
        if (valid) showStep(3);
    });
    document.getElementById('riderBackStep2').addEventListener('click', () => showStep(1));
    document.getElementById('riderBackStep3').addEventListener('click', () => showStep(2));
    form.querySelectorAll('input[type="file"]').forEach(field => field.addEventListener('change', () => {
        overlay.querySelector(`[data-file-name="${field.id}"]`).textContent = field.files[0]?.name || 'Choose a document';
    }));
    async function send(url, body) {
        const response = await fetch(url, {
            method: 'POST', body, credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value },
        });
        const data = await response.json();
        return { response, data };
    }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (form.dataset.zfSubmitting === '1') return;
        if (!helper.validateForm(form).valid) {
            const invalid = form.querySelector('[data-error]');
            if (invalid) showStep(Number(invalid.closest('[data-panel]').dataset.panel));
            return;
        }
        if (verificationUrl) { showStep(4); return; }
        helper.lockSubmit(form);
        error();
        document.getElementById('riderSubmit').textContent = 'Submitting...';
        try {
            const { response, data } = await send(form.action, new FormData(form));
            if (response.ok) {
                verificationUrl = data.verify_url;
                resendUrl = data.resend_url;
                document.getElementById('riderOtpEmail').textContent = data.email_masked;
                // Personal data is no longer editable once its application has been submitted.
                showStep(4);
                document.getElementById('riderOtp0').focus();
            } else {
                if (data.errors) {
                    const first = helper.showServerErrors(form, data.errors);
                    if (first) showStep(Number(first.closest('[data-panel]').dataset.panel));
                }
                error(data.message || 'Registration failed. Please try again.');
            }
        } catch { error('We could not complete registration. Please try again.'); }
        finally { helper.unlockSubmit(form); document.getElementById('riderSubmit').textContent = 'Submit'; }
    });
    const digits = [...otpForm.querySelectorAll('[data-otp-index]')];
    digits.forEach((input, index) => {
        input.addEventListener('input', () => {
            input.value = input.value.replace(/\D/g, '').slice(-1);
            if (input.value) digits[index + 1]?.focus();
        });
        input.addEventListener('keydown', event => { if (event.key === 'Backspace' && !input.value) digits[index - 1]?.focus(); });
        input.addEventListener('paste', event => {
            const value = event.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
            if (!value) return;
            event.preventDefault();
            digits.forEach((digit, i) => { digit.value = value[i] || ''; });
            digits[Math.min(value.length, 5)].focus();
        });
    });
    otpForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (otpForm.dataset.zfSubmitting === '1') return;
        const code = digits.map(input => input.value).join('');
        if (!/^\d{6}$/.test(code)) { error('Enter all 6 digits.'); return; }
        helper.lockSubmit(otpForm); error();
        try {
            const { response, data } = await send(verificationUrl, new URLSearchParams({ otp: code }));
            if (response.ok && data.redirect) window.location.assign(data.redirect);
            else error(data.message || 'Verification failed. Please try again.');
        } catch { error('We could not verify your code. Please try again.'); }
        finally { helper.unlockSubmit(otpForm); }
    });
    const resend = document.getElementById('riderOtpResend');
    resend.addEventListener('click', async () => {
        if (resend.disabled) return;
        resend.disabled = true; error();
        try {
            const { response, data } = await send(resendUrl, new URLSearchParams());
            if (response.ok) document.getElementById('riderOtpStatus').textContent = ` ${data.message}`;
            else error(data.message);
        } catch { error('We could not send a new code. Please try again.'); }
        finally { resend.disabled = false; }
    });
    showStep(currentStep);
    if (new URLSearchParams(window.location.search).get('open') === 'rider') open();
});
