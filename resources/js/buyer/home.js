// resources/js/buyer/home.js
// Only loaded on Buyer/Home/index.blade.php. Depends on buyer.js being
// loaded first (uses its addToCart/openQuickView functions).
//
// ⚠️⚠️ JS ↔ MARKUP CLASS COUPLING — READ BEFORE EDITING THE CARD MARKUP ⚠️⚠️
// `flashCard()` and `recoCard()` below build product cards as HTML STRINGS.
// They were converted to Tailwind IN LOCKSTEP with the shared card markup, so
// they must now stay in sync with it. The Blade-rendered card equivalents are
//   • Buyer/Cart, Buyer/Products, Buyer/Categories views (when they get real
//     markup — they are still stubs today), and
//   • anything else that renders a product card.
// Keep these three rules when editing either side:
//   1. `web` utilities are fine (rounded-xl / border-buyer-line / bg-white …),
//      but the inline `style="width:…%"` on the sold bar is REQUIRED — the
//      fill width is dynamic, so it can never be a static utility.
//   2. `wish-toggle` keeps its class name purely as a JS hook:
//      `this.classList.toggle('active')` drives `[&.active]:text-buyer-wish`.
//      Don't drop `active` handling without re-testing the heart colour.
//   3. `tab` / `active` in the tab switcher at the bottom are also JS hooks
//      (classList.add/remove) driving `[&.active]:` variants in the Blade file.
// Historical note: buyer.css styled `.card-media i` as a DESCENDANT selector
// (`font-size:34px;color:#fff;opacity:.85`), which also matched the heart icon
// inside `.wish-toggle` and made `.wish-toggle.active{color:#C4495A}` dead.
// Utilities are per-element, so that bug is gone: the heart is now a 13px
// neutral icon that turns red when active (see docs/css-migration-notes.md).
//
// TODO: `products` and `reco` are hardcoded placeholder data, same as the
// original mockup. Once ProductController/Product model exist, replace
// this with either a Blade @foreach over real data passed from the
// controller, or a fetch() call — don't leave real inventory hardcoded
// in a JS file.

const products = [
  {name:"Linen Blend Throw Pillow", price:"Rp185.000", old:"Rp250.000", icon:"fa-couch", sold:90, tag:"−26%"},
  {name:"Ceramic Pour-Over Coffee Set", price:"Rp210.000", old:"Rp310.000", icon:"fa-mug-hot", sold:70, tag:"−32%"},
  {name:"Woven Rattan Tote Bag", price:"Rp165.000", old:"Rp220.000", icon:"fa-bag-shopping", sold:55, tag:"−25%"},
  {name:"Scented Soy Candle Trio", price:"Rp120.000", old:"Rp160.000", icon:"fa-fire", sold:80, tag:"−25%"},
];
const reco = [
  {name:"Cotton Waffle Throw Blanket", price:"Rp230.000", icon:"fa-mitten", tagType:"new"},
  {name:"Bamboo Desk Organizer", price:"Rp95.000", icon:"fa-boxes-stacked"},
  {name:"Kids Wooden Building Blocks", price:"Rp175.000", icon:"fa-cubes"},
  {name:"Non-slip Yoga Mat", price:"Rp140.000", icon:"fa-person-walking"},
  {name:"Botanical Skincare Set", price:"Rp265.000", icon:"fa-spa", tagType:"new"},
  {name:"Modern Novels Bundle (3 books)", price:"Rp150.000", icon:"fa-book"},
  {name:"Garden Hand Tool Set", price:"Rp110.000", icon:"fa-trowel"},
  {name:"Stackable Storage Baskets", price:"Rp135.000", icon:"fa-boxes-packing"},
];

