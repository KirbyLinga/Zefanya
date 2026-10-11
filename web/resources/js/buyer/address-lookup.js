// resources/js/buyer/address-lookup.js
// Shared PH address cascading dropdowns (PSGC API) + manual-address fallback.
//
// Used by BOTH:
//   - the buyer registration modal (Components/buyer-register-modal.blade.php)
//   - the buyer account address page (Buyer/Account/address.blade.php)
//
// Extracted from buyer-register-modal.js so the account page doesn't
// re-implement the API-calling code. Self-initializing: does nothing on a page
// that has no #province select.
//
// Expected DOM contract (both hosts provide it):
//   #province / #municipality / #barangay   cascading selects (API mode)
//   #apiAddressFields / #manualAddressFields  mode containers
//   #toggleManualAddress                     mode toggle button
//   #addressMode                             hidden input: 'api' | 'manual'
//   #provinceNameField / #municipalityNameField / #barangayNameField
//                                            hidden inputs carrying names
// Optional (registration modal only, absent on the account page):
//   #buyerModalOverlay                       open-modal trigger for lazy loading
(function () {
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

    /**
     * Wire the cascading dropdowns + mode toggle on one address block.
     * Returns the API used by hosts (e.g. to preselect saved values).
     */
    function initAddressLookup(root) {
        if (!root || root.dataset.lookupInit === '1') return null;
        root.dataset.lookupInit = '1';

        var provinceSelect = root.querySelector('#province');
        var municipalitySelect = root.querySelector('#municipality');
        var barangaySelect = root.querySelector('#barangay');
        var apiFields = root.querySelector('#apiAddressFields');
        var manualFields = root.querySelector('#manualAddressFields');
        var manualProvince = root.querySelector('#manualProvince');
        var manualMunicipality = root.querySelector('#manualMunicipality');
        var manualBarangay = root.querySelector('#manualBarangay');
        var toggleManualBtn = root.querySelector('#toggleManualAddress');
        var addressModeField = root.querySelector('#addressMode');
        var provinceNameField = root.querySelector('#provinceNameField');
        var municipalityNameField = root.querySelector('#municipalityNameField');
        var barangayNameField = root.querySelector('#barangayNameField');

        var missing = [provinceSelect, municipalitySelect, barangaySelect, apiFields,
            manualFields, toggleManualBtn, addressModeField,
            provinceNameField, municipalityNameField, barangayNameField,
        ].filter(function (el) { return !el; });
        if (missing.length) return null; // not an address page

        var provincesLoaded = false;
        var manualMode = false;
        var manualToggles = [];

        function loadProvinces() {
            fetch(PSGC_BASE + '/provinces/')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                    populateSelect(provinceSelect, data, 'Select province');
                    provincesLoaded = true;
                    if (api.pendingProvince) {
                        provinceSelect.value = api.pendingProvince;
                        provinceSelect.dispatchEvent(new Event('change'));
                        api.pendingProvince = null;
                    }
                })
                .catch(function () {
                    provinceSelect.innerHTML = '<option value="" disabled selected>Could not load — try manual entry</option>';
                });
        }

        provinceSelect.addEventListener('change', function () {
            provinceNameField.value = provinceSelect.selectedOptions[0] ? provinceSelect.selectedOptions[0].textContent : '';
            municipalityNameField.value = '';
            barangayNameField.value = '';
            municipalitySelect.disabled = true;
            barangaySelect.disabled = true;
            municipalitySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';
            barangaySelect.innerHTML = '<option value="" disabled selected>Select municipality first</option>';
            fetch(PSGC_BASE + '/provinces/' + provinceSelect.value + '/cities-municipalities/')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                    populateSelect(municipalitySelect, data, 'Select municipality/city');
                    municipalitySelect.disabled = false;
                    if (api.pendingMunicipality) {
                        municipalitySelect.value = api.pendingMunicipality;
                        municipalitySelect.dispatchEvent(new Event('change'));
                        api.pendingMunicipality = null;
                    }
                })
                .catch(function () {
                    municipalitySelect.innerHTML = '<option value="" disabled selected>Could not load</option>';
                });
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
                    if (api.pendingBarangay) {
                        barangaySelect.value = api.pendingBarangay;
                        barangayNameField.value = barangaySelect.selectedOptions[0] ? barangaySelect.selectedOptions[0].textContent : '';
                        api.pendingBarangay = null;
                    }
                })
                .catch(function () {
                    barangaySelect.innerHTML = '<option value="" disabled selected>Could not load</option>';
                });
        });

        barangaySelect.addEventListener('change', function () {
            barangayNameField.value = barangaySelect.selectedOptions[0] ? barangaySelect.selectedOptions[0].textContent : '';
        });

        function setManualMode(on) {
            manualMode = on;
            apiFields.classList.toggle('hidden', on);
            manualFields.classList.toggle('hidden', !on);
            toggleManualBtn.textContent = on ? 'Use address lookup instead' : 'Enter address manually';
            addressModeField.value = on ? 'manual' : 'api';
            // Dropdowns required only in API mode; street is ALWAYS required (HTML required attr).
            [provinceSelect, municipalitySelect, barangaySelect].forEach(function (el) { el.required = !on; });
            // Mirror the manual free-text into the hidden *_name fields so the
            // backend contract ('province_name' etc.) is identical in both modes
            // — the *_name_manual inputs are display-only.
            if (on) {
                var sync = function () {
                    provinceNameField.value = manualProvince ? manualProvince.value : '';
                    municipalityNameField.value = manualMunicipality ? manualMunicipality.value : '';
                    barangayNameField.value = manualBarangay ? manualBarangay.value : '';
                };
                [manualProvince, manualMunicipality, manualBarangay].forEach(function (el) {
                    if (el) {
                        el.addEventListener('input', sync);
                        el.addEventListener('change', sync);
                    }
                });
                sync();
            }
            manualToggles.forEach(function (fn) { fn(on); });
        }

        toggleManualBtn.addEventListener('click', function () { setManualMode(!manualMode); });

        var api = {
            provincesLoaded: function () { return provincesLoaded; },
            ensureLoaded: function () { if (!provincesLoaded) loadProvinces(); },
            setManualMode: setManualMode,
            isManualMode: function () { return manualMode; },
            onManualToggle: function (fn) { manualToggles.push(fn); },
            pendingProvince: null,
            pendingMunicipality: null,
            pendingBarangay: null,
            /**
             * Preselect saved PSGC selections once the province list loads.
             * Pass codes ({province, municipality, barangay}) and, when the
             * host starts in manual mode, nothing else is needed — the manual
             * inputs are plain server-rendered values.
             */
            preselect: function (codes) {
                if (!codes) return;
                if (codes.province) {
                    api.pendingProvince = codes.province;
                    if (provincesLoaded) {
                        provinceSelect.value = codes.province;
                        provinceSelect.dispatchEvent(new Event('change'));
                        api.pendingProvince = null;
                    } else {
                        loadProvinces();
                    }
                }
                if (codes.municipality) api.pendingMunicipality = codes.municipality;
                if (codes.barangay) api.pendingBarangay = codes.barangay;
            },
        };

        return api;
    }

    window.zfAddressLookup = { init: initAddressLookup };
})();
