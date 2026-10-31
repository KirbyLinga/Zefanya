import { accountAddress } from './account-address.js';

// resources/js/buyer/address-lookup.js
// Shared PH address cascading dropdowns (PSGC API).
//
// Supports:
//   - Region -> Province -> City/Municipality -> Barangay (when region select exists)
//   - Province -> City/Municipality -> Barangay (when region select is omitted)
(function () {

    function populateSelect(select, items, placeholder) {
        if (!select) return;
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
     * Wire the cascading dropdowns (+ optional mode toggle) on one address block.
     * Returns the API used by hosts (e.g. to preselect saved values).
     */
    function initAddressLookup(root) {
        if (!root || root.dataset.lookupInit === '1') return null;
        root.dataset.lookupInit = '1';
        var PSGC_BASE = (root.dataset.psgcBase || 'https://psgc.gitlab.io/api').replace(/\/+$/, '');

        var regionSelect = root.querySelector('[data-address-region]') || root.querySelector('#region');
        var provinceSelect = root.querySelector('[data-address-province]') || root.querySelector('#province');
        var municipalitySelect = root.querySelector('[data-address-municipality]') || root.querySelector('#municipality');
        var barangaySelect = root.querySelector('[data-address-barangay]') || root.querySelector('#barangay');

        var regionNameField = root.querySelector('[data-address-region-name]') || root.querySelector('#regionNameField');
        var provinceNameField = root.querySelector('[data-address-province-name]') || root.querySelector('#provinceNameField');
        var municipalityNameField = root.querySelector('[data-address-municipality-name]') || root.querySelector('#municipalityNameField');
        var barangayNameField = root.querySelector('[data-address-barangay-name]') || root.querySelector('#barangayNameField');

        // Required minimal dropdowns
        var missing = [provinceSelect, municipalitySelect, barangaySelect].filter(function (el) { return !el; });
        if (missing.length) return null; // not an address block

        var regionsLoaded = false;
        var provincesLoaded = false;

        function loadRegions() {
            if (!regionSelect) return;
            fetch(PSGC_BASE + '/regions/')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                    populateSelect(regionSelect, data, 'Select region');
                    regionsLoaded = true;
                    if (api.pendingRegion) {
                        regionSelect.value = api.pendingRegion;
                        regionSelect.dispatchEvent(new Event('change'));
                        api.pendingRegion = null;
                    }
                })
                .catch(function () {
                    regionSelect.innerHTML = '<option value="" disabled selected>Could not load regions</option>';
                });
        }

        function loadProvincesForRegion(regionCode) {
            if (!provinceSelect) return;
            provinceSelect.disabled = true;
            municipalitySelect.disabled = true;
            barangaySelect.disabled = true;
            provinceSelect.innerHTML = '<option value="" disabled selected>Loading provinces...</option>';
            municipalitySelect.innerHTML = '<option value="" disabled selected>Select province first</option>';
            barangaySelect.innerHTML = '<option value="" disabled selected>Select municipality first</option>';

            // NCR special handling: Metro Manila has no provinces in PSGC API
            if (regionCode === '130000000') {
                populateSelect(provinceSelect, [{ code: '130000000', name: 'Metro Manila' }], 'Select province');
                provinceSelect.disabled = false;
                provinceSelect.value = '130000000';
                if (provinceNameField) provinceNameField.value = 'Metro Manila';
                loadCitiesForProvince('130000000', '130000000');
                provincesLoaded = true;
                return;
            }

            var url = regionCode ? (PSGC_BASE + '/regions/' + regionCode + '/provinces/') : (PSGC_BASE + '/provinces/');
            fetch(url)
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                    populateSelect(provinceSelect, data, 'Select province');
                    provinceSelect.disabled = false;
                    provincesLoaded = true;
                    if (api.pendingProvince) {
                        provinceSelect.value = api.pendingProvince;
                        provinceSelect.dispatchEvent(new Event('change'));
                        api.pendingProvince = null;
                    }
                })
                .catch(function () {
                    provinceSelect.innerHTML = '<option value="" disabled selected>Could not load provinces</option>';
                });
        }

        function loadCitiesForProvince(provinceCode, regionCode) {
            if (!municipalitySelect) return;
            municipalitySelect.disabled = true;
            barangaySelect.disabled = true;
            municipalitySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';
            barangaySelect.innerHTML = '<option value="" disabled selected>Select municipality first</option>';

            var url = (provinceCode === '130000000' || regionCode === '130000000')
                ? (PSGC_BASE + '/regions/130000000/cities-municipalities/')
                : (PSGC_BASE + '/provinces/' + provinceCode + '/cities-municipalities/');

            fetch(url)
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
        }

        function loadBarangaysForCity(cityCode) {
            if (!barangaySelect) return;
            barangaySelect.disabled = true;
            barangaySelect.innerHTML = '<option value="" disabled selected>Loading...</option>';
            fetch(PSGC_BASE + '/cities-municipalities/' + cityCode + '/barangays/')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    data.sort(function (a, b) { return a.name.localeCompare(b.name); });
                    populateSelect(barangaySelect, data, 'Select barangay');
                    barangaySelect.disabled = false;
                    if (api.pendingBarangay) {
                        barangaySelect.value = api.pendingBarangay;
                        if (barangayNameField) {
                            barangayNameField.value = barangaySelect.selectedOptions[0] ? barangaySelect.selectedOptions[0].textContent : '';
                        }
                        api.pendingBarangay = null;
                    }
                })
                .catch(function () {
                    barangaySelect.innerHTML = '<option value="" disabled selected>Could not load</option>';
                });
        }

        function loadProvinces() {
            if (regionSelect) {
                loadRegions();
            } else {
                loadProvincesForRegion(null);
            }
        }

        if (regionSelect) {
            regionSelect.addEventListener('change', function () {
                if (regionNameField) {
                    regionNameField.value = regionSelect.selectedOptions[0] ? regionSelect.selectedOptions[0].textContent : '';
                }
                if (provinceNameField) provinceNameField.value = '';
                if (municipalityNameField) municipalityNameField.value = '';
                if (barangayNameField) barangayNameField.value = '';
                loadProvincesForRegion(regionSelect.value);
            });
        }

        provinceSelect.addEventListener('change', function () {
            if (provinceNameField) {
                provinceNameField.value = provinceSelect.selectedOptions[0] ? provinceSelect.selectedOptions[0].textContent : '';
            }
            if (municipalityNameField) municipalityNameField.value = '';
            if (barangayNameField) barangayNameField.value = '';
            var rCode = regionSelect ? regionSelect.value : null;
            loadCitiesForProvince(provinceSelect.value, rCode);
        });

        municipalitySelect.addEventListener('change', function () {
            if (municipalityNameField) {
                municipalityNameField.value = municipalitySelect.selectedOptions[0] ? municipalitySelect.selectedOptions[0].textContent : '';
            }
            if (barangayNameField) barangayNameField.value = '';
            loadBarangaysForCity(municipalitySelect.value);
        });

        barangaySelect.addEventListener('change', function () {
            if (barangayNameField) {
                barangayNameField.value = barangaySelect.selectedOptions[0] ? barangaySelect.selectedOptions[0].textContent : '';
            }
        });

        var api = {
            provincesLoaded: function () { return provincesLoaded; },
            ensureLoaded: function () { if (!provincesLoaded) loadProvinces(); },
            pendingRegion: null,
            pendingProvince: null,
            pendingMunicipality: null,
            pendingBarangay: null,
            preselect: function (codes) {
                if (!codes) return;
                if (codes.region && regionSelect) {
                    api.pendingRegion = codes.region;
                }
                if (codes.province) {
                    api.pendingProvince = codes.province;
                }
                if (codes.municipality) api.pendingMunicipality = codes.municipality;
                if (codes.barangay) api.pendingBarangay = codes.barangay;

                if (regionSelect && !regionsLoaded) {
                    loadRegions();
                } else if (!provincesLoaded) {
                    loadProvinces();
                }
            },
        };

        var initialMunicipality = root.dataset.selectedMunicipality || null;
        var initialBarangay = root.dataset.selectedBarangay || null;

        // If locked province is specified on root (e.g. station hub under province hub)
        var lockedProv = root.dataset.lockedProvinceCode;
        if (lockedProv) {
            if (initialMunicipality) api.pendingMunicipality = initialMunicipality;
            if (initialBarangay) api.pendingBarangay = initialBarangay;
            loadCitiesForProvince(lockedProv, root.dataset.lockedRegionCode || null);
        } else if (root.dataset.autoLoad !== 'false' && !root.closest('#buyerModalOverlay')) {
            var initialRegion = root.dataset.selectedRegion || null;
            var initialProvince = root.dataset.selectedProvince || null;

            if (initialRegion) {
                api.pendingRegion = initialRegion;
                if (initialProvince) api.pendingProvince = initialProvince;
                if (initialMunicipality) api.pendingMunicipality = initialMunicipality;
                if (initialBarangay) api.pendingBarangay = initialBarangay;
                loadRegions();
            } else {
                loadProvinces();
            }
        }

        return api;
    }

    // Auto-init on elements with data-address-lookup
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-account-address]').forEach(root => accountAddress(root));
        document.querySelectorAll('[data-address-lookup]').forEach(function (el) {
            initAddressLookup(el);
        });
    });

    window.zfAddressLookup = { init: initAddressLookup };
})();
