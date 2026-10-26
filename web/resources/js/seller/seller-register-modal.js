// resources/js/seller/seller-register-modal.js
// Behavior for the seller registration modal (Components/seller-register-modal.blade.php).
// Mirrors resources/js/buyer/buyer-register-modal.js.

import '../registration-validation.js';

document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('sellerModalOverlay');
    if (!overlay) return;
    // Initialize before a direct-link auto-open can request provinces.
    var PSGC_BASE = (overlay.dataset.psgcBase || 'https://psgc.gitlab.io/api').replace(/\/+$/, '');


    if (overlay.dataset.jsInit === '1') {
        console.warn('[seller-register-modal] duplicate modal found on this page — only the first instance is wired up.');
        return;
    }
    overlay.dataset.jsInit = '1';

    var closeBtn = document.getElementById('sellerModalClose');
    var form = document.getElementById('sellerRegisterForm');
    var panels = Array.prototype.slice.call(document.querySelectorAll('.seller-panel'));

    var stepIcons = { 1: 'store', 2: 'map-pin', 3: 'briefcase', 4: 'mail-check' };
    var headerIcon = document.getElementById('sellerModalIcon');

    // Panel 1 elements
    var birthdayInput = document.getElementById('sellerBirthday');
    var ageInput = document.getElementById('sellerAge');
    var birthMonthSelect = document.getElementById('sellerBirthMonth');
    var birthDaySelect = document.getElementById('sellerBirthDay');
    var birthYearSelect = document.getElementById('sellerBirthYear');

    var passwordInput = document.getElementById('sellerPassword');
    var confirmInput = document.getElementById('sellerPasswordConfirmation');
    var nextStep1 = document.getElementById('sellerNextStep1');

    // Panel 2 elements
    var provinceSelect = document.getElementById('sellerProvince');
    var municipalitySelect = document.getElementById('sellerMunicipality');
    var barangaySelect = document.getElementById('sellerBarangay');
    var apiFields = document.getElementById('sellerApiAddressFields');
    var addressModeField = document.getElementById('sellerAddressMode');
    var provinceNameField = document.getElementById('sellerProvinceNameField');
    var municipalityNameField = document.getElementById('sellerMunicipalityNameField');
    var barangayNameField = document.getElementById('sellerBarangayNameField');
    var streetTextarea = document.getElementById('sellerStreet');
    var addressError = document.getElementById('sellerAddressError');

    var backStep2 = document.getElementById('sellerBackStep2');
    var nextStep2 = document.getElementById('sellerNextStep2');
    var backStep3 = document.getElementById('sellerBackStep3');

    // Panel 3 file upload elements
    var uploadIdInput = document.getElementById('sellerUploadId');
    var uploadIdName = document.getElementById('sellerUploadIdName');
    var uploadIdWrapper = uploadIdInput ? uploadIdInput.previousElementSibling : null;
    
    var businessPermitInput = document.getElementById('sellerBusinessPermit');
    var businessPermitName = document.getElementById('sellerBusinessPermitName');
    var businessPermitWrapper = businessPermitInput ? businessPermitInput.previousElementSibling : null;

    var provincesLoaded = false;

    var requiredElements = {
        closeBtn: closeBtn, form: form, headerIcon: headerIcon,
        birthdayInput: birthdayInput, ageInput: ageInput,
        birthMonthSelect: birthMonthSelect, birthDaySelect: birthDaySelect, birthYearSelect: birthYearSelect,
        passwordInput: passwordInput, confirmInput: confirmInput, nextStep1: nextStep1,
        provinceSelect: provinceSelect, municipalitySelect: municipalitySelect, barangaySelect: barangaySelect,
        apiFields: apiFields, addressModeField: addressModeField, provinceNameField: provinceNameField,
        municipalityNameField: municipalityNameField, barangayNameField: barangayNameField,
        streetTextarea: streetTextarea, backStep2: backStep2, nextStep2: nextStep2,
    };

    var missing = Object.keys(requiredElements).filter(function (k) { return !requiredElements[k]; });
    if (missing.length) {
        console.error('[seller-register-modal] missing element(s), aborting init:', missing.join(', '));
        return;
    }

    // ===== File Upload Handlers =====
    if (uploadIdInput && uploadIdWrapper) {
        uploadIdWrapper.addEventListener('click', function () {
            uploadIdInput.click();
        });
        uploadIdInput.addEventListener('change', function () {
            if (this.files.length > 0) {
                uploadIdName.textContent = this.files[0].name;
            }
        });
    }

    // ===== Contact number: numeric input only =====
    var sellerContactNo = document.getElementById('sellerContactNo');
    if (sellerContactNo) {
        sellerContactNo.addEventListener('input', function () {
            // Remove any non-digit characters
            this.value = this.value.replace(/\D/g, '');
        });
        sellerContactNo.addEventListener('keypress', function (e) {
            // Prevent non-numeric characters from being typed
            if (!/\d/.test(String.fromCharCode(e.which))) {
                e.preventDefault();
            }
        });
    }

    if (businessPermitInput && businessPermitWrapper) {
        businessPermitWrapper.addEventListener('click', function () {
            businessPermitInput.click();
        });
        businessPermitInput.addEventListener('change', function () {
            if (this.files.length > 0) {
                businessPermitName.textContent = this.files[0].name;
            }
        });
    }

    // ===== Stepper / panel switching =====
    function showPanel(n) {
        panels.forEach(function (p) {
            p.classList.toggle('hidden', parseInt(p.dataset.panel, 10) !== n);
        });

        document.querySelectorAll('#sellerStepper [data-step-circle]').forEach(function (el) {
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

        document.querySelectorAll('#sellerStepper [data-step-line]').forEach(function (el) {
            var i = parseInt(el.dataset.stepLine, 10);
            el.classList.toggle('bg-[#9c5c68]', i < n);
            el.classList.toggle('bg-neutral-200', i >= n);
        });

        document.querySelectorAll('#sellerStepper [data-step-label]').forEach(function (el) {
            var i = parseInt(el.dataset.stepLabel, 10);
            el.classList.toggle('text-neutral-900', i <= n);
            el.classList.toggle('text-neutral-400', i > n);
        });

        headerIcon.innerHTML = '<i data-lucide="' + (stepIcons[n] || 'store') + '" width="20" height="20"></i>';
        if (window.lucide) lucide.createIcons();
    }

    // ===== Open / close =====
    window.openSellerRegisterModal = function (e) {
        if (e) e.preventDefault();
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (!provincesLoaded) {
            fetchProvinces();
        }
    };

    function closeModal() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('[data-seller-register-trigger]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            window.openSellerRegisterModal(e);
        });
    });

    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
    });

    if (new URLSearchParams(window.location.search).get('open') === 'seller') {
        window.openSellerRegisterModal();
    }

    // ===== Birthday + age auto-generation =====
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
        var month = parseInt(birthMonthSelect.value, 10);
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
        el.addEventListener('change', function () {
            rebuildDayOptions();
            updateBirthdayValue();
        });
    });
    birthDaySelect.addEventListener('change', updateBirthdayValue);

    // Initial birthday calculation if repopulating
    if (birthdayInput.value) {
        ageInput.value = calculateAge(birthdayInput.value);
    }

    // Password visibility is handled once by registration-validation.js.

    // ===== PH address cascading dropdowns (PSGC API) =====

    function populateSelect(select, items, placeholder) {
        select.innerHTML = '';
        var ph = document.createElement('option');
        ph.value = '';
        ph.disabled = true;
        ph.selected = true;
        ph.textContent = placeholder;
        select.appendChild(ph);
        items.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = item.code;
            opt.textContent = item.name;
            select.appendChild(opt);
        });
    }

    function fetchProvinces() {
        provinceSelect.innerHTML = '<option value="" disabled selected>Loading provinces...</option>';
        fetch(PSGC_BASE + '/provinces/')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                populateSelect(provinceSelect, data, 'Select province');
                provincesLoaded = true;
            })
            .catch(function () {
                provinceSelect.innerHTML = '<option value="" disabled selected>Could not load provinces</option>';
            });
    }

    function resetMunicipalityBarangayForNewProvince() {
        municipalityNameField.value = '';
        barangayNameField.value = '';
        municipalitySelect.disabled = true;
        barangaySelect.disabled = true;
        municipalitySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';
        barangaySelect.innerHTML = '<option value="" disabled selected>Select municipality first</option>';
    }

    function fetchMunicipalities(provinceCode) {
        fetch(PSGC_BASE + '/provinces/' + provinceCode + '/cities-municipalities/')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                populateSelect(municipalitySelect, data, 'Select municipality/city');
                municipalitySelect.disabled = false;
            })
            .catch(function () {
                municipalitySelect.innerHTML = '<option value="" disabled selected>Could not load</option>';
            });
    }

    function fetchBarangays(municipalityCode) {
        fetch(PSGC_BASE + '/cities-municipalities/' + municipalityCode + '/barangays/')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                populateSelect(barangaySelect, data, 'Select barangay');
                barangaySelect.disabled = false;
            })
            .catch(function () {
                barangaySelect.innerHTML = '<option value="" disabled selected>Could not load</option>';
            });
    }

    provinceSelect.addEventListener('change', function () {
        provinceNameField.value = provinceSelect.selectedOptions[0] ? provinceSelect.selectedOptions[0].textContent : '';
        resetMunicipalityBarangayForNewProvince();
        fetchMunicipalities(provinceSelect.value);
    });

    municipalitySelect.addEventListener('change', function () {
        municipalityNameField.value = municipalitySelect.selectedOptions[0] ? municipalitySelect.selectedOptions[0].textContent : '';
        barangayNameField.value = '';
        barangaySelect.disabled = true;
        barangaySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';
        fetchBarangays(municipalitySelect.value);
    });

    barangaySelect.addEventListener('change', function () {
        barangayNameField.value = barangaySelect.selectedOptions[0] ? barangaySelect.selectedOptions[0].textContent : '';
    });

    addressModeField.value = 'api';

    // ===== Validation helpers =====
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

    function clearPanelErrors(panel) {
        panel.querySelectorAll('.field-error').forEach(function (el) { el.textContent = ''; });
        panel.querySelectorAll('input, select, textarea').forEach(function (el) { el.classList.remove('border-[#a5333d]'); });
    }

    function validatePanel(n) {
        var panel = panels.filter(function (p) { return parseInt(p.dataset.panel, 10) === n; })[0];
        if (!panel) return true;
        clearPanelErrors(panel);
        var valid = true;

        panel.querySelectorAll('[required]').forEach(function (field) {
            if (field.offsetParent === null && field.type !== 'hidden' && field.type !== 'file') return;
            if (field.type === 'file') {
                // For file inputs, check if any file is selected
                if (!field.files || field.files.length === 0) {
                    showFieldError(field, 'Please upload a file.');
                    valid = false;
                }
            } else if (!field.value) {
                showFieldError(field, 'This field is required.');
                valid = false;
            } else if (field.id === 'sellerContactNo' && !/^09\d{9}$/.test(field.value)) {
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
                var birthdayError = document.getElementById('sellerBirthdayError') || findFieldError(birthdayInput);
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
        } else if (n === 2) {
            if (!provinceSelect.value) {
                showFieldError(provinceSelect, 'Select a province.');
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
            if (!streetTextarea.value || !streetTextarea.value.trim()) {
                showFieldError(streetTextarea, 'Street / house number is required.');
                valid = false;
            }
        }

        return valid;
    }

    // ===== Navigation event listeners =====
    nextStep1.addEventListener('click', function () {
        if (validatePanel(1)) {
            showPanel(2);
            if (!provincesLoaded) {
                fetchProvinces();
            }
        }
    });

    backStep2.addEventListener('click', function () {
        showPanel(1);
    });

    nextStep2.addEventListener('click', function () {
        if (validatePanel(2)) {
            showPanel(3);
        }
    });

    if (backStep3) {
        backStep3.addEventListener('click', function () {
            showPanel(2);
        });
    }

    // ===== Step 3 "Submit" = actual seller registration submit (fires the OTP email) =====
    var sellerSubmitRegistration = document.getElementById('submitSellerRegistration');
    if (sellerSubmitRegistration) {
        sellerSubmitRegistration.addEventListener('click', function () {
            if (!validatePanel(3)) {
                console.error('[seller-register-modal] Step 3 validation failed');
                // Show a visible error message for Step 3
                var panel = panels.filter(function (p) { return parseInt(p.dataset.panel, 10) === 3; })[0];
                if (panel) {
                    var errorDiv = panel.querySelector('[data-step3-error]');
                    if (!errorDiv) {
                        errorDiv = document.createElement('div');
                        errorDiv.setAttribute('data-step3-error', 'true');
                        errorDiv.className = 'mb-4 rounded-sm border border-[#e8a49c] bg-[#fbe9e7] px-4 py-3 font-sans text-[12.5px] text-[#a5333d]';
                        panel.insertBefore(errorDiv, sellerSubmitRegistration.parentElement);
                    }
                    errorDiv.textContent = 'Please complete all required fields.';
                }
                return;
            }

            var submitBtn = this;
            var originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';

            var formData = new FormData(form);
            console.log('[seller-register-modal] Form data keys:', Array.from(formData.keys()));
            console.log('[seller-register-modal] Form data:', {
                email: formData.get('email'),
                contact_no: formData.get('contact_no'),
                birthday: formData.get('birthday'),
                password: formData.get('password') ? '***' : 'missing',
                password_confirmation: formData.get('password_confirmation') ? '***' : 'missing',
                province: formData.get('province'),
                municipality: formData.get('municipality'),
                barangay: formData.get('barangay'),
                street: formData.get('street'),
                upload_id: formData.get('upload_id') ? 'present' : 'missing',
                business_permit: formData.get('business_permit') ? 'present' : 'missing',
            });
            // Multipart: upload_id and business_permit are files.
            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin',
            })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
                console.log('[seller-register-modal] Response:', res.ok, res.body);
                if (res.ok && res.body && res.body.seller_id) {
                    var otpIdInput = document.getElementById('sellerOtpId');
                    otpIdInput.value = res.body.seller_id;
                    // Signed verify/resend URLs from the server.
                    otpIdInput.dataset.verifyUrl = res.body.verify_url || '';
                    otpIdInput.dataset.resendUrl = res.body.resend_url || '';
                    document.getElementById('sellerOtpEmail').textContent = res.body.email_masked || res.body.email;
                    showPanel(4);
                    setTimeout(function () {
                        var first = document.querySelector('.seller-otp-input[data-otp-index="0"]');
                        if (first) first.focus();
                    }, 50);
                } else if (res.body && res.body.errors) {
                    panels.forEach(function (p) { clearPanelErrors(p); });
                    Object.keys(res.body.errors).forEach(function (name) {
                        var field = form.querySelector('[name="' + name + '"]');
                        if (field) showFieldError(field, res.body.errors[name][0]);
                    });
                    var step1Fields = ['first_name', 'last_name', 'middle_initial', 'sex', 'email', 'password', 'contact_no', 'birthday'];
                    var step2Fields = ['province', 'municipality', 'barangay', 'street'];
                    var step3Fields = ['business_name', 'line_of_business_id', 'upload_id', 'business_permit'];
                    var errKeys = Object.keys(res.body.errors);
                    if (errKeys.some(function (k) { return step1Fields.indexOf(k) !== -1; })) showPanel(1);
                    else if (errKeys.some(function (k) { return step2Fields.indexOf(k) !== -1; })) showPanel(2);
                    else if (errKeys.some(function (k) { return step3Fields.indexOf(k) !== -1; })) showPanel(3);
                } else {
                    console.error('[seller-register-modal] Unexpected response:', res.body);
                    alert(res.body && res.body.message ? res.body.message : 'Registration failed. Please try again.');
                }
            })
            .catch(function (err) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
                console.error('[seller-register-modal] Fetch error:', err);
                alert('Network error. Please try again.');
            });
        });
    }

    // ===== Panel 4: OTP =====
    var otpForm = document.getElementById('sellerOtpForm');
    var sellerIdInput = document.getElementById('sellerOtpId');
    var otpErrorSummary = document.getElementById('sellerOtpErrorSummary');
    var otpFieldError = document.getElementById('sellerOtpFieldError');
    var hiddenOtp = document.getElementById('sellerOtpHidden');
    var otpInputs = Array.prototype.slice.call(document.querySelectorAll('.seller-otp-input'));
    var resendBtn = document.getElementById('sellerOtpResendBtn');
    var resendStatus = document.getElementById('sellerOtpResendStatus');
    var otpSubmitBtn = document.getElementById('sellerOtpSubmit');
    var csrf = document.querySelector('meta[name="csrf-token"]');
    csrf = csrf ? csrf.getAttribute('content') : '';

    if (otpForm && sellerIdInput && otpErrorSummary && otpFieldError && hiddenOtp && resendBtn && resendStatus && otpSubmitBtn) {
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
            var resendUrl = sellerIdInput.dataset.resendUrl || '';
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
            var code = collectOtp();
            if (code.length !== 6) { showOtpError('Enter all 6 digits.'); return; }
            var verifyUrl = sellerIdInput.dataset.verifyUrl || '';
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
                if (res.status === 200) {
                    // Success: close modal and show message
                    closeModal();
                    alert('Your seller registration is complete! Awaiting admin approval.');
                } else {
                    // 422 validation / 429 lock / 503 mail failure: show the
                    // server's own message (+ retry time for a rate limit).
                    showOtpError(otpServerError(res, res.status === 422 ? 'Invalid or expired code.' : 'Verification failed.'));
                }
            })
            .catch(function (err) {
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
    function initSellerPasswordMeter() {
        if (window.__zfReg) {
            window.__zfReg.initPasswordMeter();
        }
    }
    if (window.__zfReg) {
        initSellerPasswordMeter();
    }
    if (window.__zfReg && window.__zfReg.initPasswordToggles) {
        window.__zfReg.initPasswordToggles();
    }
});

