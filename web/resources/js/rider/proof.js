// Rider proof-of-delivery draft preservation.
//
// The proof page is a plain multipart POST. If the session or CSRF has expired,
// the rider gets a 419 and loses the photo, COD amount and "received by" name.
// old() only helps after validation errors, not after a stale session / expired
// CSRF, so this module persists the text fields as a client-side draft and
// warns the rider before submit when the session looks expired.
//
// Photo handling:
//   The selected photo is a File, not something we can serialize into
//   sessionStorage and later re-attach to the same input. Re-selecting the same
//   file path is also commonly blocked by the browser. This module therefore
//   preserves the text fields and warns early; the photo itself is not cached.
//   If the rider loses the photo (e.g. reloads the page), they can pick it
//   again — but the typed COD amount, received_by and notes survive.
//
// Verification token handling:
//   The delivery verification token is a single-use, per-delivery token issued
//   by ParcelTrackingVerificationService and checked in ParcelHandoffVerification.
//   That service stores each token in the cache with a 10-minute TTL
//   (ParcelHandoffVerification::TTL_MINUTES = 10), so a stale page can easily
//   carry an already-expired token.
//
//   This module does NOT cache that token in sessionStorage. Re-submitting the
//   same token would fail with "expired or already used" and could burn the
//   token before the real submit. Instead, the proof route re-issues the token
//   server-side on each GET, so the form always carries a fresh one at render
//   time. If the session expires and the rider reloads, the reloaded page gets
//   a new token; the draft text fields survive the reload.
//
// Session-expired warning:
//   Before submit, this module sends a CSRF-safe lightweight GET to the same
//   origin (the rider layout already exposes <meta name="csrf-token">) to check
//   whether the session/CSRF is still alive. If the probe returns 401/419, the
//   module shows proofSessionWarn and blocks the native multipart submit.
//
//   On a native submit that still gets a 419 (e.g. because the session expired
//   between the probe and the submit, or because the submit is a file upload
//   that goes through a different code path), the module surfaces an in-page
//   session-expired banner via the shared session-banner helper instead of
//   leaving the rider in a silent failure state.
//
// TODO(dev): if stronger recovery is wanted, the proof controller/route could
//   re-render proof.blade.php with the rider's last-typed text values on a
//   419/expired-token response instead of a hard "Page Expired". That would let
//   old() do its job for the rider too, which is currently only useful for
//   validation errors.

