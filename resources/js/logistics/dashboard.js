// Zefanya — Logistics › Dashboard shell behavior.
//  1) Light/dark toggle (mirrors resources/js/seller/theme-toggle.js:
//     same 'zf-theme' localStorage key, same [data-theme-toggle] selector,
//     same zf-theme-ready gating so the icon-swap rules in theme.css apply).
//  2) Zone tab filter + parcel search (keeps state in the DOM via aria-selected).
(function () {
    'use strict';

    // Render <i data-lucide> icons (the logistics layout loads js/lucide.min.js
    // in its shell, mirroring the seller layout). Runs once when the module
    // executes so sidebar + table icons populate immediately.
    if (window.lucide) {
        lucide.createIcons();
    }

    // ── Theme toggle ────────────────────────────────────────────────
    (function () {
        var KEY = 'zf-theme';

        function apply(theme) {
            document.documentElement.dataset.theme = theme;
            var dark = theme === 'dark';
            var buttons = document.querySelectorAll('[data-theme-toggle]');
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].setAttribute('aria-pressed', dark ? 'true' : 'false');
                buttons[i].setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
            }
        }

        document.addEventListener('click', function (event) {
            var toggle = event.target.closest('[data-theme-toggle]');
            if (!toggle) { return; }

            var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            apply(next);
            try { localStorage.setItem(KEY, next); } catch (e) { /* storage unavailable */ }
        });

        if (!document.documentElement.dataset.theme) { apply('light'); }

        requestAnimationFrame(function () {
            document.documentElement.classList.add('zf-theme-ready');
        });
    })();

    // ── Parcel zone tabs + search ───────────────────────────────────
    (function () {
        var tablist = document.querySelector('[data-lg-tabs]');
        var tabs = tablist ? Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"]')) : [];
        var rows = Array.prototype.slice.call(document.querySelectorAll('[data-lg-row]'));
        var search = document.getElementById('lgParcelSearch');
        var showing = document.getElementById('lgShowingCount');

        function totalFromLabel() {
            if (!showing) { return '860'; }
            var m = showing.textContent.match(/of\s+(\d+)/i);
            return m ? m[1] : '860';
        }

        function activeZone() {
            var t = tablist ? tablist.querySelector('[aria-selected="true"]') : null;
            return t ? t.getAttribute('data-lg-zone') : 'all';
        }

        function refresh() {
            var zone = activeZone();
            var query = search ? search.value.trim().toLowerCase() : '';
            var visible = 0;

            rows.forEach(function (row) {
                var matchesZone = zone === 'all' || row.getAttribute('data-lg-zone') === zone;
                var matchesQuery = !query || row.textContent.toLowerCase().indexOf(query) > -1;
                row.hidden = !(matchesZone && matchesQuery);
                if (!row.hidden) { visible++; }
            });

            if (showing) {
                showing.textContent = 'Showing 1 to ' + visible + ' of ' + totalFromLabel() + ' parcels';
            }
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (other) {
                    var selected = other === tab;
                    other.setAttribute('aria-selected', selected ? 'true' : 'false');
                    other.classList.toggle('is-active', selected);
                });
                refresh();
            });

            tab.addEventListener('keydown', function (e) {
                if (!/^(ArrowLeft|ArrowRight|Home|End)$/.test(e.key)) { return; }
                e.preventDefault();
                var idx = tabs.indexOf(tab);
                var next;
                if (e.key === 'ArrowRight') { next = (idx + 1) % tabs.length; }
                else if (e.key === 'ArrowLeft') { next = (idx - 1 + tabs.length) % tabs.length; }
                else if (e.key === 'Home') { next = 0; }
                else { next = tabs.length - 1; }
                tabs[next].click();
                tabs[next].focus();
            });
        });

        if (search) {
            search.addEventListener('input', refresh);
            // Clear query when the zone changes so switching back to "All" is useful.
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () { search.value = ''; });
            });
        }

        var filterBtn = document.getElementById('lgFilterBtn');
        if (filterBtn) {
            filterBtn.addEventListener('click', function () {
                var on = this.getAttribute('aria-pressed') === 'true';
                this.setAttribute('aria-pressed', on ? 'false' : 'true');
                this.classList.toggle('is-pressed', !on);
            });
        }
    })();
})();
