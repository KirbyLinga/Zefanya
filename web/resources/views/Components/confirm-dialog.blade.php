{{-- In-page replacement for window.confirm(): it never blocks the browser, so it works with screen readers and browser automation. --}}
<script>
    function appConfirm(message, onConfirm) {
        let dialog = document.getElementById('app-confirm-dialog');
        if (! dialog) {
            dialog = document.createElement('dialog');
            dialog.id = 'app-confirm-dialog';
            dialog.style.cssText = 'margin:auto;max-width:26rem;width:calc(100% - 2rem);padding:1.25rem;border:1px solid var(--border-strong);border-radius:0.5rem;background:var(--bg-surface);color:var(--text-ink);font-family:inherit';
            dialog.innerHTML = '<p data-message style="margin:0 0 1rem;font-size:0.875rem;line-height:1.5"></p>'
                + '<div style="display:flex;justify-content:flex-end;gap:0.5rem">'
                + '<button type="button" data-cancel style="min-height:2.25rem;padding:0 1rem;border:1px solid var(--border-strong);border-radius:0.25rem;background:transparent;color:inherit;font-weight:600;cursor:pointer">Cancel</button>'
                + '<button type="button" data-ok style="min-height:2.25rem;padding:0 1rem;border:0;border-radius:0.25rem;background:var(--brand);color:#fff;font-weight:600;cursor:pointer">Confirm</button>'
                + '</div>';
            document.body.appendChild(dialog);
        }

        dialog.querySelector('[data-message]').textContent = message;
        // Replace the buttons so a previous action's handler can never fire for this one.
        const ok = dialog.querySelector('[data-ok]').cloneNode(true);
        const cancel = dialog.querySelector('[data-cancel]').cloneNode(true);
        dialog.querySelector('[data-ok]').replaceWith(ok);
        dialog.querySelector('[data-cancel]').replaceWith(cancel);
        ok.addEventListener('click', () => { dialog.close(); onConfirm(); });
        cancel.addEventListener('click', () => dialog.close());

        dialog.showModal();
        cancel.focus();
    }

    /** onclick="return appConfirmSubmit(this, 'Are you sure?')" on a submit button. */
    function appConfirmSubmit(button, message) {
        const form = button.form;
        if (form.reportValidity()) {
            // requestSubmit still runs the form's own submit handlers, but does not click the button again.
            appConfirm(message, () => form.requestSubmit(button));
        }

        return false;
    }
</script>
