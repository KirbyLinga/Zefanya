// Shared light/dark theme toggle (vanilla, no framework) — loaded by the
// seller, logistics, buyer and public shells.
// The <head> inline script in each shell sets data-theme BEFORE the stylesheet
// loads (no flash). This file only: wires the toggle, keeps
// aria-label/aria-pressed in sync, persists the choice, and enables the
// 150ms surface transitions after first paint (see theme.css).
//
// The persistence key is PER SHELL. Every layout declares its own on the <html>
// tag (seller.theme / logistics.theme / buyer.theme / public.theme), so toggling
// one panel can never switch another. The old shared key 'zf-theme' is read as a
// one-time fallback by the <head> init script only; this file never writes or
// clears it — clearing it would reset a panel the user has not visited yet.
(function () {
    var KEY = document.documentElement.dataset.themeKey || 'zf-theme';

    function apply(theme) {
        document.documentElement.dataset.theme = theme;
        var dark = theme === 'dark';
        var buttons = document.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].setAttribute('aria-pressed', dark ? 'true' : 'false');
            buttons[i].setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
            
            // Update label text to show what mode you can SWITCH TO
            var label = buttons[i].querySelector('.theme-label');
            if (label) {
                label.textContent = dark ? 'Light mode' : 'Dark mode';
            }
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
