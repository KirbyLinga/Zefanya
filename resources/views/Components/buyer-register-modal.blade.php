{{-- resources/views/Components/buyer-register-modal.blade.php
     Include once in the page that needs it (e.g. Auth/Register-Type.blade.php),
     near the bottom of the content, inside .html-body so login-modal-style
     scoped CSS applies:
       @include('Components.buyer-register-modal')
     Trigger it from the Buyer card with:
       <a href="#" data-buyer-register-trigger>REGISTER AS BUYER</a> --}}

<div class="buyer-modal-overlay" id="buyerModalOverlay" aria-hidden="true">
    <div class="buyer-modal" role="dialog" aria-modal="true" aria-labelledby="buyerModalTitle">

        <button type="button" class="buyer-modal__close" id="buyerModalClose" aria-label="Close registration form">
            <i data-lucide="x" width="18" height="18"></i>
        </button>

        <div class="buyer-modal__header">
            <h2 class="buyer-modal__title" id="buyerModalTitle">Register as Buyer</h2>
            <p class="buyer-modal__subtitle">Create your account to start shopping on Zefanya.</p>
        </div>

        @if ($errors->any())
            <div class="buyer-modal__error-summary">
                {{ $errors->first() }}
            </div>
        @endif

        <form class="buyer-modal__form" id="buyerRegisterForm" method="POST"
              action="{{ Route::has('register.buyer.store') ? route('register.buyer.store') : '#' }}"
              enctype="multipart/form-data" novalidate>
            @csrf

            {{-- Which address entry mode was used, plus human-readable names
                 alongside the PSGC codes so admin doesn't need a second API call. --}}
            <input type="hidden" name="address_mode" id="addressMode" value="{{ old('address_mode', 'api') }}">
            <input type="hidden" name="province_name" id="provinceNameField" value="{{ old('province_name') }}">
            <input type="hidden" name="municipality_name" id="municipalityNameField" value="{{ old('municipality_name') }}">
            <input type="hidden" name="barangay_name" id="barangayNameField" value="{{ old('barangay_name') }}">

            <div class="buyer-modal__row">
                <div class="buyer-modal__field">
                    <label for="lastName">Last name *</label>
                    <input type="text" name="last_name" id="lastName" required value="{{ old('last_name') }}">
                    <span class="buyer-modal__field-error"></span>
                </div>
                <div class="buyer-modal__field">
                    <label for="firstName">First name *</label>
                    <input type="text" name="first_name" id="firstName" required value="{{ old('first_name') }}">
                    <span class="buyer-modal__field-error"></span>
                </div>
            </div>

            <div class="buyer-modal__row">
                <div class="buyer-modal__field buyer-modal__field--small">
                    <label for="middleInitial">Middle initial</label>
                    <input type="text" name="middle_initial" id="middleInitial" maxlength="2" value="{{ old('middle_initial') }}">
                </div>
                <div class="buyer-modal__field">
                    <label for="sex">Sex *</label>
                    <select name="sex" id="sex" required>
                        <option value="" disabled {{ old('sex') ? '' : 'selected' }}>Select</option>
                        <option value="male" {{ old('sex') === 'male' ? 'selected' : '' }}>Male</option>
                        <option value="female" {{ old('sex') === 'female' ? 'selected' : '' }}>Female</option>
                    </select>
                    <span class="buyer-modal__field-error"></span>
                </div>
            </div>

            <div class="buyer-modal__field">
                <label for="email">E-mail *</label>
                <input type="email" name="email" id="email" required value="{{ old('email') }}">
                <span class="buyer-modal__field-error"></span>
            </div>

            <div class="buyer-modal__row">
                <div class="buyer-modal__field">
                    <label for="password">Password *</label>
                    <div class="buyer-modal__password-wrap">
                        <input type="password" name="password" id="password" placeholder="At least 8 characters"
                               required minlength="8" autocomplete="new-password">
                        <button type="button" class="buyer-modal__toggle-visibility"
                                data-toggle-visibility="password" aria-label="Show password">
                            <i data-lucide="eye" width="18" height="18"></i>
                        </button>
                    </div>
                    <span class="buyer-modal__field-error"></span>
                </div>
                <div class="buyer-modal__field">
                    <label for="passwordConfirmation">Confirm Password *</label>
                    <div class="buyer-modal__password-wrap">
                        <input type="password" name="password_confirmation" id="passwordConfirmation"
                               placeholder="Re-enter your password" required autocomplete="new-password">
                        <button type="button" class="buyer-modal__toggle-visibility"
                                data-toggle-visibility="passwordConfirmation" aria-label="Show password">
                            <i data-lucide="eye" width="18" height="18"></i>
                        </button>
                    </div>
                    <span class="buyer-modal__field-error"></span>
                </div>
            </div>

            <div class="buyer-modal__row">
                <div class="buyer-modal__field">
                    <label for="contactNo">Contact No. *</label>
                    <input type="tel" name="contact_no" id="contactNo" placeholder="09XXXXXXXXX" required value="{{ old('contact_no') }}">
                    <span class="buyer-modal__field-error"></span>
                </div>
                <div class="buyer-modal__field buyer-modal__field--small">
                    <label for="birthday">Birthday *</label>
                    <input type="date" name="birthday" id="birthday" required value="{{ old('birthday') }}">
                    <span class="buyer-modal__field-error"></span>
                </div>
                <div class="buyer-modal__field buyer-modal__field--small">
                    <label for="age">Age</label>
                    <input type="text" id="age" readonly placeholder="—">
                </div>
            </div>

            <div class="buyer-modal__address">
                <div class="buyer-modal__address-header">
                    <span class="buyer-modal__label-plain">Address *</span>
                    <button type="button" class="buyer-modal__toggle-address" id="toggleManualAddress">
                        Enter address manually
                    </button>
                </div>

                <div class="buyer-modal__row" id="apiAddressFields">
                    <div class="buyer-modal__field">
                        <label for="province">Province</label>
                        <select name="province" id="province" required>
                            <option value="" disabled selected>Loading provinces...</option>
                        </select>
                    </div>
                    <div class="buyer-modal__field">
                        <label for="municipality">Municipality / City</label>
                        <select name="municipality" id="municipality" required disabled>
                            <option value="" disabled selected>Select province first</option>
                        </select>
                    </div>
                    <div class="buyer-modal__field">
                        <label for="barangay">Barangay</label>
                        <select name="barangay" id="barangay" required disabled>
                            <option value="" disabled selected>Select municipality first</option>
                        </select>
                    </div>
                </div>

                <div class="buyer-modal__row buyer-modal__manual-fields" id="manualAddressFields" hidden>
                    <div class="buyer-modal__field">
                        <label for="street">Street</label>
                        <input type="text" name="street" id="street" value="{{ old('street') }}">
                    </div>
                    <div class="buyer-modal__field buyer-modal__field--small">
                        <label for="houseNumber">House number</label>
                        <input type="text" name="house_number" id="houseNumber" value="{{ old('house_number') }}">
                    </div>
                    <div class="buyer-modal__field">
                        <label for="addressDetail">Other detail</label>
                        <input type="text" name="address_detail" id="addressDetail" placeholder="Subdivision, landmark, etc." value="{{ old('address_detail') }}">
                    </div>
                </div>
                <span class="buyer-modal__field-error" id="addressError"></span>
            </div>

            <div class="buyer-modal__field">
                <label for="uploadId">Upload ID *</label>
                <div class="buyer-modal__file-wrap">
                    <label for="uploadId" class="buyer-modal__file-btn">
                        <i data-lucide="upload" width="16" height="16"></i>
                        <span>Choose file</span>
                    </label>
                    <input type="file" name="upload_id" id="uploadId" accept="image/*,.pdf" required class="buyer-modal__file-input">
                    <span class="buyer-modal__file-name" id="uploadIdName">No file selected</span>
                </div>
                <span class="buyer-modal__field-error"></span>
            </div>

            <p class="buyer-modal__notice">
                After submitting your registration, please wait for the administrator's approval, which will be sent to your email.
            </p>

            <button type="submit" class="buyer-modal__submit">REGISTER AS BUYER</button>
        </form>

    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('buyerModalOverlay');
    if (!overlay) return;

    var closeBtn = document.getElementById('buyerModalClose');
    var form = document.getElementById('buyerRegisterForm');

    var birthdayInput = document.getElementById('birthday');
    var ageInput = document.getElementById('age');

    var provinceSelect = document.getElementById('province');
    var municipalitySelect = document.getElementById('municipality');
    var barangaySelect = document.getElementById('barangay');
    var apiFields = document.getElementById('apiAddressFields');
    var manualFields = document.getElementById('manualAddressFields');
    var toggleManualBtn = document.getElementById('toggleManualAddress');
    var addressModeField = document.getElementById('addressMode');
    var provinceNameField = document.getElementById('provinceNameField');
    var municipalityNameField = document.getElementById('municipalityNameField');
    var barangayNameField = document.getElementById('barangayNameField');

    var uploadIdInput = document.getElementById('uploadId');
    var uploadIdName = document.getElementById('uploadIdName');

    var passwordInput = document.getElementById('password');
    var confirmInput = document.getElementById('passwordConfirmation');

    var provincesLoaded = false;

    // ===== Open / close =====
    function openModal(e) {
        if (e) e.preventDefault();
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (!provincesLoaded) loadProvinces();
    }

    function closeModal() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    // Expose openModal globally so navbar and other triggers can open this modal
    // Supports optional options object for future extensibility
    window.openBuyerRegisterModal = function (e, options) {
        if (e) e.preventDefault();
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };

    document.querySelectorAll('[data-buyer-register-trigger]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            window.openBuyerRegisterModal(e);
        });
    });

    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
    });

    // Deep link support: /register/buyer redirects here with ?open=buyer
    // so a direct link still lands the user in the modal.
    if (new URLSearchParams(window.location.search).get('open') === 'buyer') {
        openModal();
    }

    // ===== Age auto-generation =====
    function calculateAge(birthDateStr) {
        var birthDate = new Date(birthDateStr);
        if (isNaN(birthDate.getTime())) return '';
        var today = new Date();
        var age = today.getFullYear() - birthDate.getFullYear();
        var monthDiff = today.getMonth() - birthDate.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        return age >= 0 ? age : '';
    }

    birthdayInput.addEventListener('change', function () {
        ageInput.value = calculateAge(birthdayInput.value);
    });

    // ===== PH address cascading dropdowns (PSGC API) =====
    var PSGC_BASE = 'https://psgc.gitlab.io/api';

    function populateSelect(select, items, placeholder) {
        select.innerHTML = '';
        var placeholderOpt = document.createElement('option');
        placeholderOpt.value = '';
        placeholderOpt.disabled = true;
        placeholderOpt.selected = true;
        placeholderOpt.textContent = placeholder;
        select.appendChild(placeholderOpt);

        items.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = item.code;
            opt.textContent = item.name;
            select.appendChild(opt);
        });
    }

    function loadProvinces() {
        fetch(PSGC_BASE + '/provinces/')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                populateSelect(provinceSelect, data, 'Select province');
                provincesLoaded = true;
            })
            .catch(function () {
                provinceSelect.innerHTML = '<option value="" disabled selected>Could not load — try manual entry</option>';
            });
    }

    provinceSelect.addEventListener('change', function () {
        var code = provinceSelect.value;
        provinceNameField.value = provinceSelect.selectedOptions[0] ? provinceSelect.selectedOptions[0].textContent : '';
        municipalityNameField.value = '';
        barangayNameField.value = '';

        municipalitySelect.disabled = true;
        barangaySelect.disabled = true;
        municipalitySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';
        barangaySelect.innerHTML = '<option value="" disabled selected>Select municipality first</option>';

        fetch(PSGC_BASE + '/provinces/' + code + '/cities-municipalities/')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                populateSelect(municipalitySelect, data, 'Select municipality/city');
                municipalitySelect.disabled = false;
            })
            .catch(function () {
                municipalitySelect.innerHTML = '<option value="" disabled selected>Could not load</option>';
            });
    });

    municipalitySelect.addEventListener('change', function () {
        var code = municipalitySelect.value;
        municipalityNameField.value = municipalitySelect.selectedOptions[0] ? municipalitySelect.selectedOptions[0].textContent : '';
        barangayNameField.value = '';

        barangaySelect.disabled = true;
        barangaySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';

        fetch(PSGC_BASE + '/cities-municipalities/' + code + '/barangays/')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                populateSelect(barangaySelect, data, 'Select barangay');
                barangaySelect.disabled = false;
            })
            .catch(function () {
                barangaySelect.innerHTML = '<option value="" disabled selected>Could not load</option>';
            });
    });

    barangaySelect.addEventListener('change', function () {
        barangayNameField.value = barangaySelect.selectedOptions[0] ? barangaySelect.selectedOptions[0].textContent : '';
    });

    // ===== Manual address toggle =====
    var manualMode = false;
    toggleManualBtn.addEventListener('click', function () {
        manualMode = !manualMode;
        apiFields.hidden = manualMode;
        manualFields.hidden = !manualMode;
        toggleManualBtn.textContent = manualMode ? 'Use address lookup instead' : 'Enter address manually';
        addressModeField.value = manualMode ? 'manual' : 'api';

        [provinceSelect, municipalitySelect, barangaySelect].forEach(function (el) {
            el.required = !manualMode;
        });
        document.getElementById('street').required = manualMode;
    });

    // ===== File name display =====
    uploadIdInput.addEventListener('change', function () {
        uploadIdName.textContent = uploadIdInput.files.length ? uploadIdInput.files[0].name : 'No file selected';
    });

    // ===== Password visibility toggles =====
    document.querySelectorAll('.buyer-modal__toggle-visibility').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-toggle-visibility'));
            if (!target) return;
            var isHidden = target.type === 'password';
            target.type = isHidden ? 'text' : 'password';
            btn.innerHTML = '<i data-lucide="' + (isHidden ? 'eye-off' : 'eye') + '" width="18" height="18"></i>';
            btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            if (window.lucide) lucide.createIcons();
        });
    });

    // ===== Client-side validation =====
    function showFieldError(field, message) {
        var errorEl = field.closest('.buyer-modal__field') &&
            field.closest('.buyer-modal__field').querySelector('.buyer-modal__field-error');
        field.classList.add('has-error');
        if (errorEl) errorEl.textContent = message;
    }

    function clearFieldError(field) {
        var errorEl = field.closest('.buyer-modal__field') &&
            field.closest('.buyer-modal__field').querySelector('.buyer-modal__field-error');
        field.classList.remove('has-error');
        if (errorEl) errorEl.textContent = '';
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var valid = true;
        var requiredFields = form.querySelectorAll('[required]');

        requiredFields.forEach(function (field) {
            if (field.offsetParent === null) return; // skip hidden fields
            clearFieldError(field);

            if (!field.value || (field.type === 'file' && field.files.length === 0)) {
                showFieldError(field, 'This field is required.');
                valid = false;
            } else if (field.id === 'contactNo' && !/^09\d{9}$/.test(field.value)) {
                showFieldError(field, 'Enter a valid PH mobile number (09XXXXXXXXX).');
                valid = false;
            } else if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
                showFieldError(field, 'Enter a valid email address.');
                valid = false;
            }
        });

        // ===== Password checks =====
        clearFieldError(passwordInput);
        clearFieldError(confirmInput);
        if (passwordInput.value && passwordInput.value.length < 8) {
            showFieldError(passwordInput, 'Password must be at least 8 characters.');
            valid = false;
        }
        if (confirmInput.value && passwordInput.value !== confirmInput.value) {
            showFieldError(confirmInput, 'Passwords do not match.');
            valid = false;
        }

        if (!valid) return;

        // Client-side checks passed — POST via fetch so we can open the OTP modal in-page.
        var submitBtn = form.querySelector('.buyer-modal__submit');
        submitBtn.disabled = true;
        submitBtn.textContent = 'SUBMITTING...';

        var formData = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        .then(function (r) {
            return r.json().then(function (j) { return { ok: r.ok, body: j }; });
        })
        .then(function (res) {
            if (res.ok && res.body && res.body.buyer_id) {
                // Hide the registration modal and hand off to the OTP modal.
                closeModal();
                document.body.style.overflow = 'hidden';
                if (typeof window.openBuyerOtpModal === 'function') {
                    window.openBuyerOtpModal(res.body.buyer_id, res.body.email);
                } else {
                    window.location.href = res.body.verify_url || '/register/buyer/verify-otp/' + res.body.buyer_id;
                }
            } else if (res.body && res.body.errors) {
                // Map server validation errors back onto the right fields.
                Object.keys(res.body.errors).forEach(function (name) {
                    var field = form.querySelector('[name="' + name + '"]');
                    if (field) showFieldError(field, res.body.errors[name][0]);
                });
                submitBtn.disabled = false;
                submitBtn.textContent = 'REGISTER AS BUYER';
            } else {
                submitBtn.disabled = false;
                submitBtn.textContent = 'REGISTER AS BUYER';
                alert(res.body && res.body.message ? res.body.message : 'Registration failed. Please try again.');
            }
        })
        .catch(function () {
            submitBtn.disabled = false;
            submitBtn.textContent = 'REGISTER AS BUYER';
            alert('Network error. Please try again.');
        });
    });

    @if ($errors->any())
        openModal();
    @endif
})();
</script>
