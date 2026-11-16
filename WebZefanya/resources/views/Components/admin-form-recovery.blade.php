@if ($errors->any() && old('_ui_form'))
<script>
(function () {
    const formKey = {{ Illuminate\Support\Js::from(old('_ui_form')) }};
    const values = {{ Illuminate\Support\Js::from(\Illuminate\Support\Arr::only(session()->getOldInput(), ['reference', 'reason', 'outcome', 'note', 'retry_note', 'resume_note', 'close_reason', 'disposition', 'disposition_note', 'resolution_note', 'origin_hub_id', 'barangay_code', 'address', 'evidence', 'fault_party', 'fault_note', 'void_reason', 'amount', 'recipient', 'paid_on', 'refund_reason'])) }};
    const errors = {{ Illuminate\Support\Js::from($errors->getMessages()) }};
    const form = [...document.querySelectorAll('form')].find(candidate => candidate.elements.namedItem('_ui_form')?.value === formKey);
    if (!form) return;

    for (let details = form.closest('details'); details; details = details.parentElement.closest('details')) details.open = true;
    const controls = [...form.elements].filter(field => field.name && !['hidden', 'password', 'file', 'submit', 'button'].includes(field.type));
    controls.forEach(field => {
        if (!Object.prototype.hasOwnProperty.call(values, field.name)) return;
        if (field.type === 'radio' || field.type === 'checkbox') field.checked = String(values[field.name]) === field.value;
        else field.value = values[field.name] ?? '';
    });

    const summary = document.createElement('div');
    summary.className = 'mb-3 rounded-lg border border-[var(--pill-danger-border)] bg-[var(--pill-danger-bg)] p-4 text-sm text-[var(--status-danger)]';
    summary.setAttribute('role', 'alert');
    summary.setAttribute('tabindex', '-1');
    const title = document.createElement('p');
    title.className = 'font-semibold';
    title.textContent = 'Please check the form and correct the information below.';
    summary.appendChild(title);
    const list = document.createElement('ul');
    list.className = 'mt-1 list-inside list-disc';
    summary.appendChild(list);
    let firstInvalid = null;
    Object.entries(errors).forEach(([name, messages], index) => {
        const text = (Array.isArray(messages) ? messages : [messages]).join(' ');
        const item = document.createElement('li');
        item.textContent = text;
        const matching = controls.filter(field => field.name === name);
        const file = [...form.querySelectorAll('input[type="file"]')].find(field => field.name === name);
        const target = matching[0] || file;
        if (!target) { list.appendChild(item); return; }
        const id = 'admin-form-error-' + index;
        const error = document.createElement('p');
        error.id = id;
        error.className = 'mt-1 text-xs text-[var(--status-danger)]';
        error.textContent = text;
        const label = target.closest('label');
        if (label) label.appendChild(error);
        else target.insertAdjacentElement('afterend', error);
        (matching.length ? matching : [target]).forEach(field => {
            field.setAttribute('aria-invalid', 'true');
            field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), id].filter(Boolean).join(' '));
        });
        if (!firstInvalid && !target.disabled) firstInvalid = target;
    });
    if (form.querySelector('input[type="file"]')) {
        const note = document.createElement('p');
        note.className = 'mt-2';
        note.textContent = 'Please select any proof file again.';
        summary.appendChild(note);
    }
    form.insertAdjacentElement('beforebegin', summary);
    (firstInvalid || summary).focus();
    form.scrollIntoView({block: 'center'});
})();
</script>
@endif
