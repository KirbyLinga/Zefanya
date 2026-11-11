<script>
(() => {
    const dialog = document.getElementById('edit-voucher-dialog');
    const form = document.getElementById('edit-voucher-form');
    if (!dialog || !form) return;
    const sync = () => {
        const type = form.querySelector('[name="type"]:checked')?.value;
        form.querySelectorAll('[data-edit-voucher-type]').forEach(block => {
            const active = block.dataset.editVoucherType === type;
            block.hidden = !active;
            block.querySelectorAll('input').forEach(input => { input.disabled = !active; input.required = active; });
        });
        const products = document.getElementById('edit-voucher-products');
        if (products) {
            const selected = form.querySelector('[name="applies_to"]:checked')?.value === 'selected';
            products.hidden = !selected;
            products.querySelectorAll('input').forEach(input => input.disabled = !selected);
        }
    };
    form.addEventListener('change', sync);
    sync();
    dialog.addEventListener('cancel', event => { event.preventDefault(); window.location.assign(dialog.dataset.returnUrl); });
    dialog.showModal();
    document.body.style.overflow = 'hidden';
    (dialog.querySelector('.voucher-edit-errors') || document.getElementById('edit-voucher-title')).focus({ preventScroll: true });
})();
</script>
