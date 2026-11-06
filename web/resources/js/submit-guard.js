// Duplicate-submit guard for server-rendered POST forms (all role panels).
//
// The server already rejects stale or repeated actions under row locks; this
// only stops a double click from sending the same POST twice. A form is locked
// after the submit event finishes dispatching and nobody cancelled it, so
// fetch-driven forms, confirm() prompts and client validation are unaffected.
// Submit buttons are disabled on the next tick, after the browser has built
// the form data, so a clicked button's name/value is still sent.
//
// Opt out with data-allow-resubmit on the <form>.

const RELEASE_AFTER_MS = 8000;

function submitButtons(form) {
    return Array.from(form.elements).filter((el) => el.type === 'submit');
}

function lock(form) {
    form.setAttribute('data-submitting', '');
    form.setAttribute('aria-busy', 'true');
    submitButtons(form).forEach((button) => {
        if (!button.disabled) {
            button.disabled = true;
            button.setAttribute('data-submit-guarded', '');
        }
    });
}

function release(form) {
    form.removeAttribute('data-submitting');
    form.removeAttribute('aria-busy');
    submitButtons(form).forEach((button) => {
        if (button.hasAttribute('data-submit-guarded')) {
            button.disabled = false;
            button.removeAttribute('data-submit-guarded');
        }
    });
}

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

    setTimeout(() => {
        if (event.defaultPrevented) {
            return;
        }
        lock(form);
        // Downloads and new-tab targets never navigate this page away.
        setTimeout(() => release(form), RELEASE_AFTER_MS);
    }, 0);
});

// Back/forward cache restores the page exactly as it was left: locked.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        document.querySelectorAll('form[data-submitting]').forEach(release);
    }
});
