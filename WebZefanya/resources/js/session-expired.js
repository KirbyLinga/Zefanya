// Global "session expired" popup. Loaded on every layout.
// Any 401/419 from fetch or XMLHttpRequest on this origin opens a modal that
// sends the user back to the right login page. Self-contained: injects its own CSS.
(function () {
  'use strict';
  if (window.__sessionExpiredModal) { return; }

  var overlay = null;
  var shown = false;

  function loginUrl() {
    var seg = (location.pathname.split('/')[1] || '').toLowerCase();
    return seg === 'admin' ? '/admin/login' : '/?login=1';
  }

  function injectCss() {
    var s = document.createElement('style');
    s.textContent =
      '.se-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,10,10,.55);backdrop-filter:blur(2px);font-family:Montserrat,system-ui,sans-serif}' +
      '.se-overlay[hidden]{display:none}' +
      '.se-card{width:100%;max-width:400px;background:#fff;color:#1f1514;border-radius:16px;padding:28px 24px 22px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.35)}' +
      '.se-icon{width:52px;height:52px;margin:0 auto 14px;border-radius:50%;background:#fdecec;color:#c62828;display:flex;align-items:center;justify-content:center}' +
      '.se-card h2{margin:0 0 8px;font-size:18px;font-weight:700}' +
      '.se-card p{margin:0 0 20px;font-size:14px;line-height:1.5;color:#5b4a48}' +
      '.se-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}' +
      '.se-btn{min-height:44px;padding:0 20px;border-radius:10px;border:1px solid #c62828;font:600 13px Montserrat,system-ui,sans-serif;cursor:pointer;background:transparent;color:#c62828}' +
      '.se-btn-primary{background:#c62828;color:#fff}' +
      '.se-btn:focus-visible{outline:3px solid #c62828;outline-offset:2px}' +
      '@media (prefers-color-scheme:dark){.se-card{background:#2a1f1e;color:#f5eceb}.se-card p{color:#cdbab8}.se-icon{background:#4a2321;color:#ff8a80}.se-btn{border-color:#ff8a80;color:#ff8a80}.se-btn-primary{background:#ff8a80;color:#1f1514}}';
    document.head.appendChild(s);
  }

  function build() {
    if (overlay) { return overlay; }
    injectCss();
    overlay = document.createElement('div');
    overlay.className = 'se-overlay';
    overlay.hidden = true;
    overlay.setAttribute('role', 'alertdialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'se-title');
    overlay.innerHTML =
      '<div class="se-card">' +
        '<div class="se-icon" aria-hidden="true"><svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>' +
        '<h2 id="se-title">Session expired</h2>' +
        '<p>For your security, you were signed out after a period of inactivity. Please log in again to continue.</p>' +
        '<div class="se-actions">' +
          '<button type="button" class="se-btn se-btn-primary" data-se="login">Log in again</button>' +
          '<button type="button" class="se-btn" data-se="close">Dismiss</button>' +
        '</div>' +
      '</div>';
    overlay.addEventListener('click', function (e) {
      var a = e.target && e.target.getAttribute && e.target.getAttribute('data-se');
      if (a === 'login') { location.assign(loginUrl()); }
      else if (a === 'close') { hide(); }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && shown) { hide(); }
    });
    document.body.appendChild(overlay);
    return overlay;
  }

  function show() {
    if (shown) { return; }
    if (!document.body) { document.addEventListener('DOMContentLoaded', show); return; }
    build().hidden = false;
    shown = true;
    var b = overlay.querySelector('[data-se="login"]');
    if (b) { b.focus(); }
  }

  function hide() {
    if (overlay) { overlay.hidden = true; }
    shown = false;
  }

  function sameOrigin(url) {
    try { return new URL(url, location.href).origin === location.origin; }
    catch (e) { return true; }
  }

  function isLoginAttempt(url) {
    try {
      var p = new URL(url, location.href).pathname;
      return /\/login$|\/two-factor|\/logout$/.test(p);
    } catch (e) { return false; }
  }

  function check(status, url) {
    if ((status === 401 || status === 419) && sameOrigin(url) && !isLoginAttempt(url)) { show(); }
  }

  var origFetch = window.fetch;
  if (typeof origFetch === 'function') {
    window.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      return origFetch.apply(this, arguments).then(function (res) {
        check(res.status, url);
        return res;
      });
    };
  }

  var origOpen = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (method, url) {
    var self = this;
    this.addEventListener('loadend', function () { check(self.status, url); });
    return origOpen.apply(this, arguments);
  };

  window.__sessionExpiredModal = { show: show, hide: hide };
})();