// Product card — Tailwind. Values 1:1 with the old buyer.css card rules:
//   .card        → flex flex-col overflow-hidden rounded-xl border-buyer-line
//                  bg-white transition-[box-shadow,transform] duration-150
//                  hover:-translate-y-[3px] hover:shadow-buyer
//   .card-media  → relative flex aspect-square cursor-pointer items-center
//                  justify-center bg-linear-[135deg] from-secondary-300
//                  to-primary-300   (--secondary #F7D6D0 → secondary-300,
//                  --primary #E2B4BD → primary-300)
//   .card-media i→ text-[34px] text-white opacity-85  ← ONLY the big icon.
//                  The old rule was a DESCENDANT selector, so it also matched
//                  the heart; utilities are per-element, which is why the heart
//                  is now a 13px neutral icon that really turns red on toggle.
//   .tag / .new  → absolute top-2.5 left-2.5 rounded-[5px] px-2 py-1 text-[10px]
//                  font-bold tracking-[0.03em] text-white, bg-buyer-ink /
//                  bg-buyer-tertiary-dark
//   .wish-toggle → absolute top-2 right-2 h-[30px] w-[30px] rounded-full
//                  border-0 bg-white text-[13px] text-neutral-500, with
//                  [&.active]:text-buyer-wish as the toggled state
//   .card-body   → flex flex-1 flex-col gap-2 px-3.5 pt-3.5 pb-4
//   .name        → min-h-9 text-[13.5px] leading-[1.35] font-semibold
//   .price(-old) → text-[14.5px] font-bold text-buyer-ink / text-[11.5px]
//                  text-neutral-500 line-through
//   .sold-bar    → h-1.5 overflow-hidden rounded-full bg-secondary-300, inner
//                  <i> = block h-full bg-buyer-primary-dark + INLINE width %
//   .sold-label  → text-[10.5px] text-neutral-500
//   .add-btn     → mt-1 flex w-full items-center justify-center gap-[7px]
//                  rounded-full border border-buyer-ink bg-transparent p-[9px]
//                  text-xs font-semibold text-buyer-ink
//                  transition-colors duration-150 hover:bg-buyer-ink
//                  hover:text-white
// NOTE: easing is Tailwind's cubic-bezier(.4,0,.2,1) rather than the CSS
// default `ease`, matching the convention already used on the landing cards.
function flashCard(p){
  return `<div class="flex flex-col overflow-hidden rounded-xl border border-buyer-line bg-white transition-[box-shadow,transform] duration-150 hover:-translate-y-[3px] hover:shadow-buyer">
    <div class="relative flex aspect-square cursor-pointer items-center justify-center bg-linear-[135deg] from-secondary-300 to-primary-300" onclick="openQuickView()">
      <span class="absolute top-2.5 left-2.5 rounded-[5px] bg-buyer-ink px-2 py-1 text-[10px] font-bold tracking-[0.03em] text-white">${p.tag}</span>
      <button class="wish-toggle absolute top-2 right-2 flex h-[30px] w-[30px] items-center justify-center rounded-full border-0 bg-white text-[13px] text-neutral-500 [&.active]:text-buyer-wish" onclick="event.stopPropagation();this.classList.toggle('active')"><i class="fa-solid fa-heart"></i></button>
      <i class="fa-solid ${p.icon} text-[34px] text-white opacity-85"></i>
    </div>
    <div class="flex flex-1 flex-col gap-2 px-3.5 pt-3.5 pb-4">
      <div class="min-h-9 text-[13.5px] leading-[1.35] font-semibold">${p.name}</div>
      <div class="flex items-baseline gap-2"><span class="text-[14.5px] font-bold text-buyer-ink">${p.price}</span><span class="text-[11.5px] text-neutral-500 line-through">${p.old}</span></div>
      <div class="h-1.5 overflow-hidden rounded-full bg-secondary-300"><i class="block h-full bg-buyer-primary-dark" style="width:${p.sold}%"></i></div>
      <div class="text-[10.5px] text-neutral-500">${p.sold}% sold</div>
      <button class="mt-1 flex w-full items-center justify-center gap-[7px] rounded-full border border-buyer-ink bg-transparent p-[9px] text-xs font-semibold text-buyer-ink transition-colors duration-150 hover:bg-buyer-ink hover:text-white" onclick="addToCart()"><i class="fa-solid fa-bag-shopping"></i> Add to cart</button>
    </div>
  </div>`;
}
function recoCard(p){
  return `<div class="flex flex-col overflow-hidden rounded-xl border border-buyer-line bg-white transition-[box-shadow,transform] duration-150 hover:-translate-y-[3px] hover:shadow-buyer">
    <div class="relative flex aspect-square cursor-pointer items-center justify-center bg-linear-[135deg] from-secondary-300 to-primary-300" onclick="openQuickView()">
      ${p.tagType==='new' ? '<span class="absolute top-2.5 left-2.5 rounded-[5px] bg-buyer-tertiary-dark px-2 py-1 text-[10px] font-bold tracking-[0.03em] text-white">NEW</span>' : ''}
      <button class="wish-toggle absolute top-2 right-2 flex h-[30px] w-[30px] items-center justify-center rounded-full border-0 bg-white text-[13px] text-neutral-500 [&.active]:text-buyer-wish" onclick="event.stopPropagation();this.classList.toggle('active')"><i class="fa-solid fa-heart"></i></button>
      <i class="fa-solid ${p.icon} text-[34px] text-white opacity-85"></i>
    </div>
    <div class="flex flex-1 flex-col gap-2 px-3.5 pt-3.5 pb-4">
      <div class="min-h-9 text-[13.5px] leading-[1.35] font-semibold">${p.name}</div>
      <div class="flex items-baseline gap-2"><span class="text-[14.5px] font-bold text-buyer-ink">${p.price}</span></div>
      <button class="mt-1 flex w-full items-center justify-center gap-[7px] rounded-full border border-buyer-ink bg-transparent p-[9px] text-xs font-semibold text-buyer-ink transition-colors duration-150 hover:bg-buyer-ink hover:text-white" onclick="addToCart()"><i class="fa-solid fa-bag-shopping"></i> Add to cart</button>
    </div>
  </div>`;
}
document.getElementById('flashGrid').innerHTML = products.map(flashCard).join('');
document.getElementById('recoGrid').innerHTML = reco.map(recoCard).join('');

// Tabs (Best Seller / New Arrivals / Top Rated / Official Store / Under Rp100.000)
document.querySelectorAll('.tab').forEach(t => t.addEventListener('click', () => {
  document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
  t.classList.add('active');
  // TODO: currently just toggles visual state. Wire up to actually
  // re-filter recoGrid once real product data/filtering exists.
}));

// Flash-sale countdown timer
let total = 4*3600 + 18*60 + 52;
setInterval(() => {
  if (total <= 0) return;
  total--;
  const h = String(Math.floor(total/3600)).padStart(2,'0');
  const m = String(Math.floor((total%3600)/60)).padStart(2,'0');
  const s = String(total%60).padStart(2,'0');
  document.getElementById('hh').textContent = h;
  document.getElementById('mm').textContent = m;
  document.getElementById('ss').textContent = s;
  document.getElementById('chipTimer').textContent = `${h}:${m}:${s}`;
}, 1000);
