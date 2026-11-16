{{-- One delegated listener for [data-toggle-visibility] on pages that do not load registration-validation.js. --}}
@once
<script>
(function () {
    if (window.__zfPasswordReveal) return;
    window.__zfPasswordReveal = true;

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-toggle-visibility]');
        if (!btn) return;

        var field = document.getElementById(btn.getAttribute('data-toggle-visibility'));
        if (!field) return;

        var reveal = field.type === 'password';
        var pos = field.value.length;
        field.type = reveal ? 'text' : 'password';
        btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
        btn.setAttribute('aria-pressed', reveal ? 'true' : 'false');

        var old = btn.querySelector('[data-lucide]');
        if (old) {
            var cls = old.getAttribute('class') || '';
            btn.innerHTML = '<i data-lucide="' + (reveal ? 'eye-off' : 'eye') + '" class="' + cls + '"></i>';
            if (window.lucide) lucide.createIcons();
        } else {
            var svg = btn.querySelector('svg');
            if (svg) {
                svg.innerHTML = reveal
                    ? '<path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 5.2A11.6 11.6 0 0 1 12 5c6.5 0 10 7 10 7a18 18 0 0 1-3.1 4.1M6.2 6.2A19.3 19.3 0 0 0 2 12s3.5 7 10 7a11 11 0 0 0 5.8-1.7"/>'
                    : '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>';
            }
        }

        setTimeout(function () {
            field.focus();
            try { field.setSelectionRange(pos, pos); } catch (err) { /* not supported */ }
        }, 0);
    });
})();
</script>
@endonce
