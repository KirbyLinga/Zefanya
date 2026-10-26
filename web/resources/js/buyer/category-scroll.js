/**
 * Category Pill Scroll Controller
 * Manages horizontal scroll behavior for category pills on the products page:
 * - Dynamic fade overlays based on scroll position
 * - Arrow buttons for desktop navigation
 * - Scroll-to-active pill on page load
 * - Keyboard focus management
 * - Touch-friendly with native scroll
 */

export function initCategoryScroll() {
    const scrollTrack = document.querySelector('[data-category-scroll]');
    if (!scrollTrack) return;

    const leftBtn = document.querySelector('[data-category-scroll-left]');
    const rightBtn = document.querySelector('[data-category-scroll-right]');
    const fadeLeft = document.querySelector('[data-category-fade-left]');
    const fadeRight = document.querySelector('[data-category-fade-right]');
    const pills = document.querySelectorAll('[data-category-pill]');

    // Scroll amount for arrow buttons (70% of visible width)
    const scrollStep = () => scrollTrack.clientWidth * 0.7;

    // Update fade visibility and arrow button state based on scroll position
    const updateScrollState = () => {
        const hasScrolled = scrollTrack.scrollLeft > 0;
        const hasMoreToRight = scrollTrack.scrollLeft + scrollTrack.clientWidth < scrollTrack.scrollWidth;

        // Update fades
        if (fadeLeft) {
            fadeLeft.classList.toggle('hidden', !hasScrolled);
            if (hasScrolled) {
                fadeLeft.style.background = 'linear-gradient(to right, var(--color-neutral-50), var(--color-neutral-50) / 0.6, transparent)';
            }
        }

        if (fadeRight) {
            fadeRight.classList.toggle('hidden', !hasMoreToRight);
        }

        // Update arrow buttons
        if (leftBtn) {
            leftBtn.style.display = hasScrolled ? 'inline-flex' : 'none';
            leftBtn.disabled = !hasScrolled;
        }

        if (rightBtn) {
            rightBtn.style.display = hasMoreToRight ? 'inline-flex' : 'none';
            rightBtn.disabled = !hasMoreToRight;
        }
    };

    // Throttle scroll event with requestAnimationFrame
    let scrollScheduled = false;
    const handleScroll = () => {
        if (!scrollScheduled) {
            scrollScheduled = true;
            requestAnimationFrame(() => {
                updateScrollState();
                scrollScheduled = false;
            });
        }
    };

    // Left arrow click
    if (leftBtn) {
        leftBtn.addEventListener('click', () => {
            scrollTrack.scrollBy({
                left: -scrollStep(),
                behavior: 'smooth',
            });
        });
    }

    // Right arrow click
    if (rightBtn) {
        rightBtn.addEventListener('click', () => {
            scrollTrack.scrollBy({
                left: scrollStep(),
                behavior: 'smooth',
            });
        });
    }

    // Scroll event listener
    scrollTrack.addEventListener('scroll', handleScroll);

    // Handle window resize
    let resizeScheduled = false;
    const handleResize = () => {
        if (!resizeScheduled) {
            resizeScheduled = true;
            requestAnimationFrame(() => {
                updateScrollState();
                resizeScheduled = false;
            });
        }
    };
    window.addEventListener('resize', handleResize);

    // Handle keyboard focus — scroll active pill into view
    pills.forEach((pill) => {
        pill.addEventListener('focus', () => {
            // Small delay to allow focus to be set
            setTimeout(() => {
                // Calculate if the pill is off-screen
                const pillRect = pill.getBoundingClientRect();
                const trackRect = scrollTrack.getBoundingClientRect();

                const isOffLeft = pillRect.left < trackRect.left;
                const isOffRight = pillRect.right > trackRect.right;

                if (isOffLeft || isOffRight) {
                    // Scroll into view, preferring center
                    pill.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest',
                        inline: 'center',
                    });
                    updateScrollState();
                }
            }, 0);
        });
    });

    // Scroll active pill into view on load
    const activePill = document.querySelector('[data-category-pill][class*="bg-primary-800"]');
    if (activePill) {
        // Use setTimeout to ensure DOM is ready
        setTimeout(() => {
            const pillRect = activePill.getBoundingClientRect();
            const trackRect = scrollTrack.getBoundingClientRect();

            const isOffScreen = pillRect.left < trackRect.left || pillRect.right > trackRect.right;

            if (isOffScreen) {
                activePill.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                    inline: 'center',
                });
            }

            updateScrollState();
        }, 100);
    } else {
        // No active pill, just update state
        updateScrollState();
    }
}

// Auto-initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCategoryScroll);
} else {
    initCategoryScroll();
}
