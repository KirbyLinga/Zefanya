// resources/js/seller/seller-register-modal.js
// Behavior for the seller registration modal (Components/seller-register-modal.blade.php).
// Mirrors resources/js/buyer/buyer-register-modal.js.

document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('sellerModalOverlay');
    if (!overlay) return;

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
    var birthdayInput = document.getElementById('birthday');
    var ageInput = document.getElementById('sellerAge');
    var birthMonthSelect = document.getElementById('birthMonth');
    var birthDaySelect = document.getElementById('birthDay');
    var birthYearSelect = document.getElementById('birthYear');

    var passwordInput = document.getElementById('sellerPassword');
    var confirmInput = document.getElementById('passwordConfirmation');
    var nextStep1 = document.getElementById('sellerNextStep1');

    // Panel 2 elements
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
    var manualProvinceInput = document.getElementById('sellerManualProvince');
    var manualMunicipalityInput = document.getElementById('sellerManualMunicipality');
    var manualBarangayInput = document.getElementById('sellerManualBarangay');
    var streetTextarea = document.getElementById('sellerStreet');
    var addressError = document.getElementById('sellerAddressError');

    var backStep2 = document.getElementById('sellerBackStep2');
    var nextStep2 = document.getElementById('sellerNextStep2');
    var backStep3 = document.getElementById('sellerBackStep3');

    var provincesLoaded = false;
    var manualMode = addressModeField && addressModeField.value === 'manual';

    var requiredElements = {
        closeBtn: closeBtn, form: form, headerIcon: headerIcon,
        birthdayInput: birthdayInput, ageInput: ageInput,
        birthMonthSelect: birthMonthSelect, birthDaySelect: birthDaySelect, birthYearSelect: birthYearSelect,
        passwordInput: passwordInput, confirmInput: confirmInput, nextStep1: nextStep1,
        provinceSelect: provinceSelect, municipalitySelect: municipalitySelect, barangaySelect: barangaySelect,
        apiFields: apiFields, manualFields: manualFields, toggleManualBtn: toggleManualBtn,
        addressModeField: addressModeField, provinceNameField: provinceNameField,
        municipalityNameField: municipalityNameField, barangayNameField: barangayNameField,
        streetTextarea: streetTextarea, backStep2: backStep2, nextStep2: nextStep2,
    };

    var missing = Object.keys(requiredElements).filter(function (k) { return !requiredElements[k]; });
    if (missing.length) {
        console.error('[seller-register-modal] missing element(s), aborting init:', missing.join(', '));
        return;
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
        if (!provincesLoaded && !manualMode) {
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

    // ===== Password visibility toggles =====
    document.querySelectorAll('#sellerRegisterForm [data-toggle-visibility]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-toggle-visibility'));
            if (!target) return;
            var isHidden = target.type === 'password';
            target.type = isHidden ? 'text' : 'password';
            btn.innerHTML = '<i data-lucide="' + (isHidden ? 'eye-off' : 'eye') + '" width="16" height="16"></i>';
            if (window.lucide) lucide.createIcons();
        });
    });

    // ===== PH address cascading dropdowns (PSGC API) =====
    var PSGC_BASE = 'https://psgc.gitlab.io/api';

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
                provinceSelect.innerHTML = '<option value="" disabled selected>Could not load — try manual entry</option>';
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

    // ===== Manual Address Track & Toggle =====
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

        // Dropdowns required only in API mode; street is ALWAYS required (HTML required attr).
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
            if (!provincesLoaded) {
                fetchProvinces();
            }
        }
    }

    toggleManualBtn.addEventListener('click', function () {
        setAddressMode(!manualMode);
    });

    if (manualMode) {
        setAddressMode(true);
    }

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
            if (field.offsetParent === null && field.type !== 'hidden') return;
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

        if (n === 1) {
            if (!(birthMonthSelect.value && birthDaySelect.value && birthYearSelect.value)) {
                [birthMonthSelect, birthDaySelect, birthYearSelect].forEach(function (s) { s.classList.add('border-[#a5333d]'); });
                var birthdayError = document.getElementById('birthdayError') || findFieldError(birthdayInput);
                if (birthdayError) birthdayError.textContent = 'Select your full birthday.';
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
        } else if (n === 2) {
            if (!manualMode) {
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
            // Street is required in BOTH modes.
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
            if (!provincesLoaded && !manualMode) {
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

    showPanel(1);
});

