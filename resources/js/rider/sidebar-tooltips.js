// Rider sidebar — collapsed-state tooltips.
// Shared tooltip element appended to <body> and positioned with
// getBoundingClientRect() so the sidebar's overflow:hidden cannot clip it.
// 1:1 copy of resources/js/logistics/sidebar-tooltips.js, rebound to rider ids.
(function () {
    'use strict';

    if (!window.matchMedia('(hover: hover)').matches) { return; }

    var sidebar = document.getElementById('riderSidebar');
    if (!sidebar) { return; }

    var tip = document.createElement('div');
    tip.id = 'rider-sidebar-tip';
    tip.className = 'sp-tooltip';
    tip.setAttribute('aria-hidden', 'true');
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);

    var showTimer = null;
    var activeItem = null;

    function isCollapsed() {
        return sidebar.classList.contains('is-collapsed');
    }

    function getTooltipTarget(el) {
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
        tip.style.top = (rect.top + rect.height / 2) + 'px';
        tip.style.left = (rect.right + 8) + 'px';
    }

    function hideTip() {
        tip.classList.remove('sp-tooltip--visible');
        activeItem = null;
        clearTimeout(showTimer);
        showTimer = null;
    }

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

    sidebar.addEventListener('mouseout', function (e) {
        if (!isCollapsed()) { return; }
        var target = getTooltipTarget(e.target);
        var to = getTooltipTarget(e.relatedTarget);
        if (target && target !== to) {
            clearTimeout(showTimer);
            showTimer = null;
            showTimer = setTimeout(function () {
                if (activeItem === target) { hideTip(); }
            }, 60);
        }
    });

    sidebar.addEventListener('focusin', function (e) {
        if (!isCollapsed()) { return; }
        var target = getTooltipTarget(e.target);
        if (!target) { hideTip(); return; }
        activeItem = target;
        showTip(target);
    });

    sidebar.addEventListener('focusout', function () {
        setTimeout(function () {
            if (!sidebar.contains(document.activeElement)) { hideTip(); }
        }, 0);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') { hideTip(); }
    });

    window.addEventListener('scroll', hideTip, { passive: true });

    var observer = new MutationObserver(function () {
        if (!isCollapsed()) { hideTip(); }
    });
    observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
})();
