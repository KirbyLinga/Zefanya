// resources/js/seller/product-create-modal.js
// Shared behavior for the Add Product / Edit Product modal
// (Components/seller/product-create-modal.blade.php).
//
// Modes:
//   - create: the form ships server-rendered; [data-product-create-open] opens it.
//   - edit:   [data-product-edit-open="{id}"] GETs the edit route with
//             X-Requested-With: XMLHttpRequest and injects the returned
//             Seller/Products/_form partial into the modal body; the form is
//             repointed at the update route. Submission stays a normal multipart
//             PUT (no AJAX submit).
//
// This entry also owns the shared product-form behavior (image file-name preview)
// and the 3-step Variant Options flow (Basic Info → Variant Options → Variant
// Table). All product-form behavior is document/body-level delegation, so it
// works on injected HTML (edit mode swaps the modal body) too.

document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('productModalOverlay');

    if (overlay) {
        if (overlay.dataset.jsInit === '1') {
            console.warn('[product-modal] duplicate modal found on this page — only the first instance is wired up.');
        } else {
            overlay.dataset.jsInit = '1';
            initProductModal(overlay);
        }
    }

    initImageFileNamePreview();
    initProductFormSteps();
});

function initProductModal(overlay) {
    var form = document.getElementById('productModalForm');
    var body = document.getElementById('productModalBody');
    var titleEl = document.getElementById('productModalTitle');
    var subtitleEl = document.getElementById('productModalSubtitle');
    var flagInput = document.getElementById('productModalFlag');
    var productIdInput = document.getElementById('productModalProductId');
    var submitLabel = document.getElementById('productModalSubmitLabel');

    var lastFocused = null;
    var fetchToken = 0;

    var labels = {
        create: { title: 'Add Product', subtitle: 'List a new item in your store.', submit: 'Create Product' },
        edit: { title: 'Edit Product', subtitle: '', submit: 'Save Changes' },
    };

    // Snapshot of the server-rendered create form so that switching to edit mode
    // and back restores the original markup (including its csrf token).
    var createSnapshot = null;
    if (body && body.dataset.mode === 'create') {
        createSnapshot = { html: body.innerHTML, action: form.getAttribute('action') };
    }

    function isOpen() {
        return overlay.classList.contains('is-open');
    }

    function focusFirstField() {
        var firstField = body.querySelector('#name')
            || body.querySelector('input, select, textarea')
            || document.getElementById('productModalClose');
        if (firstField) {
            firstField.focus();
        }
    }

    function openModal() {
        lastFocused = document.activeElement;
        overlay.hidden = false;
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        focusFirstField();
        if (window.lucide) window.lucide.createIcons();
    }

    function closeModal() {
        overlay.classList.remove('is-open');
        overlay.hidden = true;
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        fetchToken++; // ignore in-flight edit responses after closing
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    }

    function applyMode(mode, options) {
        body.innerHTML = options.html;
        body.dataset.mode = mode;
        form.setAttribute('action', options.action);
        flagInput.value = mode === 'edit' ? 'product-edit' : 'product-create';
        productIdInput.value = options.productId || '';
        titleEl.textContent = labels[mode].title;
        subtitleEl.textContent = options.subtitle || labels[mode].subtitle;
        submitLabel.textContent = labels[mode].submit;
        if (window.lucide) window.lucide.createIcons();
    }

    function openCreate() {
        if (body.dataset.mode !== 'create') {
            if (!createSnapshot) {
                return; // page rendered in edit mode; reload to get the create form back
            }
            applyMode('create', { html: createSnapshot.html, action: createSnapshot.action, productId: '' });
        }
        openModal();
    }

    function editUrls(productId, trigger) {
        var id = String(productId);
        return {
            fetchUrl: (trigger && trigger.getAttribute('href'))
                || (overlay.dataset.editTemplate || '').replace('__ID__', id),
            updateUrl: (trigger && trigger.dataset.updateUrl)
                || (overlay.dataset.updateTemplate || '').replace('__ID__', id),
        };
    }

    function openEdit(productId, trigger) {
        var urls = editUrls(productId, trigger);
        var token = ++fetchToken;

        // Loading state while the edit form is being fetched.
        body.innerHTML = '<div class="sp-modal-loading" role="status">Loading product…</div>';
        body.dataset.mode = 'edit';
        form.setAttribute('action', urls.updateUrl);
        flagInput.value = 'product-edit';
        productIdInput.value = String(productId);

        openModal();

        fetch(urls.fetchUrl, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text();
            })
            .then(function (html) {
                if (token !== fetchToken || !isOpen()) {
                    return; // closed or superseded meanwhile
                }
                applyMode('edit', { html: html, action: urls.updateUrl, productId: productId });
                var nameField = body.querySelector('#name');
                if (nameField && nameField.value) {
                    subtitleEl.textContent = nameField.value;
                }
                focusFirstField();
            })
            .catch(function () {
                if (token !== fetchToken || !isOpen()) {
                    return;
                }
                body.innerHTML = '<div class="sp-modal-error" role="alert">'
                    + 'Could not load this product. Close the modal and try again.</div>';
            });
    }

    // Trigger delegation — covers server-rendered rows and any re-rendered markup.
    document.addEventListener('click', function (event) {
        var createTrigger = event.target.closest('[data-product-create-open]');
        if (createTrigger) {
            event.preventDefault();
            openCreate();
            return;
        }

        var editTrigger = event.target.closest('[data-product-edit-open]');
        if (editTrigger) {
            event.preventDefault();
            openEdit(editTrigger.dataset.productEditOpen, editTrigger);
        }
    });

    document.getElementById('productModalClose').addEventListener('click', closeModal);

    overlay.querySelectorAll('[data-modal-cancel]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) closeModal();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isOpen()) closeModal();
    });

    // Server-driven auto-open:
    //   - data-auto-edit-id: ?edit={id} — fetch and open the edit form on load.
    //   - data-auto-open="1": failed store()/update() POST (modal rendered with
    //     old() input + errors) or ?create=1.
    var autoEditId = overlay.dataset.autoEditId;
    if (autoEditId && /^\d+$/.test(autoEditId)) {
        openEdit(autoEditId, null);
    } else if (overlay.dataset.autoOpen === '1') {
        if (body.dataset.mode === 'edit') {
            openModal();
        } else {
            openCreate();
        }
    }
}

