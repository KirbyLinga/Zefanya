/*
 * Seller Products index — page interactions.
 *   - row checkboxes: select-all + indeterminate state, enables "Bulk Actions"
 *   - dropdown menus (row kebab + Bulk Actions): toggle, outside click, Escape
 *   - filter selects auto-submit their form; the search input submits on Enter
 *
 * Row actions (status toggle, delete) stay in public/js/seller/products-table.js,
 * which is still loaded by the view and owns the [data-actions-*] / [data-delete] /
 * [data-status-set] contracts.
 */
document.addEventListener('DOMContentLoaded', function () {
    initBulkSelection();
    initDropdowns();
    initAutoSubmitFields();
});

/* ---------- Row selection ---------- */

function initBulkSelection() {
    var selectAll = document.querySelector('[data-select-all]');
    var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.sp-row-check'));
    var bulkToggles = Array.prototype.slice.call(document.querySelectorAll('[data-bulk-toggle]'));

    function closeBulkMenu() {
        bulkToggles.forEach(function (toggle) {
            var root = toggle.closest('[data-dropdown]');
            var menu = root ? root.querySelector('[data-dropdown-menu]') : null;
            if (menu) {
                menu.classList.remove('is-open');
                menu.hidden = true;
            }
            toggle.setAttribute('aria-expanded', 'false');
        });
    }

    function refresh() {
        var checked = rowChecks.filter(function (box) {
            return box.checked;
        }).length;

        if (!rowChecks.length) {
            return;
        }

        bulkToggles.forEach(function (toggle) {
            toggle.disabled = checked === 0;
            if (checked === 0) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        if (checked === 0) {
            closeBulkMenu();
        }

        if (selectAll) {
            selectAll.checked = checked > 0 && checked === rowChecks.length;
            selectAll.indeterminate = checked > 0 && checked < rowChecks.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rowChecks.forEach(function (box) {
                box.checked = selectAll.checked;
            });
            refresh();
        });
    }

    rowChecks.forEach(function (box) {
        box.addEventListener('change', refresh);
    });

    refresh();
}

/* ---------- Dropdown menus ---------- */

function initDropdowns() {
    var dropdowns = Array.prototype.slice.call(document.querySelectorAll('[data-dropdown]'));
    if (!dropdowns.length) {
        return;
    }

    function setOpen(root, open) {
        var toggle = root.querySelector('[data-dropdown-toggle]');
        var menu = root.querySelector('[data-dropdown-menu]');
        if (!toggle || !menu) {
            return;
        }

        // Visibility is class-driven (the menu is display:none until .is-open);
        // the hidden attribute is kept in sync for assistive technology.
        menu.classList.toggle('is-open', open);
        menu.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function closeAll(except) {
        dropdowns.forEach(function (root) {
            if (root !== except) {
                setOpen(root, false);
            }
        });
    }

    dropdowns.forEach(function (root) {
        var toggle = root.querySelector('[data-dropdown-toggle]');
        var menu = root.querySelector('[data-dropdown-menu]');
        if (!toggle || !menu) {
            return;
        }

        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            if (toggle.disabled) {
                return;
            }
            var willOpen = menu.hidden;
            closeAll(root);
            setOpen(root, willOpen);
        });

        // Any pick inside a menu (Edit, Set Active, Delete, …) closes it; the
        // action itself is handled by products-table.js or by the anchor's href.
        menu.addEventListener('click', function () {
            closeAll(null);
        });
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-dropdown]')) {
            closeAll(null);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAll(null);
        }
    });
}

/* ---------- Filter form ---------- */

function initAutoSubmitFields() {
    document.querySelectorAll('[data-auto-submit]').forEach(function (field) {
        if (field.tagName === 'SELECT') {
            field.addEventListener('change', function () {
                var form = field.form || field.closest('form');
                if (form) {
                    form.submit();
                }
            });
            return;
        }

        field.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }
            var form = field.form || field.closest('form');
            if (form) {
                event.preventDefault();
                form.submit();
            }
        });
    });
}
