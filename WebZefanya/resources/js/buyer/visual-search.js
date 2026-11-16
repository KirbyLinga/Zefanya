export const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

export function visualSearchResultMessage(count, categoryCount = 0) {
    if (categoryCount > 0) {
        const visualCount = count - categoryCount;
        const visual = visualCount > 0 ? `${visualCount} photo ${visualCount === 1 ? 'comparison' : 'comparisons'} and ` : '';
        return `${visual}${categoryCount} category ${categoryCount === 1 ? 'recommendation' : 'recommendations'} found.`;
    }
    if (count === 0) return 'No sufficiently similar catalog photos found. You can browse products by category instead.';
    return `${count} visual ${count === 1 ? 'suggestion' : 'suggestions'} found.`;
}

export function imageFileError(file) {
    if (!file) return 'Choose a JPEG, PNG or WebP image.';
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return 'Only JPEG, PNG and WebP images are supported.';
    if (file.size > MAX_IMAGE_BYTES) return 'Choose an image no larger than 5 MB.';
    return null;
}

if (typeof document !== 'undefined') {
    const cleanups = new Map();
    for (const panel of document.querySelectorAll('[data-visual-search-panel]')) {
        const form = panel.querySelector('[data-visual-search-form]');
        const input = form.querySelector('[data-visual-file]');
        const preview = form.querySelector('[data-visual-preview]');
        const message = form.querySelector('[data-visual-message]');
        const button = form.querySelector('[data-visual-submit]');
        const results = panel.querySelector('[data-visual-results]');
        const category = form.querySelector('[data-visual-category]');
        let previewUrl = null;
        let pending = null;
        let generation = 0;
        const busy = active => {
            form.setAttribute('aria-busy', String(active));
            button.disabled = active;
            button.textContent = active ? 'Comparing image…' : 'Search image';
        };
        const clearPreview = () => {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = null;
            preview.removeAttribute('src');
            preview.hidden = true;
        };
        cleanups.set(panel, () => {
            generation++;
            pending?.abort();
            clearPreview();
            form.reset();
            message.textContent = '';
            results.replaceChildren();
            busy(false);
        });
        input.addEventListener('change', () => {
            generation++;
            pending?.abort();
            clearPreview();
            busy(false);
            results.replaceChildren();
            const error = imageFileError(input.files?.[0]);
            input.setAttribute('aria-invalid', String(Boolean(error)));
            message.textContent = error || '';
            if (error) return;
            previewUrl = URL.createObjectURL(input.files[0]);
            preview.src = previewUrl;
            preview.hidden = false;
            form.requestSubmit();
        });
        category?.addEventListener('change', () => {
            generation++;
            pending?.abort();
            busy(false);
            results.replaceChildren();
            if (input.files?.[0]) form.requestSubmit();
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (button.disabled) return;
            const error = imageFileError(input.files?.[0]);
            input.setAttribute('aria-invalid', String(Boolean(error)));
            if (error) { message.textContent = error; return; }
            const current = ++generation;
            pending?.abort();
            pending = new AbortController();
            busy(true);
            results.replaceChildren();
            message.textContent = 'Comparing image structure, colors and layout with available catalog photos…';
            try {
                const response = await fetch(form.action, {
                    method: 'POST', body: new FormData(form), credentials: 'same-origin',
                    headers: { Accept: 'application/json' }, signal: pending.signal,
                });
                let data;
                try { data = await response.json(); } catch { throw new Error('Image search could not complete. Please try again.'); }
                if (current !== generation) return;
                if (!response.ok) {
                    const reason = response.status === 419 ? 'Your session expired. Refresh the page and select the image again.'
                        : response.status === 429 ? 'Too many image searches. Please wait a minute and try again.'
                        : data.errors?.image?.[0] || (response.status === 503 ? data.message : 'Image search could not complete. Please try again.');
                    throw new Error(reason);
                }
                // Trusted same-origin Blade output; all catalog text is escaped server-side.
                results.innerHTML = data.html;
                message.textContent = visualSearchResultMessage(data.count, data.category_count || 0);
                window.lucide?.createIcons();
                results.querySelector('[data-visual-results-heading]')?.focus({ preventScroll: true });
            } catch (failure) {
                if (current === generation && failure.name !== 'AbortError') message.textContent = failure.message || 'Image search is unavailable. Please try again.';
            } finally {
                if (current === generation) busy(false);
            }
        });
    }

    const dialog = document.getElementById('visual-search-dialog');
    let opener = null;
    document.querySelectorAll('[data-visual-search-open]').forEach(link => link.addEventListener('click', event => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (dialog && typeof dialog.showModal === 'function') {
            event.preventDefault();
            opener = link;
            if (!dialog.open) dialog.showModal();
            dialog.querySelector('[data-visual-file]')?.click();
        } else if (!dialog) {
            const file = document.querySelector('[data-visual-search-panel] [data-visual-file]');
            if (file) { event.preventDefault(); file.focus(); file.click(); }
        }
    }));
    dialog?.querySelector('[data-visual-search-close]')?.addEventListener('click', () => dialog.close());
    dialog?.addEventListener('close', () => {
        const panel = dialog.querySelector('[data-visual-search-panel]');
        cleanups.get(panel)?.();
        opener?.focus();
    });
    addEventListener('pagehide', () => cleanups.forEach(cleanup => cleanup()));
}
