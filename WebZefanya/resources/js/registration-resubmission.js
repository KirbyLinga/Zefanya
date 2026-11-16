import { registrationAddress } from './registration-address.js';

const address = document.getElementById('resubmissionAddress');
if (address) {
    const field = name => document.getElementById(`resubmit-${name}`);
    const base = 'https://psgc.gitlab.io/api';
    const start = registrationAddress({
        region: field('region_code'), province: field('province'), city: field('municipality'), barangay: field('barangay'),
        names: [null, field('province-name'), field('municipality-name'), field('barangay-name')], base,
        initial: JSON.parse(address.dataset.initial),
    });
    start();

    const sellerType = document.getElementById('resubmitSellerType');
    if (sellerType) {
        const updateDocuments = () => {
            const corporation = sellerType.value === 'corporation';
            const certificate = document.getElementById('resubmitSecCertificate');
            document.getElementById('resubmitSecFields').hidden = !corporation;
            certificate.disabled = !corporation;
            certificate.required = corporation && certificate.dataset.existing !== '1';
        };
        sellerType.addEventListener('change', updateDocuments);
        updateDocuments();
    }
    const areas = document.getElementById('resubmitServiceAreas');
    if (areas) {
        const selected = JSON.parse(areas.dataset.initial);
        areas.disabled = true;
        Promise.all(['/cities-municipalities/', '/provinces/'].map(async path => {
            const response = await fetch(base + path);
            if (!response.ok) throw new Error('Lookup unavailable');
            return response.json();
        })).then(([cities, provinces]) => {
            const provinceNames = new Map(provinces.map(province => [province.code, province.name]));
            cities.sort((a, b) => a.name.localeCompare(b.name)).forEach(city => {
                const province = provinceNames.get(city.provinceCode) || (city.regionCode === '130000000' ? 'Metro Manila' : '');
                areas.add(new Option(city.name + (province ? `, ${province}` : ''), city.code, false, selected.includes(city.code)));
            });
            areas.disabled = false;
        }).catch(() => {
            document.getElementById('resubmissionLookupError').textContent = 'Could not load service areas. Reload this page to try again.';
        });
    }
}
