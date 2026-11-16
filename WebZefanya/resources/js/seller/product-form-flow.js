// Presentation shared by create/edit: field values and validation stay in the form.
export function refreshProductSections(root, { step, variations, colorFirst, hasColor }) {
    root.querySelectorAll('[data-pf-step]').forEach(panel => { panel.hidden = Number(panel.dataset.pfStep) !== step; });
    const colorPhotos = root.querySelector('[data-pf-color-photos]');
    if (colorPhotos) colorPhotos.hidden = !variations || colorFirst || !hasColor;
}

export function updateStoreCategoryChoices(root) {
    const boxes = [...root.querySelectorAll('[data-pf-store-categories] input[type="checkbox"]')];
    const selected = boxes.filter(box => box.checked).length;
    boxes.forEach(box => { box.disabled = !box.checked && selected >= 3; });
    const addNew = root.querySelector('[data-pf-new-store-category]');
    if (addNew) addNew.disabled = selected >= 3;
    const count = root.querySelector('[data-pf-category-count]');
    if (count) count.textContent = boxes.length ? selected + ' of 3 selected' : '';
}

export function revealProductField(field) {
    let details = field.closest('details');
    while (details) { details.open = true; details = details.parentElement.closest('details'); }
    field.scrollIntoView({ block: 'center', behavior: 'smooth' });
}
