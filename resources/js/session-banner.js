// Shared helpers for in-panel JS that talks to the app's own HTTP endpoints.
//
// Reuses the same named helpers the auth/OR code already has in
// buyer.js / rider-register-modal.js, so the session-expired banner can be
// wired through one place instead of bespoke per-script logic.
//
// Noun used by the existing auth/OR helpers:
//   - sessionExpiredKey  — sessionStorage key that signals an expired auth/OR
//                           session was detected after a login-type flow.
//   - expiredSessionSeen — helper that reads that key and clears it.
if (typeof window === 'undefined') {
  module.exports = {};
}

(function (global) {
  'use strict';

  var root = typeof global !== 'undefined' ? global : window;

  // ---------------------------------------------------------------------------
  // Existing pattern: auth/OR flows already stash a session-expired notice in
  // sessionStorage so the landing page can act on it after a reload. We reuse
  // the same idea here for in-panel scripts: each script that sees a 401/419
  // from its own panel should set this key, and the shared helper surfaces it.
  // ---------------------------------------------------------------------------

  var SESSION_EXPIRED_KEY = 'app_session_expired';

  /**
   * Mark the current browser session as having seen an expired app session.
   *
   * Stored in sessionStorage so it survives only the current tab lifetime and
   * does not leak across tabs or persist after a reload.
   */
  function markExpiredSessionSeen() {
    try {
      root.sessionStorage.setItem(SESSION_EXPIRED_KEY, '1');
    } catch (e) {
      // sessionStorage can throw in some contexts (e.g. file:// or when
      // blocked). Failing here is non-fatal: the script will still surface
      // the banner from the current fetch failure.
    }
  }

  /**
   * Return true when an expired-session marker is present, and clear it.
   *
   * Call this on page load (or when entering a panel) to surface any banner
   * that was set by a prior in-panel fetch failure.
   */
  function expiredSessionSeen() {
    try {
      if (root.sessionStorage.getItem(SESSION_EXPIRED_KEY) === '1') {
        root.sessionStorage.removeItem(SESSION_EXPIRED_KEY);
        return true;
      }
    } catch (e) {
      // Non-fatal: a blocked sessionStorage just means we can't show the
      // previously-set banner. The current tab may still show a live one.
    }
    return false;
  }

  /**
   * Shared session-expired banner renderer.
   *
   * Used by rider and logistics notification bells (and any other in-panel
   * script that wants the same message) instead of each script inventing its
   * own banner markup. The banner is a single container appended to the body;
   * calling showBanner(true/false) toggles visibility.
   *
   * Using the existing app error container if present keeps the banner in the
   * same visual tier as other app-alerts; falling back to a body-level banner
   * keeps it visible when no container exists.
   */
  var bannerEl = null;
  var bannerTarget = null;

  function findBannerTarget() {
    if (bannerTarget) { return bannerTarget; }

    // Prefer the existing app error/alerts container if the page provides one.
    var candidates = [
      root.document.querySelector('x-container-error'),
      root.document.querySelector('[class*="page-container"]'),
      root.document.body,
    ];

    for (var i = 0; i < candidates.length; i++) {
      if (candidates[i]) {
        bannerTarget = candidates[i];
        break;
      }
    }

    return bannerTarget;
  }

  function buildBanner() {
    if (bannerEl) { return bannerEl; }

    bannerEl = root.document.createElement('div');
    bannerEl.className = 'app-session-banner sp-banner sp-banner--danger';
    bannerEl.setAttribute('role', 'alert');
    bannerEl.setAttribute('aria-live', 'assertive');
    bannerEl.innerHTML =
      '<div class="sp-banner__inner flex items-center gap-2">' +
        '<span class="sp-banner__icon" aria-hidden="true">' +
          '<svg class="h-[16px] w-[16px]" fill="none" viewBox="0 0 24 24" stroke="currentColor">' +
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />' +
          '</svg>' +
        '</span>' +
        '<span class="sp-banner__text">Your session expired. Log in again.</span>' +
        '<button type="button" class="sp-banner__close ml-auto" aria-label="Dismiss">' +
          '<svg class="h-[14px] w-[14px]" fill="none" viewBox="0 0 24 24" stroke="currentColor">' +
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />' +
          '</svg>' +
        '</button>' +
      '</div>';

    bannerEl.addEventListener('click', function (e) {
      if (e.target.tagName === 'BUTTON') {
        hideBanner();
      }
    });

    return bannerEl;
  }

  function showBanner() {
    var target = findBannerTarget();
    if (!target) { return; }

    if (!root.document.body.contains(bannerEl)) {
      target.insertBefore(bannerEl, target.firstChild);
    }

    bannerEl.hidden = false;
  }

  function hideBanner() {
    if (!bannerEl) { return; }
    bannerEl.hidden = true;
  }

  /**
   * Returns true when the shared banner is currently visible.
   */
  function bannerVisible() {
    return !!bannerEl && !bannerEl.hidden;
  }

  /**
   * Retrofit window.fetch so that any 401/419 response from the app is treated
   * as an expired session and raises the shared banner.
   *
   * Safe to call multiple times; each call wraps the current fetch. Designed
   * for the rider and logistics bell fetches (and any other in-panel fetch)
   * when you want the same "log in again" behavior without each script
   * inventing its own 401/419 handling.
   *
   * Does not touch non-401/419 failures: network errors, 429, 5xx etc. are
   * left to the caller's existing best-effort handling.
   */
  function retrofitFetchForSessionExpiry() {
    var originalFetch = root.fetch;
    if (!originalFetch || typeof originalFetch !== 'function') { return; }

    root.fetch = function retrofitFetch(input, init) {
      return originalFetch.call(root, input, init).then(function (response) {
        // 401 and 419 are both signals that the session / CSRF state is no
        // longer valid for this request. Treat both as "log in again".
        // 401 = unauthenticated / no valid session.
        // 419 = Laravel CSRF mismatch, commonly caused by an expired session
        //        or stale CSRF token.
        if (response.status === 401 || response.status === 419) {
          markExpiredSessionSeen();
          showBanner();
        }

        return response;
      });
    };
  }

  /**
   * Reset the in-panel session-expired state: clear the marker and hide the
   * banner. Call after a successful re-login or when entering a panel that
   * wants a clean slate.
   */
  function resetExpiredSessionState() {
    try {
      root.sessionStorage.removeItem(SESSION_EXPIRED_KEY);
    } catch (e) {
      // non-fatal
    }
    hideBanner();
  }

  root.__appSessionBanner = {
    SESSION_EXPIRED_KEY: SESSION_EXPIRED_KEY,
    markExpiredSessionSeen: markExpiredSessionSeen,
    expiredSessionSeen: expiredSessionSeen,
    showBanner: showBanner,
    hideBanner: hideBanner,
    bannerVisible: bannerVisible,
    resetExpiredSessionState: resetExpiredSessionState,
    retrofitFetchForSessionExpiry: retrofitFetchForSessionExpiry,
    buildBanner: buildBanner,
    findBannerTarget: findBannerTarget,
  };
})(typeof window !== 'undefined' ? window : this);
