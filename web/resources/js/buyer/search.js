/**
 * Buyer search interactions: suggestions dropdown, recent searches, debounced API calls
 */

(function () {
    let debounceTimer;
    const DEBOUNCE_DELAY = 300; // ms

    function initSearch() {
        const searchInput = document.querySelector('.zf-search-input');
        const searchBtn = document.querySelector('.zf-search-btn');
        const catMenuToggle = document.getElementById('catMenuToggle');

        if (!searchInput || !searchBtn) return;

        // Submit search on button click
        searchBtn.addEventListener('click', function () {
            const query = searchInput.value.trim();
            if (query.length >= 1) {
                savSearchTerm(query);
                window.location.href = `/buyer/search?q=${encodeURIComponent(query)}`;
            }
        });

        // Submit search on Enter key
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const query = searchInput.value.trim();
                if (query.length >= 1) {
                    saveSearchTerm(query);
                    window.location.href = `/buyer/search?q=${encodeURIComponent(query)}`;
                }
            }
        });

        // Debounced search suggestions as user types
        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            const query = searchInput.value.trim();

            if (query.length < 1) {
                // Hide suggestions if empty
                hideSuggestions();
                return;
            }

            debounceTimer = setTimeout(function () {
                fetchSearchSuggestions(query);
            }, DEBOUNCE_DELAY);
        });

        // Close suggestions when category menu opens
        if (catMenuToggle) {
            catMenuToggle.addEventListener('change', function () {
                if (this.checked) {
                    hideSuggestions();
                }
            });
        }

        // Close suggestions on outside click
        document.addEventListener('click', function (e) {
            if (!searchInput.contains(e.target) && !document.querySelector('.zf-search-suggestions')?.contains(e.target)) {
                hideSuggestions();
            }
        });
    }

    function fetchSearchSuggestions(term) {
        fetch(`/buyer/search/suggestions?term=${encodeURIComponent(term)}`, {
            headers: {
                'Accept': 'application/json',
            },
        })
            .then(res => res.json())
            .then(data => {
                displaySuggestions(term, data);
            })
            .catch(err => console.error('Error fetching search suggestions:', err));
    }

    function displaySuggestions(term, data) {
        const searchInput = document.querySelector('.zf-search-input');
        const searchWrap = document.querySelector('.zf-search-wrap');

        // Remove existing suggestions if present
        const existing = document.querySelector('.zf-search-suggestions');
        if (existing) {
            existing.remove();
        }

        // Create suggestions container
        const container = document.createElement('div');
        container.className = 'zf-search-suggestions absolute top-[calc(100%+6px)] left-0 right-0 z-50 rounded-lg border border-line bg-white shadow-lg overflow-hidden';

        let html = '';

        // Render recent searches (if any)
        if (data.recent && data.recent.length > 0) {
            html += '<div class="border-b border-line">';
            html += '<div class="px-4 py-2 text-[11px] font-bold uppercase tracking-wide text-neutral-500">Recent Searches</div>';
            data.recent.forEach(item => {
                html += `
                    <button type="button" class="w-full text-left px-4 py-2 hover:bg-gray-50 flex items-center gap-2" data-search-term="${item}">
                        <i data-lucide="clock" width="14" height="14" class="text-neutral-400"></i>
                        <span class="text-[13px] text-buyer-ink">${escapeHtml(item)}</span>
                    </button>
                `;
            });
            html += '</div>';
        }

        // Render popular/matching products
        if (data.popular && data.popular.length > 0) {
            html += '<div>';
            html += '<div class="px-4 py-2 text-[11px] font-bold uppercase tracking-wide text-neutral-500">Popular Products</div>';
            data.popular.forEach(product => {
                html += `
                    <a href="/buyer/products/${product.id}" class="block px-4 py-2 hover:bg-gray-50 flex items-center gap-2 border-b border-line last:border-0">
                        ${product.image ? `<img src="${product.image}" alt="" class="h-8 w-8 rounded object-cover bg-gray-100">` : '<div class="h-8 w-8 rounded bg-gray-100"></div>'}
                        <span class="text-[13px] text-buyer-ink truncate">${escapeHtml(product.name)}</span>
                    </a>
                `;
            });
            html += '</div>';
        }

        if (html === '') {
            html = '<div class="px-4 py-3 text-center"><p class="text-[12px] text-buyer-ink-soft">No results found</p></div>';
        }

        container.innerHTML = html;
        searchWrap.appendChild(container);

        // Wire up recent search clicks
        container.querySelectorAll('[data-search-term]').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const term = this.dataset.searchTerm;
                saveSearchTerm(term);
                window.location.href = `/buyer/search?q=${encodeURIComponent(term)}`;
            });
        });

        // Render lucide icons in suggestions
        lucide.createIcons();
    }

    function hideSuggestions() {
        const suggestions = document.querySelector('.zf-search-suggestions');
        if (suggestions) {
            suggestions.remove();
        }
    }

    function saveSearchTerm(term) {
        // Save to localStorage (for recent searches)
        try {
            let recentSearches = JSON.parse(localStorage.getItem('recentSearches') || '[]');
            // Remove duplicates and limit to 10
            recentSearches = recentSearches.filter(s => s !== term);
            recentSearches.unshift(term);
            recentSearches = recentSearches.slice(0, 10);
            localStorage.setItem('recentSearches', JSON.stringify(recentSearches));
        } catch (e) {
            console.error('Error saving search term:', e);
        }
    }

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSearch);
    } else {
        initSearch();
    }
})();
