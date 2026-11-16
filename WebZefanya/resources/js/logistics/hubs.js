/** Scoped disclosure behavior; all hub validation and address lookup remain server/shared-owned. */
function initHubs() {
    const page = document.querySelector('[data-hubs-page]');
    if (!page) return;
    // This page keeps its navigation accessible on phones without letting the
    // shared desktop sidebar squeeze the network into a narrow content strip.
    // Mobile toggles are local to this page and never rewrite the saved preference.
    const sidebar = document.getElementById('logisticsSidebar');
    const sidebarToggle = document.getElementById('logisticsSidebarToggle');
    if (sidebar && sidebarToggle) {
        const mobile = window.matchMedia('(max-width: 639px)');
        let mobileActive = false;
        let desktopCollapsed = sidebar.classList.contains('is-collapsed');
        sidebar.classList.add('max-sm:fixed', 'max-sm:left-0', 'max-sm:z-40', 'max-sm:overflow-y-auto');
        page.parentElement.classList.add('max-sm:ml-[72px]', 'max-sm:p-3');
        const setCollapsed = (collapsed) => {
            sidebar.classList.toggle('is-collapsed', collapsed);
            sidebarToggle.setAttribute('aria-expanded', String(!collapsed));
            sidebarToggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            sidebarToggle.setAttribute('data-tooltip', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        };
        const syncMobile = () => {
            if (mobile.matches && !mobileActive) {
                desktopCollapsed = sidebar.classList.contains('is-collapsed');
                mobileActive = true;
                setCollapsed(true);
            } else if (!mobile.matches && mobileActive) {
                mobileActive = false;
                setCollapsed(desktopCollapsed);
            }
        };
        sidebarToggle.addEventListener('click', (event) => {
            if (!mobile.matches) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            setCollapsed(!sidebar.classList.contains('is-collapsed'));
        }, true);
        mobile.addEventListener('change', syncMobile);
        syncMobile();
    }
    const stationForms = [...page.querySelectorAll('[data-station-create]')];

    page.querySelectorAll('details').forEach((details) => {
        const summary = details.querySelector(':scope > summary');
        if (!summary) return;
        if (!summary.id) summary.id = `${details.id}-toggle`;
        summary.setAttribute('aria-controls', details.id);
        const sync = () => {
            summary.setAttribute('aria-expanded', String(details.open));
            page.querySelectorAll('[data-hub-open]').forEach((button) => {
                if (button.dataset.hubOpen === details.id) button.setAttribute('aria-expanded', String(details.open));
            });
        };
        sync();
        details.addEventListener('toggle', () => {
            if (details.open && details.hasAttribute('data-station-create')) {
                stationForms.forEach((other) => { if (other !== details) other.open = false; });
            }
            sync();
        });
    });

    page.querySelectorAll('[data-hub-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const details = document.getElementById(button.dataset.hubOpen);
            if (!details || !page.contains(details)) return;
            details.open = true;
            details.scrollIntoView({ behavior: 'smooth', block: 'start' });
            details.querySelector('input:not([type="hidden"]), select:not([disabled])')?.focus({ preventScroll: true });
        });
    });
    page.querySelectorAll('[data-hub-cancel]').forEach((button) => {
        button.addEventListener('click', () => {
            const details = button.closest('details');
            if (!details) return;
            details.open = false;
            details.querySelector(':scope > summary')?.focus();
        });
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initHubs);
else initHubs();
