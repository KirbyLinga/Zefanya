// Seller light/dark theme toggle (vanilla, no framework).
// The <head> inline script in Layouts/seller.blade.php sets data-theme BEFORE
// the stylesheet loads (no flash). This file only: wires the toggle, keeps
// aria-label/aria-pressed in sync, persists the choice, and enables the
// 150ms surface transitions after first paint (see theme.css).
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

    // Safety net: if the head init script could not run, default to light.
    if (!document.documentElement.dataset.theme) { apply('light'); }

    // Enable the 150ms surface transitions only after first paint.
    requestAnimationFrame(function () {
        document.documentElement.classList.add('zf-theme-ready');
    });
})();
