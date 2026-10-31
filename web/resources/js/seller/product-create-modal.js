// Shared multipart create/edit dialog. Product state belongs to each injected form root.
import { clearProductValidationErrors, showProductValidationErrors } from './product-validation-errors.js';
import { refreshProductSections, updateStoreCategoryChoices, revealProductField } from './product-form-flow.js';

document.addEventListener('DOMContentLoaded', () => {
    const overlay = document.getElementById('productModalOverlay');
    const form = document.getElementById('productModalForm');
    if (!overlay || !form || overlay.dataset.jsInit) return;
    overlay.dataset.jsInit = '1';
    const body = document.getElementById('productModalBody');
    const title = document.getElementById('productModalTitle');
    const subtitle = document.getElementById('productModalSubtitle');
    const submit = document.getElementById('productModalSubmit');
    const submitLabel = document.getElementById('productModalSubmitLabel');
    const back = document.querySelector('[data-pf-back]');
    const next = document.querySelector('[data-pf-next]');
    const createSnapshot = body.dataset.mode === 'create' ? { html: body.innerHTML, action: form.action } : null;
    const LAST_STEP = 4;
    let state;
    let fetchToken = 0;
    let previousFocus;
    const fieldClass = 'w-full rounded-sm border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-ink)] focus:border-[var(--brand)] focus:outline-none';
    const smallButton = 'rounded-sm border border-[var(--border-input)] px-3 py-2 text-xs font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-soft)] focus-visible:outline-2 focus-visible:outline-[var(--brand)]';
    const q = (selector) => body.querySelector(selector);
    const qa = (selector) => [...body.querySelectorAll(selector)];
    const node = (tag, text, className) => {
        const element = document.createElement(tag);
        if (text !== undefined) element.textContent = text;
        if (className) element.className = className;
        return element;
    };
    const input = (type, name, value, hook) => {
        const element = node('input', undefined, fieldClass);
        element.type = type;
        if (name) element.name = name;
        element.value = value ?? '';
        if (hook) element.setAttribute(hook, '');
        return element;
    };
    const action = (text, hook) => {
        const element = node('button', text, smallButton);
        element.type = 'button';
        element.setAttribute(hook, '');
        return element;
    };
    const asValues = (value) => (Array.isArray(value) ? value : String(value || '').split(',')).map(value => String(value).trim()).filter(Boolean);
    const key = (values) => JSON.stringify(values);
    const error = (message) => {
        const target = q('[data-pf-error]');
        if (target) { target.textContent = message || ''; target.hidden = !message; }
    };
    function dispose() {
        if (state) state.urls.forEach(url => URL.revokeObjectURL(url));
        state = null;
    }
    function preview(files, destination, cover = false) {
        destination.querySelectorAll('[data-object-url]').forEach(image => { URL.revokeObjectURL(image.src); state.urls.delete(image.src); });
        destination.replaceChildren();
        files.forEach((file, index) => {
            const url = URL.createObjectURL(file);
            state.urls.add(url);
            const figure = node('figure', undefined, 'min-w-0 space-y-2');
            const image = node('img', undefined, 'aspect-square w-full rounded-sm border border-[var(--border-input)] object-cover');
            image.src = url; image.setAttribute('data-object-url', '');
            image.alt = file.name;
            figure.append(image);
            if (cover) {
                const button = action(index === Number(q('[data-pf-cover-index]').value) && !q('[data-pf-cover-index]').disabled ? 'Cover image' : 'Use as cover', 'data-pf-set-cover');
                button.dataset.pfSetCover = String(index);
                button.classList.add('w-full');
                figure.append(button);
            }
            destination.append(figure);
        });
    }
    function initialize() {
        dispose();
        const root = q('[data-pf-root]');
        if (!root) { submit.disabled = true; next.disabled = true; return; }
        const initial = JSON.parse(q('[data-pf-initial]').textContent);
        state = { colorFirst: root.dataset.pfColorFirst === '1', groups: [], step: 1, furthestStep: 1, edit: root.dataset.pfEdit === '1', urls: new Set(), rows: new Map(), colors: new Map(), initialColors: initial.colorImages || {}, imageIds: initial.colorImageIds || {}, axes: (initial.options || []).slice(0, 2).map(axis => ({ name: axis.name || '', values: asValues(axis.values) })) };
        initial.variants.forEach(row => state.rows.set(key(asValues(row.values)), { ...row, values: asValues(row.values) }));
        if (state.colorFirst) {
            const colors = state.axes[0]?.values || [];
            state.groups = colors.map(value => ({ value, sizes: [...new Set(initial.variants.filter(row => asValues(row.values)[0] === value).map(row => asValues(row.values)[1]).filter(Boolean))] }));
            state.axes = [{ name: 'Color', values: colors }, { name: 'Size', values: [...new Set(state.groups.flatMap(group => group.sizes))] }];
        }
        if (!state.axes.length) state.axes.push({ name: '', values: [] });
        weightBounds(q('#weight'), q('[data-pf-single-weight] [data-pf-weight-unit]').value);
        renderOptions();
        syncVariations();
        generate();
        updateStoreCategoryChoices(body);
        showStep(1);
    }
    function weightBounds(field, unit) {
        field.min = unit === 'kg' ? '0.001' : '1';
        field.max = unit === 'kg' ? '100' : '100000';
        field.step = unit === 'kg' ? '0.001' : '1';
    }
    function enabled() { return q('[data-pf-variants-toggle]').checked; }
    function renderOptions() {
        const container = q('[data-pf-options]');
        container.replaceChildren();
        if (state.colorFirst) {
            state.axes = [{ name: 'Color', values: state.groups.map(group => group.value) }, { name: 'Size', values: [...new Set(state.groups.flatMap(group => group.sizes))] }];
            state.axes.forEach((axis, index) => {
                container.append(input('hidden', 'options[' + index + '][name]', axis.name, 'data-pf-option-name'), input('hidden', 'options[' + index + '][values]', axis.values.join(','), 'data-pf-option-values'));
            });
            q('[data-pf-add-option]').hidden = true;
            syncVariations();
            return;
        }
        state.axes.forEach((axis, index) => {
            const row = node('div', undefined, 'space-y-3 rounded-sm border border-[var(--border-input)] p-4');
            row.setAttribute('data-pf-option-row', '');
            row.dataset.index = String(index);
            const heading = node('div', undefined, 'flex items-center justify-between gap-3');
            const label = node('label', 'Variation ' + (index + 1), 'font-semibold text-[var(--text-ink)]');
            label.htmlFor = 'pf-option-' + index;
            heading.append(label);
            if (index > 0) heading.append(action('Remove group', 'data-pf-remove-option'));
            row.append(heading);
            const name = input('text', 'options[' + index + '][name]', axis.name, 'data-pf-option-name');
            name.id = 'pf-option-' + index;
            name.maxLength = 50;
            name.placeholder = index ? 'e.g. Size' : 'e.g. Color';
            row.append(name);
            const values = input('hidden', 'options[' + index + '][values]', axis.values.join(','), 'data-pf-option-values');
            row.append(values);
            const chips = node('div', undefined, 'flex flex-wrap gap-2');
            chips.setAttribute('data-pf-chips', '');
            axis.values.forEach((value, valueIndex) => {
                const button = action(value + ' ×', 'data-pf-remove-value');
                button.dataset.valueIndex = String(valueIndex);
                button.setAttribute('aria-label', 'Remove ' + value);
                chips.append(button);
            });
            row.append(chips);
            const entryRow = node('div', undefined, 'flex gap-2');
            const entry = input('text', null, '', 'data-pf-value-entry');
            entry.placeholder = index ? 'Add a size, e.g. S' : 'Add an option, e.g. Black';
            entry.maxLength = 50;
            entry.setAttribute('aria-label', 'New value for variation ' + (index + 1));
            entryRow.append(entry, action('Add', 'data-pf-add-value'));
            row.append(entryRow);
            container.append(row);
        });
        q('[data-pf-add-option]').hidden = state.axes.length >= 2;
        syncVariations();
    }
    function syncVariations() {
        q('[data-pf-single-weight]').hidden = enabled();
        q('[data-pf-single-weight] [data-pf-weight]').disabled = enabled();
        q('[data-pf-single-weight] [data-pf-weight-unit]').disabled = enabled();
        q('[data-pf-variants-exist]').value = enabled() ? '1' : '0';
        q('[data-pf-options-area]').hidden = !enabled();
        qa('[data-pf-option-name], [data-pf-option-values]').forEach(field => { field.disabled = !enabled(); });
        q('[data-pf-single-inventory]').hidden = enabled();
        q('[data-pf-variant-inventory]').hidden = !enabled();
        qa('[data-pf-sku-col]').forEach(column => { column.hidden = !state.edit; });
        q('#price').readOnly = enabled();
        q('#stock_quantity').readOnly = enabled();
        if (enabled()) q('#stock_quantity').removeAttribute('max');
        else q('#stock_quantity').max = '99999';
        qa('[data-pf-variant-row] input, [data-pf-variant-row] select').forEach(field => { field.disabled = !enabled(); });
        qa('[data-pf-colors] input').forEach(field => { field.disabled = !enabled(); });
        const count = state.colorFirst ? state.groups.reduce((sum, group) => sum + group.sizes.length, 0) : state.axes.reduce((total, axis) => total * axis.values.length, 1);
        q('[data-pf-combo-summary]').textContent = count + ' version' + (count === 1 ? '' : 's') + ' so far. You will set their prices in the next step.';
        rollup();
    }
    function saveRows() {
        qa('[data-pf-variant-row]').forEach(row => {
            const values = JSON.parse(row.dataset.values);
            state.rows.set(key(values), { id: state.rows.get(key(values))?.id, values, price: row.querySelector('[data-pf-price]').value, weight: row.querySelector('[data-pf-weight]').value, weight_unit: row.querySelector('[data-pf-weight-unit]').value, stock_quantity: row.querySelector('[data-pf-stock]').value, sku: row.querySelector('[data-pf-sku]').value, is_available: row.querySelector('[data-pf-enabled]').checked });
        });
    }
    function combinations() {
        if (state.colorFirst) return enabled() ? state.groups.flatMap(group => group.sizes.map(size => [group.value, size])) : [];
        if (!enabled() || state.axes.some(axis => !axis.name.trim() || !axis.values.length)) return [];
        return state.axes.reduce((rows, axis) => rows.flatMap(row => axis.values.map(value => [...row, value])), [[]]);
    }
    function generate() {
        saveRows();
        const rows = combinations();
        qa('[data-pf-variant-row]').forEach(row => row.remove());
        const container = q('[data-pf-variant-body]');
        container.replaceChildren();
        if (rows.length <= 400) rows.forEach((values, index) => {
            const old = state.rows.get(key(values)) || { price: '', stock_quantity: 0, sku: '', is_available: true };
            const row = node('tr', undefined, 'border-t border-[var(--border-hairline)]');
            row.setAttribute('data-pf-variant-row', '');
            row.dataset.values = JSON.stringify(values);
            const label = node('td', undefined, 'sticky left-0 z-10 bg-[var(--bg-surface)] p-3 font-semibold text-[var(--text-ink)]');
            label.append(node('span', values.join(' / '), 'text-[var(--text-ink)]'));
            label.append(input('hidden', 'variants[' + index + '][values]', values.join(',')));
            if (old.id) label.append(input('hidden', 'variants[' + index + '][id]', old.id));
            row.append(label);
            [['price', 'number', 'data-pf-price'], ['stock_quantity', 'number', 'data-pf-stock'], ['weight', 'number', 'data-pf-weight'], ['sku', 'text', 'data-pf-sku']].forEach(([name, type, hook]) => {
                const cell = node('td', undefined, 'p-3');
                if (name === 'sku') { cell.setAttribute('data-pf-sku-col', ''); cell.hidden = !state.edit; }
                const field = input(type, 'variants[' + index + '][' + name + ']', old[name], hook);
                field.setAttribute('aria-label', values.join(' / ') + ' ' + name.replace('_quantity', ''));
                if (type === 'number') { field.min = name === 'price' ? '1' : '0'; if (name === 'price') field.max = '1000000'; if (name === 'stock_quantity') field.max = '99999'; field.step = name === 'price' ? '0.01' : '1'; field.required = true; }
                else field.maxLength = 64;
                field.classList.add(name === 'sku' ? 'min-w-28' : 'min-w-24');
                if (name === 'weight') {
                    const pair = node('div', undefined, 'flex gap-1');
                    pair.append(field);
                    cell.append(pair);
                    const unit = node('select', undefined, fieldClass);
                    unit.name = 'variants[' + index + '][weight_unit]';
                    unit.setAttribute('data-pf-weight-unit', '');
                    unit.setAttribute('aria-label', values.join(' / ') + ' weight unit');
                    ['g', 'kg'].forEach(value => { const option = node('option', value); option.value = value; unit.append(option); });
                    unit.value = old.weight_unit || 'g';
                    unit.classList.remove('w-full');
                    unit.classList.add('w-[72px]', 'shrink-0');
                    pair.append(unit);
                    weightBounds(field, unit.value);
                } else cell.append(field);
                row.append(cell);
            });
            const cell = node('td', undefined, 'p-3');
            const availability = input('hidden', 'variants[' + index + '][is_available]', (Boolean(Number(old.is_available)) || old.is_available === true) ? '1' : '0', 'data-pf-available');
            const check = input('checkbox', null, '', 'data-pf-enabled');
            check.checked = Boolean(Number(old.is_available) || old.is_available === true);
            check.className = 'size-4 accent-[var(--brand)]';
            check.setAttribute('aria-label', 'Selling ' + values.join(' / '));
            cell.append(availability, check);
            row.append(cell);
            container.append(row);
        });
        renderColors();
        syncVariations();
    }
    function renderColors() {
        const axis = enabled() ? state.axes.find(axis => /^(color|colour)$/i.test(axis.name.trim())) : null;
        const values = axis?.values || [];
        refreshProductSections(body, { step: state.step, variations: enabled(), colorFirst: state.colorFirst, hasColor: Boolean(axis) });
        const container = q('[data-pf-colors]');
        const previous = state.colors;
        const current = new Map();
        values.forEach((value, index) => {
            let card = previous.get(value);
            if (!card) {
                card = node('div', undefined, 'space-y-3 rounded-sm border border-[var(--border-input)] p-4');
                card.setAttribute('data-pf-color-card', '');
                card.dataset.color = value;
                if (state.colorFirst) {
                    const header = node('div', undefined, 'flex items-end gap-3');
                    const nameLabel = node('label', 'Color name', 'flex min-w-0 flex-1 flex-col gap-2 font-semibold');
                    const name = input('text', null, value, 'data-pf-color-name');
                    name.maxLength = 100; name.required = true;
                    nameLabel.append(name);
                    header.append(nameLabel, action('Remove color', 'data-pf-remove-color'));
                    card.append(header);
                    const sizeLabel = node('label', 'Sizes in this color', 'flex flex-col gap-2 font-semibold');
                    const sizes = input('text', null, state.groups.find(group => group.value === value)?.sizes.join(', '), 'data-pf-color-sizes');
                    sizes.placeholder = 'S, M, L'; sizes.required = true;
                    sizeLabel.append(sizes);
                    sizeLabel.append(node('span', 'Separate with commas. No sizes? Type "One size".', 'text-xs font-normal text-[var(--text-muted)]'));
                    card.append(sizeLabel);
                } else card.append(node('h4', value, 'font-semibold text-[var(--text-ink)]'));
                const existing = node('div', undefined, 'grid grid-cols-3 gap-2');
                (state.initialColors[value] || []).forEach(url => {
                    const image = node('img', undefined, 'aspect-square w-full rounded-sm object-cover');
                    image.src = url; image.alt = value + ' existing photo';
                    const figure = node('figure', undefined, 'space-y-2');
                    const remove = action('Remove photo', 'data-pf-remove-color-photo');
                    remove.dataset.imageId = String(state.imageIds[value]?.[url] || '');
                    remove.dataset.imageUrl = url;
                    figure.append(image, remove); existing.append(figure);
                });
                card.append(existing);
                card.append(input('hidden', null, value, 'data-pf-color-value'));
                const uploadLabel = node('label', undefined, 'flex cursor-pointer flex-col items-center gap-1 rounded-sm border border-dashed border-[var(--border-input)] bg-[var(--bg-soft)] p-3 text-center hover:border-[var(--brand)]');
                uploadLabel.append(node('span', '+ Add photos of this color', 'font-semibold text-[var(--text-ink)]'));
                uploadLabel.append(node('span', 'Up to 9. Buyers see these when they pick this color.', 'text-xs text-[var(--text-muted)]'));
                const files = input('file', null, '', 'data-pf-color-files');
                files.multiple = true; files.accept = 'image/jpeg,image/png';
                files.className = 'sr-only';
                uploadLabel.append(files);
                card.append(uploadLabel);
                const previews = node('div', undefined, 'grid grid-cols-3 gap-2');
                previews.setAttribute('data-pf-color-previews', '');
                card.append(previews);
            }
            card.querySelector('[data-pf-color-value]').name = 'color_images[' + index + '][value]';
            card.querySelector('[data-pf-color-files]').name = 'color_images[' + index + '][files][]';
            card.querySelectorAll('[data-pf-removed-color-photo]').forEach(field => { field.name = 'color_images[' + index + '][remove_ids][]'; });
            current.set(value, card);
        });
        previous.forEach((card, value) => { if (!current.has(value)) card.querySelectorAll('[data-object-url]').forEach(image => { URL.revokeObjectURL(image.src); state.urls.delete(image.src); }); });
        container.replaceChildren(...current.values());
        state.colors = current;
        q('[data-pf-no-colors]').hidden = values.length > 0;
        if (state.colorFirst) q('[data-pf-add-color]').textContent = values.length ? '+ Add Another Color' : '+ Add Color';
    }
    function rollup() {
        if (!state) return;
        if (enabled()) {
            const rows = qa('[data-pf-variant-row]');
            const prices = rows.map(row => Number(row.querySelector('[data-pf-price]').value)).filter(Number.isFinite);
            q('#price').value = prices.length ? Math.min(...prices).toFixed(2) : '0';
            q('#stock_quantity').value = rows.reduce((sum, row) => sum + (Number(row.querySelector('[data-pf-stock]').value) || 0), 0);
        }
        q('[data-pf-total-stock]').textContent = q('#stock_quantity').value || '0';
    }
    function validate(step) {
        error('');
        if (step === 2 && !state.edit && !q('[data-pf-gallery-files]').files.length) { error('Add at least one main photo.'); return false; }
        if (step === 2 && enabled() && state.colorFirst) {
            const names = state.groups.map(group => group.value.trim().toLowerCase());
            if (!names.length || names.some(name => !name) || new Set(names).size !== names.length || state.groups.some(group => !group.sizes.length || new Set(group.sizes.map(size => size.toLowerCase())).size !== group.sizes.length)) {
                error('Give each color its own name and at least one size. No sizes? Type "One size".'); return false;
            }
            if (state.axes.some(axis => axis.values.length > 20)) { error('Each variation can have at most 20 options.'); return false; }
            for (const card of state.colors.values()) {
                if (!card.querySelector('[data-pf-color-files]').files.length && !(state.initialColors[card.dataset.color] || []).length) { error('Add at least one photo of ' + card.dataset.color + '.'); return false; }
            }
        }
        if (step === 2 && enabled()) {
            const axisErrors = {}, names = new Set();
            state.axes.forEach((axis, index) => {
                const name = axis.name.trim().toLowerCase();
                if (!name || names.has(name)) axisErrors['options.' + index + '.name'] = ['Variation group names must be unique and not empty.'];
                if (!axis.values.length) axisErrors['options.' + index + '.values'] = ['Add at least one option.'];
                names.add(name);
            });
            if (Object.keys(axisErrors).length) {
                showProductValidationErrors(form, axisErrors);
                error(Object.values(axisErrors).flat().join(' '));
                return false;
            }
            if (state.axes.some(axis => axis.values.length > 20)) { error('Each variation can have at most 20 options.'); return false; }
        }
        const panel = q('[data-pf-step="' + step + '"]');
        for (const field of panel.querySelectorAll('input, textarea, select')) {
            if (!field.disabled && !field.checkValidity()) {
                revealProductField(field);
                showProductValidationErrors(form, { [field.name]: [field.validationMessage] });
                field.reportValidity();
                return false;
            }
        }
        return true;
    }
    function review() {
        rollup();
        q('[data-pf-review-name]').textContent = q('#name').value;
        const category = q('[data-pf-category]');
        q('[data-pf-review-category]').textContent = (category.selectedOptions ? category.selectedOptions[0]?.textContent : category.textContent) || '';
        q('[data-pf-review-options]').textContent = enabled() ? state.axes.map(axis => axis.name + ': ' + axis.values.join(', ')).join(' · ') : 'One version';
        const price = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(q('#price').value));
        q('[data-pf-review-summary]').textContent = (enabled() ? combinations().length + ' versions' : '1 version') + ' · ' + q('#stock_quantity').value + ' pieces · ' + (enabled() ? 'from ' : '') + price;
        const cover = q('[data-pf-review-cover]');
        const index = Number(q('[data-pf-cover-index]').value);
        const gallery = qa('[data-pf-gallery] img');
        const existing = q('[data-pf-existing-images] figure:has(figcaption) img') || q('[data-pf-existing-images] img');
        const source = (!q('[data-pf-cover-index]').disabled && gallery[index]) || existing || gallery[0];
        cover.hidden = !source;
        if (source) cover.src = source.src;
        submitLabel.textContent = state.edit ? 'Save Changes' : q('#status').value === 'draft' ? 'Save Draft' : q('#status').value === 'inactive' ? 'Save Product' : 'Publish Product';
    }
    function showStep(step) {
        if (!state) return;
        state.step = step;
        state.furthestStep = Math.max(state.furthestStep, step);
        error('');
        refreshProductSections(body, { step, variations: enabled(), colorFirst: state.colorFirst, hasColor: state.axes.some(axis => /^(color|colour)$/i.test(axis.name.trim())) });
        qa('[data-pf-progress]').forEach(item => {
            const number = Number(item.dataset.pfProgress);
            if (number === step) item.setAttribute('aria-current', 'step'); else item.removeAttribute('aria-current');
            // New products unlock steps in order; an existing one can jump anywhere.
            item.disabled = !state.edit && number > state.furthestStep;
        });
        // Editing a price should not mean walking through every step to save it:
        // an existing product moves by the step bar and can save from anywhere.
        back.hidden = state.edit || step === 1;
        next.hidden = state.edit || step === LAST_STEP;
        next.disabled = false;
        submit.hidden = !state.edit && step !== LAST_STEP;
        submit.disabled = false;
        review();
        body.scrollTop = 0;
    }
    function addValue(row, focus = true) {
        const index = Number(row.dataset.index);
        const entry = row.querySelector('[data-pf-value-entry]');
        const values = asValues(entry.value);
        if (!values.length) return true;
        if (values.some(value => value.length > 50)) { error('Keep each option value within 50 characters.'); entry.focus(); return false; }
        const axis = state.axes[index];
        const additions = values.filter(value => !axis.values.some(old => old.toLowerCase() === value.toLowerCase()));
        if (axis.values.length + new Set(additions.map(value => value.toLowerCase())).size > 20) { error('Each variation can have at most 20 options.'); entry.focus(); return false; }
        values.forEach(value => { if (!axis.values.some(old => old.toLowerCase() === value.toLowerCase())) axis.values.push(value); });
        error(''); renderOptions(); generate();
        if (focus) q('[data-pf-option-row][data-index="' + index + '"] [data-pf-value-entry]').focus();
        return true;
    }
    function commitPendingValues(exceptIndex = null) {
        if (!enabled()) return true;
        for (const entry of qa('[data-pf-value-entry]')) {
            if (Number(entry.closest('[data-pf-option-row]').dataset.index) === exceptIndex) continue;
            if (entry.value.trim() && !addValue(entry.closest('[data-pf-option-row]'), false)) return false;
        }
        return true;
    }
    function open() {
        previousFocus = document.activeElement;
        overlay.hidden = false; overlay.classList.add('is-open'); overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        (q('#name') || document.getElementById('productModalClose')).focus();
    }
    function close() {
        overlay.hidden = true; overlay.classList.remove('is-open'); overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = ''; fetchToken++;
        previousFocus?.focus();
    }
    function apply(mode, html, actionUrl, id = '') {
        dispose();
        body.innerHTML = html; body.dataset.mode = mode; form.action = actionUrl;
        document.getElementById('productModalFlag').value = mode === 'edit' ? 'product-edit' : 'product-create';
        document.getElementById('productModalProductId').value = id;
        title.textContent = mode === 'edit' ? 'Edit Product' : 'Add New Product';
        subtitle.textContent = 'Seller Center / Product catalog';
        initialize();
    }
    async function openEdit(id, trigger) {
        const token = ++fetchToken;
        dispose();
        body.replaceChildren(node('p', 'Loading product…', 'p-6 text-sm text-[var(--text-muted)]'));
        submit.disabled = true; submit.hidden = true; next.disabled = true;
        open();
        const url = trigger?.getAttribute('href') || overlay.dataset.editTemplate.replace('__ID__', id);
        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (!response.ok || response.redirected) throw new Error('Unable to load product');
            const html = await response.text();
            if (token !== fetchToken) return;
            apply('edit', html, trigger?.dataset.updateUrl || overlay.dataset.updateTemplate.replace('__ID__', id), id);
            if (trigger?.hasAttribute('data-product-stock-open')) {
                title.textContent = 'Add Stock';
                showStep(3);
                (enabled() ? q('[data-pf-stock]:not(:disabled)') : q('#stock_quantity'))?.focus();
            } else {
                q('#name')?.focus();
            }
        } catch {
            if (token === fetchToken) body.replaceChildren(node('p', 'Could not load this product. Close and try again.', 'p-6 text-[var(--status-danger)]'));
        }
    }
    document.addEventListener('click', event => {
        const create = event.target.closest('[data-product-create-open]');
        const edit = event.target.closest('[data-product-edit-open]');
        if (create) {
            event.preventDefault(); fetchToken++;
            if (body.dataset.mode !== 'create' || !state) {
                if (!createSnapshot) { window.location.assign(window.location.pathname + '?create=1'); return; }
                apply('create', createSnapshot.html, createSnapshot.action);
            }
            open();
        } else if (edit) { event.preventDefault(); openEdit(edit.dataset.productEditOpen, edit); }
    });
    document.getElementById('productModalClose').addEventListener('click', close);
    overlay.querySelectorAll('[data-modal-cancel]').forEach(button => button.addEventListener('click', close));
    overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
    document.addEventListener('keydown', event => {
        if (overlay.hidden || !overlay.classList.contains('is-open')) return;
        if (event.key === 'Escape') close();
        if (event.key === 'Tab') {
            const focusable = [...overlay.querySelectorAll('button,input,select,textarea,a[href]')].filter(element => !element.disabled && element.getClientRects().length);
            const first = focusable[0], last = focusable.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        }
    });
    back.addEventListener('click', () => { if (state) showStep(Math.max(1, state.step - 1)); });
    next.addEventListener('click', () => {
        if (!state || !commitPendingValues() || !validate(state.step)) return;
        if (state.step === 2) generate();
        showStep(state.step + 1);
    });
    form.addEventListener('click', event => {
        if (!state) return;
        const button = event.target.closest('button');
        if (!button) return;
        const row = button.closest('[data-pf-option-row]');
        if (button.matches('[data-pf-progress]')) { generate(); showStep(Number(button.dataset.pfProgress)); return; }
        if (button.matches('[data-pf-remove-color-photo]')) {
            const card = button.closest('[data-pf-color-card]');
            if (!button.dataset.imageId) { error('Reload this product to remove saved photos.'); return; }
            const removed = input('hidden', null, button.dataset.imageId, 'data-pf-removed-color-photo');
            card.append(removed);
            state.initialColors[card.dataset.color] = (state.initialColors[card.dataset.color] || []).filter(url => url !== button.dataset.imageUrl);
            button.closest('figure').remove();
            // Preserve all existing file inputs and inventory rows while assigning field names.
            const index = [...state.colors.keys()].indexOf(card.dataset.color);
            removed.name = 'color_images[' + index + '][remove_ids][]';
        }
        if (button.matches('[data-pf-add-color]')) {
            if (state.groups.length >= 20) { error('A variation can have at most 20 colors.'); return; }
            saveRows();
            let index = state.groups.length + 1;
            while (state.groups.some(group => group.value === 'Color ' + index)) index++;
            state.groups.push({ value: 'Color ' + index, sizes: [] });
            renderOptions(); generate();
            const name = [...state.colors.values()].at(-1).querySelector('[data-pf-color-name]');
            name.focus(); name.select();
        }
        if (button.matches('[data-pf-remove-color]')) {
            saveRows();
            state.groups = state.groups.filter(group => group.value !== button.closest('[data-pf-color-card]').dataset.color);
            renderOptions(); generate();
        }
        if (button.matches('[data-pf-add-option]') && state.axes.length < 2) {
            if (!commitPendingValues()) return;
            state.axes.push({ name: '', values: [] }); renderOptions();
        }
        if (button.matches('[data-pf-remove-option]')) {
            if (!commitPendingValues(Number(row.dataset.index))) return;
            saveRows(); state.axes.splice(Number(row.dataset.index), 1); renderOptions(); generate();
        }
        if (button.matches('[data-pf-add-value]')) commitPendingValues();
        if (button.matches('[data-pf-remove-value]')) {
            if (!commitPendingValues()) return;
            saveRows(); state.axes[Number(row.dataset.index)].values.splice(Number(button.dataset.valueIndex), 1); renderOptions(); generate();
        }
        if (button.matches('[data-pf-set-cover]')) { q('[data-pf-cover-index]').disabled = false; q('[data-pf-cover-index]').value = button.dataset.pfSetCover; preview([...q('[data-pf-gallery-files]').files], q('[data-pf-gallery]'), true); }
        if (button.matches('[data-pf-apply-bulk]')) {
            const price = q('[data-pf-bulk-price]'), stock = q('[data-pf-bulk-stock]'), weight = q('[data-pf-bulk-weight]'), unit = q('[data-pf-bulk-weight-unit]');
            if (![price, stock, weight].every(field => field.checkValidity())) { error('Enter a valid price, whole-number stock and weight.'); return; }
            qa('[data-pf-variant-row]').forEach(row => {
                if (price.value !== '') row.querySelector('[data-pf-price]').value = price.value;
                if (stock.value !== '') row.querySelector('[data-pf-stock]').value = stock.value;
                if (weight.value !== '') {
                    const field = row.querySelector('[data-pf-weight]'), rowUnit = row.querySelector('[data-pf-weight-unit]');
                    rowUnit.value = unit.value;
                    weightBounds(field, unit.value);
                    field.value = weight.value;
                }
            });
            clearProductValidationErrors(form);
            rollup();
        }
    });
    form.addEventListener('keydown', event => {
        if (['Enter', ','].includes(event.key) && event.target.matches('[data-pf-value-entry]')) { event.preventDefault(); addValue(event.target.closest('[data-pf-option-row]')); }
        else if (event.key === 'Enter' && event.target.tagName === 'INPUT') event.preventDefault();
    });
    form.addEventListener('focusout', event => {
        const entry = event.target;
        if (!state || !entry.matches('[data-pf-value-entry]') || !entry.value.trim()) return;
        // Buttons already have an explicit action; don't replace their DOM before click.
        if (event.relatedTarget?.closest('button')) return;
        const nextField = event.relatedTarget;
        const nextRow = nextField?.closest('[data-pf-option-row]');
        const nextHook = nextField?.matches('[data-pf-option-name]') ? '[data-pf-option-name]' : nextField?.matches('[data-pf-value-entry]') ? '[data-pf-value-entry]' : null;
        const nextIndex = nextRow?.dataset.index;
        if (!addValue(entry.closest('[data-pf-option-row]'), false)) return;
        if (nextHook && nextIndex !== undefined) q('[data-pf-option-row][data-index="' + nextIndex + '"] ' + nextHook)?.focus();
    });
    form.addEventListener('input', event => {
        if (!state) return;
        if (form.querySelector('[data-pf-field-error]')) clearProductValidationErrors(form);
        if (event.target.matches('[data-pf-option-name]')) {
            state.axes[Number(event.target.closest('[data-pf-option-row]').dataset.index)].name = event.target.value;
            renderColors();
        }
        if (event.target.matches('[data-pf-price], [data-pf-stock], #price, #stock_quantity')) rollup();
        review();
    });
    form.addEventListener('change', event => {
        if (!state) return;
        const target = event.target;
        if (target.matches('[data-pf-weight-unit]')) {
            const field = target.parentElement.querySelector('[data-pf-weight]');
            const oldUnit = field.step === '0.001' ? 'kg' : 'g';
            if (field.value !== '' && oldUnit !== target.value) field.value = String(Number((Number(field.value) * (target.value === 'kg' ? 0.001 : 1000)).toFixed(6)));
            weightBounds(field, target.value);
        }
        if (target.matches('[data-pf-color-name], [data-pf-color-sizes]')) {
            saveRows();
            const card = target.closest('[data-pf-color-card]');
            const old = card.dataset.color;
            const group = state.groups.find(group => group.value === old);
            if (target.matches('[data-pf-color-name]')) {
                const name = target.value.trim();
                if (!name || name.includes(',') || state.groups.some(other => other !== group && other.value.toLowerCase() === name.toLowerCase())) { target.value = old; error('Choose a unique color name without commas.'); return; }
                group.sizes.forEach(size => {
                    const row = state.rows.get(key([old, size]));
                    if (row) { state.rows.delete(key([old, size])); state.rows.set(key([name, size]), { ...row, values: [name, size] }); }
                });
                group.value = name; card.dataset.color = name;
                state.colors.delete(old); state.colors.set(name, card);
                state.initialColors[name] = state.initialColors[old]; delete state.initialColors[old];
                state.imageIds[name] = state.imageIds[old]; delete state.imageIds[old];
                card.querySelector('[data-pf-color-value]').value = name;
            } else {
                const sizes = asValues(target.value);
                const allSizes = new Set([...sizes, ...state.groups.filter(other => other !== group).flatMap(other => other.sizes)]);
                if (new Set([...allSizes].map(size => size.toLowerCase())).size > 20) { target.value = group.sizes.join(', '); error('A variation can have at most 20 sizes.'); return; }
                if (new Set(sizes.map(size => size.toLowerCase())).size !== sizes.length) { target.value = group.sizes.join(', '); error('Sizes must be unique within a color.'); return; }
                // A size label replacement retains that row's identity and inventory.
                const removed = group.sizes.filter(size => !sizes.includes(size));
                const added = sizes.filter(size => !group.sizes.includes(size));
                if (removed.length === added.length) removed.forEach((size, index) => {
                    const row = state.rows.get(key([old, size]));
                    if (row) {
                        state.rows.delete(key([old, size]));
                        state.rows.set(key([old, added[index]]), { ...row, values: [old, added[index]] });
                    }
                });
                group.sizes = sizes;
            }
            renderOptions(); generate();
        }
        if (target.matches('[data-pf-variants-toggle], [data-pf-variants-none]')) {
            if (enabled() && state.colorFirst && !state.groups.length) state.groups.push({ value: 'Color 1', sizes: [] });
            renderOptions(); generate(); syncVariations();
            refreshProductSections(body, { step: state.step, variations: enabled(), colorFirst: state.colorFirst, hasColor: state.axes.some(axis => /^(color|colour)$/i.test(axis.name.trim())) });
            if (enabled() && state.colorFirst && state.groups.length === 1 && state.groups[0].value === 'Color 1') {
                const firstColor = q('[data-pf-color-name]');
                firstColor?.focus(); firstColor?.select();
            }
        }
        if (target.matches('[data-pf-store-categories] input[type="checkbox"]')) updateStoreCategoryChoices(body);
        if (target.matches('[data-pf-enabled]')) target.closest('tr').querySelector('[data-pf-available]').value = target.checked ? '1' : '0';
        if (target.matches('#status')) review();
        if (target.matches('[data-pf-gallery-files], [data-pf-color-files]')) {
            const files = [...target.files];
            const invalid = files.length > 9 || files.some(file => !['image/jpeg', 'image/png'].includes(file.type) || file.size > 5 * 1024 * 1024);
            if (invalid) { target.value = ''; error('Choose up to 9 JPG/PNG images, no larger than 5 MB each.'); }
            else error('');
            const isGallery = target.matches('[data-pf-gallery-files]');
            if (isGallery) { q('[data-pf-cover-index]').value = '0'; q('[data-pf-cover-index]').disabled = state.edit; }
            preview([...target.files], isGallery ? q('[data-pf-gallery]') : target.closest('[data-pf-color-card]').querySelector('[data-pf-color-previews]'), isGallery);
        }
        review();
    });
    async function persistForm() {
        form.dataset.submitting = '1';
        clearProductValidationErrors(form);
        try {
            const response = await fetch(form.action, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) });
            if (!response.ok) {
                const data = await response.json();
                const first = showProductValidationErrors(form, data.errors || {});
                if (first?.step) showStep(first.step);
                error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Unable to save the product.');
                if (first?.field) { revealProductField(first.field); first.field.focus(); }
                return;
            }
            window.location.assign(response.url);
        } catch { error('Unable to save the product. Your entered values and photos have been kept. Please try again.'); }
        finally { delete form.dataset.submitting; }
    }
    form.addEventListener('submit', event => {
        event.preventDefault();
        if (form.dataset.submitting === '1') { event.preventDefault(); return; }
        if (!commitPendingValues()) return;
        if (event.submitter?.matches('[data-pf-save-draft]') || q('#status')?.value === 'draft') {
            if (!q('#name').checkValidity()) { event.preventDefault(); showStep(1); q('#name').reportValidity(); return; }
            q('#status').value = 'draft';
            rollup();
            persistForm();
            return;
        }
        if (!state) return;
        for (let step = 1; step <= LAST_STEP; step++) {
            if (!validate(step)) { event.preventDefault(); showStep(step); validate(step); return; }
        }
        rollup();
        persistForm();
    });
    initialize();
    if (overlay.dataset.autoEditId && /^\d+$/.test(overlay.dataset.autoEditId)) openEdit(overlay.dataset.autoEditId);
    else if (overlay.dataset.autoOpen === '1') open();
    window.addEventListener('pagehide', dispose);
});
