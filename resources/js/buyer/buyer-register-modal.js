import './address-lookup.js';
// resources/js/buyer/buyer-register-modal.js
// Behavior for the 4-step buyer registration modal
// (resources/views/Components/buyer-register-modal.blade.php).
// Pulled out of the blade file to match the buyer.js / home.js convention:
// all page/component behavior lives in resources/js, not inline <script>.

import '../registration-validation.js';

document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('buyerModalOverlay');
    if (!overlay) return; // component not included on this page

    // Guard against the component being @include'd more than once on the
    // same page (shared layout + a page that also includes it directly):
    // without this, getElementById would silently bind to whichever
    // duplicate happens to be first in the DOM, and every button — Next
    // included — would look dead because the visible modal has no listeners.
    if (overlay.dataset.jsInit === '1') {
        console.warn('[buyer-register-modal] duplicate modal found on this page — only the first instance is wired up. Remove the duplicate @include.');
        return;
    }
    overlay.dataset.jsInit = '1';

    var closeBtn = document.getElementById('buyerModalClose');
    var form = document.getElementById('buyerRegisterForm');
    var panels = Array.prototype.slice.call(document.querySelectorAll('.buyer-panel'));

    var stepIcons = { 1: 'shopping-bag', 2: 'map-pin', 3: 'shield-check', 4: 'mail-check' };
    var headerIcon = document.getElementById('buyerModalIcon');

    var birthdayInput = document.getElementById('birthday');
    var ageInput = document.getElementById('age');
    var birthMonthSelect = document.getElementById('birthMonth');
    var birthDaySelect = document.getElementById('birthDay');
    var birthYearSelect = document.getElementById('birthYear');

    var provinceSelect = document.getElementById('province');
    var municipalitySelect = document.getElementById('municipality');
    var barangaySelect = document.getElementById('barangay');
    var addressModeField = document.getElementById('addressMode');
    var provinceNameField = document.getElementById('provinceNameField');
    var municipalityNameField = document.getElementById('municipalityNameField');
    var barangayNameField = document.getElementById('barangayNameField');

    var uploadIdInput = document.getElementById('uploadId');
    var uploadIdName = document.getElementById('uploadIdName');

    var passwordInput = document.getElementById('password');
    var confirmInput = document.getElementById('passwordConfirmation');

    var nextStep1 = document.getElementById('nextStep1');
    var backStep2 = document.getElementById('backStep2');
    var nextStep2 = document.getElementById('nextStep2');
    var backStep3 = document.getElementById('backStep3');
    var submitRegistration = document.getElementById('submitRegistration');
    var backStep4 = document.getElementById('backStep4');

    // Bail out loudly instead of crashing silently mid-way through wiring —
    // but only for truly essential elements. Make others optional to avoid
    // silent failures when a single element is mildly different.
    var essentialElements = {
        overlay: overlay, closeBtn: closeBtn, form: form, headerIcon: headerIcon,
        birthdayInput: birthdayInput, ageInput: ageInput, passwordInput: passwordInput,
        confirmInput: confirmInput, nextStep1: nextStep1, nextStep2: nextStep2,
        submitRegistration: submitRegistration, uploadIdInput: uploadIdInput,
    };
    var missing = Object.keys(essentialElements).filter(function (k) { return !essentialElements[k]; });
    if (missing.length) {
        console.error('[buyer-register-modal] missing essential element(s), aborting init:', missing.join(', '));
        return;
    }

    // ===== Stepper / panel switching =====
    function showPanel(n) {
        panels.forEach(function (p) { p.classList.toggle('hidden', parseInt(p.dataset.panel, 10) !== n); });
        document.querySelectorAll('[data-step-circle]').forEach(function (el) {
            var i = parseInt(el.dataset.stepCircle, 10);
            // Always strip BOTH the idle static classes and the active/completed
            // classes first. Leaving e.g. bg-white on an element that also gets
            // bg-[#9c5c68] added creates two same-specificity bg-* rules, and
            // whichever Tailwind happens to output later in the stylesheet wins
            // the tie — which is exactly why the fill was staying white before.
            el.classList.remove('bg-[#9c5c68]', 'border-[#9c5c68]', 'text-white', 'bg-white', 'border-neutral-200', 'text-neutral-400');
            el.innerHTML = i;
            if (i < n) {
                el.classList.add('bg-[#9c5c68]', 'border-[#9c5c68]', 'text-white');
                el.innerHTML = '<i data-lucide="check" width="14" height="14"></i>';
            } else if (i === n) {
                el.classList.add('bg-[#9c5c68]', 'border-[#9c5c68]', 'text-white');
            } else {
                el.classList.add('bg-white', 'border-neutral-200', 'text-neutral-400');
            }
        });
        document.querySelectorAll('[data-step-line]').forEach(function (el) {
            var i = parseInt(el.dataset.stepLine, 10);
            el.classList.toggle('bg-[#9c5c68]', i < n);
            el.classList.toggle('bg-neutral-200', i >= n);
        });
        document.querySelectorAll('[data-step-label]').forEach(function (el) {
            var i = parseInt(el.dataset.stepLabel, 10);
            el.classList.toggle('text-neutral-900', i <= n);
            el.classList.toggle('text-neutral-400', i > n);
        });
        headerIcon.innerHTML = '<i data-lucide="' + stepIcons[n] + '" width="20" height="20"></i>';
        if (window.lucide) lucide.createIcons();
    }

    var addressLookup = window.zfAddressLookup
        ? window.zfAddressLookup.init(document.getElementById('buyerModalOverlay') || document)
        : null;

    // ===== Open / close =====
    window.openBuyerRegisterModal = function (e) {
        if (e) e.preventDefault();
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (addressLookup) addressLookup.ensureLoaded();
    };

    function closeModal() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('[data-buyer-register-trigger]').forEach(function (el) {
        el.addEventListener('click', function (e) { window.openBuyerRegisterModal(e); });
    });
    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
    });
    if (new URLSearchParams(window.location.search).get('open') === 'buyer') {
        window.openBuyerRegisterModal();
    }

    // ===== Birthday (Month / Day / Year selects) + age auto-generation =====
    // Three plain dropdowns instead of <input type="date"> — native date
    // pickers make picking an old birth year (1990s and earlier) painful,
    // usually one month-click at a time. The three combine into the hidden
    // #birthday input as Y-m-d, so the backend contract
    // ('birthday' => required|date|before:today) is unchanged.
    function calculateAge(birthDateStr) {
        var birthDate = new Date(birthDateStr);
        if (isNaN(birthDate.getTime())) return '';
        var today = new Date();
        var age = today.getFullYear() - birthDate.getFullYear();
        var monthDiff = today.getMonth() - birthDate.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) age--;
        return age >= 0 ? age : '';
    }

    // Rebuild Day options whenever month/year changes so impossible dates
    // (Feb 30, Apr 31, Feb 29 in non-leap years) can't be picked; keeps the
    // previously chosen day when it is still valid for the new month.
    function rebuildDayOptions() {
        var year = parseInt(birthYearSelect.value, 10);
        var month = parseInt(birthMonthSelect.value, 10); // 1-12
        var total = 31;
        if (year && month) total = new Date(year, month, 0).getDate();
        var previous = birthDaySelect.value;
        while (birthDaySelect.options.length > 1) birthDaySelect.remove(1);
        for (var d = 1; d <= total; d++) {
            var opt = document.createElement('option');
            opt.value = d;
            opt.textContent = d;
            birthDaySelect.appendChild(opt);
        }
        if (previous && parseInt(previous, 10) <= total) birthDaySelect.value = previous;
    }

    // Compose the hidden #birthday value + auto-fill Age only when all three
    // parts are chosen; clears both while the date is still incomplete.
    function updateBirthdayValue() {
        var y = birthYearSelect.value;
        var m = birthMonthSelect.value; // already zero-padded ('01'..'12')
        var d = birthDaySelect.value;
        if (y && m && d) {
            birthdayInput.value = y + '-' + m + '-' + ('0' + d).slice(-2);
            ageInput.value = calculateAge(birthdayInput.value);
        } else {
            birthdayInput.value = '';
            ageInput.value = '';
        }
    }

    [birthMonthSelect, birthYearSelect].forEach(function (el) {
        el.addEventListener('change', function () { rebuildDayOptions(); updateBirthdayValue(); });
    });
    birthDaySelect.addEventListener('change', updateBirthdayValue);

    // Initial birthday calculation if repopulating from old() values
    if (birthdayInput.value) {
        ageInput.value = calculateAge(birthdayInput.value);
    }

    // ===== PH address cascading dropdowns (PSGC API) =====
    // Shared logic lives in resources/js/buyer/address-lookup.js (also used by
    // the account address page) — the modal just hosts the DOM contract.

    var MAX_ID_BYTES = 5 * 1024 * 1024;
    var ALLOWED_ID_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    function idFileError(file) {
        if (!file) return 'Please upload a file.';
        if (file.size > MAX_ID_BYTES) return 'Upload ID must be smaller than 5MB.';
        if (file.type && ALLOWED_ID_TYPES.indexOf(file.type) === -1) {
            return 'Upload ID must be a JPG, PNG, or PDF.';
        }
        return '';
    }

    uploadIdInput.addEventListener('change', function () {
        clearFieldError(uploadIdInput);
        if (!uploadIdInput.files.length) {
            uploadIdName.textContent = 'Choose a valid ID';
            return;
        }
        var file = uploadIdInput.files[0];
        var error = idFileError(file);
        if (error) {
            uploadIdInput.value = '';
            uploadIdName.textContent = 'Choose a valid ID';
            showFieldError(uploadIdInput, error);
            return;
        }
        uploadIdName.textContent = file.name;
    });

    // ===== Contact number: live validation + numeric input =====
    var contactNoInput = document.getElementById('contactNo');
    if (contactNoInput) {
        contactNoInput.addEventListener('input', function () {
            var val = this.value;
            if (/\D/.test(val) && !/^\+?\d*$/.test(val)) {
                showFieldError(this, 'Enter a valid mobile number (09XXXXXXXXX or +639XXXXXXXXX).');
                return;
            }
            var digits = val.replace(/\D/g, '');
            if (digits.length === 0) {
                clearFieldError(this);
                return;
            }
            if (digits.length >= 2 && !digits.startsWith('09')) {
                showFieldError(this, 'Enter a valid mobile number (09XXXXXXXXX or +639XXXXXXXXX).');
                return;
            }
            if (digits.length === 10 || digits.length > 11) {
                showFieldError(this, 'Mobile number must be 11 digits, like 09171234567.');
                return;
            }
            if (digits.length === 11) {
                clearFieldError(this);
                return;
            }
            clearFieldError(this);
        });
        contactNoInput.addEventListener('blur', function () {
            if (!this.value) {
                showFieldError(this, 'This field is required.');
            } else if (!/^09\d{9}$/.test(this.value)) {
                var msg = (this.value.length === 10 || this.value.length > 11)
                    ? 'Mobile number must be 11 digits, like 09171234567.'
                    : 'Enter a valid PH mobile number (09XXXXXXXXX).';
                showFieldError(this, msg);
            } else {
                clearFieldError(this);
            }
        });
    }

    // Password visibility is handled once by registration-validation.js.

    // ===== Validation helpers =====
    // Every field's error <span class="field-error"> is either the field's own
    // next sibling, or (for fields wrapped in a relative div, e.g. the password
    // show/hide button) the next sibling of that wrapping div. Matching it this
    // way — instead of searching the whole row or panel — guarantees we hit the
    // one span that actually belongs to this field, never a neighbor's.
    function findFieldError(field) {
        var sib = field.nextElementSibling;
        if (sib && sib.classList && sib.classList.contains('field-error')) return sib;
        var parentSib = field.parentElement && field.parentElement.nextElementSibling;
        if (parentSib && parentSib.classList && parentSib.classList.contains('field-error')) return parentSib;
        return null;
    }
    function showFieldError(field, message) {
        field.classList.add('border-[#a5333d]');
        var errorEl = findFieldError(field);
        if (errorEl) errorEl.textContent = message;
    }
    function clearFieldError(field) {
        field.classList.remove('border-[#a5333d]');
        var errorEl = findFieldError(field);
        if (errorEl) errorEl.textContent = '';
    }
    // Clears every error span + red border in a panel before re-validating it,
    // so a message from a previous attempt never lingers next to a field that
    // has since become valid.
    function clearPanelErrors(panel) {
        panel.querySelectorAll('.field-error').forEach(function (el) { el.textContent = ''; });
        panel.querySelectorAll('input, select, textarea').forEach(function (el) { el.classList.remove('border-[#a5333d]'); });
    }
    document.querySelectorAll('.field-error').forEach(function (el) { el.textContent = ''; });

    function validatePanel(n) {
        var panel = panels.filter(function (p) { return parseInt(p.dataset.panel, 10) === n; })[0];
        if (!panel) return true;
        clearPanelErrors(panel);
        var valid = true;
        panel.querySelectorAll('[required]').forEach(function (field) {
            // Hidden file inputs (class="hidden") have offsetParent === null;
            // still validate them — otherwise step 3 always looks "incomplete".
            if (field.offsetParent === null && field.type !== 'hidden' && field.type !== 'file') return;
            if (field.type === 'file') {
                var file = field.files && field.files[0];
                var fileError = idFileError(file);
                if (fileError) {
                    showFieldError(field, fileError);
                    valid = false;
                }
            } else if (field.type === 'checkbox') {
                if (!field.checked) {
                    showFieldError(field, 'You must accept the Terms and Privacy Policy.');
                    valid = false;
                }
            } else if (!field.value) {
                showFieldError(field, 'This field is required.');
                valid = false;
            } else if (field.id === 'contactNo' && !/^09\d{9}$/.test(field.value)) {
                var contactError = (field.value.length === 10 || field.value.length > 11)
                    ? 'Mobile number must be 11 digits, like 09171234567.'
                    : 'Enter a valid PH mobile number (09XXXXXXXXX).';
                showFieldError(field, contactError);
                valid = false;
            } else if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
                showFieldError(field, 'Enter a valid email address.');
                valid = false;
            }
        });
        if (n === 1) {
            // Birthday selects: all three parts must be chosen (the hidden
            // #birthday input carries the combined Y-m-d for the backend).
            if (!(birthMonthSelect.value && birthDaySelect.value && birthYearSelect.value)) {
                [birthMonthSelect, birthDaySelect, birthYearSelect].forEach(function (s) { s.classList.add('border-[#a5333d]'); });
                var birthdayError = findFieldError(birthdayInput);
                if (birthdayError) birthdayError.textContent = 'Select your full birthday.';
                valid = false;
            }
            // Password validation: minimum 8 characters (allow weak passwords with warning)
            if (passwordInput.value) {
                if (passwordInput.value.length < 8) {
                    showFieldError(passwordInput, 'Password must be at least 8 characters.');
                    valid = false;
                }
                // If >= 8 chars, password is valid even if weak
            }
            if (confirmInput.value && passwordInput.value !== confirmInput.value) {
                showFieldError(confirmInput, 'Passwords do not match.');
                valid = false;
            }
        }
        return valid;
    }

    nextStep1.addEventListener('click', function (e) { 
        if (!validatePanel(1)) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
                if (e.stopImmediatePropagation) e.stopImmediatePropagation();
            }
            return;
        }
        showPanel(2);
    });
    backStep2.addEventListener('click', function () { showPanel(1); });
    nextStep2.addEventListener('click', function () { if (validatePanel(2)) showPanel(3); });
    backStep3.addEventListener('click', function () { showPanel(2); });
    backStep4.addEventListener('click', function () { showPanel(3); });

    // ===== Step 3 "Next" = actual registration submit (fires the OTP email) =====
    var modalEl = overlay.querySelector('.buyer-modal');

    function setSubmitting(isBusy) {
        submitRegistration.disabled = isBusy;
        submitRegistration.textContent = isBusy ? 'Uploading...' : 'Next';
        if (modalEl) modalEl.classList.toggle('is-busy', isBusy);
        overlay.setAttribute('aria-busy', isBusy ? 'true' : 'false');
    }

    submitRegistration.addEventListener('click', function () {
        if (!validatePanel(3)) {
            var panel = panels.filter(function (p) { return parseInt(p.dataset.panel, 10) === 3; })[0];
            if (panel) {
                var errorDiv = panel.querySelector('[data-step3-error]');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.setAttribute('data-step3-error', 'true');
                    errorDiv.className = 'mb-4 rounded-sm border border-[#e8a49c] bg-[#fbe9e7] px-4 py-3 font-sans text-[12.5px] text-[#a5333d]';
                    panel.insertBefore(errorDiv, submitRegistration.parentElement);
                }
                errorDiv.textContent = 'Please complete all required fields.';
            }
            return;
        }

        setSubmitting(true);

        // Let the busy overlay paint before FormData + fetch block the UI.
        window.requestAnimationFrame(function () {
            var formData = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin',
            })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                if (res.ok && res.body && res.body.buyer_id) {
                    setSubmitting(false);
                    var otpIdInput = document.getElementById('buyerOtpId');
                    otpIdInput.value = res.body.buyer_id;
                    // Signed verify/resend URLs come from the server (see
                    // SignedOtpLink); the id alone would be rejected.
                    otpIdInput.dataset.verifyUrl = res.body.verify_url || '';
                    otpIdInput.dataset.resendUrl = res.body.resend_url || '';
                    document.getElementById('buyerOtpEmail').textContent = res.body.email_masked || res.body.email;
                    showPanel(4);
                    setTimeout(function () {
                        var first = document.querySelector('.buyer-otp-input[data-otp-index="0"]');
                        if (first) first.focus();
                    }, 50);
                    return;
                }

                setSubmitting(false);
                if (res.body && res.body.errors) {
                    panels.forEach(function (p) { clearPanelErrors(p); });
                    Object.keys(res.body.errors).forEach(function (name) {
                        var field = form.querySelector('[name="' + name + '"]');
                        if (field) showFieldError(field, res.body.errors[name][0]);
                        if (name === 'birthday') {
                            [birthMonthSelect, birthDaySelect, birthYearSelect].forEach(function (s) { s.classList.add('border-[#a5333d]'); });
                        }
                    });
                    var step1Fields = ['first_name', 'last_name', 'sex', 'email', 'password', 'contact_no', 'birthday'];
                    var step2Fields = ['province', 'municipality', 'barangay', 'street', 'address_mode', 'province_name', 'municipality_name', 'barangay_name'];
                    var step3Fields = ['upload_id', 'terms'];
                    var errKeys = Object.keys(res.body.errors);
                    if (errKeys.some(function (k) { return step1Fields.indexOf(k) !== -1; })) showPanel(1);
                    else if (errKeys.some(function (k) { return step2Fields.indexOf(k) !== -1; })) showPanel(2);
                    else if (errKeys.some(function (k) { return step3Fields.indexOf(k) !== -1; })) showPanel(3);
                } else {
                    alert(res.body && res.body.message ? res.body.message : 'Registration failed. Please try again.');
                }
            })
            .catch(function () {
                setSubmitting(false);
                alert('Network error. Please try again.');
            });
        });
    });

    // ===== Panel 4: OTP =====
    var otpForm = document.getElementById('buyerOtpForm');
    var buyerIdInput = document.getElementById('buyerOtpId');
    var otpErrorSummary = document.getElementById('buyerOtpErrorSummary');
    var otpFieldError = document.getElementById('buyerOtpFieldError');
    var hiddenOtp = document.getElementById('buyerOtpHidden');
    var otpInputs = Array.prototype.slice.call(document.querySelectorAll('.buyer-otp-input'));
    var resendBtn = document.getElementById('buyerOtpResendBtn');
    var resendStatus = document.getElementById('buyerOtpResendStatus');
    var otpSubmitBtn = document.getElementById('buyerOtpSubmit');
    var csrf = document.querySelector('meta[name="csrf-token"]');
    csrf = csrf ? csrf.getAttribute('content') : '';

    if (otpForm && buyerIdInput && otpErrorSummary && otpFieldError && hiddenOtp && resendBtn && resendStatus && otpSubmitBtn) {
        function collectOtp() { return otpInputs.map(function (i) { return i.value; }).join(''); }
        function clearOtpErrors() {
            otpErrorSummary.classList.add('hidden'); otpErrorSummary.textContent = '';
            otpFieldError.textContent = '';
            otpInputs.forEach(function (i) { i.classList.remove('border-[#a5333d]'); });
        }
        function showOtpError(msg) {
            otpErrorSummary.classList.remove('hidden'); otpErrorSummary.textContent = msg;
            otpFieldError.textContent = msg;
            otpInputs.forEach(function (i) { i.classList.add('border-[#a5333d]'); });
        }
        // Human "try again in N" text for a rate-limit / lock response.
        function otpWaitText(seconds) {
            seconds = parseInt(seconds, 10);
            if (!seconds || seconds <= 0) return '';
            if (seconds < 60) return 'Try again in ' + seconds + ' second' + (seconds === 1 ? '' : 's') + '.';
            var mins = Math.ceil(seconds / 60);
            return 'Try again in ' + mins + ' minute' + (mins === 1 ? '' : 's') + '.';
        }
        // Prefer the server's own message (429 lock / 503 mail failure) and add
        // the retry time when the server sends one and the message omits it.
        function otpServerError(res, fallback) {
            var body = (res && res.body) ? res.body : {};
            var msg = body.message || fallback;
            if (res && res.status === 429 && body.retry_after && !/\d+\s*(second|minute)/i.test(msg)) {
                msg += ' ' + otpWaitText(body.retry_after);
            }
            return msg;
        }

        otpInputs.forEach(function (input, idx) {
            input.addEventListener('input', function () {
                var v = input.value.replace(/\D/g, '');
                input.value = v.length > 0 ? v[v.length - 1] : '';
                if (input.value && idx < otpInputs.length - 1) otpInputs[idx + 1].focus();
                hiddenOtp.value = collectOtp();
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !input.value && idx > 0) otpInputs[idx - 1].focus();
                if (e.key === 'ArrowLeft' && idx > 0) otpInputs[idx - 1].focus();
                if (e.key === 'ArrowRight' && idx < otpInputs.length - 1) otpInputs[idx + 1].focus();
            });
            input.addEventListener('paste', function (e) {
                var pasted = ((e.clipboardData || window.clipboardData).getData('text') || '').replace(/\D/g, '').slice(0, 6);
                if (!pasted.length) return;
                e.preventDefault();
                for (var i = 0; i < 6; i++) otpInputs[i].value = pasted[i] || '';
                hiddenOtp.value = collectOtp();
                var lastFilled = Math.min(pasted.length, 6) - 1;
                otpInputs[lastFilled < 5 ? lastFilled + 1 : 5].focus();
            });
        });

        resendBtn.addEventListener('click', function () {
            var resendUrl = buyerIdInput.dataset.resendUrl || '';
            if (!resendUrl) {
                resendStatus.textContent = 'Your verification session expired. Please reload the page.';
                return;
            }
            resendBtn.disabled = true;
            resendStatus.textContent = 'Sending...';
            fetch(resendUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); })
            .then(function (res) {
                if (res.ok) {
                    otpInputs.forEach(function (i) { i.value = ''; });
                    hiddenOtp.value = '';
                    otpInputs[0].focus();
                    
                    // Start countdown timer (60 seconds)
                    var secondsLeft = 60;
                    var timerInterval = setInterval(function () {
                        secondsLeft--;
                        resendStatus.textContent = secondsLeft + 's';
                        
                        if (secondsLeft <= 0) {
                            clearInterval(timerInterval);
                            resendBtn.disabled = false;
                            resendStatus.textContent = '';
                        }
                    }, 1000);
                    
                    resendStatus.textContent = '60s';
                } else {
                    resendBtn.disabled = false;
                    resendStatus.textContent = otpServerError(res, 'Could not resend.');
                }
            })
            .catch(function () { resendBtn.disabled = false; resendStatus.textContent = 'Network error.'; });
        });

        otpForm.addEventListener('submit', function (e) {
            e.preventDefault();
            clearOtpErrors();
            var verifyUrl = buyerIdInput.dataset.verifyUrl || '';
            var code = collectOtp();
            if (code.length !== 6) { showOtpError('Enter all 6 digits.'); return; }
            if (!verifyUrl) { showOtpError('Your verification session expired. Please reload the page.'); return; }
            hiddenOtp.value = code;

            otpSubmitBtn.disabled = true;
            otpSubmitBtn.textContent = 'Verifying...';

            fetch(verifyUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ otp: code }),
            })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); })
            .then(function (res) {
                otpSubmitBtn.disabled = false;
                otpSubmitBtn.textContent = 'Verify';
                if (res.ok) {
                    window.location.href = res.body.redirect || '/register/buyer/pending';
                } else {
                    showOtpError(otpServerError(res, 'Verification failed.'));
                }
            })
            .catch(function () {
                otpSubmitBtn.disabled = false;
                otpSubmitBtn.textContent = 'Verify';
                showOtpError('Network error. Please try again.');
            });
        });
    }

    showPanel(1);

    // ===== Password strength meter + visibility helpers =====
    // The shared registration-validation.js exposes these on window.__zfReg.
    // Initialise the meter now, after the modal DOM has been rendered, and
    // reset it on every showPanel() call so pre-filled values are reflected.
    function initBuyerPasswordMeter() {
        if (window.__zfReg) {
            window.__zfReg.initPasswordMeter();
        }
    }
    if (window.__zfReg) {
        initBuyerPasswordMeter();
    }
    if (window.__zfReg && window.__zfReg.initPasswordToggles) {
        window.__zfReg.initPasswordToggles();
    }

    if (window.__buyerModalHasServerErrors) {
        window.openBuyerRegisterModal();
    }
});