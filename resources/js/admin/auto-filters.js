// Admin list filters use native GET navigation, preserving their named fields.
const FOCUS_KEY = 'admin.filters.focus';
const SEARCH_DELAY_MS = 450;

function rememberSearch(field) {
    if (!(field instanceof HTMLInputElement) || !['search', 'text'].includes(field.type)) return;
    try {
        sessionStorage.setItem(FOCUS_KEY, JSON.stringify({
            path: new URL(field.form.action).pathname,
            name: field.name,
            value: field.value,
            start: field.selectionStart,
            end: field.selectionEnd,
            time: Date.now(),
        }));
    } catch { /* Filtering still works when storage is unavailable. */ }
}

function restoreSearch() {
    try {
        const saved = JSON.parse(sessionStorage.getItem(FOCUS_KEY) || 'null');
        sessionStorage.removeItem(FOCUS_KEY);
        if (!saved || saved.path !== location.pathname || Date.now() - saved.time > 10000
            || new URLSearchParams(location.search).get(saved.name) !== saved.value) return;
        for (const form of document.querySelectorAll('form[data-admin-auto-filter]')) {
            const field = form.elements.namedItem(saved.name);
            if (!(field instanceof HTMLInputElement) || field.value !== saved.value) continue;
            field.focus({ preventScroll: true });
            field.setSelectionRange(saved.start, saved.end);
            break;
        }
    } catch { /* Focus restoration is optional. */ }
}

for (const form of document.querySelectorAll('form[data-admin-auto-filter]')) {
    if (form.method.toLowerCase() !== 'get') continue;
    let timer;
    let composing = false;
    const cancel = () => clearTimeout(timer);
    const apply = () => {
        cancel();
        form.requestSubmit();
    };
    const isSearch = (field) => field instanceof HTMLInputElement
        && ['text', 'search'].includes(field.type) && field.name && !field.disabled;

    form.addEventListener('input', (event) => {
        if (!isSearch(event.target)) return;
        cancel();
        if (!composing && !event.isComposing) timer = setTimeout(apply, SEARCH_DELAY_MS);
    });
    form.addEventListener('change', (event) => {
        if (isSearch(event.target)) return;
        if (event.target.matches('select, input[type="checkbox"], input[type="radio"], input[type="date"]')) apply();
    });
    form.addEventListener('compositionstart', () => { composing = true; cancel(); });
    form.addEventListener('compositionend', (event) => {
        composing = false;
        if (isSearch(event.target)) { cancel(); timer = setTimeout(apply, SEARCH_DELAY_MS); }
    });
    form.addEventListener('submit', () => {
        cancel();
        for (const page of form.querySelectorAll('input[name="page"]')) page.value = '1';
        if (document.activeElement?.form === form) rememberSearch(document.activeElement);
    });
    window.addEventListener('pagehide', cancel);
}
restoreSearch();