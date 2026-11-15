// Duplicate-submit guard + loading feedback for server-rendered POST forms
// (all role panels).
//
// The server already rejects stale or repeated actions under row locks; this
// only stops a double click from sending the same POST twice, and shows the
// user that their click did something while the next page is on its way.
//
// Rules:
//   • The initiating submit button swaps to a spinner + "Working…".
//     A button that carries data-submit-loading shows that label instead.
//   • Every other submit button in the form is disabled (so repeat clicks are
//     impossible) but keeps its own markup, because only one action is running.
//   • Original markup, label and aria-label are remembered and restored, so
//     the form is untouched if the submission never navigates away.
//   • Everything is reserved synchronously and locked on the next tick, after
//     the browser has built the form data, so a clicked button's name/value is
//     still sent. Client validation, confirm() prompts, appConfirmSubmit() and
//     fetch-driven forms (they call preventDefault) are unaffected.
//
// Opt out with data-allow-resubmit on the <form>.

const RELEASE_AFTER_MS = 8000;
const DEFAULT_LOADING_LABEL = 'Working…';
const LIVE_MESSAGE = 'Submitting, please wait…';
const STYLE_ID = 'zf-submit-guard-styles';

// Spinner + the visually hidden live region. Colours use currentColor so the
// loading state matches every button variant (solid, outline, danger) without
// any per-page styling. Motion is disabled when the user asks for less of it.
const STYLES = `
.zf-submit-loading{display:inline-flex;align-items:center;justify-content:center;gap:.5em}
.zf-submit-spinner{display:inline-block;flex:0 0 auto;width:1em;height:1em;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:zf-submit-spin .7s linear infinite}
.zf-submit-live{position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
@keyframes zf-submit-spin{to{transform:rotate(1turn)}}
@media (prefers-reduced-motion:reduce){.zf-submit-spinner{animation:none;border-right-color:currentColor;opacity:.6}}
`;

function submitButtons(form) {
    return Array.from(form.elements).filter((el) => el.type === 'submit');
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[character]));
}

// Only the button that started this submission gets the spinner. A form can
// hold several submit actions (e.g. "Save draft" + "Publish"), and showing
// them all as busy would lie about what is happening.
function initiatingButton(form, event) {
    const submitter = event && event.submitter;
    if (submitter && submitter.type === 'submit') return submitter;

    // Browsers set event.submitter for keyboard submissions too. If they did
    // not, only a form with a single enabled action can be identified safely.
    const enabled = submitButtons(form).filter((button) => !button.disabled);
    return enabled.length === 1 ? enabled[0] : null;
}

function rememberButton(button) {
    if (button.dataset.submitOriginalHtml === undefined) {
        button.dataset.submitOriginalHtml = typeof button.innerHTML === 'string' ? button.innerHTML : '';
    }
    if (button.dataset.submitOriginalAriaLabel === undefined) {
        const ariaLabel = button.getAttribute('aria-label');
        if (ariaLabel !== null) button.dataset.submitOriginalAriaLabel = ariaLabel;
    }
}

function startLoading(button) {
    if (button.dataset.submitLoadingActive === 'true') return;

    const custom = (button.dataset.submitLoading || '').trim();
    const label = custom !== '' ? custom : DEFAULT_LOADING_LABEL;

    rememberButton(button);
    button.dataset.submitLoadingActive = 'true';

    // Icon-only buttons (log out, pagination arrows) keep their meaning: the
    // visible text becomes "Working…" and the accessible name says both.
    const ariaLabel = button.getAttribute('aria-label');
    if (ariaLabel !== null) {
        button.setAttribute('aria-label', `${ariaLabel} — ${label}`);
    }

    button.innerHTML = '<span class="zf-submit-loading">'
        + '<span class="zf-submit-spinner" aria-hidden="true"></span>'
        + '<span class="zf-submit-loading-text">' + escapeHtml(label) + '</span>'
        + '</span>';
}

function stopLoading(button) {
    if (button.dataset.submitLoadingActive !== 'true') return;
    delete button.dataset.submitLoadingActive;

    if (button.dataset.submitOriginalHtml !== undefined) {
        button.innerHTML = button.dataset.submitOriginalHtml;
        delete button.dataset.submitOriginalHtml;
    }
    if (button.dataset.submitOriginalAriaLabel !== undefined) {
        button.setAttribute('aria-label', button.dataset.submitOriginalAriaLabel);
        delete button.dataset.submitOriginalAriaLabel;
    }
}

// A polite live region so the label swap is announced: a button that greys out
// without a word is silent for screen-reader users. Created once per form.
function announce(form, message) {
    if (typeof document === 'undefined'
        || typeof document.createElement !== 'function'
        || !form
        || typeof form.querySelector !== 'function'
        || typeof form.appendChild !== 'function') {
        return;
    }

    let region = form.querySelector('[data-submit-live]');
    if (!region) {
        region = document.createElement('span');
        region.setAttribute('data-submit-live', '');
        region.setAttribute('role', 'status');
        region.setAttribute('aria-live', 'polite');
        region.className = 'zf-submit-live';
        form.appendChild(region);
    }
    region.textContent = message;
}

function lock(form, initiating) {
    form.setAttribute('data-submitting', '');
    form.setAttribute('aria-busy', 'true');
    submitButtons(form).forEach((button) => {
        // A button the page disabled itself must stay disabled on release.
        if (button.disabled) return;
        button.disabled = true;
        button.setAttribute('data-submit-guarded', '');
        if (button === initiating) startLoading(button);
    });
    announce(form, LIVE_MESSAGE);
}

function release(form) {
    form.removeAttribute('data-submitting');
    form.removeAttribute('aria-busy');
    submitButtons(form).forEach((button) => {
        if (button.hasAttribute('data-submit-guarded')) {
            button.disabled = false;
            button.removeAttribute('data-submit-guarded');
        }
        stopLoading(button);
    });
    announce(form, '');
}

function injectStyles() {
    if (typeof document === 'undefined'
        || typeof document.createElement !== 'function'
        || !document.head
        || typeof document.getElementById !== 'function'
        || document.getElementById(STYLE_ID)) {
        return;
    }

    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = STYLES;
    document.head.appendChild(style);
}

injectStyles();

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)
        || form.method.toLowerCase() !== 'post'
        || form.hasAttribute('data-allow-resubmit')) {
        return;
    }

    if (form.hasAttribute('data-submitting')) {
        event.preventDefault();
        return;
    }

    // Another handler (client validation, a fetch-driven submit) cancelled it.
    if (event.defaultPrevented) return;

    const initiating = initiatingButton(form, event);
    // A run token so a stale fallback timer can never unlock a newer submission.
    const run = String(Number(form.dataset.submitRun || 0) + 1);
    form.dataset.submitRun = run;

    // Reserve synchronously so two submit events in the same tick cannot pass.
    form.setAttribute('data-submitting', '');
    setTimeout(() => {
        if (event.defaultPrevented) {
            release(form);
            return;
        }
        lock(form, initiating);
        // Downloads and new-tab targets never navigate this page away.
        setTimeout(() => {
            if (form.dataset.submitRun === run) release(form);
        }, RELEASE_AFTER_MS);
    }, 0);
});

// Back/forward cache restores the page exactly as it was left: locked.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        document.querySelectorAll('form[data-submitting]').forEach(release);
    }
});
