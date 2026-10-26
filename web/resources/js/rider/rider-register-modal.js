import { validateField, lockSubmit, unlockSubmit, showServerErrors } from '../registration-validation.js';

function initRiderRegistration() {
    const overlay = document.getElementById('riderModalOverlay');
    if (!overlay || overlay.dataset.jsInit === '1') return;
    overlay.dataset.jsInit = '1';

    const dialog = overlay.querySelector('[role="dialog"]');
    const modal = overlay.querySelector('.buyer-modal');
    const form = overlay.querySelector('#riderRegisterForm');
    const panels = [...overlay.querySelectorAll('[data-panel]')];
    const icon = overlay.querySelector('#riderModalIcon');
    const message = overlay.querySelector('#riderMessage');
    const otpForm = overlay.querySelector('#riderOtpForm');
    const otpInputs = [...overlay.querySelectorAll('[data-rider-otp-index]')];
    const otpErrorSummary = overlay.querySelector('#riderOtpErrorSummary');
    const otpFieldError = overlay.querySelector('#riderOtpFieldError');
    const otpEmail = overlay.querySelector('#riderOtpEmail');
    const otpResend = overlay.querySelector('#riderOtpResendBtn');
    const otpResendStatus = overlay.querySelector('#riderOtpResendStatus');
    const otpSubmit = overlay.querySelector('#riderOtpSubmit');
    const pending = overlay.querySelector('#riderPendingSection');
    const resumeBtn = overlay.querySelector('#riderResumeBtn');
    const resumeStatus = overlay.querySelector('#riderResumeStatus');
    const resumeUrl = overlay.dataset.resumeUrl;
    const csrf = form.querySelector('[name="_token"]').value;
    const addressLookup = window.zfAddressLookup?.init(overlay);
    const stepIcons = { 1: 'user-round', 2: 'map-pin', 3: 'shield-check', 4: 'mail-check' };
    let session = null;
    let previousFocus = null;
    let previousOverflow = '';
    let resendUntil = 0;
    let resendTimer = null;
    let resendBusy = false;
    let finished = false;

    function setMessage(text) {
        message.textContent = text || '';
        message.classList.toggle('hidden', !text);
    }

    function setStep(number, focus = true) {
        panels.forEach((panel) => panel.classList.toggle('hidden', Number(panel.dataset.panel) !== number));
        overlay.querySelectorAll('[data-rider-step-circle]').forEach((circle) => {
            const step = Number(circle.dataset.riderStepCircle);
            circle.classList.remove('bg-[#9c5c68]', 'border-[#9c5c68]', 'text-white', 'bg-white', 'border-neutral-200', 'text-neutral-400');
            circle.innerHTML = String(step);
            if (step <= number) {
                circle.classList.add('bg-[#9c5c68]', 'border-[#9c5c68]', 'text-white');
                if (step < number) circle.innerHTML = '<i data-lucide="check" width="14" height="14"></i>';
            } else {
                circle.classList.add('bg-white', 'border-neutral-200', 'text-neutral-400');
            }
        });
        overlay.querySelectorAll('[data-rider-step-line]').forEach((line) => {
            const complete = Number(line.dataset.riderStepLine) < number;
            line.classList.toggle('bg-[#9c5c68]', complete);
            line.classList.toggle('bg-neutral-200', !complete);
        });
        overlay.querySelectorAll('[data-rider-step-label]').forEach((label) => {
            const active = Number(label.dataset.riderStepLabel) <= number;
            label.classList.toggle('text-neutral-900', active);
            label.classList.toggle('text-neutral-400', !active);
        });
        icon.innerHTML = `<i data-lucide="${stepIcons[number]}" width="20" height="20"></i>`;
        window.lucide?.createIcons();
        if (number === 2) addressLookup?.ensureLoaded();
        if (focus) {
            const panel = panels.find((item) => Number(item.dataset.panel) === number);
            (panel?.querySelector('input:not([type="hidden"]), select, button') || dialog).focus();
        }
    }

    function open(trigger) {
        if (overlay.classList.contains('is-open')) return;
        previousFocus = trigger || document.activeElement;
        previousOverflow = document.body.style.overflow;
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        addressLookup?.ensureLoaded();
        setStep(session || finished ? 4 : 1, false);
        if (finished) pending.classList.remove('hidden');
        if (session) otpInputs[0].focus();
        else if (finished) pending.focus();
        else (panels[0].querySelector('input, select, button') || dialog).focus();
    }

    function close() {
        if (!overlay.classList.contains('is-open')) return;
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = previousOverflow;
        previousFocus?.focus();
    }

    function validatePanel(number) {
        const panel = panels.find((item) => Number(item.dataset.panel) === number);
        const invalid = [...panel.querySelectorAll('[data-validate-rule]')].filter((field) => !validateField(field));
        if (invalid.length) {
            invalid[0].focus();
            invalid[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }
        return true;
    }

    function field(name) {
        return form.elements.namedItem(name);
    }

    function value(name) {
        return field(name)?.value?.trim() || 'Not provided';
    }

    function selected(name) {
        return field(name)?.selectedOptions?.[0]?.textContent?.trim() || 'Not provided';
    }

    function updateReview() {
        const address = [
            selected('barangay'), selected('municipality'), selected('province'),
            value('street'), value('house_number'), value('address_detail'), value('postal_code'),
        ].filter((part) => part !== 'Not provided').join(', ');
        const middle = value('middle_initial');
        const review = {
            riderReviewName: `${value('first_name')} ${middle === 'Not provided' ? '' : `${middle} `}${value('last_name')}`,
            riderReviewEmail: value('email'),
            riderReviewContact: value('contact_no'),
            riderReviewAddress: address || 'Not provided',
            riderReviewVehicle: selected('vehicle_type'),
            riderReviewPlate: value('plate_number'),
            riderReviewProvider: selected('logistics_provider_id'),
        };
        Object.entries(review).forEach(([id, text]) => { overlay.querySelector(`#${id}`).textContent = text; });
    }

    async function post(url, body, multipart = false) {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf };
        if (!multipart) headers['Content-Type'] = 'application/json';
        const response = await fetch(url, {
            method: 'POST', credentials: 'same-origin', headers,
            body: multipart ? body : JSON.stringify(body),
        });
        const payload = await response.json().catch(() => ({ message: 'Unable to process your request. Please try again.' }));
        if (!response.ok) {
            const error = new Error(payload.message || 'Please check your details and try again.');
            error.status = response.status;
            error.errors = payload.errors || {};
            error.retryAfter = Number(payload.retry_after || response.headers.get('Retry-After')) || 60;
            throw error;
        }
        return payload;
    }

    function showOtpError(text) {
        otpErrorSummary.textContent = text;
        otpErrorSummary.classList.toggle('hidden', !text);
        otpFieldError.textContent = text;
        otpInputs.forEach((input) => input.classList.toggle('has-error', Boolean(text)));
    }

    function countdown(seconds) {
        resendUntil = Date.now() + Math.max(0, seconds) * 1000;
        clearInterval(resendTimer);
        const update = () => {
            const remaining = Math.max(0, Math.ceil((resendUntil - Date.now()) / 1000));
            otpResend.disabled = resendBusy || remaining > 0;
            otpResendStatus.textContent = remaining ? `${remaining}s` : '';
            if (!remaining) clearInterval(resendTimer);
        };
        update();
        if (seconds > 0) resendTimer = setInterval(update, 1000);
    }

    function enterOtpSession(data) {
        session = data;
        otpEmail.textContent = data.email_masked;
        form.classList.add('hidden');
        setStep(4, false);
        otpInputs.forEach((input) => { input.value = ''; });
        showOtpError('');
        otpInputs[0].focus();
    }

    async function resendCode() {
        try {
            const response = await post(session.resend_url, {});
            otpInputs.forEach((input) => { input.value = ''; });
            showOtpError('');
            setMessage(response.message || 'A new code has been sent.');
            countdown(60);
            otpInputs[0].focus();

            return true;
        } catch (error) {
            if (error.status === 429) {
                otpResendStatus.textContent = error.message;
                countdown(error.retryAfter);
            } else {
                showOtpError(error.message);
            }

            return false;
        }
    }

    function collectOtp() {
        return otpInputs.map((input) => input.value).join('');
    }

    document.querySelectorAll('[data-rider-register-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => { event.preventDefault(); open(trigger); });
    });
    overlay.querySelector('#riderModalClose').addEventListener('click', close);
    overlay.addEventListener('click', (event) => { if (event.target === overlay) close(); });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && overlay.classList.contains('is-open')) close();
    });

    for (const [name, labelId] of [['drivers_license', 'rider-drivers_licenseName'], ['or_cr', 'rider-or_crName']]) {
        field(name).addEventListener('change', (event) => {
            const file = event.target.files?.[0];
            overlay.querySelector(`#${labelId}`).textContent = file?.name || 'No file selected';
            if (file) validateField(event.target);
        });
    }

    overlay.querySelector('#riderNextStep1').addEventListener('click', () => {
        if (validatePanel(1)) { setMessage(''); setStep(2); }
    });
    overlay.querySelector('#riderBackStep2').addEventListener('click', () => setStep(1));
    overlay.querySelector('#riderNextStep2').addEventListener('click', () => {
        if (validatePanel(2)) { setMessage(''); setStep(3); }
    });
    overlay.querySelector('#riderBackStep3').addEventListener('click', () => setStep(2));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (finished || session || form.dataset.zfSubmitting === '1') return;
        if (!validatePanel(3)) return;
        updateReview();
        setMessage('');
        lockSubmit(form);
        modal.classList.add('is-busy');
        try {
            const payload = await post(form.action, new FormData(form), true);
            enterOtpSession(payload.data);
            countdown(60);
        } catch (error) {
            setMessage(error.message);
            const first = showServerErrors(form, error.errors || {});
            if (first) {
                const panel = Number(first.closest('[data-panel]')?.dataset.panel || 1);
                setStep(panel, false);
                first.focus();
            }
            if (error.status === 429) setMessage(`${error.message} Please wait ${error.retryAfter} seconds before trying again.`);
        } finally {
            unlockSubmit(form);
            modal.classList.remove('is-busy');
        }
    });

    form.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || !event.target.matches('input:not([type="file"])')) return;
        const active = panels.find((panel) => !panel.classList.contains('hidden'));
        if (Number(active?.dataset.panel) === 1) {
            event.preventDefault();
            overlay.querySelector('#riderNextStep1').click();
        } else if (Number(active?.dataset.panel) === 2) {
            event.preventDefault();
            overlay.querySelector('#riderNextStep2').click();
        }
    });

    otpInputs.forEach((input, index) => {
        input.addEventListener('input', () => {
            input.value = input.value.replace(/\D/g, '').slice(-1);
            showOtpError('');
            if (input.value && index < otpInputs.length - 1) otpInputs[index + 1].focus();
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Backspace' && !input.value && index > 0) otpInputs[index - 1].focus();
            if (event.key === 'ArrowLeft' && index > 0) otpInputs[index - 1].focus();
            if (event.key === 'ArrowRight' && index < otpInputs.length - 1) otpInputs[index + 1].focus();
        });
        input.addEventListener('paste', (event) => {
            const pasted = (event.clipboardData?.getData('text') || '').replace(/\D/g, '').slice(0, 6);
            if (!pasted) return;
            event.preventDefault();
            otpInputs.forEach((target, targetIndex) => { target.value = pasted[targetIndex] || ''; });
            otpInputs[Math.min(pasted.length, 6) - 1].focus();
        });
    });

    otpForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        showOtpError('');
        const otp = collectOtp();
        if (!/^\d{6}$/.test(otp)) {
            showOtpError('Enter all 6 digits.');
            otpInputs.find((input) => !input.value)?.focus();
            return;
        }
        lockSubmit(otpForm);
        otpSubmit.textContent = 'Verifying...';
        try {
            const response = await post(session.verify_url, { otp });
            if (response.data?.status !== 'pending') throw new Error('Unable to confirm the application status. Please try again.');
            session = null;
            finished = true;
            clearInterval(resendTimer);
            otpForm.classList.add('hidden');
            pending.classList.remove('hidden');
            pending.focus();
        } catch (error) {
            showOtpError(error.message);
            if (error.status === 429) countdown(error.retryAfter);
        } finally {
            unlockSubmit(otpForm);
            otpSubmit.textContent = 'Verify';
        }
    });

    otpResend.addEventListener('click', async () => {
        if (!session || resendBusy || Date.now() < resendUntil) return;
        resendBusy = true;
        otpResend.disabled = true;
        try {
            await resendCode();
        } finally {
            resendBusy = false;
            otpResend.disabled = Date.now() < resendUntil;
        }
    });

    // Recover an application whose OTP was lost or whose modal was closed: the
    // email + password already on step 1 prove ownership, then a fresh code is
    // requested because the old registration's signed links may have expired.
    resumeBtn?.addEventListener('click', async () => {
        if (session || finished) return;
        const email = String(field('email')?.value || '').trim();
        const password = String(field('password')?.value || '');
        resumeStatus.textContent = '';
        if (!email || !password) {
            resumeStatus.textContent = 'Enter the email and password you registered with.';
            setStep(1);
            (email ? field('password') : field('email'))?.focus();

            return;
        }
        resumeBtn.disabled = true;
        resumeBtn.textContent = 'Checking...';
        try {
            const payload = await post(resumeUrl, { email, password });
            setMessage('');
            enterOtpSession(payload.data);
            await resendCode();
        } catch (error) {
            resumeStatus.textContent = error.message;
        } finally {
            resumeBtn.disabled = false;
            resumeBtn.textContent = 'Resend code';
        }
    });

    setStep(1, false);
    window.__zfReg?.initPasswordMeter();
    window.__zfReg?.initPasswordToggles();
    if (new URLSearchParams(window.location.search).get('open') === 'rider') open();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initRiderRegistration);
else initRiderRegistration();