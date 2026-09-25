// resources/js/logistics/logistics-register-modal.js
// Behavior for the 4-step logistics registration modal
// (resources/views/Components/logistics-register-modal.blade.php).
//
// The submit + OTP engine is copied from resources/js/buyer/buyer-register-modal.js
// (the only modal JS in this repo that actually wires those handlers — the seller
// one has none). Every element id is prefixed `logistics` on purpose: the buyer and
// seller modals share generic ids on the register-type page, so a third modal using
// getElementById('birthday') would bind to the first modal's node.

document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('logisticsModalOverlay');
    if (!overlay) return; // component not included on this page

    if (overlay.dataset.jsInit === '1') {
        console.warn('[logistics-register-modal] duplicate modal found on this page — only the first instance is wired up.');
        return;
    }
    overlay.dataset.jsInit = '1';

    var closeBtn = document.getElementById('logisticsModalClose');
    var form = document.getElementById('logisticsRegisterForm');
    var panels = Array.prototype.slice.call(document.querySelectorAll('.logistics-panel'));

    var stepIcons = { 1: 'user-round', 2: 'map-pin', 3: 'building-2', 4: 'mail-check' };
    var headerIcon = document.getElementById('logisticsModalIcon');

    // Panel 1
    var birthdayInput = document.getElementById('logisticsBirthday');
    var ageInput = document.getElementById('logisticsAge');
    var birthMonthSelect = document.getElementById('logisticsBirthMonth');
    var birthDaySelect = document.getElementById('logisticsBirthDay');
    var birthYearSelect = document.getElementById('logisticsBirthYear');
    var passwordInput = document.getElementById('logisticsPassword');
    var confirmInput = document.getElementById('logisticsPasswordConfirmation');
    var nextStep1 = document.getElementById('logisticsNextStep1');

    // Panel 2
    var provinceSelect = document.getElementById('logisticsProvince');
    var municipalitySelect = document.getElementById('logisticsMunicipality');
    var barangaySelect = document.getElementById('logisticsBarangay');
    var apiFields = document.getElementById('logisticsApiAddressFields');
    var manualFields = document.getElementById('logisticsManualAddressFields');
    var toggleManualBtn = document.getElementById('logisticsToggleManualAddress');
    var addressModeField = document.getElementById('logisticsAddressMode');
    var provinceNameField = document.getElementById('logisticsProvinceNameField');
    var municipalityNameField = document.getElementById('logisticsMunicipalityNameField');
    var barangayNameField = document.getElementById('logisticsBarangayNameField');
    var manualProvinceInput = document.getElementById('logisticsManualProvince');
    var manualMunicipalityInput = document.getElementById('logisticsManualMunicipality');
    var manualBarangayInput = document.getElementById('logisticsManualBarangay');
    var streetInput = document.getElementById('logisticsStreet');
    var backStep2 = document.getElementById('logisticsBackStep2');
    var nextStep2 = document.getElementById('logisticsNextStep2');

    // Panel 3
    var uploadIdInput = document.getElementById('logisticsUploadId');
    var uploadIdName = document.getElementById('logisticsUploadIdName');
    var dtiPermitInput = document.getElementById('logisticsDtiPermit');
    var dtiPermitName = document.getElementById('logisticsDtiPermitName');
    var backStep3 = document.getElementById('logisticsBackStep3');
    var submitRegistration = document.getElementById('submitLogisticsRegistration');
    var backStep4 = document.getElementById('logisticsBackStep4');

    var provincesLoaded = false;
    var manualMode = addressModeField && addressModeField.value === 'manual';

    // Bail out loudly instead of crashing silently mid-way through wiring.
    var required = {
        closeBtn: closeBtn, form: form, headerIcon: headerIcon,
        birthdayInput: birthdayInput, ageInput: ageInput,
        birthMonthSelect: birthMonthSelect, birthDaySelect: birthDaySelect, birthYearSelect: birthYearSelect,
        passwordInput: passwordInput, confirmInput: confirmInput, nextStep1: nextStep1,
        provinceSelect: provinceSelect, municipalitySelect: municipalitySelect, barangaySelect: barangaySelect,
        apiFields: apiFields, manualFields: manualFields, toggleManualBtn: toggleManualBtn, addressModeField: addressModeField,
        provinceNameField: provinceNameField, municipalityNameField: municipalityNameField, barangayNameField: barangayNameField,
        streetInput: streetInput, backStep2: backStep2, nextStep2: nextStep2,
        uploadIdInput: uploadIdInput, uploadIdName: uploadIdName,
        dtiPermitInput: dtiPermitInput, dtiPermitName: dtiPermitName,
        backStep3: backStep3, submitRegistration: submitRegistration, backStep4: backStep4,
    };
    var missing = Object.keys(required).filter(function (k) { return !required[k]; });
    if (missing.length) {
        console.error('[logistics-register-modal] missing element(s), aborting init:', missing.join(', '));
        return;
    }

    // ===== Stepper / panel switching =====
    function showPanel(n) {
        panels.forEach(function (p) { p.classList.toggle('hidden', parseInt(p.dataset.panel, 10) !== n); });
        document.querySelectorAll('#logisticsStepper [data-step-circle]').forEach(function (el) {
            var i = parseInt(el.dataset.stepCircle, 10);
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
        document.querySelectorAll('#logisticsStepper [data-step-line]').forEach(function (el) {
            var i = parseInt(el.dataset.stepLine, 10);
            el.classList.toggle('bg-[#9c5c68]', i < n);
            el.classList.toggle('bg-neutral-200', i >= n);
        });
        document.querySelectorAll('#logisticsStepper [data-step-label]').forEach(function (el) {
            var i = parseInt(el.dataset.stepLabel, 10);
            el.classList.toggle('text-neutral-900', i <= n);
            el.classList.toggle('text-neutral-400', i > n);
        });
        headerIcon.innerHTML = '<i data-lucide="' + (stepIcons[n] || 'truck') + '" width="20" height="20"></i>';
        if (window.lucide) lucide.createIcons();
    }

    // ===== Open / close =====
    window.openLogisticsRegisterModal = function (e) {
        if (e) e.preventDefault();
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (!provincesLoaded && !manualMode) loadProvinces();
    };

    function closeModal() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('[data-logistics-register-trigger]').forEach(function (el) {
        el.addEventListener('click', function (e) { window.openLogisticsRegisterModal(e); });
    });
    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
    });
    if (new URLSearchParams(window.location.search).get('open') === 'logistics') {
        window.openLogisticsRegisterModal();
    }

    // ===== Birthday (Month / Day / Year selects) + client-side age =====
    // The combined Y-m-d goes into hidden #logisticsBirthday; age is shown for
    // convenience only and is NEVER submitted (the server recomputes it).
    function calculateAge(birthDateStr) {
        var birthDate = new Date(birthDateStr);
        if (isNaN(birthDate.getTime())) return '';
        var today = new Date();
        var age = today.getFullYear() - birthDate.getFullYear();
        var monthDiff = today.getMonth() - birthDate.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) age--;
        return age >= 0 ? age : '';
    }

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

    function updateBirthdayValue() {
        var y = birthYearSelect.value;
        var m = birthMonthSelect.value;
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

    if (birthdayInput.value) {
        ageInput.value = calculateAge(birthdayInput.value);
    }

    // ===== PH address cascading dropdowns (PSGC API) =====
    var PSGC_BASE = 'https://psgc.gitlab.io/api';

    function populateSelect(select, items, placeholder) {
        select.innerHTML = '';
        var ph = document.createElement('option');
        ph.value = ''; ph.disabled = true; ph.selected = true; ph.textContent = placeholder;
        select.appendChild(ph);
        items.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = item.code; opt.textContent = item.name;
            select.appendChild(opt);
        });
    }

    function loadProvinces() {
        provinceSelect.innerHTML = '<option value="" disabled selected>Loading provinces...</option>';
        fetch(PSGC_BASE + '/provinces/')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                populateSelect(provinceSelect, data, 'Select province');
                provincesLoaded = true;
            })
            .catch(function () { provinceSelect.innerHTML = '<option value="" disabled selected>Could not load — try manual entry</option>'; });
    }

    provinceSelect.addEventListener('change', function () {
        provinceNameField.value = provinceSelect.selectedOptions[0] ? provinceSelect.selectedOptions[0].textContent : '';
        municipalityNameField.value = ''; barangayNameField.value = '';
        municipalitySelect.disabled = true; barangaySelect.disabled = true;
        municipalitySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';
        barangaySelect.innerHTML = '<option value="" disabled selected>Select municipality first</option>';
        fetch(PSGC_BASE + '/provinces/' + provinceSelect.value + '/cities-municipalities/')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                populateSelect(municipalitySelect, data, 'Select municipality/city');
                municipalitySelect.disabled = false;
            })
            .catch(function () { municipalitySelect.innerHTML = '<option value="" disabled selected>Could not load</option>'; });
    });

    municipalitySelect.addEventListener('change', function () {
        municipalityNameField.value = municipalitySelect.selectedOptions[0] ? municipalitySelect.selectedOptions[0].textContent : '';
        barangayNameField.value = '';
        barangaySelect.disabled = true;
        barangaySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';
        fetch(PSGC_BASE + '/cities-municipalities/' + municipalitySelect.value + '/barangays/')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                populateSelect(barangaySelect, data, 'Select barangay');
                barangaySelect.disabled = false;
            })
            .catch(function () { barangaySelect.innerHTML = '<option value="" disabled selected>Could not load</option>'; });
    });

    barangaySelect.addEventListener('change', function () {
        barangayNameField.value = barangaySelect.selectedOptions[0] ? barangaySelect.selectedOptions[0].textContent : '';
    });

    // ===== Manual address fallback =====
    function syncManualAddressFields() {
        if (manualProvinceInput) {
            manualProvinceInput.addEventListener('input', function () {
                if (manualMode) provinceNameField.value = this.value;
            });
        }
        if (manualMunicipalityInput) {
            manualMunicipalityInput.addEventListener('input', function () {
                if (manualMode) municipalityNameField.value = this.value;
            });
        }
        if (manualBarangayInput) {
            manualBarangayInput.addEventListener('input', function () {
                if (manualMode) barangayNameField.value = this.value;
            });
        }
    }
    syncManualAddressFields();

    function setAddressMode(isManual) {
        manualMode = isManual;
        apiFields.classList.toggle('hidden', manualMode);
        manualFields.classList.toggle('hidden', !manualMode);
        toggleManualBtn.textContent = manualMode ? 'Use address lookup instead' : 'Enter address manually';
        addressModeField.value = manualMode ? 'manual' : 'api';

        // Dropdowns required only in API mode; street is ALWAYS required.
        [provinceSelect, municipalitySelect, barangaySelect].forEach(function (el) {
            el.required = !manualMode;
        });

        if (manualMode) {
            if (manualProvinceInput) provinceNameField.value = manualProvinceInput.value;
            if (manualMunicipalityInput) municipalityNameField.value = manualMunicipalityInput.value;
            if (manualBarangayInput) barangayNameField.value = manualBarangayInput.value;
        } else {
            provinceNameField.value = provinceSelect.selectedOptions[0] ? provinceSelect.selectedOptions[0].textContent : '';
            municipalityNameField.value = municipalitySelect.selectedOptions[0] ? municipalitySelect.selectedOptions[0].textContent : '';
            barangayNameField.value = barangaySelect.selectedOptions[0] ? barangaySelect.selectedOptions[0].textContent : '';
            if (!provincesLoaded) loadProvinces();
        }
    }

    toggleManualBtn.addEventListener('click', function () { setAddressMode(!manualMode); });

    if (manualMode) {
        setAddressMode(true);
    }

    // ===== Upload filename display =====
    uploadIdInput.addEventListener('change', function () {
        uploadIdName.textContent = uploadIdInput.files.length ? uploadIdInput.files[0].name : 'Choose a valid ID';
    });
    dtiPermitInput.addEventListener('change', function () {
        dtiPermitName.textContent = dtiPermitInput.files.length ? dtiPermitInput.files[0].name : 'Choose a DTI permit';
    });

    // ===== Password visibility toggles =====
    document.querySelectorAll('#logisticsRegisterForm [data-toggle-visibility]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-toggle-visibility'));
            if (!target) return;
            var isHidden = target.type === 'password';
            target.type = isHidden ? 'text' : 'password';
            btn.innerHTML = '<i data-lucide="' + (isHidden ? 'eye-off' : 'eye') + '" width="16" height="16"></i>';
            if (window.lucide) lucide.createIcons();
        });
    });

    // ===== Validation helpers =====
    // Every field's error <span class="field-error"> is either the field's own next
    // sibling or the next sibling of its wrapping div (password show/hide buttons).
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
            var isFile = field.type === 'file';
            // File inputs are visually hidden on purpose, so they must not be
            // skipped by the off-screen check the other fields rely on.
            if (!isFile && field.offsetParent === null && field.type !== 'hidden') return;

            if (isFile ? field.files.length === 0 : !field.value) {
                showFieldError(field, 'This field is required.');
                valid = false;
            } else if (field.id === 'logisticsContactNo' && !/^09\d{9}$/.test(field.value)) {
                showFieldError(field, 'Enter a valid PH mobile number (09XXXXXXXXX).');
                valid = false;
            } else if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
                showFieldError(field, 'Enter a valid email address.');
                valid = false;
            }
        });

        if (n === 1) {
            if (!(birthMonthSelect.value && birthDaySelect.value && birthYearSelect.value)) {
                [birthMonthSelect, birthDaySelect, birthYearSelect].forEach(function (s) { s.classList.add('border-[#a5333d]'); });
                document.getElementById('logisticsBirthdayError').textContent = 'Select your full birthday.';
                valid = false;
            }
            if (passwordInput.value && passwordInput.value.length < 8) {
                showFieldError(passwordInput, 'Password must be at least 8 characters.');
                valid = false;
            }
            if (confirmInput.value && passwordInput.value !== confirmInput.value) {
                showFieldError(confirmInput, 'Passwords do not match.');
                valid = false;
            }
        } else if (n === 2 && !manualMode) {
            if (!provinceSelect.value) {
                showFieldError(provinceSelect, 'Select a province, or switch to manual address entry.');
                valid = false;
            }
            if (!municipalitySelect.value) {
                showFieldError(municipalitySelect, 'Select a municipality/city.');
                valid = false;
            }
            if (!barangaySelect.value) {
                showFieldError(barangaySelect, 'Select a barangay.');
                valid = false;
            }
        }

        return valid;
    }

    // ===== Navigation =====
    nextStep1.addEventListener('click', function () {
        if (validatePanel(1)) {
            showPanel(2);
            if (!provincesLoaded && !manualMode) loadProvinces();
        }
    });
    backStep2.addEventListener('click', function () { showPanel(1); });
    nextStep2.addEventListener('click', function () {
        if (validatePanel(2)) showPanel(3);
    });
    backStep3.addEventListener('click', function () { showPanel(2); });
    backStep4.addEventListener('click', function () { showPanel(3); });

    // ===== Panel 3 "Submit" — the actual registration submit (fires the OTP email) =====
    // CSRF: register-type renders Layouts/footer, which has no `csrf-token` meta tag,
    // so read the token from the form's @csrf hidden input (meta kept as fallback).
    var csrfTokenInput = form.querySelector('input[name="_token"]');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfTokenInput ? csrfTokenInput.value : (csrfMeta ? csrfMeta.getAttribute('content') : '');

    submitRegistration.addEventListener('click', function () {
        if (!validatePanel(3)) return;

        var submitBtn = this;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit';

            if (res.ok && res.body && res.body.logistics_provider_id) {
                document.getElementById('logisticsOtpId').value = res.body.logistics_provider_id;
                document.getElementById('logisticsOtpEmail').textContent = res.body.email;
                showPanel(4);
                setTimeout(function () {
                    var first = document.querySelector('.logistics-otp-input[data-otp-index="0"]');
                    if (first) first.focus();
                }, 50);
                return;
            }

            if (res.body && res.body.errors) {
                panels.forEach(function (p) { clearPanelErrors(p); });
                Object.keys(res.body.errors).forEach(function (name) {
                    var field = form.querySelector('[name="' + name + '"]');
                    if (field) showFieldError(field, res.body.errors[name][0]);
                    if (name === 'birthday') {
                        [birthMonthSelect, birthDaySelect, birthYearSelect].forEach(function (s) { s.classList.add('border-[#a5333d]'); });
                        document.getElementById('logisticsBirthdayError').textContent = res.body.errors[name][0];
                    }
                });

                // Jump to the earliest panel that actually has an error.
                var step1Fields = ['first_name', 'last_name', 'middle_initial', 'sex', 'email', 'password', 'contact_no', 'birthday'];
                var step2Fields = ['address_mode', 'province', 'province_name', 'municipality', 'municipality_name', 'barangay', 'barangay_name', 'street', 'house_number', 'address_detail'];
                var step3Fields = ['business_name', 'upload_id', 'dti_permit_upload'];
                var errKeys = Object.keys(res.body.errors);
                if (errKeys.some(function (k) { return step1Fields.indexOf(k) !== -1; })) {
                    showPanel(1);
                } else if (errKeys.some(function (k) { return step2Fields.indexOf(k) !== -1; })) {
                    showPanel(2);
                } else if (errKeys.some(function (k) { return step3Fields.indexOf(k) !== -1; })) {
                    showPanel(3);
                }
                return;
            }

            alert(res.body && res.body.message ? res.body.message : 'Registration failed. Please try again.');
        })
        .catch(function () {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit';
            alert('Network error. Please try again.');
        });
    });

    // ===== Panel 4: OTP =====
    var otpForm = document.getElementById('logisticsOtpForm');
    var logisticsIdInput = document.getElementById('logisticsOtpId');
    var otpErrorSummary = document.getElementById('logisticsOtpErrorSummary');
    var otpFieldError = document.getElementById('logisticsOtpFieldError');
    var hiddenOtp = document.getElementById('logisticsOtpHidden');
    var otpInputs = Array.prototype.slice.call(document.querySelectorAll('.logistics-otp-input'));
    var resendBtn = document.getElementById('logisticsOtpResendBtn');
    var resendStatus = document.getElementById('logisticsOtpResendStatus');
    var otpSubmitBtn = document.getElementById('logisticsOtpSubmit');

    if (otpForm && logisticsIdInput && otpErrorSummary && otpFieldError && hiddenOtp && resendBtn && resendStatus && otpSubmitBtn) {
        var collectOtp = function () { return otpInputs.map(function (i) { return i.value; }).join(''); };
        var clearOtpErrors = function () {
            otpErrorSummary.classList.add('hidden'); otpErrorSummary.textContent = '';
            otpFieldError.textContent = '';
            otpInputs.forEach(function (i) { i.classList.remove('border-[#a5333d]'); });
        };
        var showOtpError = function (msg) {
            otpErrorSummary.classList.remove('hidden'); otpErrorSummary.textContent = msg;
            otpFieldError.textContent = msg;
            otpInputs.forEach(function (i) { i.classList.add('border-[#a5333d]'); });
        };

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
            var id = logisticsIdInput.value;
            if (!id) return;
            resendBtn.disabled = true;
            resendStatus.textContent = 'Sending...';
            fetch('/register/logistics/verify-otp/' + id + '/resend', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                resendBtn.disabled = false;
                resendStatus.textContent = res.body.message || (res.ok ? 'Sent.' : 'Could not resend.');
                if (res.ok) {
                    otpInputs.forEach(function (i) { i.value = ''; });
                    hiddenOtp.value = '';
                    otpInputs[0].focus();
                }
            })
            .catch(function () { resendBtn.disabled = false; resendStatus.textContent = 'Network error.'; });
        });

        otpForm.addEventListener('submit', function (e) {
            e.preventDefault();
            clearOtpErrors();
            var id = logisticsIdInput.value;
            var code = collectOtp();
            if (code.length !== 6) { showOtpError('Enter all 6 digits.'); return; }
            hiddenOtp.value = code;

            otpSubmitBtn.disabled = true;
            otpSubmitBtn.textContent = 'Verifying...';

            fetch('/register/logistics/verify-otp/' + id, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ otp: code }),
            })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                otpSubmitBtn.disabled = false;
                otpSubmitBtn.textContent = 'Verify';
                if (res.ok) {
                    window.location.href = res.body.redirect || '/register/logistics/pending';
                } else {
                    showOtpError(res.body.message || 'Verification failed.');
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

    if (window.__logisticsModalHasServerErrors) {
        window.openLogisticsRegisterModal();
    }
});
