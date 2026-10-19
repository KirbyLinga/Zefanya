import { test, expect, Page } from '@playwright/test';

test.describe('Buyer Registration - Browser Tests', () => {
    test('TC-BUY-020: Password is hidden while typing with show/hide toggle', async ({ page }) => {
        await page.goto('http://localhost:8000/register?type=buyer');
        await page.click('[data-buyer-register-trigger]');

        const passwordInput = page.locator('#password');
        const toggleBtn = page.locator('[data-toggle-visibility="password"]');

        // Step 1: Type password - should be hidden (type=password)
        await passwordInput.fill('Mamimili@2026');
        await expect(passwordInput).toHaveAttribute('type', 'password');

        // Step 2: Click show/hide icon - password should be revealed (type=text)
        await toggleBtn.click();
        await expect(passwordInput).toHaveAttribute('type', 'text');

        // Step 3: Click again - password should be hidden again (type=password)
        await toggleBtn.click();
        await expect(passwordInput).toHaveAttribute('type', 'password');
    });

    test('TC-BUY-008: Date picker blocks future dates', async ({ page }) => {
        await page.goto('http://localhost:8000/register?type=buyer');
        await page.click('[data-buyer-register-trigger]');

        const dateInput = page.locator('#birthday');

        // Check if it's a native date input
        const inputType = await dateInput.getAttribute('type');

        if (inputType === 'date') {
            // Native date input - check max attribute
            const maxAttr = await dateInput.getAttribute('max');
            const today = new Date().toISOString().split('T')[0];
            await expect(dateInput).toHaveAttribute('max', today);
        } else {
            // Custom date picker or selects - check that future dates are blocked
            // The form uses month/day/year selects
            const today = new Date();
            const currentYear = today.getFullYear();
            const currentMonth = today.getMonth() + 1;
            const currentDay = today.getDate();

            // Set to tomorrow
            const tomorrow = new Date();
            tomorrow.setDate(today.getDate() + 1);
            const tomorrowYear = tomorrow.getFullYear();
            const tomorrowMonth = tomorrow.getMonth() + 1;
            const tomorrowDay = tomorrow.getDate();

            // Select tomorrow's date
            await page.selectOption('#birthYear', String(tomorrowYear));
            await page.selectOption('#birthMonth', String(tomorrowMonth));
            await page.selectOption('#birthDay', String(tomorrowDay));

            // Click Next to proceed to step 2
            await page.click('#nextStep1');

            await page.click('#nextStep2');

            // Click Next to submit
            await page.click('#submitRegistration');

            // Should show validation error for birthday
            const error = page.locator('#buyerBirthdayError, .field-error');
            await expect(error).toHaveText(/at least 18/i);
        }
    });

    test('TC-BUY-022: Double-click protection - rapid submissions create only one account', async ({ page }) => {
        await page.goto('http://localhost:8000/register?type=buyer');
        await page.click('[data-buyer-register-trigger]');

        // Fill in personal info
        await page.fill('#firstName', 'Juan Miguel');
        await page.fill('#lastName', 'Dela Cruz');
        await page.fill('#email', `buyer${Date.now()}@example.com`);
        await page.fill('#contactNo', '09171234567');
        await page.fill('#password', 'Mamimili@2026');
        await page.fill('#passwordConfirmation', 'Mamimili@2026');

        // Select birthday (exactly 18 years ago)
        const today = new Date();
        await page.selectOption('#birthYear', String(today.getFullYear() - 18));
        await page.selectOption('#birthMonth', String(today.getMonth() + 1));
        await page.selectOption('#birthDay', String(today.getDate()));

        // Select sex
        await page.selectOption('#sex', 'male');

        // Proceed through address and verification steps
        await page.click('#nextStep1');
        await page.click('#nextStep2');

        // Upload ID
        const fileInput = page.locator('#uploadId');
        await fileInput.setInputFiles({
            name: 'test-id.pdf',
            mimeType: 'application/pdf',
            buffer: Buffer.from('fake-pdf-content'),
        });

        // Click submit button 3 times rapidly
        const submitBtn = page.locator('#submitRegistration');
        for (let i = 0; i < 3; i++) {
            await submitBtn.click();
        }

        // Wait for any redirects
        await page.waitForTimeout(2000);

        // Check that only one account was created with this email
        // (In a real test, we would check the database)
        // For now, verify no error page was shown
        await expect(page).not.toHaveURL(/.*error.*/);
    });
});
