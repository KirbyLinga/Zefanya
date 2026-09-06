// resources/js/buyer/home.js
// Only loaded on Buyer/Home/index.blade.php. Depends on buyer.js being
// loaded first (uses its addToCart/openQuickView functions).
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

function flashCard(p){
  return `<div class="card">
    <div class="card-media" onclick="openQuickView()">
      <span class="tag">${p.tag}</span>
      <button class="wish-toggle" onclick="event.stopPropagation();this.classList.toggle('active')"><i class="fa-solid fa-heart"></i></button>
      <i class="fa-solid ${p.icon}"></i>
    </div>
    <div class="card-body">
      <div class="name">${p.name}</div>
      <div class="price-row"><span class="price">${p.price}</span><span class="price-old">${p.old}</span></div>
      <div class="sold-bar"><i style="width:${p.sold}%"></i></div>
      <div class="sold-label">${p.sold}% sold</div>
      <button class="add-btn" onclick="addToCart()"><i class="fa-solid fa-bag-shopping"></i> Add to cart</button>
    </div>
  </div>`;
}
function recoCard(p){
  return `<div class="card">
    <div class="card-media" onclick="openQuickView()">
      ${p.tagType==='new' ? '<span class="tag new">NEW</span>' : ''}
      <button class="wish-toggle" onclick="event.stopPropagation();this.classList.toggle('active')"><i class="fa-solid fa-heart"></i></button>
      <i class="fa-solid ${p.icon}"></i>
    </div>
    <div class="card-body">
      <div class="name">${p.name}</div>
      <div class="price-row"><span class="price">${p.price}</span></div>
      <button class="add-btn" onclick="addToCart()"><i class="fa-solid fa-bag-shopping"></i> Add to cart</button>
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
