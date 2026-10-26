// resources/js/buyer/home.js
// Buyer home page behaviour: category carousel tab visuals and cart hooks.
// Catalog data is rendered by Blade from the Product model.

// Tab switching for the category carousel / "Featured / New / Best-selling" tabs
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-home-tab').forEach((tab) => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.js-home-tab').forEach((other) => {
        other.classList.remove('active');
        other.setAttribute('aria-selected', 'false');
      });
      tab.classList.add('active');
      tab.setAttribute('aria-selected', 'true');
    });
  });

  // Add-to-cart on product cards is handled globally by add-to-cart-animation.js
  // (`.add-to-cart-btn` → window.handleAddToCartSimple → window.addToCart).
});


// Real-data hero carousel; hidden slides are also inert for keyboard users.
(function () {
  const hero = document.querySelector('[data-home-hero]');
  if (!hero) return;
  const slides = [...hero.querySelectorAll('[data-hero-slide]')];
  if (slides.length < 2) return;
  const track = hero.querySelector('[data-hero-track]');
  const dots = [...hero.querySelectorAll('[data-hero-dot]')];
  // Keep all slides in the horizontal track so navigation visibly scrolls.
  slides.forEach(slide => { slide.hidden = false; });
  const status = hero.querySelector('[data-hero-status]');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let index = 0;
  let timer;
  let hovering = false;
  let touching = false;
  let startX = 0;
  let startY = 0;
  let swiped = false;

  function schedule() {
    clearTimeout(timer);
    if (reducedMotion.matches || hovering || touching || document.hidden ||
        hero.matches(':focus-visible') || hero.querySelector(':focus-visible')) return;
    timer = setTimeout(() => show(index + 1), 6000);
  }

  function show(next, announce = false) {
    index = (next + slides.length) % slides.length;
    slides.forEach((slide, i) => {
      slide.inert = i !== index;
      slide.setAttribute('aria-hidden', String(i !== index));
      dots[i].setAttribute('aria-current', String(i === index));
    });
    track.style.transform = 'translateX(-' + (index * 100) + '%)';
    if (announce) status.textContent = 'Slide ' + (index + 1) + ' of ' + slides.length;
    schedule();
  }

  hero.querySelector('[data-hero-prev]').addEventListener('click', () => show(index - 1, true));
  hero.querySelector('[data-hero-next]').addEventListener('click', () => show(index + 1, true));
  dots.forEach((dot, i) => dot.addEventListener('click', () => show(i, true)));
  hero.addEventListener('keydown', event => {
    if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
    event.preventDefault();
    const active = document.activeElement;
    if (slides.some(slide => slide.contains(active))) hero.focus();
    show(index + (event.key === 'ArrowRight' ? 1 : -1), true);
  });
  hero.addEventListener('pointerenter', event => {
    if (event.pointerType !== 'mouse') return;
    hovering = true;
    schedule();
  });
  hero.addEventListener('pointerleave', event => {
    if (event.pointerType !== 'mouse') return;
    hovering = false;
    schedule();
  });
  hero.addEventListener('focusin', schedule);
  hero.addEventListener('focusout', () => setTimeout(schedule, 0));
  hero.addEventListener('touchstart', event => {
    touching = true;
    swiped = false;
    startX = event.touches[0].clientX;
    startY = event.touches[0].clientY;
    schedule();
  }, { passive: true });
  hero.addEventListener('touchend', event => {
    const dx = event.changedTouches[0].clientX - startX;
    const dy = event.changedTouches[0].clientY - startY;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
      swiped = true;
      show(index + (dx < 0 ? 1 : -1), true);
    }
    touching = false;
    schedule();
  }, { passive: true });
  hero.addEventListener('touchcancel', () => {
    touching = false;
    schedule();
  }, { passive: true });
  hero.addEventListener('click', event => {
    if (!swiped) return;
    event.preventDefault();
    event.stopPropagation();
    swiped = false;
  }, true);
  reducedMotion.addEventListener('change', schedule);
  document.addEventListener('visibilitychange', schedule);
  show(0);
})();
