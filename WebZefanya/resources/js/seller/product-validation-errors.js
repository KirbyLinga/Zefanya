const invalidClasses = ['outline-2', 'outline-[var(--status-danger)]'];
let errorSequence = 0;

export function clearProductValidationErrors(root) {
    root.querySelectorAll('[data-pf-field-error], [data-pf-dynamic-summary]').forEach(message => message.remove());
    root.querySelectorAll('[data-pf-invalid]').forEach(element => {
        element.classList.remove(...invalidClasses);
        element.removeAttribute('data-pf-invalid');
        element.removeAttribute('aria-invalid');
        if (element.hasAttribute('data-pf-temporary-tabindex')) {
            element.removeAttribute('tabindex');
            element.removeAttribute('data-pf-temporary-tabindex');
        }
        if (element.hasAttribute('data-pf-original-describedby')) {
            const original = element.getAttribute('data-pf-original-describedby');
            if (original) element.setAttribute('aria-describedby', original);
            else element.removeAttribute('aria-describedby');
            element.removeAttribute('data-pf-original-describedby');
        }
    });
}

export function showProductValidationErrors(root, errors) {
    clearProductValidationErrors(root);
    if (!root.dataset.pfErrorScope) root.dataset.pfErrorScope = String(++errorSequence);
    const fields = new Map(Array.from(root.querySelectorAll('[name]'), field => [field.name, field]));
    const entries = Object.entries(errors);
    if (entries.length > 1) {
        const summary = document.createElement('div');
        summary.setAttribute('data-pf-dynamic-summary', '');
        summary.setAttribute('role', 'alert');
        summary.className = 'rounded-sm border border-[var(--pill-danger-border)] bg-[var(--pill-danger-bg)] p-4 text-[var(--status-danger)]';
        const title = document.createElement('strong');
        title.textContent = 'Please check your product details.';
        const list = document.createElement('ul');
        list.className = 'mt-2 list-inside list-disc';
        entries.forEach(([, messages]) => (Array.isArray(messages) ? messages : [messages]).forEach(text => {
            const item = document.createElement('li'); item.textContent = String(text); list.appendChild(item);
        }));
        summary.append(title, list);
        root.prepend(summary);
    }
    let first = null;
    for (const [attribute, messages] of entries) {
        const mapped = attribute.replace(/\.weight_grams$/, '.weight');
        const name = mapped.replace(/\.([^\.]+)/g, '[$1]');
        const field = fields.get(name) || fields.get(name.replace(/\[\d+\]$/, ''));
        const target = field || (attribute === 'options' ? root.querySelector('[data-pf-options]')
            : attribute === 'variants' ? root.querySelector('[data-pf-variant-body]') : null);
        if (!target) continue;
        target.setAttribute('data-pf-invalid', '');
        target.classList.add(...invalidClasses);
        const row = target.closest('[data-pf-variant-row], [data-pf-option-row]');
        if (row) {
            row.setAttribute('data-pf-invalid', '');
            row.classList.add(...invalidClasses);
        }
        const message = document.createElement('p');
        message.id = 'pf-field-error-' + root.dataset.pfErrorScope + '-' + Array.from(attribute, character => character.codePointAt(0).toString(16)).join('-');
        message.setAttribute('data-pf-field-error', '');
        message.className = 'mt-1 text-xs text-[var(--status-danger)]';
        message.setAttribute('role', 'alert');
        message.textContent = Array.isArray(messages) ? messages.join(' ') : String(messages);
        target.insertAdjacentElement('afterend', message);
        const focusable = 'input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled), button:not(:disabled)';
        const focus = field?.type === 'hidden' ? (row?.querySelector(focusable) || row || target) : (field || target.querySelector(focusable) || target);
        if (focus) {
            focus.setAttribute('data-pf-invalid', '');
            if (focus.matches('input, select, textarea')) focus.setAttribute('aria-invalid', 'true');
            if (!focus.matches('input, select, textarea, button') && !focus.hasAttribute('tabindex')) {
                focus.setAttribute('tabindex', '-1');
                focus.setAttribute('data-pf-temporary-tabindex', '');
            }
            if (!focus.hasAttribute('data-pf-original-describedby')) focus.setAttribute('data-pf-original-describedby', focus.getAttribute('aria-describedby') || '');
            focus.setAttribute('aria-describedby', [focus.getAttribute('aria-describedby'), message.id].filter(Boolean).join(' '));
        }
        const panel = target.closest('[data-pf-step]');
        const step = panel ? Number(panel.dataset.pfStep) : null;
        if (first === null || (step !== null && (first.step === null || step < first.step))) first = { step, field: focus };
    }
    return first;
}
