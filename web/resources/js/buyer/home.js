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


// Category row. The browser scrolls and snaps it; this only drives the arrows
// and dots, and keeps them in step however the row was moved.
(function () {
  const row = document.getElementById('catCarousel');
  const dots = document.getElementById('catDots');
  if (!row || !dots || !row.children.length) return;
  const controls = document.getElementById('catControls');
  const prev = document.getElementById('catPrev');
  const next = document.getElementById('catNext');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let pending = false;

  const perView = () => parseInt(getComputedStyle(row).getPropertyValue('--cat-per-view'), 10) || 5;
  const pageCount = () => Math.ceil(row.children.length / perView());
  const pageWidth = () => (row.children[0].offsetWidth + (parseFloat(getComputedStyle(row).columnGap) || 0)) * perView();
  const maxScroll = () => row.scrollWidth - row.clientWidth;

  function go(page) {
    row.scrollTo({ left: page * pageWidth(), behavior: reducedMotion.matches ? 'auto' : 'smooth' });
  }

  function currentPage() {
    if (row.scrollLeft >= maxScroll() - 1) return pageCount() - 1;
    return Math.round(row.scrollLeft / pageWidth());
  }

  function sync() {
    pending = false;
    const page = currentPage();
    [...dots.children].forEach((dot, index) => {
      const active = index === page;
      dot.setAttribute('aria-current', String(active));
      dot.firstElementChild.classList.toggle('bg-primary-800', active);
      dot.firstElementChild.classList.toggle('scale-125', active);
      dot.firstElementChild.classList.toggle('bg-buyer-line', !active);
    });
    prev.disabled = row.scrollLeft <= 1;
    next.disabled = row.scrollLeft >= maxScroll() - 1;
  }

  function build() {
    const pages = pageCount();
    controls.hidden = pages < 2;
    controls.classList.toggle('flex', pages > 1);
    if (dots.children.length !== pages) {
      dots.replaceChildren(...Array.from({ length: pages }, (_, index) => {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = 'flex h-8 w-6 cursor-pointer items-center justify-center rounded-full border-0 bg-transparent p-0';
        dot.setAttribute('aria-label', 'Category page ' + (index + 1));
        const mark = document.createElement('span');
        mark.className = 'h-2 w-2 rounded-full bg-buyer-line transition-[background-color,transform] duration-200 motion-reduce:transition-none';
        dot.appendChild(mark);
        dot.addEventListener('click', () => go(index));
        return dot;
      }));
    }
    sync();
  }

  prev.addEventListener('click', () => go(currentPage() - 1));
  next.addEventListener('click', () => go(currentPage() + 1));
  row.addEventListener('scroll', () => {
    if (pending) return;
    pending = true;
    requestAnimationFrame(sync);
  }, { passive: true });
  new ResizeObserver(build).observe(row);
  build();
})();

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
