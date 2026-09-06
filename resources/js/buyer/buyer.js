// resources/js/buyer/buyer.js
// Shared across every Buyer page: the account dropdown, cart drawer,
// quick-view modal, chat panel, and toast — all live in the layout
// (Buyer/Layouts/app.blade.php), so their behavior belongs here rather
// than in a page-specific file like home.js.

function closeAllPanels(){
  document.getElementById('cartDrawer').classList.remove('open');
  document.getElementById('overlay').classList.remove('open');
  document.getElementById('accountDropdown').classList.remove('open');
}

function toggleCart(){
  document.getElementById('cartDrawer').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}

function toggleAccount(){
  document.getElementById('accountDropdown').classList.toggle('open');
}

document.addEventListener('click', (e) => {
  if (!e.target.closest('.header-icon-wrap') && !e.target.closest('.account-btn')) {
    document.getElementById('accountDropdown').classList.remove('open');
  }
});

function openQuickView(){ document.getElementById('qvOverlay').classList.add('open'); }
function closeQuickView(){ document.getElementById('qvOverlay').classList.remove('open'); }

document.querySelectorAll('.swatch').forEach(s => s.addEventListener('click', () => {
  document.querySelectorAll('.swatch').forEach(x => x.classList.remove('selected'));
  s.classList.add('selected');
}));
document.querySelectorAll('.size-opt').forEach(s => s.addEventListener('click', () => {
  document.querySelectorAll('.size-opt').forEach(x => x.classList.remove('selected'));
  s.classList.add('selected');
}));

let qvQty = 1;
function stepQty(d){
  qvQty = Math.max(1, qvQty + d);
  document.getElementById('qvQty').textContent = qvQty;
}

// TODO: cartCount currently starts at a hardcoded 2 (matching the original
// mockup) and only increments client-side. Once Cart is DB/session-backed,
// this should read the real count on page load instead.
let cartCount = 2;
function addToCart(){
  cartCount++;
  document.getElementById('cartCount').textContent = cartCount;
  closeQuickView();
  const toast = document.getElementById('toast');
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 1800);
}

function toggleChat(){
  document.getElementById('chatPanel').classList.toggle('open');
}
