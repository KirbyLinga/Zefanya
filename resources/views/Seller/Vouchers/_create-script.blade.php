<script>
(() => {
    const form = document.getElementById('create-voucher');
    const dialog = document.getElementById('create-voucher-dialog');
    const trigger = document.getElementById('open-create-voucher');
    if (!form || !dialog || !trigger) return;
    const marketplace = form.dataset.marketplace === 'true';
    const shipping = () => marketplace && value('scope') === 'shipping';
    const field = name => form.elements.namedItem(name);
    const value = name => field(name)?.value ?? '';
    const text = (id, content) => { document.getElementById(id).textContent = content; };
    const money = minor => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(minor / 100);
    const minor = name => Math.round(Number(value(name) || 0) * 100);
    const selectedProducts = () => [...form.querySelectorAll('[name="product_ids[]"]:checked')];
    const reviewButton = document.getElementById('review-voucher');
    const confirmButton = document.getElementById('confirm-voucher');
    const review = document.getElementById('voucher-review');
    const details = document.getElementById('voucher-details');
    const summary = document.getElementById('voucher-error-summary');
    let revision = 0;
    let codeSequence = 0;
    let codeRequest;
    let codeTimer;
    let checking = false;
    let operation = 0;
    let previousOverflow = '';
    let previousFocus;
    let submitted = false;
    let reviewedRevision = null;

    function openDialog(event) {
        event?.preventDefault();
        if (dialog.open) return;
        previousFocus = document.activeElement;
        previousOverflow = document.body.style.overflow;
        dialog.showModal();
        document.body.style.overflow = 'hidden';
        field('code').focus({ preventScroll: true });
        if (value('code')) codeTimer = setTimeout(checkCode, 500);
    }

    dialog.addEventListener('close', () => {
        revision++;
        operation++;
        codeSequence++;
        clearTimeout(codeTimer);
        codeRequest?.abort();
        checking = false;
        form.removeAttribute('aria-busy');
        if (!submitted) reviewButton.disabled = confirmButton.disabled = false;
        if (document.getElementById('code-status').textContent.includes('Checking')) text('code-status', '');
        document.body.style.overflow = previousOverflow;
        editDetails();
        const target = previousFocus instanceof HTMLElement && previousFocus !== document.body ? previousFocus : trigger;
        target.focus({ preventScroll: true });
    });
    dialog.addEventListener('keydown', event => {
        if (event.key !== 'Tab') return;
        const focusable = [...dialog.querySelectorAll('a[href], button:not(:disabled), input:not(:disabled):not([type="hidden"]), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])')]
            .filter(element => element.getClientRects().length > 0);
        const first = focusable[0];
        const last = focusable.at(-1);
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first?.focus();
        }
    });
    trigger.addEventListener('click', openDialog);
    document.getElementById('close-voucher-modal').addEventListener('click', () => dialog.close());
    document.getElementById('cancel-voucher-modal').addEventListener('click', () => dialog.close());

    function error(name, message) {
        const output = form.querySelector(`[data-error-for="${name}"]`);
        if (output) output.textContent = message;
        const input = field(name);
        if (input instanceof HTMLElement) {
            input.setAttribute('aria-invalid', message ? 'true' : 'false');
            input.setCustomValidity?.(message);
        }
    }

    function syncConditionalFields() {
        form.querySelectorAll('[data-type]').forEach(block => {
            const active = block.dataset.type === value('type');
            block.hidden = !active;
            block.querySelectorAll('input').forEach(input => {
                input.disabled = !active;
                input.required = active;
                if (!active) error(input.name, '');
            });
        });
        const selected = value('applies_to') === 'selected';
        document.getElementById('product-picker').hidden = !selected;
        form.querySelectorAll('[name="product_ids[]"]').forEach(input => input.disabled = !selected);
        text('product-count', `${selectedProducts().length} selected`);
        if (!selected) error('product_ids', '');
    }

    function impact() {
        const cap = value('type') === 'percent' ? minor('max_discount') : minor('fixed_amount');
        const funder = marketplace ? 'The marketplace funds' : 'You fund';
        if (!(cap > 0) || !Number.isFinite(cap)) return `${funder} the full voucher discount. Set your discount and limits to estimate the maximum impact.`;
        const perOrder = `${funder} up to ${money(cap)} per redemption.`;
        return value('usage_limit') && Number(value('usage_limit')) > 0
            ? `${perOrder} With ${value('usage_limit')} total redemptions, the upper bound is ${money(cap * Number(value('usage_limit')))} in ${marketplace ? 'marketplace' : 'seller'}-funded discounts. Actual discounts depend on eligible purchases; this is not a profit estimate.`
            : `${perOrder} Total redemptions are unlimited, so there is no overall discount budget cap.`;
    }

    function updatePreview() {
        const percent = value('type') === 'percent';
        text('discount-type-percent-description', shipping() ? 'A percentage off shipping fees' : 'A percentage off eligible items');
        text('discount-type-fixed-description', shipping() ? 'A set peso amount off shipping fees' : 'A set peso amount off');
        const validDiscount = percent ? Number(value('percent')) > 0 && Number(value('percent')) <= 99 : minor('fixed_amount') > 0;
        const offer = validDiscount ? (percent ? `${value('percent')}% OFF` : `${money(minor('fixed_amount'))} OFF`) : 'Your discount';
        text('preview-offer', shipping() && validDiscount ? `${offer} SHIPPING` : offer);
        text('preview-code', value('code').trim().toUpperCase() || 'YOURCODE');
        const terms = [minor('min_spend') > 0 ? `Min. spend ${money(minor('min_spend'))}` : 'No minimum spend'];
        if (percent && minor('max_discount') > 0) terms.push(`Max. ${money(minor('max_discount'))} off`);
        if (value('applies_to') === 'selected') terms.push(`${selectedProducts().length} selected products`);
        text('preview-terms', terms.join(' | '));
        const subtotal = Math.max(60000, minor('min_spend') || 0);
        const discountBase = shipping() ? 10000 : subtotal;
        const calculated = percent ? Math.min(Math.floor((discountBase * Number(value('percent')) + 50) / 100), minor('max_discount')) : minor('fixed_amount');
        const discount = validDiscount && Number.isFinite(calculated) ? Math.max(0, Math.min(discountBase, calculated)) : 0;
        text('example-subtotal', money(subtotal));
        text('example-discount', `-${money(discount)}`);
        text('example-total', money(discountBase - discount));
        document.getElementById('example-shipping-row').hidden = !shipping();
        text('example-subtotal-label', shipping() ? 'Eligible merchandise (minimum spend)' : 'Eligible items');
        text('example-total-label', shipping() ? 'Buyer pays for shipping' : 'Buyer pays for items');
        text('example-note', shipping() ? 'Illustration only: PHP 100.00 quoted shipping with the eligible merchandise shown above. This voucher discounts shipping only. Other vouchers and adjustments excluded.' : 'Illustration only, using eligible items at sale prices. Shipping and other adjustments excluded.');
        text('funding-impact', impact());
    }

    function validate() {
        let valid = true;
        const set = (name, message) => { error(name, message); if (message) valid = false; };
        if (marketplace) set('scope', ['platform', 'shipping'].includes(value('scope')) ? '' : 'Choose a platform voucher or a shipping voucher.');
        const normalizedCode = value('code').trim().toUpperCase();
        set('code', /^[A-Z0-9]{4,12}$/.test(normalizedCode) ? '' : 'Use 4-12 letters and numbers, with no spaces or symbols.');
        for (const name of ['min_spend', ...(value('type') === 'percent' ? ['max_discount'] : ['fixed_amount'])]) {
            const amount = value(name);
            const optional = name === 'min_spend';
            const validAmount = (optional && amount === '') || (/^\d+(?:\.\d{1,2})?$/.test(amount) && Number(amount) >= (optional ? 0 : 0.01) && Number(amount) <= 99999999.99);
            set(name, validAmount ? '' : `Enter ${optional ? 'zero or a positive' : 'a positive'} amount up to PHP 99,999,999.99, with at most two decimal places.`);
        }
        if (value('type') === 'percent') set('percent', /^\d+$/.test(value('percent')) && Number(value('percent')) >= 1 && Number(value('percent')) <= 99 ? '' : 'Enter a whole percentage from 1 to 99.');
        for (const name of ['usage_limit', 'per_buyer_limit']) {
            set(name, value(name) === '' || (/^\d+$/.test(value(name)) && Number.isSafeInteger(Number(value(name))) && Number(value(name)) >= 1) ? '' : 'Enter a whole number of at least 1, or leave blank for unlimited.');
        }
        if (value('usage_limit') && value('per_buyer_limit') && Number(value('per_buyer_limit')) > Number(value('usage_limit'))) set('per_buyer_limit', 'The per-buyer limit cannot be more than total redemptions.');
        const start = Date.parse(`${value('starts_at')}:00+08:00`);
        const end = Date.parse(`${value('expires_at')}:00+08:00`);
        set('starts_at', !Number.isFinite(start) ? 'Choose a start date and time.' : start < Math.floor(Date.now() / 60000) * 60000 ? 'The start time cannot be in the past (Philippine time).' : '');
        set('expires_at', !Number.isFinite(end) ? 'Choose an end date and time.' : Number.isFinite(start) && end <= start ? 'The end time must be after the start time.' : end <= Date.now() ? 'The end time must be in the future.' : '');
        set('product_ids', value('applies_to') === 'selected' && selectedProducts().length === 0 ? 'Choose at least one product.' : '');
        return valid;
    }

    async function checkCode() {
        clearTimeout(codeTimer);
        if (!dialog.open) return false;
        const code = value('code').trim().toUpperCase();
        if (!/^[A-Z0-9]{4,12}$/.test(code)) return false;
        codeRequest?.abort();
        const controller = new AbortController();
        codeRequest = controller;
        const sequence = ++codeSequence;
        text('code-status', 'Checking code availability...');
        document.getElementById('retry-code').hidden = true;
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const url = new URL(form.dataset.codeUrl, location.href);
            url.searchParams.set('code', code);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal, cache: 'no-store', credentials: 'same-origin' });
            if (!response.ok) throw new Error('Code check failed');
            const result = await response.json();
            if (typeof result.available !== 'boolean') throw new Error('Invalid code response');
            if (!dialog.open || sequence !== codeSequence || value('code').trim().toUpperCase() !== code) return false;
            error('code', result.available ? '' : 'This voucher code is already taken.');
            text('code-status', result.available ? 'This code is available.' : '');
            return result.available;
        } catch (failure) {
            if (!dialog.open || sequence !== codeSequence || value('code').trim().toUpperCase() !== code) return false;
            text('code-status', 'We could not check this code. Retry before creating your voucher.');
            document.getElementById('retry-code').hidden = false;
            return false;
        } finally {
            clearTimeout(timeout);
        }
    }

    function editDetails() {
        details.hidden = false;
        review.hidden = true;
        reviewButton.hidden = false;
        confirmButton.hidden = true;
        reviewedRevision = null;
    }

    function showErrors() {
        editDetails();
        summary.hidden = false;
        summary.textContent = 'Please check the highlighted fields and code availability. Your entries have been kept.';
        summary.focus();
    }

    function showReview() {
        const settings = document.getElementById('review-settings');
        settings.replaceChildren();
        const dateLabel = name => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(`${value(name)}:00+08:00`)) + ' (Philippine time)';
        const entries = [
            ['Voucher', `${value('code').trim().toUpperCase()} | ${document.getElementById('preview-offer').textContent}`],
            ['Discount conditions', document.getElementById('preview-terms').textContent],
            ['Eligible products', value('applies_to') === 'all' ? (marketplace ? 'All products' : 'All my products') : selectedProducts().map(input => input.closest('[data-product-name]').dataset.productName).join(', ')],
            ['Redemption limits', `${value('usage_limit') || 'Unlimited'} total | ${value('per_buyer_limit') || 'Unlimited'} per buyer`],
            ['Starts', dateLabel('starts_at')], ['Ends', dateLabel('expires_at')],
            ['Distribution', marketplace ? (value('availability') === 'claimable' ? 'Claimable: marketplace discovery and claiming are not currently available. Buyers must enter the code.' : 'Code only: buyers must enter the code.') : (value('availability') === 'claimable' ? 'Claimable: buyers can discover and save it.' : 'Code only: buyers must enter your code.')],
            ['Activation', value('is_active') === '1' ? 'Activate when the start date arrives.' : 'Save as inactive; manual activation required.'],
            [marketplace ? 'Marketplace-funded impact' : 'Seller-funded impact', impact()],
        ];
        if (marketplace) entries.unshift(['Voucher kind', shipping() ? 'Shipping voucher: discounts shipping fees; minimum spend uses merchandise.' : 'Platform voucher: discounts merchandise.']);
        entries.forEach(([title, content]) => {
            const group = document.createElement('div');
            const term = document.createElement('dt');
            term.className = 'font-semibold';
            term.textContent = title;
            const description = document.createElement('dd');
            description.className = 'mt-1 break-words leading-relaxed text-[var(--text-muted)]';
            description.textContent = content;
            group.append(term, description);
            settings.append(group);
        });
        summary.hidden = true;
        details.hidden = true;
        review.hidden = false;
        reviewButton.hidden = true;
        confirmButton.hidden = false;
        reviewedRevision = revision;
        review.focus();
        review.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    async function proceed(confirm = false) {
        if (!dialog.open || checking || submitted) return;
        if (confirm && reviewedRevision !== revision) { editDetails(); return; }
        if (!validate()) { showErrors(); return; }
        checking = true;
        reviewButton.disabled = confirmButton.disabled = true;
        form.setAttribute('aria-busy', 'true');
        const beforeCheck = revision;
        const currentOperation = ++operation;
        const available = await checkCode();
        if (currentOperation !== operation) return;
        checking = false;
        reviewButton.disabled = confirmButton.disabled = false;
        form.removeAttribute('aria-busy');
        if (!dialog.open || beforeCheck !== revision) return;
        if (!available || !validate()) { showErrors(); return; }
        if (!confirm) { showReview(); return; }
        submitted = true;
        confirmButton.disabled = true;
        confirmButton.textContent = 'Creating voucher...';
        field('code').value = value('code').trim().toUpperCase();
        HTMLFormElement.prototype.submit.call(form);
    }

    form.addEventListener('input', event => {
        if (!(event.target instanceof HTMLInputElement)) return;
        if (event.target.id === 'product-search') {
            const search = event.target.value.trim().toLocaleLowerCase();
            let matches = 0;
            form.querySelectorAll('[data-product-name]').forEach(row => { row.hidden = !row.dataset.productName.toLocaleLowerCase().includes(search); if (!row.hidden) matches++; });
            document.getElementById('no-products-found').hidden = matches !== 0;
            return;
        }
        revision++;
        if (!review.hidden) editDetails();
        syncConditionalFields();
        updatePreview();
        // Show instant errors only for fields the seller has touched. Review validates all fields.
        const currentName = event.target.name === 'product_ids[]' ? 'product_ids' : event.target.name;
        const prior = [...form.querySelectorAll('[data-error-for]')].map(output => [output.dataset.errorFor, output.textContent]);
        validate();
        const related = new Set([currentName]);
        if (['starts_at', 'expires_at'].includes(currentName)) {
            related.add('starts_at');
            related.add('expires_at');
        }
        if (['usage_limit', 'per_buyer_limit'].includes(currentName)) {
            related.add('usage_limit');
            related.add('per_buyer_limit');
        }
        if (currentName === 'type') {
            related.add('percent');
            related.add('fixed_amount');
            related.add('max_discount');
        }
        if (currentName === 'applies_to') related.add('product_ids');
        for (const [name, message] of prior) {
            if (!related.has(name)) error(name, message);
        }
        if (currentName === 'code') {
            codeSequence++;
            codeRequest?.abort();
            clearTimeout(codeTimer);
            text('code-status', '');
            document.getElementById('retry-code').hidden = true;
            if (/^[A-Z0-9]{4,12}$/.test(value('code').trim().toUpperCase())) codeTimer = setTimeout(checkCode, 500);
        }
    });
    form.addEventListener('submit', event => { event.preventDefault(); proceed(!review.hidden); });
    confirmButton.addEventListener('click', () => proceed(true));
    document.getElementById('edit-voucher').addEventListener('click', () => { editDetails(); field('code').focus(); });
    document.getElementById('retry-code').addEventListener('click', checkCode);
    document.getElementById('generate-code').addEventListener('click', () => {
        const random = crypto.getRandomValues(new Uint32Array(1))[0].toString(36).toUpperCase().padStart(7, '0');
        field('code').value = `SAVE${random}`;
        field('code').dispatchEvent(new Event('input', { bubbles: true }));
    });
    // Custom validation keeps every value intact and connects feedback to each input.
    form.noValidate = true;
    syncConditionalFields();
    updatePreview();
    form.querySelectorAll('[data-error-for]').forEach(output => {
        if (output.textContent.trim()) error(output.dataset.errorFor, output.textContent);
    });
    reviewButton.disabled = false;
    if (dialog.dataset.open === 'true') openDialog();
})();
</script>
