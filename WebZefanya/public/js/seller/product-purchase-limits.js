/*
 * Seller Products — per-product purchase limits dialog.
 * Opened from a row's ⋮ menu ([data-purchase-limits-open]); saves with
 * PATCH /seller/products/{product}/purchase-limits and updates the row's
 * "Purchase limit" badge in place. Static asset; no build step required.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('plDialog');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    var form = dialog.querySelector('[data-pl-form]');
    var enabled = form.querySelector('[data-pl-enabled]');
    var exempt = form.querySelector('[data-pl-exempt]');
    var fields = form.querySelector('[data-pl-fields]');
    var productLabel = form.querySelector('[data-pl-product]');
    var submit = form.querySelector('[data-pl-submit]');
    var inputs = {
        max_quantity: form.elements.max_quantity,
        max_order: form.elements.max_order,
        max_active: form.elements.max_active
    };
    var trigger = null;
    var opener = null;

    var csrfToken = (function () {
        var el = document.getElementById('sp-csrf');
        if (el) {
            return el.textContent.trim();
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    })();

    function toast(message) {
        if (typeof window.showToast === 'function') {
            window.showToast(message);
            return;
        }
        var bar = document.createElement('div');
        bar.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 z-[60] px-4 py-2.5 rounded-sm bg-neutral-950 text-white font-sans text-[13px] shadow-lg';
        bar.setAttribute('role', 'status');
        bar.textContent = message;
        document.body.appendChild(bar);
        setTimeout(function () {
            bar.remove();
        }, 3500);
    }

    function clearErrors() {
        form.querySelectorAll('[data-pl-error]').forEach(function (el) {
            el.textContent = '';
            el.hidden = true;
        });
        form.querySelectorAll('.pl-input[data-invalid]').forEach(function (el) {
            el.removeAttribute('data-invalid');
        });
        Object.keys(inputs).forEach(function (key) {
            inputs[key].removeAttribute('aria-invalid');
        });
    }

    function showError(key, message) {
        var el = form.querySelector('[data-pl-error="' + key + '"]') || form.querySelector('[data-pl-error="general"]');
        el.textContent = message;
        el.hidden = false;
        if (inputs[key]) {
            inputs[key].setAttribute('aria-invalid', 'true');
            inputs[key].closest('.pl-input').setAttribute('data-invalid', '');
        }
    }

    function syncFields() {
        fields.hidden = !enabled.checked;
    }

    function fill(limits) {
        enabled.checked = !!limits.enabled;
        exempt.checked = !!limits.exempt_trusted;
        inputs.max_quantity.value = limits.max_quantity || '';
        inputs.max_order.value = limits.max_order || '';
        inputs.max_active.value = limits.max_active || '';
        syncFields();
    }

    function setBadge(productId, active) {
        var badge = document.querySelector('[data-purchase-limit-badge="' + productId + '"]');
        if (badge) {
            badge.hidden = !active;
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-purchase-limits-open]');
        if (!button) {
            return;
        }
        event.preventDefault();
        trigger = button;
        opener = button.closest('[data-dropdown]') ? button.closest('[data-dropdown]').querySelector('[data-dropdown-toggle]') : null;

        var limits = {};
        try {
            limits = JSON.parse(button.getAttribute('data-limits') || '{}');
        } catch (e) {
            limits = {};
        }
        clearErrors();
        fill(limits);
        productLabel.textContent = button.getAttribute('data-name') || '';
        submit.disabled = false;
        dialog.showModal();
        enabled.focus();
    });

    enabled.addEventListener('change', function () {
        syncFields();
        if (enabled.checked) {
            var firstEmpty = inputs.max_quantity.value === '' && inputs.max_order.value === '' && inputs.max_active.value === '';
            if (firstEmpty) {
                inputs.max_quantity.focus();
            }
        }
    });

    dialog.querySelectorAll('[data-pl-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            dialog.close();
        });
    });

    // A click on the dimmed backdrop (outside the panel) closes the dialog.
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    dialog.addEventListener('close', function () {
        if (opener) {
            opener.focus();
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!trigger) {
            return;
        }
        clearErrors();
        submit.disabled = true;

        var body = {
            enabled: enabled.checked,
            exempt_trusted: exempt.checked,
            max_quantity: inputs.max_quantity.value.trim(),
            max_order: inputs.max_order.value.trim(),
            max_active: inputs.max_active.value.trim()
        };
        var productId = trigger.getAttribute('data-purchase-limits-open');

        fetch(trigger.getAttribute('data-url'), {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(body)
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (data) {
                    return { ok: response.ok, status: response.status, data: data };
                });
            })
            .then(function (result) {
                if (result.ok) {
                    trigger.setAttribute('data-limits', JSON.stringify(result.data.limits || body));
                    setBadge(productId, !!result.data.active);
                    dialog.close();
                    toast(result.data.message || 'Purchase limits saved.');
                    return;
                }
                if (result.status === 422 && result.data.errors) {
                    Object.keys(result.data.errors).forEach(function (key) {
                        showError(key, result.data.errors[key][0]);
                    });
                    if (!enabled.checked && fields.hidden && (result.data.errors.max_quantity || result.data.errors.max_order || result.data.errors.max_active)) {
                        fields.hidden = false;
                    }
                    return;
                }
                showError('general', result.data.message && result.status !== 500
                    ? result.data.message
                    : 'Could not save the purchase limits. Please try again.');
            })
            .catch(function () {
                showError('general', 'Could not save the purchase limits. Check your connection and try again.');
            })
            .then(function () {
                submit.disabled = false;
            });
    });
})();
