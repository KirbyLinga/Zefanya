{{-- In-page replacement for window.confirm(): it never blocks the browser, so it works with screen readers and browser automation. --}}
<script>
    function sellerConfirm(message, onConfirm) {
        let dialog = document.getElementById('seller-confirm-dialog');
        if (! dialog) {
            dialog = document.createElement('dialog');
            dialog.id = 'seller-confirm-dialog';
            dialog.setAttribute('aria-labelledby', 'seller-confirm-message');
            dialog.style.cssText = 'margin:auto;max-width:26rem;width:calc(100% - 2rem);padding:1.25rem;border:1px solid var(--border-strong);border-radius:0.5rem;background:var(--bg-surface);color:var(--text-ink);font-family:inherit';
            dialog.innerHTML = '<p id="seller-confirm-message" style="margin:0 0 1rem;font-size:0.875rem;line-height:1.5"></p>'
                + '<div style="display:flex;justify-content:flex-end;gap:0.5rem">'
                + '<button type="button" data-seller-confirm="cancel" style="min-height:2.25rem;padding:0 1rem;border:1px solid var(--border-strong);border-radius:0.25rem;background:transparent;color:inherit;font-weight:600;cursor:pointer">Cancel</button>'
                + '<button type="button" data-seller-confirm="ok" style="min-height:2.25rem;padding:0 1rem;border:0;border-radius:0.25rem;background:var(--brand);color:#fff;font-weight:600;cursor:pointer">Confirm</button>'
                + '</div>';
            document.body.appendChild(dialog);
        }

        dialog.querySelector('#seller-confirm-message').textContent = message;
        const ok = dialog.querySelector('[data-seller-confirm="ok"]');
        const cancel = dialog.querySelector('[data-seller-confirm="cancel"]');
        // Replace the buttons so a previous order's handler can never fire for this one.
        const freshOk = ok.cloneNode(true);
        const freshCancel = cancel.cloneNode(true);
        ok.replaceWith(freshOk);
        cancel.replaceWith(freshCancel);
        freshOk.addEventListener('click', () => { dialog.close(); onConfirm(); });
        freshCancel.addEventListener('click', () => dialog.close());

        dialog.showModal();
        freshCancel.focus();
    }
</script>
