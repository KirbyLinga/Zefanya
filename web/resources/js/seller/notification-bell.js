// Seller notification bell — open/close dropdown, outside-click, Escape key.
// No framework, no fetch calls. DOM-only; data is server-rendered.
(function () {
    'use strict';

    var btn      = document.getElementById('sp-bell-btn');
    var dropdown = document.getElementById('sp-notif-dropdown');

    if (!btn || !dropdown) { return; }

    function isOpen() {
        return !dropdown.hidden;
    }

    function open() {
        dropdown.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
    }

    function close() {
        dropdown.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        btn.focus();
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (isOpen()) {
            close();
        } else {
            open();
        }
    });

    // Close on outside click.
    document.addEventListener('click', function (e) {
        if (isOpen() && !dropdown.contains(e.target) && e.target !== btn) {
            close();
        }
    });

    // Close on Escape key.
    document.addEventListener('keydown', function (e) {
        if (isOpen() && (e.key === 'Escape' || e.key === 'Esc')) {
            close();
        }
    });
})();
