{{-- Shared, fail-closed confirmation dialog for form actions. --}}
@once
<style>
    #app-confirm-dialog::backdrop { background: rgb(0 0 0 / 55%); }
    #app-confirm-dialog [data-logout-only] { display: none; }
    #app-confirm-dialog[data-variant="logout"] { position: relative; max-width: 27rem !important; padding: 1.75rem 1.5rem 2rem !important; border-radius: 1.25rem !important; text-align: center; box-shadow: 0 18px 50px rgb(0 0 0 / 20%); }
    #app-confirm-dialog[data-variant="logout"] [data-logout-icon] { display: grid; place-items: center; width: 76px; height: 64px; margin: 0 auto .75rem; border-radius: 48% 52% 42% 48%; background: var(--pill-primary-bg); color: var(--link); }
    #app-confirm-dialog[data-variant="logout"] [data-logout-icon] svg { width: 38px; height: 38px; }
    #app-confirm-dialog[data-variant="logout"] [data-logout-close] { position: absolute; display: grid; place-items: center; top: 1rem; right: 1rem; width: 36px; height: 36px; border: 0; border-radius: .7rem; background: var(--pill-primary-bg); color: var(--link); font-size: 1.75rem; line-height: 1; cursor: pointer; }
    #app-confirm-dialog[data-variant="logout"] [data-title] { margin: 0 0 1rem !important; font-family: var(--font-serif); color: var(--text-ink); font-size: clamp(1.6rem, 5vw, 2rem) !important; line-height: 1.15; }
    #app-confirm-dialog[data-variant="logout"] [data-message] { max-width: 23rem; margin: 0 auto 1.5rem !important; font-family: var(--font-sans); color: var(--text-muted); font-size: 1rem !important; line-height: 1.55; }
    #app-confirm-dialog[data-variant="logout"] [data-actions] { justify-content: center !important; gap: .75rem !important; }
    #app-confirm-dialog[data-variant="logout"] :is([data-cancel], [data-ok]) { min-width: 7.5rem; min-height: 2.75rem !important; border-radius: .65rem !important; font-family: var(--font-sans); font-size: 1rem; }
    #app-confirm-dialog[data-variant="logout"] [data-ok] { background: var(--btn-primary-bg); color: var(--btn-primary-text); }
    #app-confirm-dialog[data-variant="logout"] [data-ok]::before { content: ''; display: inline-block; width: 1.15em; height: 1.15em; margin-right: .55em; vertical-align: -.2em; background: currentColor; mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M10 17l5-5-5-5M15 12H3M12 3h6a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3h-6'/%3E%3C/svg%3E") center/contain no-repeat; }
    #app-confirm-dialog[data-variant="logout"] [data-ok]:hover { background: var(--btn-primary-bg-hover) !important; }
    #app-confirm-dialog[data-variant="logout"] [data-cancel]:hover, #app-confirm-dialog[data-variant="logout"] [data-logout-close]:hover { background: var(--bg-raised) !important; }
    #app-confirm-dialog [data-logout-close]:focus-visible { outline: 3px solid var(--brand); outline-offset: 3px; }
    @media (max-width: 380px) { #app-confirm-dialog[data-variant="logout"] { padding: 2rem 1rem 1.5rem !important; } #app-confirm-dialog[data-variant="logout"] :is([data-cancel], [data-ok]) { min-width: 0; flex: 1 1 8rem; } }

    #app-confirm-dialog [data-cancel]:focus-visible,
    #app-confirm-dialog [data-ok]:focus-visible { outline: 3px solid var(--btn-primary-bg); outline-offset: 3px; }
    #app-confirm-dialog [data-ok][data-danger="true"] { background: #b91c1c; color: #fff; }
    #app-confirm-dialog [data-ok][data-danger="true"]:hover { background: #991b1b; }
</style>
<script>
    (function () {
        let opener = null;
        let active = false;

        function showUnavailableMessage() {
            if (typeof window.alert === 'function') {
                window.alert('The confirmation dialog could not be opened. No action was submitted. Reload the page and try again.');
            }
        }

        function appConfirm(message, onConfirm, options) {
            const dialog = document.getElementById('app-confirm-dialog');
            if (!dialog || typeof dialog.showModal !== 'function' || dialog.open || active) {
                showUnavailableMessage();
                return false;
            }

            const config = options || {};
            const cancel = dialog.querySelector('[data-cancel]');
            const ok = dialog.querySelector('[data-ok]');
            if (!cancel || !ok) {
                showUnavailableMessage();
                return false;
            }

            dialog.querySelector('[data-title]').textContent = config.variant === 'logout' ? 'Log Out?' : (config.title || 'Confirm action');
            dialog.querySelector('[data-message]').textContent = message;
            ok.textContent = config.variant === 'logout' ? 'Log Out' : (config.confirmLabel || 'Confirm');
            ok.dataset.danger = config.danger ? 'true' : 'false';
            dialog.dataset.variant = config.variant === 'logout' ? 'logout' : '';
            opener = config.opener || document.activeElement;
            active = true;

            ok.onclick = function () {
                if (!active) return;
                active = false;
                dialog.close();
                onConfirm();
            };
            cancel.onclick = function () {
                if (active) {
                    active = false;
                    dialog.close();
                }
            };

            try {
                dialog.showModal();
                cancel.focus();
                return true;
            } catch (error) {
                active = false;
                opener = null;
                showUnavailableMessage();
                return false;
            }
        }

        function appConfirmSubmit(form, message, options, submitter) {
            if (!form || typeof form.requestSubmit !== 'function') return false;
            if (typeof form.reportValidity === 'function' && !form.reportValidity()) return false;

            const config = Object.assign({}, options || {}, { opener: submitter || document.activeElement });
            const selectionName = form.dataset.confirmSelectionName;
            if (selectionName && form.elements) {
                const selected = Array.from(form.elements)
                    .filter(control => control.name === selectionName && control.checked)
                    .map(control => control.getAttribute('aria-label') || control.value);
                if (!selected.length) {
                    if (typeof window.alert === 'function') window.alert('Select at least one parcel before confirming the loss.');
                    return false;
                }
                message += ' Selected records: ' + selected.join(', ') + '.';
            }

            const fieldName = form.dataset.confirmFieldName;
            if (fieldName && form.elements && form.elements.namedItem(fieldName)) {
                const field = form.elements.namedItem(fieldName);
                const action = field.selectedOptions && field.selectedOptions[0] ? field.selectedOptions[0].textContent.trim() : field.value;
                if (action) message += ' Selected action: ' + action + '.';
                const dangerValues = (form.dataset.confirmDangerValues || '').split(',').filter(Boolean);
                if (dangerValues.length) config.danger = dangerValues.includes(field.value);
            }

            return appConfirm(message, function () {
                form.setAttribute('data-app-confirm-bypass', '');
                try {
                    if (submitter) form.requestSubmit(submitter);
                    else form.requestSubmit();
                } finally {
                    form.removeAttribute('data-app-confirm-bypass');
                }
            }, config);
        }

        document.addEventListener('close', function (event) {
            if (event.target.id !== 'app-confirm-dialog') return;
            active = false;
            const target = opener;
            opener = null;
            if (target && target.isConnected && typeof target.focus === 'function') target.focus();
        }, true);

        document.addEventListener('submit', function (event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-app-confirm')) return;
            if (form.hasAttribute('data-app-confirm-bypass')) {
                form.removeAttribute('data-app-confirm-bypass');
                return;
            }

            event.preventDefault();
            const options = {
                title: form.dataset.confirmTitle || 'Confirm action',
                confirmLabel: form.dataset.confirmLabel || 'Confirm',
                danger: form.dataset.confirmDanger === 'true',
                variant: form.dataset.confirmVariant || '',
            };
            appConfirmSubmit(form, form.dataset.confirmMessage || 'Continue with this action?', options, event.submitter);
        });

        const dialog = document.createElement('dialog');
        dialog.id = 'app-confirm-dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'app-confirm-title');
        dialog.setAttribute('aria-describedby', 'app-confirm-message');
        dialog.style.cssText = 'margin:auto;max-width:26rem;width:calc(100% - 2rem);max-height:90dvh;overflow:auto;padding:1.25rem;border:1px solid var(--border-strong);border-radius:0.5rem;background:var(--bg-surface);color:var(--text-ink);font-family:inherit';
        dialog.innerHTML = '<button type="button" data-logout-only data-logout-close aria-label="Close logout confirmation">&times;</button>'
            + '<div data-logout-only data-logout-icon aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 17l5-5-5-5M15 12H3M12 3h6a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3h-6"/></svg></div>'
            + '<h2 id="app-confirm-title" data-title style="margin:0 0 .75rem;font-size:1rem;font-weight:700"></h2>'
            + '<p id="app-confirm-message" data-message style="margin:0 0 1rem;font-size:.875rem;line-height:1.5;overflow-wrap:anywhere"></p>'
            + '<div data-actions style="display:flex;flex-wrap:wrap;justify-content:flex-end;gap:.5rem">'
            + '<button type="button" data-cancel style="min-height:2.75rem;padding:0 1rem;border:1px solid var(--border-strong);border-radius:.25rem;background:var(--bg-surface);color:var(--text-ink);font-weight:600;cursor:pointer">Cancel</button>'
            + '<button type="button" data-ok style="min-height:2.75rem;padding:0 1rem;border:0;border-radius:.25rem;background:var(--btn-primary-bg);color:var(--btn-primary-text);font-weight:600;cursor:pointer">Confirm</button>'
            + '</div>';
        document.body.appendChild(dialog);
        dialog.querySelector('[data-logout-close]').addEventListener('click', () => dialog.querySelector('[data-cancel]').click());

        if (typeof dialog.showModal === 'function' && typeof document.querySelectorAll === 'function') {
            document.querySelectorAll('[data-confirm-enable]').forEach(button => { button.disabled = false; });
        }
    })();
</script>
@endonce
