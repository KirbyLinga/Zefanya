import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Real form submissions: use only the isolated local hub preview database/router.
const base = process.env.HUBS_UI_BASE || 'http://127.0.0.1:8134';
assert.ok(['127.0.0.1', 'localhost'].includes(new URL(base).hostname));
const previewDir = process.env.HUBS_UI_PREVIEW_DIR;
assert.ok(previewDir, 'Set HUBS_UI_PREVIEW_DIR for isolated screenshots.');
const screenshots = path.join(previewDir, 'screenshots');
fs.mkdirSync(screenshots, { recursive: true });
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let checks = 0;
const run = String(Date.now()).slice(-8);
const provinceName = 'Browser Province ' + run;
const stationName = 'Browser Station ' + run;
const items = {
    '/regions/': [{ code: '030000000', name: 'Region III' }],
    '/regions/030000000/provinces/': [{ code: '030540000', name: 'Pampanga' }, { code: '030630000', name: 'Tarlac' }],
    '/provinces/030540000/cities-municipalities/': [{ code: '030540100', name: 'San Fernando' }, { code: '030540200', name: 'Angeles' }],
    '/provinces/030630000/cities-municipalities/': [{ code: '030630100', name: 'Tarlac City' }],
    '/cities-municipalities/030540100/barangays/': [{ code: '0305401001', name: 'Barangay 1' }],
    '/cities-municipalities/030540200/barangays/': [{ code: '0305402001', name: 'Barangay 1' }],
    '/cities-municipalities/030630100/barangays/': [{ code: '0306301001', name: 'Barangay 1' }],
};
await page.route('https://psgc.gitlab.io/api/**', route => {
    const key = new URL(route.request().url()).pathname.replace('/api', '');
    assert.ok(items[key], 'Unexpected location request: ' + key);
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify(items[key]) });
});
const formAt = id => page.locator('#' + id + ' form[data-address-lookup]');
async function submit(form) {
    await Promise.all([page.waitForNavigation(), form.locator('[type="submit"]').click()]);
}
async function location(form, province = true) {
    if (province) {
        await form.locator('[name="region_code"]').selectOption('030000000');
        await form.locator('[name="province_code"]').selectOption('030540000');
    }
    await form.locator('[name="city_municipality_code"]').selectOption('030540100');
    await form.locator('[name="barangay_code"]').selectOption('0305401001');
}
async function selected(form, name, value) {
    await page.waitForFunction(({ selector, name, value }) => document.querySelector(selector)?.querySelector(`[name="${name}"]`)?.value === value,
        { selector: await form.evaluate(element => '#' + element.closest('details').id + ' form[data-address-lookup]'), name, value });
}

