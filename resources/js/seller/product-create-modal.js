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
// This entry also owns the shared product-form behavior (image file-name preview).
// It is document-level delegation, so it works on injected HTML too.

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

