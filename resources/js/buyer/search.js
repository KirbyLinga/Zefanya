// One owner for buyer navbar submissions and product suggestions.
(function () {
    function initialize() {
        document.querySelectorAll('.zf-search-wrap').forEach((wrap, index) => {
            const input = wrap.querySelector('.zf-search-input');
            const button = wrap.querySelector('.zf-search-btn');
            if (!input || !button || wrap.dataset.searchInitialized === '1') return;
            wrap.dataset.searchInitialized = '1';

            const currentUrl = new URL(window.location.href);
            input.value = currentUrl.searchParams.get('q') || input.value;
            input.style.textTransform = 'none';
            let dropdown = wrap.querySelector('[data-search-suggestions]');
            if (!dropdown) {
                dropdown = document.createElement('div');
                dropdown.setAttribute('data-search-suggestions', '');
                dropdown.className = 'absolute top-[calc(100%+6px)] left-0 right-0 z-50 hidden bg-white rounded-lg border border-line shadow-xl overflow-hidden';
                wrap.appendChild(dropdown);
            }
            dropdown.id = 'buyer-search-suggestions-' + index;
            dropdown.setAttribute('role', 'listbox');
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-controls', dropdown.id);
            input.setAttribute('aria-expanded', 'false');
            let timer;
            let revision = 0;
            let options = [];
            let active = -1;

            function close() {
                clearTimeout(timer);
                revision++;
                dropdown.classList.add('hidden');
                input.setAttribute('aria-expanded', 'false');
                input.removeAttribute('aria-activedescendant');
                active = -1;
            }

            function searchUrl() {
                const current = new URL(window.location.href);
                const destination = new URL('/buyer/products', current);
                ['category', 'seller', 'sort', 'min_price', 'max_price'].forEach(key => {
                    if (current.searchParams.has(key)) destination.searchParams.set(key, current.searchParams.get(key));
                });
                const category = current.pathname.match(/^\/buyer\/categories\/(\d+)\/?$/)?.[1];
                if (category && !destination.searchParams.has('category')) destination.searchParams.set('category', category);
                const term = input.value.trim();
                if (term) destination.searchParams.set('q', term);
                destination.searchParams.set('page', '1');
                return destination.pathname + destination.search;
            }

            function submit() {
                const term = input.value.trim();
                if (term) {
                    try {
                        const stored = JSON.parse(localStorage.getItem('recentSearches') || '[]');
                        const recent = Array.isArray(stored) ? stored.filter(value => typeof value === 'string' && value !== term) : [];
                        localStorage.setItem('recentSearches', JSON.stringify([term, ...recent].slice(0, 10)));
                    } catch { /* Search also works when browser storage is unavailable. */ }
                }
                const destination = searchUrl();
                close();
                window.location.assign(destination);
            }

            function show(products) {
                dropdown.replaceChildren();
                options = [];
                active = -1;
                input.removeAttribute('aria-activedescendant');
                products.filter(product => /^\d+$/.test(String(product.id))).slice(0, 5).forEach(product => {
                    const link = document.createElement('a');
                    link.href = '/buyer/products/' + product.id;
                    link.id = dropdown.id + '-option-' + options.length;
                    link.setAttribute('role', 'option');
                    link.setAttribute('aria-selected', 'false');
                    link.className = 'flex items-center gap-3 px-4 py-3 border-b border-line last:border-b-0 hover:bg-primary-50 transition-colors';
                    const image = document.createElement(product.image ? 'img' : 'div');
                    image.className = 'h-12 w-12 object-contain rounded bg-gray-100';
                    if (product.image) {
                        image.src = product.image;
                        image.alt = '';
                    }
                    const content = document.createElement('div');
                    content.className = 'flex-1 min-w-0';
                    const name = document.createElement('div');
                    name.className = 'text-[13px] font-medium text-buyer-ink truncate';
                    name.textContent = product.name;
                    const price = document.createElement('div');
                    price.className = 'text-[12px] text-buyer-ink-soft';
                    price.textContent = product.price || '';
                    content.appendChild(name);
                    content.appendChild(price);
                    link.appendChild(image);
                    link.appendChild(content);
                    dropdown.appendChild(link);
                    options.push(link);
                });
                if (!options.length) {
                    const empty = document.createElement('div');
                    empty.className = 'px-4 py-3 text-center text-[13px] text-buyer-ink-soft';
                    empty.setAttribute('role', 'status');
                    empty.textContent = 'No results found';
                    dropdown.appendChild(empty);
                }
                dropdown.classList.remove('hidden');
                input.setAttribute('aria-expanded', 'true');
            }

            function suggest() {
                close();
                const term = input.value.trim();
                if (!term) return;
                const requestRevision = revision;
                timer = setTimeout(async () => {
                    try {
                        const response = await fetch('/buyer/search/suggestions?term=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } });
                        if (!response.ok) throw new Error('Search suggestions unavailable');
                        const data = await response.json();
                        if (revision !== requestRevision || input.value.trim() !== term) return;
                        show(Array.isArray(data.popular) ? data.popular : []);
                    } catch {
                        if (revision === requestRevision) close();
                    }
                }, 250);
            }

            button.addEventListener('click', event => { event.preventDefault(); submit(); });
            input.addEventListener('input', suggest);
            input.addEventListener('focus', suggest);
            input.addEventListener('keydown', event => {
                if (event.isComposing) return;
                if (event.key === 'Escape') { close(); return; }
                if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && !dropdown.classList.contains('hidden') && options.length) {
                    event.preventDefault();
                    active = (active + (event.key === 'ArrowDown' ? 1 : (active < 0 ? 0 : -1)) + options.length) % options.length;
                    options.forEach((option, i) => {
                        option.setAttribute('aria-selected', String(i === active));
                        option.classList.toggle('bg-primary-50', i === active);
                    });
                    input.setAttribute('aria-activedescendant', options[active].id);
                    options[active].scrollIntoView({ block: 'nearest' });
                }
                if (event.key === 'Enter') {
                    event.preventDefault();
                    if (active >= 0 && !dropdown.classList.contains('hidden')) {
                        const destination = options[active].getAttribute('href');
                        close();
                        window.location.assign(destination);
                    } else submit();
                }
            });
            document.addEventListener('click', event => { if (!wrap.contains(event.target)) close(); });
            wrap.querySelector('#catMenuToggle')?.addEventListener('change', event => { if (event.target.checked) close(); });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
