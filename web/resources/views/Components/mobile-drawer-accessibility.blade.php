<script>
window.zfCreateMobileDrawer = window.zfCreateMobileDrawer || function (sidebar, mobile, options) {
    let opened = false;
    let opener = null;
    let inertBefore = [];
    let previousOverflow = '';
    const focusable = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';
    function items() {
        return [...sidebar.querySelectorAll(focusable)].filter(element => !element.closest('[inert]') && element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden');
    }
    function sync(open, restoreFocus) {
        const active = mobile.matches && open;
        if (active && !opened) {
            opener = options.opener();
            previousOverflow = document.body.style.overflow;
            inertBefore = [...document.querySelectorAll('[data-mobile-drawer-content], [data-mobile-drawer-skip]')].map(element => [element, element.inert]);
            inertBefore.forEach(([element]) => { element.inert = true; });
            document.body.style.overflow = 'hidden';
            sidebar.setAttribute('role', 'dialog');
            sidebar.setAttribute('aria-modal', 'true');
            sidebar.setAttribute('aria-label', options.label);
        } else if (!active && opened) {
            inertBefore.forEach(([element, previous]) => { element.inert = previous; });
            inertBefore = [];
            document.body.style.overflow = previousOverflow;
            sidebar.removeAttribute('role');
            sidebar.removeAttribute('aria-modal');
            sidebar.removeAttribute('aria-label');
        }
        sidebar.inert = Boolean(options.hideWhenClosed && mobile.matches && !active);
        if (options.hideWhenClosed && mobile.matches && !active) sidebar.setAttribute('aria-hidden', 'true');
        else sidebar.removeAttribute('aria-hidden');
        const changed = opened !== active;
        opened = active;
        if (changed && active) {
            const initial = sidebar.querySelector('nav a[href]') || items()[0];
            (initial || sidebar).focus();
        } else if (changed && restoreFocus && opener && opener.isConnected) {
            opener.focus();
        }
    }
    document.addEventListener('keydown', function (event) {
        if (!opened) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            options.close();
            return;
        }
        if (event.key !== 'Tab') return;
        const controls = items();
        if (!controls.length) { event.preventDefault(); sidebar.focus(); return; }
        const current = document.activeElement;
        if (event.shiftKey && (current === controls[0] || !sidebar.contains(current))) {
            event.preventDefault(); controls[controls.length - 1].focus();
        } else if (!event.shiftKey && (current === controls[controls.length - 1] || !sidebar.contains(current))) {
            event.preventDefault(); controls[0].focus();
        }
    });
    return { sync: sync };
};
</script>
