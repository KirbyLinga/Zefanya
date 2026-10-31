import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const base = process.env.RIDER_WEB_BASE || 'http://127.0.0.1:8127';
assert.ok(['127.0.0.1', 'localhost'].includes(new URL(base).hostname), 'Use an isolated local server.');
const previewDir = process.env.RIDER_WEB_PREVIEW_DIR;
assert.ok(previewDir, 'Set RIDER_WEB_PREVIEW_DIR to the isolated preview directory.');
const screenshots = path.join(previewDir, 'screenshots');
fs.mkdirSync(screenshots, { recursive: true });
const mailLog = path.join(previewDir, 'mail.log');
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const errors = [];
page.on('pageerror', error => errors.push(error.message));
let checks = 0;
const runId = String(Date.now()).slice(-8);
const riderEmail = `browser.rider.${runId}@example.com`;
const riderPhone = '09' + runId.padStart(9, '0');
const riderLicense = 'N01-23-' + runId.slice(-6);
const riderPlate = 'WEB' + runId;
const checked = () => { checks++; };
const pdf = { name: 'document.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\nPreview document') };

async function personal(email = riderEmail) {
    await page.locator('#riderFirstName').fill('Pedro Jose');
    await page.locator('#riderLastName').fill('Garcia');
    await page.locator('#riderMiddleInitial').fill('J');
    await page.locator('#riderSex').selectOption('male');
    await page.locator('#riderEmail').fill(email);
    await page.locator('#riderPassword').fill('Rider@2026');
    await page.locator('#riderPasswordConfirmation').fill('Rider@2026');
    await page.locator('#riderContactNo').fill(riderPhone);
    await page.locator('#riderBirthMonth').selectOption('03');
    await page.locator('#riderBirthDay').selectOption('22');
    await page.locator('#riderBirthYear').selectOption('1995');
    await page.locator('#riderNextStep1').click();
    await page.locator('#riderProvince').waitFor({ state: 'visible' });
}

async function address() {
    await page.waitForFunction(() => document.querySelector('#riderProvince').options.length > 1);
    await page.locator('#riderProvince').selectOption('0300000000');
    await page.waitForFunction(() => document.querySelector('#riderMunicipality').options.length > 1);
    await page.locator('#riderMunicipality').selectOption('city-1');
    await page.waitForFunction(() => document.querySelector('#riderBarangay').options.length > 1);
    await page.locator('#riderBarangay').selectOption('barangay-1');
    await page.locator('#riderStreet').fill('123 Rizal Street');
    await page.locator('#riderHouseNumber').fill('12');
    await page.locator('#riderAddressDetail').fill('Near the town plaza');
    await page.locator('#riderPostalCode').fill('4030');
    await page.locator('#riderNextStep2').click();
    await page.locator('#riderLicenseNo').waitFor({ state: 'visible' });
}

async function verification() {
    await page.locator('#riderLicenseNo').fill(riderLicense);
    await page.locator('#riderLicenseExpiry').fill('2030-03-22');
    await page.locator('#riderPlateNumber').fill(riderPlate);
    await page.locator('#riderVehicleType').selectOption('Motorcycle');
    await page.locator('#riderLogisticsProvider').selectOption({ label: 'Preview Logistics' });
    await page.locator('#rider-drivers_license').setInputFiles(pdf);
    await page.locator('#rider-or_cr').setInputFiles(pdf);
    assert.equal(await page.locator('#riderReviewName').innerText(), 'Pedro Jose J Garcia');
    assert.equal(await page.locator('#riderReviewEmail').innerText(), riderEmail);
    assert.equal(await page.locator('#riderReviewAddress').innerText(), 'Barangay Uno, Lipa City, Batangas, 123 Rizal Street, 12, Near the town plaza, 4030');
    await page.locator('#riderTerms').check();
}

async function enterOtp(code) {
    for (const [index, digit] of [...code].entries()) {
        await page.locator(`[data-rider-otp-index="${index}"]`).fill(digit);
    }
}