try {
    const initial = await page.goto(base + '/logistics/hubs');
    assert.equal(initial.status(), 200);
    assert.equal(initial.headers()['x-zefanya-isolated-preview'], 'hubs', 'Use the isolated preview router.');
    assert.equal(await page.locator('[data-hubs-page] details[open]').count(), 0);
    assert.ok(await page.getByRole('heading', { name: 'Hubs and Stations', exact: true }).count());
    assert.match(await page.locator('[data-hubs-page]').innerText(), /Preview Logistics/);
    checks++;
    await page.screenshot({ path: path.join(screenshots, 'hubs-desktop.png'), fullPage: true });

    await page.locator('[data-hub-open="province-create"]').first().click();
    const provinceForm = formAt('province-create');
    assert.equal(await page.locator('#province-create').getAttribute('open'), '');
    await provinceForm.locator('[name="name"]').fill(provinceName);
    await location(provinceForm);
    await provinceForm.locator('[name="building"]').fill('Preview warehouse');
    await provinceForm.evaluate(form => { form.noValidate = true; });
    await submit(provinceForm);
    assert.equal(await page.locator('#province-create').getAttribute('open'), '');
    assert.equal(await formAt('province-create').locator('[name="name"]').inputValue(), provinceName);
    await selected(formAt('province-create'), 'barangay_code', '0305401001');
    assert.match(await page.locator('[role="alert"]').first().innerText(), /street address field is required/i);
    checks++;
    await formAt('province-create').locator('[name="street"]').fill('246 Preview Avenue');
    await formAt('province-create').locator('[name="landmark"]').fill('Test landmark');
    await formAt('province-create').locator('[name="postal_code"]').fill('2000');
    await formAt('province-create').locator('[name="location_details"]').fill('Preview notes');
    await submit(formAt('province-create'));
    const createdProvince = page.locator('article').filter({ has: page.getByRole('heading', { name: provinceName, exact: true }) });
    assert.match(await createdProvince.innerText(), /Pending Approval/);
    assert.match(await createdProvince.innerText(), /Inactive/);
    assert.equal(await createdProvince.locator('[data-station-create]').count(), 0);
    checks++;

    const first = page.locator('#station-create-1');
    const second = page.locator('#station-create-2');
    await first.locator(':scope > summary').click();
    await formAt('station-create-1').locator('[name="name"]').fill(stationName);
    await second.locator(':scope > summary').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-station-create][open]').length === 1 && document.querySelector('#station-create-2').open);
    await first.locator(':scope > summary').click();
    await page.waitForFunction(() => document.querySelector('#station-create-1').open && !document.querySelector('#station-create-2').open);
    assert.equal(await formAt('station-create-1').locator('[name="name"]').inputValue(), stationName);
    checks++;
    await location(formAt('station-create-1'), false);
    await formAt('station-create-1').locator('[name="building"]').fill('Station warehouse');
    await formAt('station-create-1').evaluate(form => { form.noValidate = true; });
    await submit(formAt('station-create-1'));
    assert.equal(await page.locator('[data-station-create][open]').count(), 1);
    assert.equal(await page.locator('#station-create-1').getAttribute('open'), '');
    assert.equal(await formAt('station-create-1').locator('[name="name"]').inputValue(), stationName);
    await selected(formAt('station-create-1'), 'barangay_code', '0305401001');
    assert.equal(await formAt('province-create').locator('[name="name"]').inputValue(), '');
    checks++;
    await formAt('station-create-1').locator('[name="street"]').fill('Station Preview Street');
    await submit(formAt('station-create-1'));
    const stationDetails = page.locator('[data-station-details]').filter({ hasText: stationName });
    assert.match(await stationDetails.locator(':scope > summary').innerText(), /Pending Approval/);
    assert.match(await stationDetails.locator(':scope > summary').innerText(), /Inactive/);
    checks++;

    await page.locator('#hub-edit-1 > summary').click();
    const provinceEdit = formAt('hub-edit-1');
    await selected(provinceEdit, 'barangay_code', '0305401001');
    await provinceEdit.locator('[name="street"]').fill('456 Proposed Road');
    await submit(provinceEdit);
    const operational = page.locator('article[aria-labelledby="province-hub-1"]');
    assert.match(await operational.innerText(), /Location change awaiting Admin approval/);
    assert.match(await operational.innerText(), /123 Rizal Street/);
    assert.match(await operational.innerText(), /456 Proposed Road/);
    checks++;

    await page.locator('#station-details-3 > summary').click();
    await page.locator('#hub-edit-3 > summary').click();
    await selected(formAt('hub-edit-3'), 'barangay_code', '0305401001');
    await formAt('hub-edit-3').locator('[name="location_details"]').fill('Updated station notes');
    await submit(formAt('hub-edit-3'));
    await page.locator('#station-details-3 > summary').click();
    assert.match(await page.locator('#station-details-3').innerText(), /Updated station notes/);
    checks++;
    const status = page.locator('#station-details-3 form').filter({ has: page.locator('input[name="is_active"]') });
    await Promise.all([page.waitForNavigation(), status.getByRole('button', { name: 'Deactivate', exact: true }).click()]);
    assert.match(await page.locator('#station-details-3 > summary').innerText(), /Inactive/);
    await page.locator('#station-details-3 > summary').click();
    const activate = page.locator('#station-details-3 form').filter({ has: page.locator('input[name="is_active"]') });
    await Promise.all([page.waitForNavigation(), activate.getByRole('button', { name: 'Activate', exact: true }).click()]);
    assert.match(await page.locator('#station-details-3 > summary').innerText(), /Active/);
    checks++;
    assert.match(await page.locator('article').filter({ hasText: 'Rejected Province Hub' }).innerText(), /Address documentation needs correction/);
    checks++;

    for (const width of [768, 390, 320]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(base + '/logistics/hubs');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(screenshots, `hubs-${width}.png`), fullPage: true });
        await page.locator('[data-hub-open="province-create"]').first().click();
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(screenshots, `province-form-${width}.png`), fullPage: true });
        checks++;
    }
    await page.goto(base + '/logistics/hubs');
    const coverage = page.getByRole('link', { name: 'Municipality coverage', exact: true });
    assert.equal(new URL(await coverage.getAttribute('href'), base).pathname, '/logistics/areas');
    await coverage.click();
    assert.equal(new URL(page.url()).pathname, '/logistics/areas');
    checks++;
    assert.deepEqual(errors, []);
    checks++;
    console.log(JSON.stringify({ result: 'passed', checks, screenshots }));
} catch (error) {
    await page.screenshot({ path: path.join(screenshots, 'failure.png'), fullPage: true });
    throw error;
} finally {
    await browser.close();
}