function initImageFileNamePreview() {
    document.addEventListener('change', function (e) {
        var input = e.target;

        if (!input || input.tagName !== 'INPUT' || input.type !== 'file' || input.name !== 'images[]') return;

        var scope = input.closest('form') || document;
        var label = scope.querySelector('[id="spFileName"]');
        if (!label) return;

        label.textContent = input.files && input.files.length
            ? input.files.length + ' file(s) selected'
            : 'No files selected';
    });
}

// ═══════════════════════════════════════════════════════════════════════
// 3-step product form: Basic Info → Variant Options → Variant Table
//
// Server contract (StoreProductRequest):
//   variants_exist = 1 → options[{name, values}] + variants[{values, sku,
//   price, stock_quantity, is_available}] payloads are applied by
//   VariantSyncService; products.price/stock become rollups.
//   variants_exist = 0 → legacy single-SKU product; product-level
//   price/stock are mirrored into its auto Default variant.
// ═══════════════════════════════════════════════════════════════════════

function initProductFormSteps() {
    var form = document.getElementById('productModalForm');
    if (!form) return; // page without the product modal

    form.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-pf-next], [data-pf-back], [data-pf-generate]');
        if (!btn) return;

        if (btn.hasAttribute('data-pf-back')) {
            showStep(Number(btn.getAttribute('data-pf-back')));
            return;
        }

        if (btn.hasAttribute('data-pf-next')) {
            if (validateStep1()) showStep(2);
            return;
        }

        if (btn.hasAttribute('data-pf-generate')) {
            generateAndReview();
        }
    });

    form.addEventListener('change', function (event) {
        var el = event.target;

        // Second option axis row appears once option 1 has a name + values.
        if (el.matches('[data-pf-option-name], [data-pf-option-values]')) {
            syncSecondOptionRow();
            return;
        }

        // Selling checkbox on a variant row mirrors its is_available field.
        if (el.matches('[data-pf-enabled]')) {
            var flag = el.closest('[data-pf-variant-row]').querySelector('[data-pf-available]');
            if (flag) flag.value = el.checked ? '1' : '0';
        }
    });

    // Rollup hints on step 1 once variants exist.
    form.addEventListener('input', function (event) {
        if (event.target.matches('[data-pf-option-name], [data-pf-option-values]')) {
            updateComboSummary();
        }
    });

    // A failed POST re-renders the whole form server-side; if the seller had
    // variants configured, land them back on the step they were on.
    if (form.querySelector('[data-pf-variants-exist]')?.value === '1' &&
        (document.querySelector('[data-pf-error-summary]') || form.querySelector('[data-pf-variant-body] [data-pf-variant-row]'))) {
        showStep(2);
    }
}

function pfStep(index) {
    return document.querySelector('[data-pf-step="' + index + '"]');
}

function showStep(index) {
    document.querySelectorAll('[data-pf-step]').forEach(function (el) {
        el.hidden = el.getAttribute('data-pf-step') !== String(index);
    });
}

function validateStep1() {
    var form = document.getElementById('productModalForm');
    var required = form.querySelectorAll('[data-pf-step="1"] input[required], [data-pf-step="1"] select[required]');

    for (var i = 0; i < required.length; i++) {
        if (!required[i].checkValidity()) {
            showStep(1);
            required[i].reportValidity();
            return false;
        }
    }

    return true;
}

function readOptionAxes() {
    var axes = [];


    document.querySelectorAll('[data-pf-option-row]').forEach(function (row) {
        var name = (row.querySelector('[data-pf-option-name]')?.value || '').trim();
        var values = (row.querySelector('[data-pf-option-values]')?.value || '')
            .split(',')
            .map(function (v) { return v.trim(); })
            .filter(Boolean);

        if (name && values.length) {
            axes.push({ name: name, values: values });
        }
    });

    return axes.slice(0, 2);
}

