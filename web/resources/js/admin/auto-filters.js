document.querySelectorAll('form[data-admin-auto-filter][method="GET" i]').forEach((form) => {
    let timer;
    form.addEventListener('input', (event) => {
        if (event.target instanceof HTMLInputElement && event.target.type === 'search') {
            clearTimeout(timer);
            timer = setTimeout(() => form.requestSubmit(), 400);
        }
    });
    form.addEventListener('change', (event) => {
        if (event.target instanceof HTMLSelectElement) {
            clearTimeout(timer);
            form.requestSubmit();
        }
    });
});
