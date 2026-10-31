const invalidClasses = ['outline-2', 'outline-[var(--status-danger)]'];

export function clearProductValidationErrors(root) {
    root.querySelectorAll('[data-pf-field-error]').forEach(message => message.remove());
    root.querySelectorAll('[data-pf-invalid]').forEach(element => {
        element.classList.remove(...invalidClasses);
        element.removeAttribute('data-pf-invalid');
        element.removeAttribute('aria-invalid');
    });
}

export function showProductValidationErrors(root, errors) {
    clearProductValidationErrors(root);
    const fields = new Map(Array.from(root.querySelectorAll('[name]'), field => [field.name, field]));
    let first = null;
    for (const [attribute, messages] of Object.entries(errors)) {
        const mapped = attribute.replace(/\.weight_grams$/, '.weight');
        const name = mapped.replace(/\.([^\.]+)/g, '[$1]');
        const field = fields.get(name) || fields.get(name.replace(/\[\d+\]$/, ''));
        const target = field || (attribute === 'options' ? root.querySelector('[data-pf-options]')
            : attribute === 'variants' ? root.querySelector('[data-pf-variant-body]') : null);
        if (!target) continue;
        target.setAttribute('data-pf-invalid', '');
        target.classList.add(...invalidClasses);
        if (field) field.setAttribute('aria-invalid', 'true');
        const row = target.closest('[data-pf-variant-row], [data-pf-option-row]');
        if (row) {
            row.setAttribute('data-pf-invalid', '');
            row.classList.add(...invalidClasses);
        }
        const message = document.createElement('p');
        message.setAttribute('data-pf-field-error', '');
        message.className = 'mt-1 text-xs text-[var(--status-danger)]';
        message.setAttribute('role', 'alert');
        message.textContent = Array.isArray(messages) ? messages.join(' ') : String(messages);
        target.insertAdjacentElement('afterend', message);
        const panel = target.closest('[data-pf-step]');
        const step = panel ? Number(panel.dataset.pfStep) : null;
        const focus = field?.type === 'hidden' ? row?.querySelector('input:not([type="hidden"]):not(:disabled), select:not(:disabled)') : field;
        if (first === null || (step !== null && (first.step === null || step < first.step))) first = { step, field: focus };
    }
    return first;
}