try {
    await page.clock.install();
    await page.route('https://psgc.gitlab.io/api/**', route => {
        const url = new URL(route.request().url());
        let body = [];
        if (url.pathname === '/api/provinces/') body = [{ code: '0300000000', name: 'Batangas' }];
        else if (url.pathname === '/api/provinces/0300000000/cities-municipalities/') body = [{ code: 'city-1', name: 'Lipa City' }];
        else if (url.pathname === '/api/cities-municipalities/city-1/barangays/') body = [{ code: 'barangay-1', name: 'Barangay Uno' }];
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
    });

    const initial = await page.goto(base + '/register');
    assert.equal(initial.headers()['x-zefanya-isolated-preview'], 'rider-registration', 'Use the isolated preview router.');
    for (const role of ['buyer', 'seller', 'logistics', 'rider']) {
        assert.equal(await page.locator(`[data-${role}-register-trigger]`).count(), 1);
    }
    checked();
    await page.screenshot({ path: path.join(screenshots, 'register-desktop.png'), fullPage: true });
    await page.locator('[data-rider-register-trigger]').click();
    await page.locator('#riderFirstName').waitFor({ state: 'visible' });
    assert.equal(await page.locator('#riderModalOverlay').getAttribute('aria-hidden'), 'false');
    checked();
    await page.locator('#riderNextStep1').click();
    assert.equal(await page.locator('#riderFirstName').getAttribute('aria-invalid'), 'true');
    checked();
    await page.locator('#riderPassword').fill('Rider@2026');
    await page.locator('[data-toggle-visibility="riderPassword"]').click();
    assert.equal(await page.locator('#riderPassword').getAttribute('type'), 'text');
    await page.locator('[data-toggle-visibility="riderPassword"]').click();
    assert.equal(await page.locator('#riderPassword').getAttribute('type'), 'password');
    checked();

    await personal();
    await address();
    await verification();
    const submitted = page.waitForResponse(response => response.url().endsWith('/register/rider') && response.request().method() === 'POST');
    await page.locator('#riderSubmitRegistration').click();
    const response = await submitted;
    assert.equal(response.status(), 201, await response.text());
    const registration = await response.json();
    await page.locator('#riderOtpPanel').waitFor({ state: 'visible' });
    assert.equal(await page.locator('#riderPassword').inputValue(), '');
    checked();

    await enterOtp('000000');
    const wrong = page.waitForResponse(result => result.url().includes('/verify-otp'));
    await page.locator('#riderOtpSubmit').click();
    assert.equal((await wrong).status(), 422);
    await page.locator('#riderOtpErrorSummary').filter({ hasText: 'does not match' }).waitFor();
    checked();

    for (let attempt = 0; attempt < 3; attempt++) {
        await page.clock.fastForward(61000);
        const resent = page.waitForResponse(result => result.url().includes('/resend-otp'));
        await page.locator('#riderOtpResendBtn').click();
        assert.equal((await resent).status(), 200);
        await page.waitForFunction(() => document.querySelector('#riderOtpResendStatus').textContent.includes('s'));
    }
    await page.clock.fastForward(61000);
    const limited = page.waitForResponse(result => result.url().includes('/resend-otp'));
    await page.locator('#riderOtpResendBtn').click();
    assert.equal((await limited).status(), 429);
    checked();

    const matches = [...fs.readFileSync(mailLog, 'utf8').matchAll(/^([1-9]\d{5})\s*$/gm)];
    assert.ok(matches.length, 'OTP was logged by the isolated mail transport.');
    await enterOtp(matches.at(-1)[1]);
    const verified = page.waitForResponse(result => result.url().includes('/verify-otp'));
    await page.locator('#riderOtpSubmit').click();
    assert.equal((await verified).status(), 200);
    await page.locator('#riderPendingSection').waitFor({ state: 'visible' });
    assert.match(await page.locator('#riderPendingSection').innerText(), /pending logistics approval/i);
    checked();
    await page.screenshot({ path: path.join(screenshots, 'rider-pending.png'), fullPage: true });
    await page.locator('#riderModalClose').click();

    for (const role of ['buyer', 'seller', 'logistics']) {
        await page.locator(`[data-${role}-register-trigger]`).click();
        await page.locator(`#${role}ModalOverlay`).waitFor({ state: 'visible' });
        await page.locator(`#${role}ModalClose`).click();
        await page.locator(`#${role}ModalOverlay`).waitFor({ state: 'hidden' });
    }
    checked();

    await page.reload();
    await page.locator('[data-rider-register-trigger]').click();
    await personal();
    await address();
    await verification();
    const duplicate = page.waitForResponse(result => result.url().endsWith('/register/rider') && result.request().method() === 'POST');
    await page.locator('#riderSubmitRegistration').click();
    assert.equal((await duplicate).status(), 422);
    await page.locator('#riderEmail').getAttribute('aria-invalid').then(value => assert.equal(value, 'true'));
    assert.equal(await page.locator('#riderFirstName').inputValue(), 'Pedro Jose');
    assert.equal(await page.locator('#rider-drivers_license').evaluate(input => input.files.length), 1);
    checked();

    for (const width of [390, 320]) {
        await page.setViewportSize({ width, height: 844 });
        await page.goto(base + '/register?open=rider');
        await page.locator('#riderFirstName').waitFor({ state: 'visible' });
        assert.equal(await page.locator('#riderModalOverlay').evaluate(element => element.scrollWidth <= element.clientWidth), true);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
        await page.screenshot({ path: path.join(screenshots, `rider-mobile-${width}.png`), fullPage: true });
        await personal();
        assert.equal(await page.locator('#riderModalOverlay').evaluate(element => element.scrollWidth <= element.clientWidth), true);
        await page.screenshot({ path: path.join(screenshots, `rider-address-mobile-${width}.png`), fullPage: true });
        await address();
        await page.screenshot({ path: path.join(screenshots, `rider-verification-mobile-${width}.png`), fullPage: true });
        await page.locator('#riderModalClose').click();
        checked();
    }
    assert.deepEqual(errors, []);
    checked();
    console.log(JSON.stringify({ result: 'passed', checks, screenshots, riderId: registration.data.rider_id }));
} catch (error) {
    await page.screenshot({ path: path.join(screenshots, 'failure.png'), fullPage: true });
    console.log(JSON.stringify({ debugMessage: await page.locator('#riderMessage').textContent(), debugOtp: await page.locator('#riderOtpFieldError').textContent(), errors }));
    throw error;
} finally {
    await browser.close();
}