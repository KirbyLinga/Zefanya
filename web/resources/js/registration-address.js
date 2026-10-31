// The registration forms share one cascade; stale responses never restore cleared children.
export function registrationAddress({ region, province, city, barangay, names, base, fetcher = fetch, initial = [] }) {
    const fields = [region, province, city, barangay];
    const labels = ['region', 'province', 'city / municipality', 'barangay'];
    const versions = [0, 0, 0, 0];
    let loaded = false;
    let loading = false;
    const changes = [];

    function reset(index, label = `Select ${labels[index]}`) {
        versions[index]++;
        fields[index].replaceChildren(new Option(label, ''));
        fields[index].disabled = true;
        if (names[index]) names[index].value = '';
    }

    async function load(index, path) {
        reset(index, 'Loading...');
        const version = versions[index];
        try {
            const response = await fetcher(`${base}${path}`);
            if (!response.ok) throw new Error('Location lookup failed');
            const data = await response.json();
            if (!Array.isArray(data)) throw new Error('Invalid locations');
            if (version !== versions[index]) return false;
            fields[index].replaceChildren(new Option(`Select ${labels[index]}`, ''));
            data.sort((a, b) => a.name.localeCompare(b.name)).forEach(item => fields[index].add(new Option(item.name, item.code)));
            fields[index].disabled = false;
            return true;
        } catch {
            if (version === versions[index]) reset(index, 'Could not load. Select the parent again to retry.');
            return false;
        }
    }

    fields.forEach((field, index) => {
        changes[index] = async () => {
            if (names[index]) names[index].value = field.value ? field.selectedOptions[0]?.textContent || '' : '';
            for (let child = index + 1; child < fields.length; child++) reset(child);
            if (!field.value || index === 3) return;
            if (index === 0) {
                if (['130000000', '1300000000'].includes(field.value)) {
                    province.add(new Option('Metro Manila', field.value));
                    province.disabled = false;
                    return;
                }
                await load(1, `/regions/${field.value}/provinces/`);
                // NCR has no provinces; use its region as the province selector.
                if (!province.disabled && province.options.length === 1) {
                    province.add(new Option(field.selectedOptions[0].textContent, field.value));
                }
            } else if (index === 1) {
                const path = province.value === region.value ? `/regions/${region.value}` : `/provinces/${province.value}`;
                await load(2, `${path}/cities-municipalities/`);
            } else {
                await load(3, `/cities-municipalities/${city.value}/barangays/`);
            }
        };
        field.addEventListener('change', changes[index]);
    });
    for (let index = 1; index < fields.length; index++) reset(index);

    region.form?.addEventListener('reset', () => {
        // Invalidate requests immediately, then clear names after the native reset.
        for (let index = 1; index < fields.length; index++) reset(index);
        queueMicrotask(() => {
            for (let index = 1; index < fields.length; index++) reset(index);
        });
    });

    return async function start() {
        if (loaded || loading) return;
        loading = true;
        loaded = await load(0, '/regions/');
        if (loaded) {
            for (let index = 0; index < fields.length; index++) {
                const value = String(initial[index] || '');
                if (!value || !Array.from(fields[index].options).some(option => option.value === value)) break;
                fields[index].value = value;
                await changes[index]();
            }
        }
        loading = false;
    };
}
