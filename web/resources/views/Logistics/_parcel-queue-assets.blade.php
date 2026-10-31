{{-- Tabs and cards shared by the Logistics parcel queues. Colours avoid hex values that start with a digit, so they can never read as a parcel number like "#2". --}}
@push('styles')
<style>
    .ip-canvas { --ip-canvas: #fdfbf9; --ip-field: #f8f6f4; --ip-tile: #fbf1f2; --ip-tile-border: #f3e4e6; --ip-accent: rgb(107 39 55); --ip-strip: #fffbeb; --ip-strip-border: #fdf0c8; --ip-warn: rgb(146 64 14); --ip-warn-bg: #fef3c7; background-color: var(--ip-canvas); }
    html[data-theme="dark"] .ip-canvas { --ip-canvas: var(--bg-page); --ip-field: rgb(42 35 36); --ip-tile: rgb(226 180 189 / .12); --ip-tile-border: rgb(226 180 189 / .2); --ip-accent: #e2b4bd; --ip-strip: rgb(245 158 11 / .1); --ip-strip-border: rgb(245 158 11 / .2); --ip-warn: #fcd34d; --ip-warn-bg: rgb(245 158 11 / .18); }
    .ip-tab { border-bottom: 2px solid transparent; color: var(--text-muted); }
    .ip-tab:hover { color: var(--text-ink); }
    .ip-tab[aria-selected="true"] { border-bottom-color: var(--ip-accent); color: var(--ip-accent); }
    .ip-count { background-color: var(--ip-field); color: var(--text-muted); }
    .ip-tab[aria-selected="true"] .ip-count, .ip-primary { background-color: rgb(107 39 55); color: #fff; }
    .ip-primary:hover { background-color: rgb(84 29 42); }
    .ip-tile { background-color: var(--ip-tile); border: 1px solid var(--ip-tile-border); color: var(--ip-accent); }
    .ip-chip { background-color: var(--ip-field); border: 1px solid var(--border-strong); color: var(--text-ink); }
    .ip-field { background-color: var(--ip-field); }
    .ip-strip { background-color: var(--ip-strip); border-top: 1px solid var(--ip-strip-border); }
    .ip-warn { background-color: var(--ip-warn-bg); color: var(--ip-warn); }
    .ip-link { color: var(--ip-accent); }
    .ip-highlight { box-shadow: inset 0 0 0 2px #f59e0b; }
    [data-ip-toggle] svg { transition: transform .15s; }
    [data-ip-toggle][aria-expanded="true"] svg { transform: rotate(180deg); }

    .ip-amber { --ip-fg: rgb(146 64 14); --ip-bg: #fef3c7; }
    .ip-rose { --ip-fg: rgb(159 18 57); --ip-bg: #ffe4e6; }
    .ip-sky { --ip-fg: rgb(7 89 133); --ip-bg: #e0f2fe; }
    .ip-violet { --ip-fg: rgb(91 33 182); --ip-bg: #ede9fe; }
    .ip-emerald { --ip-fg: rgb(6 95 70); --ip-bg: #d1fae5; }
    .ip-maroon { --ip-fg: rgb(107 39 55); --ip-bg: #fbf1f2; }
    .ip-neutral { --ip-fg: rgb(82 82 91); --ip-bg: #f4f4f5; }
    html[data-theme="dark"] .ip-amber { --ip-fg: #fcd34d; --ip-bg: rgb(245 158 11 / .16); }
    html[data-theme="dark"] .ip-rose { --ip-fg: #fda4af; --ip-bg: rgb(244 63 94 / .16); }
    html[data-theme="dark"] .ip-sky { --ip-fg: rgb(125 211 252); --ip-bg: rgb(14 165 233 / .16); }
    html[data-theme="dark"] .ip-violet { --ip-fg: #c4b5fd; --ip-bg: rgb(139 92 246 / .18); }
    html[data-theme="dark"] .ip-emerald { --ip-fg: rgb(110 231 183); --ip-bg: rgb(16 185 129 / .16); }
    html[data-theme="dark"] .ip-maroon { --ip-fg: #e2b4bd; --ip-bg: rgb(226 180 189 / .14); }
    html[data-theme="dark"] .ip-neutral { --ip-fg: #d4d4d8; --ip-bg: rgb(161 161 170 / .16); }
    .ip-tinted { color: var(--ip-fg); background-color: var(--ip-bg); }
    .ip-tone-text { color: var(--ip-fg); }
    .ip-tab .ip-count.ip-tinted { color: var(--ip-fg); background-color: var(--ip-bg); }
    .ip-tab[aria-selected="true"] .ip-count.ip-tinted { background-color: rgb(107 39 55); color: #fff; }
</style>
@endpush

@push('scripts')
<script>
    (function () {
        const tabs = [...document.querySelectorAll('[data-ip-tab]')];
        const showStage = key => {
            tabs.forEach(tab => tab.setAttribute('aria-selected', String(tab.dataset.ipTab === key)));
            document.querySelectorAll('[data-ip-stage]').forEach(stage => { stage.hidden = stage.dataset.ipStage !== key; });
        };
        const setOpen = (row, open) => {
            row.querySelector('[data-ip-details]').hidden = ! open;
            row.querySelector('[data-ip-toggle]').setAttribute('aria-expanded', String(open));
        };

        tabs.forEach(tab => tab.addEventListener('click', event => {
            event.preventDefault();
            history.replaceState(null, '', tab.getAttribute('href'));
            showStage(tab.dataset.ipTab);
        }));
        document.addEventListener('click', event => {
            const toggle = event.target.closest('[data-ip-toggle]');
            if (toggle) setOpen(toggle.closest('li'), toggle.getAttribute('aria-expanded') !== 'true');
            const open = event.target.closest('[data-ip-open]');
            if (open) {
                const row = open.closest('li');
                setOpen(row, true);
                row.querySelector('[data-ip-details] input[name="reason"]').focus();
            }
        });

        const fromLink = location.hash.startsWith('#stage-') ? location.hash.slice(7) : null;
        if (tabs.some(tab => tab.dataset.ipTab === fromLink)) showStage(fromLink);
    })();
</script>
@endpush
