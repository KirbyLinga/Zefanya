{{-- resources/views/Components/seller-verify-otp-modal.blade.php
     Second modal in the seller registration flow. Shown after a successful
     POST to /register/seller. The user types the 6-digit code emailed to
     them; correct code -> markEmailVerified() -> redirect to pending page.

     Triggered programmatically by seller-register-modal.blade.php on a
     successful registration response — no separate "open" trigger needed.
--}}

<div class="buyer-modal-overlay" id="sellerOtpOverlay" aria-hidden="true">
    <div class="buyer-modal" role="dialog" aria-modal="true" aria-labelledby="sellerOtpTitle">

        <button type="button" class="buyer-modal__close" id="sellerOtpClose" aria-label="Close verification form">
            <i data-lucide="x" width="18" height="18"></i>
        </button>

        <div class="buyer-modal__header">
            <h2 class="buyer-modal__title" id="sellerOtpTitle">Verify your email</h2>
            <p class="buyer-modal__subtitle">
                We sent a 6-digit code to
                <strong id="sellerOtpEmail">your email</strong>.
                Enter it below to finish your registration.
            </p>
        </div>

        <div class="buyer-modal__error-summary" id="sellerOtpErrorSummary" hidden></div>

        <form class="buyer-modal__form" id="sellerOtpForm" novalidate>
            <input type="hidden" id="sellerOtpId" value="">

            <div class="buyer-modal__field">
                <label for="sellerOtpInput">Verification code *</label>
                <div class="buyer-otp-inputs" id="sellerOtpInputs">
                    <input type="text" inputmode="numeric" pattern="\d" maxlength="1" class="buyer-otp-input" data-otp-index="0" autocomplete="one-time-code">
                    <input type="text" inputmode="numeric" pattern="\d" maxlength="1" class="buyer-otp-input" data-otp-index="1">
                    <input type="text" inputmode="numeric" pattern="\d" maxlength="1" class="buyer-otp-input" data-otp-index="2">
                    <input type="text" inputmode="numeric" pattern="\d" maxlength="1" class="buyer-otp-input" data-otp-index="3">
                    <input type="text" inputmode="numeric" pattern="\d" maxlength="1" class="buyer-otp-input" data-otp-index="4">
                    <input type="text" inputmode="numeric" pattern="\d" maxlength="1" class="buyer-otp-input" data-otp-index="5">
                </div>
                <input type="hidden" name="otp" id="sellerOtpHidden" value="">
                <span class="buyer-modal__field-error" id="sellerOtpFieldError"></span>
            </div>

            <div class="buyer-modal__otp-resend">
                Didn't get a code?
                <button type="button" class="buyer-modal__otp-resend-btn" id="sellerOtpResendBtn">Resend code</button>
                <span class="buyer-modal__otp-resend-status" id="sellerOtpResendStatus" aria-live="polite"></span>
            </div>

            <button type="submit" class="buyer-modal__submit" id="sellerOtpSubmit">VERIFY</button>
        </form>

    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('sellerOtpOverlay');
    if (!overlay) return;

    var closeBtn = document.getElementById('sellerOtpClose');
    var form = document.getElementById('sellerOtpForm');
    var sellerIdInput = document.getElementById('sellerOtpId');
    var emailEl = document.getElementById('sellerOtpEmail');
    var errorSummary = document.getElementById('sellerOtpErrorSummary');
    var fieldError = document.getElementById('sellerOtpFieldError');
    var hiddenOtp = document.getElementById('sellerOtpHidden');
    var inputs = Array.prototype.slice.call(form.querySelectorAll('.buyer-otp-input'));
    var resendBtn = document.getElementById('sellerOtpResendBtn');
    var resendStatus = document.getElementById('sellerOtpResendStatus');
    var submitBtn = document.getElementById('sellerOtpSubmit');

    var csrf = document.querySelector('meta[name="csrf-token"]');
    csrf = csrf ? csrf.getAttribute('content') : '';

    function openOtpModal(sellerId, email) {
        sellerIdInput.value = sellerId;
        emailEl.textContent = email;
        clearErrors();
        resetInputs();
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        setTimeout(function () { inputs[0].focus(); }, 50);
    }

    function closeOtpModal() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function resetInputs() {
        inputs.forEach(function (i) { i.value = ''; i.classList.remove('has-error'); });
        hiddenOtp.value = '';
    }

    function clearErrors() {
        errorSummary.hidden = true;
        errorSummary.textContent = '';
        fieldError.textContent = '';
        inputs.forEach(function (i) { i.classList.remove('has-error'); });
    }

    function showError(msg) {
        errorSummary.hidden = false;
        errorSummary.textContent = msg;
        fieldError.textContent = msg;

    inputs.forEach(function (input, idx) {
        input.addEventListener('input', function () {
            var v = input.value.replace(/\D/g, '');
            input.value = v.length > 0 ? v[v.length - 1] : '';
            if (input.value && idx < inputs.length - 1) {
                inputs[idx + 1].focus();
            }
            hiddenOtp.value = collectOtp();
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !input.value && idx > 0) {
                inputs[idx - 1].focus();
            }
            if (e.key === 'ArrowLeft' && idx > 0) inputs[idx - 1].focus();
            if (e.key === 'ArrowRight' && idx < inputs.length - 1) inputs[idx + 1].focus();
        });

        input.addEventListener('paste', function (e) {
            var pasted = (e.clipboardData || window.clipboardData).getData('text') || '';
            pasted = pasted.replace(/\D/g, '').slice(0, 6);
            if (pasted.length === 0) return;
            e.preventDefault();
            for (var i = 0; i < 6; i++) {
                inputs[i].value = pasted[i] || '';
            }
            hiddenOtp.value = collectOtp();
            var lastFilled = Math.min(pasted.length, 6) - 1;
            inputs[lastFilled < 5 ? lastFilled + 1 : 5].focus();

    resendBtn.addEventListener('click', function () {
        var id = sellerIdInput.value;
        if (!id) return;
        resendBtn.disabled = true;
        resendStatus.textContent = 'Sending...';
        fetch('/register/seller/verify-otp/' + id + '/resend', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
            resendBtn.disabled = false;
            if (res.ok) {
                resendStatus.textContent = res.body.message || 'Sent.';
                resetInputs();
                inputs[0].focus();
            } else {
                resendStatus.textContent = res.body.message || 'Could not resend.';
            }
        })
        .catch(function () {
            resendBtn.disabled = false;
            resendStatus.textContent = 'Network error.';
        });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearErrors();

        var id = sellerIdInput.value;
        var code = collectOtp();
        if (code.length !== 6) {
            showError('Enter all 6 digits.');
            return;
        }
        hiddenOtp.value = code;

        submitBtn.disabled = true;
        submitBtn.textContent = 'VERIFYING...';

        fetch('/register/seller/verify-otp/' + id, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ otp: code }),
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'VERIFY';
            if (res.ok) {
                window.location.href = res.body.redirect || '/register/seller/pending';
            } else {
                showError(res.body.message || 'Verification failed.');
            }
        })
        .catch(function () {
            submitBtn.disabled = false;
            submitBtn.textContent = 'VERIFY';
            showError('Network error. Please try again.');
        });
    });

    window.openSellerOtpModal = openOtpModal;
})();
</script>
        });
    });

    closeBtn.addEventListener('click', closeOtpModal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeOtpModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeOtpModal();
    });
        inputs.forEach(function (i) { i.classList.add('has-error'); });
    }

    function collectOtp() {
        return inputs.map(function (i) { return i.value; }).join('');
    }