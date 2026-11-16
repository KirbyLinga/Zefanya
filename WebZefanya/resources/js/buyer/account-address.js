import { registrationAddress } from '../registration-address.js';

export async function accountAddress(root, fetcher = fetch) {
    const region = root.querySelector('#region');
    const province = root.querySelector('#province');
    const city = root.querySelector('#municipality');
    const barangay = root.querySelector('#barangay');
    const base = (root.dataset.psgcBase || 'https://psgc.gitlab.io/api').replace(/\/+$/, '');
    const initial = [region, province, city, barangay].map(field => field.dataset.savedCode || '');

    // Older buyer records store province codes but have no region column.
    if (!initial[0] && initial[1]) {
        if (['130000000', '1300000000'].includes(initial[1])) {
            initial[0] = initial[1];
        } else {
            try {
                const response = await fetcher(`${base}/provinces/${initial[1]}/`);
                if (response.ok) initial[0] = (await response.json()).regionCode || '';
            } catch { /* The region list remains usable if restoring the old address fails. */ }
        }
    }

    const start = registrationAddress({
        region, province, city, barangay, base, fetcher, initial,
        names: [null, root.querySelector('#provinceNameField'), root.querySelector('#municipalityNameField'), root.querySelector('#barangayNameField')],
    });
    await start();
}
