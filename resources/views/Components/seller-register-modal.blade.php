{{-- resources/views/Components/seller-register-modal.blade.php
     Include once in the page that needs it (e.g. Auth/Register-Type.blade.php):
       @include('Components.seller-register-modal')
     Trigger it from the Seller card with:
       <a href="#" data-seller-register-trigger>REGISTER AS SELLER</a> --}}

<div class="buyer-modal-overlay" id="sellerModalOverlay" aria-hidden="true">
    <div class="buyer-modal" role="dialog" aria-modal="true" aria-labelledby="sellerModalTitle">

        <button type="button" class="buyer-modal__close" id="sellerModalClose" aria-label="Close registration form">
            <i data-lucide="x" width="18" height="18"></i>
        </button>

        <div class="buyer-modal__header">
            <h2 class="buyer-modal__title" id="sellerModalTitle">Register as Seller</h2>
            <p class="buyer-modal__subtitle">Start selling on Zefanya.</p>
        </div>

        @if ($errors->any())
            <div class="buyer-modal__error-summary">
                {{ $errors->first() }}
            </div>
        @endif

        <form class="buyer-modal__form" id="sellerRegisterForm" method="POST"
              action="{{ Route::has('register.seller.store') ? route('register.seller.store') : '#' }}"
              enctype="multipart/form-data" novalidate>
            @csrf

            <input type="hidden" name="address_mode" id="sellerAddressMode" value="{{ old('address_mode', 'api') }}">
            <input type="hidden" name="province_name" id="sellerProvinceNameField" value="{{ old('province_name') }}">
            <input type="hidden" name="municipality_name" id="sellerMunicipalityNameField" value="{{ old('municipality_name') }}">
            <input type="hidden" name="barangay_name" id="sellerBarangayNameField" value="{{ old('barangay_name') }}">

            <div class="buyer-modal__row">
                <div class="buyer-modal__field">
                    <label for="sellerLastName">Last name *</label>
                    <input type="text" name="last_name" id="sellerLastName" required value="{{ old('last_name') }}">
                    <span class="buyer-modal__field-error"></span>
                </div>
                <div class="buyer-modal__field">
                    <label for="sellerFirstName">First name *</label>
                    <input type="text" name="first_name" id="sellerFirstName" required value="{{ old('first_name') }}">
                    <span class="buyer-modal__field-error"></span>
                </div>
            </div>

            <div class="buyer-modal__row">
                <div class="buyer-modal__field buyer-modal__field--small">
                    <label for="sellerMiddleInitial">Middle initial</label>
                    <input type="text" name="middle_initial" id="sellerMiddleInitial" maxlength="2" value="{{ old('middle_initial') }}">
                </div>
                <div class="buyer-modal__field">
                    <label for="sellerSex">Sex *</label>
                    <select name="sex" id="sellerSex" required>
                        <option value="" disabled {{ old('sex') ? '' : 'selected' }}>Select</option>
                        <option value="male" {{ old('sex') === 'male' ? 'selected' : '' }}>Male</option>
                        <option value="female" {{ old('sex') === 'female' ? 'selected' : '' }}>Female</option>
                    </select>
                    <span class="buyer-modal__field-error"></span>
                </div>
            </div>

            <div class="buyer-modal__field">
                <label for="sellerEmail">E-mail *</label>
                <input type="email" name="email" id="sellerEmail" required value="{{ old('email') }}">
                <span class="buyer-modal__field-error"></span>
            </div>

            <div class="buyer-modal__row">
                <div class="buyer-modal__field">
                    <label for="sellerPassword">Password *</label>
                    <div class="buyer-modal__password-wrap">
                        <input type="password" name="password" id="sellerPassword" placeholder="At least 8 characters"
                               required minlength="8" autocomplete="new-password">
                        <button type="button" class="buyer-modal__toggle-visibility"
                                data-toggle-visibility="sellerPassword" aria-label="Show password">
                            <i data-lucide="eye" width="18" height="18"></i>
                        </button>
                    </div>
                    <span class="buyer-modal__field-error"></span>
                </div>
                <div class="buyer-modal__field">
                    <label for="sellerPasswordConfirmation">Confirm Password *</label>
                    <div class="buyer-modal__password-wrap">
                        <input type="password" name="password_confirmation" id="sellerPasswordConfirmation"
                               placeholder="Re-enter your password" required autocomplete="new-password">
                        <button type="button" class="buyer-modal__toggle-visibility"
                                data-toggle-visibility="sellerPasswordConfirmation" aria-label="Show password">
                            <i data-lucide="eye" width="18" height="18"></i>
                        </button>
                    </div>
                    <span class="buyer-modal__field-error"></span>
                </div>
            </div>

            <div class="buyer-modal__row">
                <div class="buyer-modal__field">
                    <label for="sellerContactNo">Contact No. *</label>
                    <input type="tel" name="contact_no" id="sellerContactNo" placeholder="09XXXXXXXXX" required value="{{ old('contact_no') }}">
                    <span class="buyer-modal__field-error"></span>
                </div>
                <div class="buyer-modal__field buyer-modal__field--small">
                    <label for="sellerBirthday">Birthday *</label>
                    <input type="date" name="birthday" id="sellerBirthday" required value="{{ old('birthday') }}">
                    <span class="buyer-modal__field-error"></span>
                </div>
                <div class="buyer-modal__field buyer-modal__field--small">
                    <label for="sellerAge">Age</label>
                    <input type="text" id="sellerAge" readonly placeholder="—">
                </div>
            </div>

            <div class="buyer-modal__address">
                <div class="buyer-modal__address-header">
                    <span class="buyer-modal__label-plain">Address *</span>
                    <button type="button" class="buyer-modal__toggle-address" id="sellerToggleManualAddress">
                        Enter address manually
                    </button>
                </div>

                <div class="buyer-modal__row" id="sellerApiAddressFields">
                    <div class="buyer-modal__field">
                        <label for="sellerProvince">Province</label>
                        <select name="province" id="sellerProvince" required>
                            <option value="" disabled selected>Loading provinces...</option>
                        </select>
                    </div>
                    <div class="buyer-modal__field">
                        <label for="sellerMunicipality">Municipality / City</label>
                        <select name="municipality" id="sellerMunicipality" required disabled>
                            <option value="" disabled selected>Select province first</option>
                        </select>
                    </div>
                    <div class="buyer-modal__field">
                        <label for="sellerBarangay">Barangay</label>
                        <select name="barangay" id="sellerBarangay" required disabled>
                            <option value="" disabled selected>Select municipality first</option>
                        </select>
                    </div>
                </div>

                <div class="buyer-modal__row buyer-modal__manual-fields" id="sellerManualAddressFields" hidden>
                    <div class="buyer-modal__field">
                        <label for="sellerStreet">Street</label>
                        <input type="text" name="street" id="sellerStreet" value="{{ old('street') }}">
                    </div>
                    <div class="buyer-modal__field buyer-modal__field--small">
                        <label for="sellerHouseNumber">House number</label>
                        <input type="text" name="house_number" id="sellerHouseNumber" value="{{ old('house_number') }}">
                    </div>
                    <div class="buyer-modal__field">
                        <label for="sellerAddressDetail">Other detail</label>
                        <input type="text" name="address_detail" id="sellerAddressDetail" placeholder="Subdivision, landmark, etc." value="{{ old('address_detail') }}">
                    </div>
                </div>
                <span class="buyer-modal__field-error" id="sellerAddressError"></span>
            </div>

            <div class="buyer-modal__field">
                <label for="sellerBusinessName">Business name *</label>
                <input type="text" name="business_name" id="sellerBusinessName" required value="{{ old('business_name') }}">
                <span class="buyer-modal__field-error"></span>
            </div>

            <div class="buyer-modal__field">
                <label for="sellerLineOfBusiness">Line of business *</label>
                <select name="line_of_business_id" id="sellerLineOfBusiness" required>
                    <option value="" disabled {{ old('line_of_business_id') ? '' : 'selected' }}>Select category</option>
                    @foreach (\App\Models\Category::orderBy('label')->get() as $category)
                        <option value="{{ $category->id }}" {{ (string) old('line_of_business_id') === (string) $category->id ? 'selected' : '' }}>
                            {{ $category->label }}
                        </option>
                    @endforeach
                </select>
                <span class="buyer-modal__field-error"></span>
            </div>

            <div class="buyer-modal__field">
                <label for="sellerUploadId">Upload ID *</label>
                <div class="buyer-modal__file-wrap">
                    <label for="sellerUploadId" class="buyer-modal__file-btn">
                        <i data-lucide="upload" width="16" height="16"></i>
                        <span>Choose file</span>
                    </label>
                    <input type="file" name="upload_id" id="sellerUploadId" accept="image/*,.pdf" required class="buyer-modal__file-input">
                    <span class="buyer-modal__file-name" id="sellerUploadIdName">No file selected</span>
                </div>
                <span class="buyer-modal__field-error"></span>
            </div>

            <div class="buyer-modal__field">
                <label for="sellerBusinessPermit">Upload business permit *</label>
                <div class="buyer-modal__file-wrap">
                    <label for="sellerBusinessPermit" class="buyer-modal__file-btn">
                        <i data-lucide="upload" width="16" height="16"></i>
                        <span>Choose file</span>
                    </label>
                    <input type="file" name="business_permit" id="sellerBusinessPermit" accept="image/*,.pdf" required class="buyer-modal__file-input">
                    <span class="buyer-modal__file-name" id="sellerBusinessPermitName">No file selected</span>
                </div>
                <span class="buyer-modal__field-error"></span>
            </div>

            <p class="buyer-modal__notice">
                After submitting your registration, please wait for the administrator's approval, which will be sent to your email.
            </p>

            <button type="submit" class="buyer-modal__submit">REGISTER AS SELLER</button>
        </form>

    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('sellerModalOverlay');
    if (!overlay) return;

    var closeBtn = document.getElementById('sellerModalClose');
    var form = document.getElementById('sellerRegisterForm');

    var birthdayInput = document.getElementById('sellerBirthday');
    var ageInput = document.getElementById('sellerAge');

    var provinceSelect = document.getElementById('sellerProvince');
    var municipalitySelect = document.getElementById('sellerMunicipality');
    var barangaySelect = document.getElementById('sellerBarangay');
    var apiFields = document.getElementById('sellerApiAddressFields');
    var manualFields = document.getElementById('sellerManualAddressFields');
    var toggleManualBtn = document.getElementById('sellerToggleManualAddress');
    var addressModeField = document.getElementById('sellerAddressMode');
    var provinceNameField = document.getElementById('sellerProvinceNameField');
    var municipalityNameField = document.getElementById('sellerMunicipalityNameField');
    var barangayNameField = document.getElementById('sellerBarangayNameField');

    var uploadIdInput = document.getElementById('sellerUploadId');
    var uploadIdName = document.getElementById('sellerUploadIdName');
    var permitInput = document.getElementById('sellerBusinessPermit');
    var permitName = document.getElementById('sellerBusinessPermitName');

    var passwordInput = document.getElementById('sellerPassword');
    var confirmInput = document.getElementById('sellerPasswordConfirmation');

    var provincesLoaded = false;

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

    document.querySelectorAll('[data-seller-register-trigger]').forEach(function (el) {
        el.addEventListener('click', openModal);
    });

    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
    });

    if (new URLSearchParams(window.location.search).get('open') === 'seller') {
        openModal();
    }

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
        document.getElementById('sellerStreet').required = manualMode;
    });

    uploadIdInput.addEventListener('change', function () {
        uploadIdName.textContent = uploadIdInput.files.length ? uploadIdInput.files[0].name : 'No file selected';
    });
    permitInput.addEventListener('change', function () {
        permitName.textContent = permitInput.files.length ? permitInput.files[0].name : 'No file selected';
    });

    document.querySelectorAll('#sellerRegisterForm .buyer-modal__toggle-visibility').forEach(function (btn) {
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
            if (field.offsetParent === null) return;
            clearFieldError(field);

            if (!field.value || (field.type === 'file' && field.files.length === 0)) {
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
            if (res.ok && res.body && res.body.seller_id) {
                closeModal();
                document.body.style.overflow = 'hidden';
                if (typeof window.openSellerOtpModal === 'function') {
                    window.openSellerOtpModal(res.body.seller_id, res.body.email);
                } else {
                    window.location.href = res.body.verify_url || '/register/seller/verify-otp/' + res.body.seller_id;
                }
            } else if (res.body && res.body.errors) {
                Object.keys(res.body.errors).forEach(function (name) {
                    var field = form.querySelector('[name="' + name + '"]');
                    if (field) showFieldError(field, res.body.errors[name][0]);
                });
                submitBtn.disabled = false;
                submitBtn.textContent = 'REGISTER AS SELLER';
            } else {
                submitBtn.disabled = false;
                submitBtn.textContent = 'REGISTER AS SELLER';
                alert(res.body && res.body.message ? res.body.message : 'Registration failed. Please try again.');
            }
        })
        .catch(function () {
            submitBtn.disabled = false;
            submitBtn.textContent = 'REGISTER AS SELLER';
            alert('Network error. Please try again.');
        });
    });

    @if ($errors->any())
        openModal();
    @endif
})();
</script>