function updateComboSummary() {
    var axes = readOptionAxes();
    var summary = document.querySelector('[data-pf-combo-summary]');
    if (!summary) return;


    var total = axes.reduce(function (acc, axis) { return acc * axis.values.length; }, 1);
    summary.hidden = total === 0;
    summary.textContent = total > 0
        ? total + ' variant combination' + (total === 1 ? '' : 's') + ' will be generated (max 50).'
        : '';
}

function syncSecondOptionRow() {
    var rows = document.querySelectorAll('[data-pf-option-row]');
    if (rows.length < 2) return;


    var first = rows[0];
    var firstName = (first.querySelector('[data-pf-option-name]')?.value || '').trim();
    var firstValues = (first.querySelector('[data-pf-option-values]')?.value || '').trim();
    var second = rows[1];


    second.hidden = !(firstName && firstValues);


    // Clear the hidden row so empty axes never reach the server.
    if (second.hidden) {
        second.querySelector('[data-pf-option-name]').value = '';
        second.querySelector('[data-pf-option-values]').value = '';
    }
}

function generateAndReview() {
    var form = document.getElementById('productModalForm');
    var axes = readOptionAxes();
    var errorEl = document.querySelector('[data-pf-options-error]');
    var flag = form.querySelector('[data-pf-variants-exist]');


    errorEl.hidden = true;


    if (axes.length === 0) {
        // Legacy single-SKU submit — product-level price/stock win.
        flag.value = '0';
        form.submit();
        return;
    }


    var total = axes.reduce(function (acc, axis) { return acc * axis.values.length; }, 1);


    if (total > 50) {
        errorEl.textContent = 'Too many combinations (' + total + '). Use at most 50 — fewer option values per axis.';
        errorEl.hidden = false;
        return;
    }


    flag.value = '1';
    buildVariantTable(axes);
    showStep(3);
}

function buildVariantTable(axes) {
    var body = document.querySelector('[data-pf-variant-body]');
    if (!body) return;


    // Preserve previously entered SKU/price/stock per combination signature.
    var previous = {};
    body.querySelectorAll('[data-pf-variant-row]').forEach(function (row) {
        var sig = row.querySelector('input[name$="[values]"]').value;
        previous[sig] = {
            sku: row.querySelector('input[name$="[sku]"]').value,
            price: row.querySelector('input[name$="[price]"]').value,
            stock: row.querySelector('input[name$="[stock_quantity]"]').value,
            available: row.querySelector('[data-pf-enabled]').checked,
        };
    });


    var combinations = axes.reduce(function (acc, axis) {
        var next = [];
        acc.forEach(function (combo) {
            axis.values.forEach(function (value) {
                next.push(combo.concat(value));
            });
        });
        return next;
    }, [[]]);


    body.innerHTML = '';


    combinations.forEach(function (combo, index) {
        var valuesStr = combo.join(',');
        var prior = previous[valuesStr] || {};
        var available = prior.available !== undefined ? prior.available : true;
        var tr = document.createElement('tr');
        tr.setAttribute('data-pf-variant-row', '');


        tr.innerHTML =
            '<input type="hidden" name="variants[' + index + '][values]" value="' + valuesStr + '">' +
            '<input type="hidden" name="variants[' + index + '][is_available]" data-pf-available value="' + (available ? '1' : '0') + '">' +
            '<td class="py-2 pr-3 font-semibold text-[var(--text-ink)]" data-pf-combo-label>' + combo.map(escapePfCell).join(' / ') + '</td>' +
            '<td class="py-2 pr-3"><input type="text" name="variants[' + index + '][sku]" maxlength="64" value="' + escapePfAttr(prior.sku || '') + '" class="w-28 px-2 py-1.5 border border-[var(--border-input)] rounded-sm text-[12.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]"></td>' +
            '<td class="py-2 pr-3"><input type="number" step="0.01" min="0" name="variants[' + index + '][price]" value="' + escapePfAttr(prior.price || '') + '" required class="w-24 px-2 py-1.5 border border-[var(--border-input)] rounded-sm text-[12.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]"></td>' +
            '<td class="py-2 pr-3"><input type="number" min="0" name="variants[' + index + '][stock_quantity]" value="' + escapePfAttr(prior.stock || '0') + '" required class="w-20 px-2 py-1.5 border border-[var(--border-input)] rounded-sm text-[12.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]"></td>' +
            '<td class="py-2"><input type="checkbox" data-pf-enabled ' + (available ? 'checked' : '') + ' class="cursor-pointer" aria-label="Selling this combination"></td>';


        body.appendChild(tr);
    });


    // Seed each new row's price with the product-level price for convenience.
    var productPrice = document.getElementById('price')?.value;
    if (productPrice) {
        body.querySelectorAll('input[name$="[price]"]').forEach(function (input) {
            if (input.value === '') input.value = productPrice;
        });
    }
}

function escapePfCell(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapePfAttr(text) {
    return escapePfCell(String(text)).replace(/"/g, '&quot;');
}
