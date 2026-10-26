// Area coverage and rider-link controls. All writes remain server validated.
(() => {
    function init(root) {
        const station = root.querySelector('[data-coverage-station]');
        const city = root.querySelector('[data-coverage-municipality]');
        const cityName = root.querySelector('[data-coverage-municipality-name]');
        const list = root.querySelector('[data-coverage-barangays]');
        const search = root.querySelector('[data-barangay-search]');
        const selectAll = root.querySelector('[data-barangay-select-all]');
        const status = root.querySelector('[data-barangay-status]');
        const claims = JSON.parse(root.dataset.claims || '[]');
        const selected = new Set(JSON.parse(list.dataset.selected || '[]').map(String));
        const single = list.dataset.single === '1';
        const base = (root.dataset.apiBase || '').replace(/\/+$/, '');
        let barangays = [];
        let cityVersion = 0;
        let barangayVersion = 0;

        function visible() {
            const term = (search.value || '').trim().toLocaleLowerCase();
            return barangays.filter(item => item.name.toLocaleLowerCase().includes(term));
        }
        function claimed(item) { return claims.find(claim => String(claim.code) === String(item.code)); }
        function render() {
            list.replaceChildren();
            visible().forEach(item => {
                const claim = claimed(item);
                const label = document.createElement('label');
                label.className = 'flex min-h-11 items-center gap-3 rounded-sm px-2 py-2 text-sm text-[var(--text-ink)]';
                const input = document.createElement('input');
                input.type = single ? 'radio' : 'checkbox';
                input.name = single ? 'barangay_code' : 'barangay_codes[]';
                input.value = item.code;
                input.disabled = !!claim;
                input.checked = !claim && selected.has(String(item.code));
                input.className = 'h-5 w-5 shrink-0 accent-primary-800';
                const text = document.createElement('span');
                text.textContent = item.name + (claim ? ': claimed by ' + claim.station : '');
                if (claim) label.classList.add('bg-neutral-100');
                input.addEventListener('change', () => {
                    if (single) selected.clear();
                    if (input.checked) selected.add(String(item.code)); else selected.delete(String(item.code));
                    render();
                });
                label.append(input, text);
                list.append(label);
            });
            // Keep selected values submitted even when hidden by the search filter.
            const visibleCodes = new Set(visible().map(item => String(item.code)));
            selected.forEach(code => {
                if (visibleCodes.has(code)) return;
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = single ? 'barangay_code' : 'barangay_codes[]'; input.value = code;
                list.append(input);
            });
            if (!visible().length) {
                const empty = document.createElement('p');
                empty.className = 'p-2 text-sm text-[var(--text-ink)]';
                empty.textContent = barangays.length ? 'No matching barangays.' : 'Select a municipality to load barangays.';
                list.append(empty);
            }
            if (selectAll) selectAll.disabled = !visible().some(item => !claimed(item));
            status.textContent = selected.size + ' selected. Claimed barangays cannot be selected.';
        }
        async function loadBarangays() {
            const version = ++barangayVersion;
            barangays = []; render();
            if (!city.value) return;
            status.textContent = 'Loading barangays...';
            try {
                const response = await fetch(base + '/logistics/psgc/barangays?city_municipality_code=' + encodeURIComponent(city.value));
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (version !== barangayVersion) return;
                barangays = data.sort((a, b) => a.name.localeCompare(b.name));
                const allowed = new Set(barangays.filter(item => !claimed(item)).map(item => String(item.code)));
                selected.forEach(code => { if (!allowed.has(code)) selected.delete(code); });
                render();
            } catch {
                if (version === barangayVersion) status.textContent = 'Could not load barangays. Select the municipality again to retry.';
            }
        }
        function syncCityName() { cityName.value = city.value ? city.selectedOptions[0].textContent : ''; }
        async function loadCities(initial = '') {
            const version = ++cityVersion;
            ++barangayVersion;
            barangays = []; render();
            city.disabled = true;
            city.replaceChildren(new Option('Loading municipalities...', ''));
            const option = station.selectedOptions[0];
            if (!station.value || !option?.dataset.provinceCode) {
                city.replaceChildren(new Option('Select a Station Hub with a province first', ''));
                return;
            }
            try {
                const response = await fetch(base + '/logistics/psgc/municipalities?province_code=' + encodeURIComponent(option.dataset.provinceCode) + '&region_code=' + encodeURIComponent(option.dataset.regionCode || ''));
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (version !== cityVersion) return;
                city.replaceChildren(new Option('Select municipality/city', ''));
                data.sort((a, b) => a.name.localeCompare(b.name)).forEach(item => city.add(new Option(item.name, item.code)));
                city.disabled = false;
                if (initial && data.some(item => String(item.code) === initial)) city.value = initial;
                syncCityName();
                await loadBarangays();
            } catch {
                if (version === cityVersion) city.replaceChildren(new Option('Could not load municipalities. Select the station again.', ''));
            }
        }
        search.addEventListener('input', render);
        selectAll?.addEventListener('click', () => {
            visible().filter(item => !claimed(item)).forEach(item => selected.add(String(item.code)));
            render();
        });
        station.addEventListener('change', () => { selected.clear(); search.value = ''; loadCities(); });
        city.addEventListener('change', () => { selected.clear(); search.value = ''; syncCityName(); loadBarangays(); });
        root.addEventListener('submit', event => {
            if (!selected.size) {
                event.preventDefault();
                status.textContent = 'Select at least one available barangay.';
                search.focus();
            }
        });
        loadCities(city.dataset.selectedCode || '');
    }
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-coverage-form]').forEach(init);
        document.querySelectorAll('form[data-confirm]').forEach(form => {
            form.addEventListener('submit', event => { if (!window.confirm(form.dataset.confirm)) event.preventDefault(); });
        });
        document.querySelectorAll('[data-rider-link-form]').forEach(form => {
            const rider = form.querySelector('[data-link-rider]');
            const inputs = form.querySelectorAll('[data-link-area]');
            function sync() {
                const station = rider.selectedOptions[0]?.dataset.station;
                inputs.forEach(input => {
                    input.disabled = !station || input.dataset.station !== station;
                    if (input.disabled) input.checked = false;
                    input.closest('label').classList.toggle('bg-neutral-100', input.disabled);
                });
            }
            rider.addEventListener('change', sync);
            sync();
        });
    });
})();
