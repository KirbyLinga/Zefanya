// Seller sidebar — collapsed-state tooltips.
// One shared tooltip element is appended to <body> and positioned with
// getBoundingClientRect() so the sidebar's overflow:hidden cannot clip it.
// Tooltips only show when:
//   - the sidebar is collapsed (has class "is-collapsed")
//   - the device supports hover (@media (hover: hover))
// Events are delegated from #sellerSidebar; the tooltip is aria-hidden because
// accessible names come from aria-label on each control.
(function () {
    'use strict';

    // Only activate on pointer devices that support hover.
    if (!window.matchMedia('(hover: hover)').matches) { return; }

    var sidebar  = document.getElementById('sellerSidebar');
    if (!sidebar) { return; }

    // ── Create the shared tooltip element ──────────────────────────────────
    var tip = document.createElement('div');
    tip.id = 'sp-sidebar-tip';
    tip.className = 'sp-tooltip';
    tip.setAttribute('aria-hidden', 'true');
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);

    var showTimer  = null;
    var activeItem = null;

    // ── Helpers ────────────────────────────────────────────────────────────
    function isCollapsed() {
        return sidebar.classList.contains('is-collapsed');
    }

    function getTooltipTarget(el) {
        // Walk up from the event target to find the nearest [data-tooltip].
        // Stop at the sidebar boundary.
        var node = el;
        while (node && node !== sidebar) {
            if (node.dataset && node.dataset.tooltip) { return node; }
            node = node.parentElement;
        }
        return null;
    }

    function showTip(target) {
        var label = target.dataset.tooltip;
        if (!label) { return; }

        tip.textContent = label;
        tip.classList.add('sp-tooltip--visible');

        var rect = target.getBoundingClientRect();
        // Position: 8px to the right of the rail, vertically centred on target.
        var top  = rect.top + rect.height / 2;
        var left = rect.right + 8;

        tip.style.top  = top + 'px';
        tip.style.left = left + 'px';
    }

    function hideTip() {
        tip.classList.remove('sp-tooltip--visible');
        activeItem = null;
        clearTimeout(showTimer);
        showTimer = null;
    }

    // ── Mouse events (delegated on sidebar) ────────────────────────────────
    sidebar.addEventListener('mouseover', function (e) {
        if (!isCollapsed()) { return; }

        var target = getTooltipTarget(e.target);
        if (!target) { hideTip(); return; }
        if (target === activeItem) { return; }

        activeItem = target;
        clearTimeout(showTimer);
        showTimer = setTimeout(function () { showTip(target); }, 120);
    });

    sidebar.addEventListener('mouseleave', function () {
        hideTip();
    });

    // Hide when individual item is moused out (covers gaps between items).
    sidebar.addEventListener('mouseout', function (e) {
        if (!isCollapsed()) { return; }
        var target = getTooltipTarget(e.target);
        var to     = getTooltipTarget(e.relatedTarget);
        if (target && target !== to) {
            clearTimeout(showTimer);
            showTimer = null;
            // Don't hide immediately — mouseover on the next item fires first.
            // Use a short delay so moving between adjacent items feels smooth.
            showTimer = setTimeout(function () {
                if (activeItem === target) { hideTip(); }
            }, 60);
        }
    });

    // ── Keyboard focus (delegated) ─────────────────────────────────────────
    sidebar.addEventListener('focusin', function (e) {
        if (!isCollapsed()) { return; }
        var target = getTooltipTarget(e.target);
        if (!target) { hideTip(); return; }
        activeItem = target;
        showTip(target);
    });

    sidebar.addEventListener('focusout', function (e) {
        // Hide unless focus moves to another item inside the sidebar.
        setTimeout(function () {
            if (!sidebar.contains(document.activeElement)) { hideTip(); }
        }, 0);
    });

    // ── Global hide conditions ─────────────────────────────────────────────
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') { hideTip(); }
    });

    window.addEventListener('scroll', hideTip, { passive: true });

    // Hide when the sidebar expands (MutationObserver on class list).
    var observer = new MutationObserver(function () {
        if (!isCollapsed()) { hideTip(); }
    });
    observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
})();