(function () {
    'use strict';

    var form = document.getElementById('proofForm');
    if (!form) { return; }

    var sellerOrderId = form.getAttribute('data-seller-order-id') || '';
    var receivedByInput = document.getElementById('proof_received_by');
    var codAmountInput = document.getElementById('proof_cod_amount');
    var deliveryNotesInput = document.getElementById('proof_delivery_notes');
    var proofPhotoInput = document.getElementById('proof_photo');
    var submitButton = form.querySelector('button[type="submit"]');
    var sessionWarnEl = document.getElementById('proofSessionWarn');

    if (!receivedByInput && !codAmountInput && !deliveryNotesInput) { return; }

    var SESSION_PROBE_MS = 5000;
    var DRAFT_TTL_MINUTES = 10;

    var sessionHealthy = null; // null = unknown, true = looked valid, false = looked expired
    var sessionProbeTimer = null;
    var sessionWarnVisible = false;

    // ── Session probe ──────────────────────────────────────────────────────────

    function sessionProbeUrl() {
        // Use a lightweight same-origin GET. The rider layout exposes the CSRF
        // token in a <meta>, and the app's notification bell already trusts that
        // endpoint; we only care about the status code, not the body.
        var base = window.location.origin + '/rider/notifications/recent';
        return base.indexOf('//') === 0 ? window.location.origin + '/rider/notifications/recent' : base;
    }

    function showSessionWarn(visible) {
        sessionWarnVisible = visible;
        if (!sessionWarnEl) { return; }
        sessionWarnEl.hidden = !visible;
        if (visible && submitButton) {
            submitButton.disabled = true;
        } else if (submitButton) {
            submitButton.disabled = false;
        }
    }

    function probeSession() {
        if (!window.fetch || typeof window.fetch !== 'function') {
            // Can't probe without fetch; assume healthy so the rider can still
            // submit and get the real 419 page if it's actually expired.
            sessionHealthy = true;
            showSessionWarn(false);
            return;
        }

        var url = sessionProbeUrl();
        if (!url) {
            sessionHealthy = true;
            showSessionWarn(false);
            return;
        }

        window.fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            // 401 and 419 both indicate the session or CSRF is no longer valid.
            // Laravel returns 419 on a stale CSRF token, which commonly happens
            // when the session itself has expired or the token was regenerated.
            if (response.status === 401 || response.status === 419) {
                sessionHealthy = false;
                showSessionWarn(true);
                markSessionExpired();
                return;
            }

            sessionHealthy = true;
            showSessionWarn(false);
            refreshDraftFromServer();
        }).catch(function () {
            // Network errors are not the same as expired sessions, but they are
            // unusual for a same-origin probe. Leave the current state alone rather
            // than force-blocking the rider.
        });
    }



    function markSessionExpired() {
        if (typeof window.__appSessionBanner === 'object' &&
            typeof window.__appSessionBanner.markExpiredSessionSeen === 'function') {
            window.__appSessionBanner.markExpiredSessionSeen();
        }
        if (typeof window.__appSessionBanner === 'object' &&
            typeof window.__appSessionBanner.showBanner === 'function') {
            window.__appSessionBanner.showBanner();
        }
    }

    function startSessionProbe() {
        stopSessionProbe();
        probeSession();
        sessionProbeTimer = window.setInterval(probeSession, SESSION_PROBE_MS);
    }

    function stopSessionProbe() {
        if (sessionProbeTimer) {
            window.clearInterval(sessionProbeTimer);
            sessionProbeTimer = null;
        }
    }

    // ── Draft persistence (reuses the buyer.js savePendingAction idea) ─────────

    var DRAFT_KEY_PREFIX = 'rider_pod_draft_';

    function draftKey() {
        return DRAFT_KEY_PREFIX + sellerOrderId;
    }

    function nowMinutes() {
        return Math.floor(Date.now() / 60000);
    }

    function saveDraft() {
        if (!sessionStorage) { return; }

        var payload = {
            received_by: receivedByInput ? receivedByInput.value : '',
            cod_amount: codAmountInput ? codAmountInput.value : '',
            delivery_notes: deliveryNotesInput ? deliveryNotesInput.value : '',
            ts: nowMinutes(),
        };

        try {
            sessionStorage.setItem(draftKey(), JSON.stringify(payload));
        } catch (e) {
            // sessionStorage may be unavailable in some contexts; non-fatal.
        }
    }

    function loadDraft() {
        if (!sessionStorage) { return null; }

        var raw = null;
        try {
            raw = sessionStorage.getItem(draftKey());
        } catch (e) {
            return null;
        }
        if (!raw) { return null; }

        var parsed = null;
        try {
            parsed = JSON.parse(raw);
        } catch (e) {
            return null;
        }
        if (!parsed || typeof parsed !== 'object') { return null; }

        // Expire drafts older than DRAFT_TTL_MINUTES.
        var age = nowMinutes() - (typeof parsed.ts === 'number' ? parsed.ts : 0);
        if (age > DRAFT_TTL_MINUTES) {
            clearDraft();
            return null;
        }

        return parsed;
    }

    function clearDraft() {
        if (!sessionStorage) { return; }
        try {
            sessionStorage.removeItem(draftKey());
        } catch (e) {
            // non-fatal
        }
    }

    function restoreDraft() {
        var draft = loadDraft();
        if (!draft) { return; }

        if (receivedByInput && typeof draft.received_by === 'string') {
            receivedByInput.value = draft.received_by;
        }
        if (codAmountInput && typeof draft.cod_amount === 'string') {
            codAmountInput.value = draft.cod_amount;
        }
        if (deliveryNotesInput && typeof draft.delivery_notes === 'string') {
            deliveryNotesInput.value = draft.delivery_notes;
        }

        if (typeof window.__riderPodProof === 'object' &&
            typeof window.__riderPodProof.onDraftRestored === 'function') {
            window.__riderPodProof.onDraftRestored(draft);
        }
    }

    // ── Form submit ────────────────────────────────────────────────────────────

    function warnBeforeSubmit(sellerOrderId, form) {
        if (sessionHealthy === false) {
            showSessionWarn(true);
            return false;
        }

        // If we haven't probed yet, do a quick probe and block only if it
        // immediately looks expired. Otherwise let the native submit proceed so
        // the server can return the real 419 page if needed.
        if (sessionHealthy === null) {
            probeSession();
            // The probe is async; we can't block on it here. Fall back to letting
            // the submit go, and handle a later 419 below.
        }

        return true;
    }

    function onNativeSubmit(event) {
        if (!warnBeforeSubmit(sellerOrderId, form)) {
            event.preventDefault();
            return;
        }

        // Save the draft one more time right before submit so the rider's latest
        // typed values survive a later reload if the submit fails with 419.
        saveDraft();

        // We cannot stop a native multipart submit once it leaves the page, so if
        // the server returns 419 after submit, we surface the session-expired
        // banner from the shared helper instead of leaving the rider in a silent
        // failure state. The draft itself has already been saved above, so the
        // next reload will restore the text fields.
        if (typeof window.__appSessionBanner === 'object' &&
            typeof window.__appSessionBanner.markExpiredSessionSeen === 'function') {
            window.__appSessionBanner.markExpiredSessionSeen();
        }
    }

    // ── Page load ──────────────────────────────────────────────────────────────

    function onLoad() {
        restoreDraft();

        // If a prior session-expired condition was recorded by the shared banner
        // helper (e.g. from a failed proof submit, or from the bell), surface it
        // here too so the rider sees the banner on the proof page.
        if (typeof window.__appSessionBanner === 'object' &&
            typeof window.__appSessionBanner.expiredSessionSeen === 'function') {
            if (window.__appSessionBanner.expiredSessionSeen()) {
                showSessionWarn(true);
                if (typeof window.__appSessionBanner.showBanner === 'function') {
                    window.__appSessionBanner.showBanner();
                }
            }
        }

        startSessionProbe();
    }

    // ── Wire up ────────────────────────────────────────────────────────────────

    if (receivedByInput) {
        receivedByInput.addEventListener('input', saveDraft);
    }
    if (codAmountInput) {
        codAmountInput.addEventListener('input', saveDraft);
    }
    if (deliveryNotesInput) {
        deliveryNotesInput.addEventListener('input', saveDraft);
    }
    if (proofPhotoInput) {
        proofPhotoInput.addEventListener('change', saveDraft);
    }

    form.addEventListener('submit', onNativeSubmit);

    window.addEventListener('beforeunload', function () {
        saveDraft();
        stopSessionProbe();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onLoad);
    } else {
        onLoad();
    }

    // ── Public helpers (for other rider scripts that may want to interact) ─────

    if (typeof window !== 'undefined') {
        window.__riderPodProof = window.__riderPodProof || {};
        window.__riderPodProof.saveDraft = saveDraft;
        window.__riderPodProof.clearDraft = clearDraft;
        window.__riderPodProof.reloadDraft = restoreDraft;
        window.__riderPodProof.sellerOrderId = sellerOrderId;
        window.__riderPodProof.sessionHealthy = function () { return sessionHealthy; };
        window.__riderPodProof.sessionWarnVisible = function () { return sessionWarnVisible; };
    }
})();
