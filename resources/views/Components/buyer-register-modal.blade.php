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
                        <option value="" disabled selected>Select</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
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
                        <input type="text" name="street" id="street">
                    </div>
                    <div class="buyer-modal__field buyer-modal__field--small">
                        <label for="houseNumber">House number</label>
                        <input type="text" name="house_number" id="houseNumber">
                    </div>
                    <div class="buyer-modal__field">
                        <label for="addressDetail">Other detail</label>
                        <input type="text" name="address_detail" id="addressDetail" placeholder="Subdivision, landmark, etc.">
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

    var uploadIdInput = document.getElementById('uploadId');
    var uploadIdName = document.getElementById('uploadIdName');

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

    document.querySelectorAll('[data-buyer-register-trigger]').forEach(function (el) {
        el.addEventListener('click', openModal);
    });

    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
    });

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

    // ===== Manual address toggle =====
    var manualMode = false;
    toggleManualBtn.addEventListener('click', function () {
        manualMode = !manualMode;
        apiFields.hidden = manualMode;
        manualFields.hidden = !manualMode;
        toggleManualBtn.textContent = manualMode ? 'Use address lookup instead' : 'Enter address manually';

        [provinceSelect, municipalitySelect, barangaySelect].forEach(function (el) {
            el.required = !manualMode;
        });
        document.getElementById('street').required = manualMode;
    });

    // ===== File name display =====
    uploadIdInput.addEventListener('change', function () {
        uploadIdName.textContent = uploadIdInput.files.length ? uploadIdInput.files[0].name : 'No file selected';
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

        if (!valid) e.preventDefault();
    });

    @if ($errors->any())
        openModal();
    @endif
})();
</script>