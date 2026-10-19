/**
 * resources/js/registration-validation.js
 *
 * Shared registration validation and UX for buyer, seller, logistics and rider forms.
 * Rules follow the Assumptions sheet of the Black-Box Test Cases workbook (A2 to A15).
 *
 * Markup contract (data attributes, same style as before):
 *   data-validate-rule="<rule>"          field is validated; blank = required unless data-optional
 *   data-min-age="18"                    optional, on a birthday select or date input
 *   data-error-for="<field id>"          optional, on the .field-error span that belongs to a field
 *   data-password-meter="<field id>"     on the 4-bar meter container
 *   data-toggle-visibility="<field id>"  on the eye button
 *   data-panel="1".."n"                  wizard steps; .next-step-btn moves forward
 *
 * Rules: required, email, password, password-confirm, contact-no,
 *   letters-spaces-hyphens-apostrophes (names), letter-max-2, shop-name,
 *   birthday-month / birthday-day / birthday-year (three selects), birthdate (date input),
 *   tin, postal-code, sec-reg-no, drivers-license, license-expiry,
 *   doc-file (upload), terms (checkbox), otp
 *
 * Do not put maxlength on fields that have a boundary test (names, mobile, postal code,
 * shop name, OTP). The browser would cut the extra characters and the invalid value
 * could never be rejected.
 *
 * Helpers on window.__zfReg for scripts that submit with fetch:
 *   validateForm(form), lockSubmit(form), unlockSubmit(form), showServerErrors(form, errors)
 */

const ZF = (window.__zfReg = window.__zfReg || {});
ZF.bound = ZF.bound || {};

function once(name) {
    if (ZF.bound[name]) return false;
    ZF.bound[name] = true;
    return true;
}

const REQUIRED = 'This field is required.';
const DATE_MSG = 'Please select a valid date.';

function esc(value) {
    return window.CSS && CSS.escape ? CSS.escape(value) : String(value).replace(/["\\]/g, '\\$&');
}

function isBlank(value) {
    return value === null || value === undefined || value === false || String(value).trim() === '';
}

function tidy(value) {
    return String(value ?? '').normalize('NFC').replace(/\s+/g, ' ').trim();
}

// ============================================================
// PASSWORD EYE TOGGLE
// ============================================================

function initPasswordToggles() {
    if (!once('toggles')) return;

    document.addEventListener('click', function (e) {
        const btn = e.target.closest && e.target.closest('[data-toggle-visibility]');
        if (!btn) return;
        e.preventDefault();

        const field = document.getElementById(btn.getAttribute('data-toggle-visibility'));
        if (!field) return;

        const reveal = field.type === 'password';
        field.type = reveal ? 'text' : 'password';
        btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
        btn.setAttribute('aria-pressed', reveal ? 'true' : 'false');

        const old = btn.querySelector('[data-lucide]');
        if (old) {
            const cls = old.getAttribute('class') || '';
            btn.innerHTML = '<i data-lucide="' + (reveal ? 'eye-off' : 'eye') + '" class="' + cls + '"></i>';
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }

        updatePasswordMeterDisplay(field);

        const pos = field.value.length;
        setTimeout(() => {
            field.focus();
            try { field.setSelectionRange(pos, pos); } catch (err) { /* not supported */ }
        }, 0);
    });
}

// ============================================================
// PASSWORD STRENGTH METER
// Four bars. Four bars means every rule is met (same rule as Laravel's
// Password::min(8)->mixedCase()->numbers()->symbols()).
// ============================================================

const BAR_COLORS = { 1: '#e74c3c', 2: '#f39c12', 3: '#f1c40f', 4: '#27ae60' };
const BAR_LABELS = { 1: 'Weak', 2: 'Fair', 3: 'Good', 4: 'Strong' };

function getPasswordStrength(password) {
    if (!password) return { score: 0, label: '', hint: '', bars: 0, color: '', valid: false, missing: [] };

    const checks = [
        ['8+ characters', password.length >= 8],
        ['an uppercase letter', /\p{Lu}/u.test(password)],
        ['a lowercase letter', /\p{Ll}/u.test(password)],
        ['a number', /\p{N}/u.test(password)],
        ['a special character', /[\p{Z}\p{S}\p{P}]/u.test(password)],
    ];
    const missing = checks.filter((c) => !c[1]).map((c) => c[0]);
    const classesMet = checks.slice(1).filter((c) => c[1]).length;
    const bars = password.length < 8 ? 1 : Math.max(1, classesMet);

    let hint = '';
    if (missing.length === 1) hint = 'Add ' + missing[0];
    else if (missing.length > 1) hint = 'Add ' + missing.slice(0, -1).join(', ') + ' and ' + missing[missing.length - 1];

    return {
        score: bars,
        bars: bars,
        label: BAR_LABELS[bars],
        color: BAR_COLORS[bars],
        hint: hint,
        valid: missing.length === 0,
        missing: missing,
    };
}

function updatePasswordMeterDisplay(field) {
    const meter = document.querySelector('[data-password-meter="' + esc(field.id) + '"]');
    if (!meter) return;

    const strength = getPasswordStrength(field.value);

    meter.querySelectorAll('[data-bar]').forEach((bar, idx) => {
        bar.style.backgroundColor = idx < strength.bars ? strength.color : '#e5e7eb';
    });

    const label = document.querySelector('[data-password-label="' + esc(field.id) + '"]');
    if (label) label.textContent = strength.label;

    const hint = document.querySelector('[data-password-hint="' + esc(field.id) + '"]');
    if (hint) hint.textContent = strength.hint;

    const warning = document.querySelector('[data-password-warning="' + esc(field.id) + '"]');
    if (warning) {
        if (field.value.length >= 8 && !strength.valid) {
            warning.textContent = strength.hint;
            warning.style.display = 'block';
        } else {
            warning.textContent = '';
            warning.style.display = 'none';
        }
    }
}

function initPasswordMeter() {
    if (once('meter')) {
        document.addEventListener('input', function (e) {
            const f = e.target;
            if (!f || !f.id) return;
            if (!document.querySelector('[data-password-meter="' + esc(f.id) + '"]')) return;
            updatePasswordMeterDisplay(f);
        });

        // autofill fires change without input
        document.addEventListener('change', function (e) {
            const f = e.target;
            if (!f || !f.id) return;
            if (!document.querySelector('[data-password-meter="' + esc(f.id) + '"]')) return;
            updatePasswordMeterDisplay(f);
        });
    }

    // pre-filled fields (runs again when the modal markup is added later)
    document.querySelectorAll('[data-password-meter]').forEach((meter) => {
        const field = document.getElementById(meter.getAttribute('data-password-meter'));
        if (field && field.value) updatePasswordMeterDisplay(field);
    });
}

// ============================================================
// PURE CHECKS (no DOM, easy to test)
// ============================================================

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
// starts with a letter, ends with a letter or period, only letters, marks, space, hyphen, apostrophe, period between
const NAME_RE = /^\p{L}(?:[\p{L}\p{M} '’.\-]*[\p{L}\p{M}.])?$/u;
const NAME_MSG = 'Only letters, spaces, hyphens, apostrophes and periods are allowed.';

function nameCheck(value, min = 2, max = 50) {
    const v = tidy(value);
    if (!v) return REQUIRED;
    if (!NAME_RE.test(v)) return NAME_MSG;
    if (v.length < min) return 'Must be at least ' + min + ' characters.';
    if (v.length > max) return 'Must be ' + max + ' characters or fewer.';
    return null;
}

const MOBILE_FORMAT_MSG = 'Enter a valid mobile number (09XXXXXXXXX or +639XXXXXXXXX).';
const MOBILE_LENGTH_MSG = 'Mobile number must be 11 digits, like 09171234567.';
const MOBILE_LENGTH_MSG_INTL = 'Enter +63 followed by 10 digits, like +639171234567.';

// Returns { error } or { normalized } where normalized is always the 09XXXXXXXXX form.
function normalizeMobile(value) {
    const v = String(value ?? '').trim();
    if (!v) return { error: REQUIRED };
    if (!/^\+?\d+$/.test(v)) return { error: MOBILE_FORMAT_MSG };

    if (v.startsWith('+')) {
        if (!v.startsWith('+63')) return { error: MOBILE_FORMAT_MSG };
        const rest = v.slice(3);
        if (rest.length !== 10) return { error: MOBILE_LENGTH_MSG_INTL };
        if (rest[0] !== '9') return { error: MOBILE_FORMAT_MSG };
        return { normalized: '0' + rest };
    }

    if (!v.startsWith('09')) return { error: MOBILE_FORMAT_MSG };
    if (v.length !== 11) return { error: MOBILE_LENGTH_MSG };
    return { normalized: v };
}

function checkContactNoLive(value) {
    const v = String(value ?? '').trim();
    if (!v) return null;

    if (!/^\+?\d+$/.test(v)) {
        return MOBILE_FORMAT_MSG;
    }

    if (v.startsWith('+')) {
        if (!v.startsWith('+63')) return MOBILE_FORMAT_MSG;
        const rest = v.slice(3);
        if (rest.length > 0 && rest[0] !== '9') return MOBILE_FORMAT_MSG;
        if (rest.length === 10) return null;
        if (rest.length > 10) return MOBILE_LENGTH_MSG_INTL;
        return null;
    }

    if (!v.startsWith('0') && v.length >= 1) {
        return MOBILE_FORMAT_MSG;
    }
    if (v.length >= 2 && !v.startsWith('09')) {
        return MOBILE_FORMAT_MSG;
    }

    if (v.length === 10 || v.length > 11) {
        return MOBILE_LENGTH_MSG;
    }
    if (v.length === 11) {
        return null;
    }

    return null;
}

function getDaysInMonth(month, year) {
    const monthDays = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    if (month === 2 && year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0)) return 29;
    return monthDays[month - 1] || 31;
}

function cmpDate(a, b) {
    for (let i = 0; i < 3; i++) {
        if (a[i] !== b[i]) return a[i] < b[i] ? -1 : 1;
    }
    return 0;
}

function todayParts(today = new Date()) {
    return [today.getFullYear(), today.getMonth() + 1, today.getDate()];
}

// Born on or before (today minus minAge years) passes. Future dates fail.
function checkBirthdate(y, m, d, minAge = 18, today = new Date()) {
    y = parseInt(y, 10); m = parseInt(m, 10); d = parseInt(d, 10);
    if (!y || !m || !d || m > 12 || d > getDaysInMonth(m, y)) return DATE_MSG;

    const t = todayParts(today);
    const b = [y, m, d];
    if (cmpDate(b, t) > 0) return 'Please enter a valid date of birth. It cannot be in the future.';
    if (cmpDate(b, [t[0] - minAge, t[1], t[2]]) > 0) return 'You must be at least ' + minAge + ' years old.';
    return null;
}

function checkNotExpired(y, m, d, today = new Date()) {
    y = parseInt(y, 10); m = parseInt(m, 10); d = parseInt(d, 10);
    if (!y || !m || !d || m > 12 || d > getDaysInMonth(m, y)) return DATE_MSG;
    return cmpDate([y, m, d], todayParts(today)) < 0 ? 'This license is expired.' : null;
}

function ageOn(y, m, d, today = new Date()) {
    const t = todayParts(today);
    let age = t[0] - y;
    if (t[1] < m || (t[1] === m && t[2] < d)) age--;
    return age;
}

const DOC_EXT_RE = /\.(jpe?g|png|pdf)$/i;
const DOC_MIME = ['image/jpeg', 'image/png', 'application/pdf'];
const MAX_FILE_BYTES = 5 * 1024 * 1024; // 5,242,880 bytes is accepted, one byte more is not

function checkDocFile(file) {
    if (!file) return REQUIRED;
    const typeOk = DOC_EXT_RE.test(file.name || '') && (!file.type || DOC_MIME.includes(file.type));
    if (!typeOk) return 'Only JPG, PNG or PDF files are accepted.';
    if (file.size > MAX_FILE_BYTES) return 'File is too large. The maximum size is 5 MB.';
    return null;
}

// ============================================================
// BIRTHDAY GROUP (three selects) HELPERS
// ============================================================

function isBirthdayRule(rule) {
    return typeof rule === 'string' && rule.indexOf('birthday-') === 0;
}

function birthdayGroup(el) {
    return el.closest('[data-birthday-group]') || el.closest('[data-panel]') || el.closest('form') || document;
}

function birthdayParts(group) {
    return {
        month: group.querySelector('[data-validate-rule="birthday-month"]'),
        day: group.querySelector('[data-validate-rule="birthday-day"]'),
        year: group.querySelector('[data-validate-rule="birthday-year"]'),
    };
}

function birthdayError(field) {
    if (isBlank(field.value)) return DATE_MSG;
    const g = birthdayParts(birthdayGroup(field));
    if (!g.month || !g.day || !g.year) return null;
    if (isBlank(g.month.value) || isBlank(g.day.value) || isBlank(g.year.value)) return null;
    const minAge = parseInt([g.month, g.day, g.year].map((el) => el.getAttribute('data-min-age')).find(Boolean) || '18', 10);
    return checkBirthdate(g.year.value, g.month.value, g.day.value, minAge);
}

function dateInputParts(value) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || '').trim());
    return m ? [m[1], m[2], m[3]] : null;
}

// ============================================================
// VALIDATORS: return null when valid, otherwise the message
// ============================================================

const validators = {
    required(value) {
        return isBlank(value) ? REQUIRED : null;
    },
    email(value) {
        const v = String(value ?? '').trim();
        if (!v) return REQUIRED;
        return EMAIL_RE.test(v) ? null : 'Please enter a valid email address.';
    },
    password(value) {
        if (!value) return REQUIRED;
        const s = getPasswordStrength(value);
        if (value.length < 8) return 'Password must be at least 8 characters.';
        if (!s.valid) return 'Password must include an uppercase letter, a lowercase letter, a number and a special character.';
        return null;
    },
    'password-confirm'(value, field) {
        if (!value) return 'Please confirm your password.';
        const other = document.getElementById(field.getAttribute('data-password-confirm-for'));
        if (!other) return null;
        return value === other.value ? null : 'Passwords do not match.';
    },
    'contact-no'(value) {
        return normalizeMobile(value).error || null;
    },
    'letters-spaces-hyphens-apostrophes'(value) {
        return nameCheck(value);
    },
    'letter-max-2'(value) {
        const v = tidy(value);
        if (!v) return null; // optional
        return /^\p{L}{1,2}$/u.test(v) ? null : 'Middle initial must be 1-2 letters only.';
    },
    'shop-name'(value) {
        const v = tidy(value);
        if (!v) return REQUIRED;
        return v.length >= 3 && v.length <= 30 ? null : 'Shop name must be 3 to 30 characters.';
    },
    'birthday-month'(value, field) { return birthdayError(field); },
    'birthday-day'(value, field) { return birthdayError(field); },
    'birthday-year'(value, field) { return birthdayError(field); },
    birthdate(value, field) {
        const p = dateInputParts(value);
        if (!p) return DATE_MSG;
        return checkBirthdate(p[0], p[1], p[2], parseInt(field.getAttribute('data-min-age') || '18', 10));
    },
    tin(value) {
        const v = String(value ?? '').trim();
        if (!v) return REQUIRED;
        return /^\d{3}-\d{3}-\d{3}-\d{3}$/.test(v) ? null : 'Enter the 12-digit TIN in this format: 000-000-000-000.';
    },
    'postal-code'(value) {
        const v = String(value ?? '').trim();
        if (!v) return REQUIRED;
        return /^\d{4}$/.test(v) ? null : 'Postal code must be exactly 4 digits.';
    },
    'sec-reg-no'(value) {
        const v = String(value ?? '').trim();
        if (!v) return REQUIRED;
        return /^[A-Za-z]{2}\d{9}$/.test(v) ? null : 'Use 2 letters followed by 9 digits, like CS202012345.';
    },
    'drivers-license'(value) {
        const v = String(value ?? '').trim();
        if (!v) return REQUIRED;
        return /^[A-Za-z]\d{2}-\d{2}-\d{6}$/.test(v) ? null : 'Use the format A00-00-000000.';
    },
    'license-expiry'(value) {
        const p = dateInputParts(value);
        if (!p) return DATE_MSG;
        return checkNotExpired(p[0], p[1], p[2]);
    },
    'doc-file'(file) {
        return checkDocFile(file);
    },
    terms(checked) {
        return checked ? null : 'You must accept the Terms and Privacy Policy.';
    },
    otp(value) {
        const v = String(value ?? '').trim();
        if (!v) return 'Enter the 6-digit code from your email.';
        return /^\d{6}$/.test(v) ? null : 'Enter the 6-digit code from your email.';
    },
};

// ============================================================
// FIELD ERROR DISPLAY
// ============================================================

function findErrorSpan(field) {
    if (field.id) {
        const explicit = document.querySelector('.field-error[data-error-for="' + esc(field.id) + '"]');
        if (explicit) return explicit;
    }

    // Walk up while the container holds only this field (or only this birthday group),
    // so one field never grabs another field's message span.
    let node = field.parentElement;
    while (node && node.tagName !== 'FORM') {
        const others = Array.from(node.querySelectorAll('[data-validate-rule]')).filter((f) => {
            if (f === field) return false;
            return !(isBirthdayRule(f.getAttribute('data-validate-rule')) && isBirthdayRule(field.getAttribute('data-validate-rule')));
        });
        if (others.length) break;
        const span = node.querySelector('.field-error');
        if (span) return span;
        node = node.parentElement;
    }
    return null;
}

function ensureErrorSpan(field) {
    let span = findErrorSpan(field);
    if (span) return span;
    span = document.createElement('p');
    span.className = 'field-error mt-1 font-sans text-[12px] text-[#a5333d]';
    if (field.id) span.setAttribute('data-error-for', field.id);
    const anchor = field.parentElement && field.parentElement.tagName !== 'FORM' ? field.parentElement : field;
    anchor.insertAdjacentElement('afterend', span);
    return span;
}

function birthdayGroupHasOtherError(field) {
    const g = birthdayParts(birthdayGroup(field));
    return [g.month, g.day, g.year].some((el) => el && el !== field && el.hasAttribute('data-error'));
}

function setFieldError(field, error) {
    if (error) {
        field.setAttribute('data-error', 'true');
        field.setAttribute('aria-invalid', 'true');
        const span = ensureErrorSpan(field);
        span.textContent = error;
        span.setAttribute('role', 'alert');
    } else {
        field.removeAttribute('data-error');
        field.removeAttribute('aria-invalid');
        const span = findErrorSpan(field);
        const rule = field.getAttribute('data-validate-rule');
        if (span && !(isBirthdayRule(rule) && birthdayGroupHasOtherError(field))) span.textContent = '';
    }
}

function fieldValue(field) {
    if (field.type === 'checkbox') return field.checked;
    if (field.type === 'file') return (field.files && field.files[0]) || null;
    return field.value;
}

function validateField(field) {
    const rule = field.getAttribute('data-validate-rule');
    if (!rule) return true;
    if (field.disabled) {
        setFieldError(field, '');
        return true;
    }

    const value = fieldValue(field);
    const optional = rule === 'letter-max-2' || field.hasAttribute('data-optional');
    if (optional && (isBlank(value) || value === null)) {
        setFieldError(field, '');
        return true;
    }

    const check = validators[rule];
    const error = check ? check(value, field) : null;
    setFieldError(field, error);
    return !error;
}

// ============================================================
// LIVE FIELD VALIDATION
// ============================================================

function tidyOnBlur(field, rule) {
    if (field.type === 'file' || field.type === 'checkbox' || field.tagName === 'SELECT') return;
    if (rule === 'password' || rule === 'password-confirm') return;
    if (field.type === 'date') return;

    let v = field.value;
    if (rule === 'letters-spaces-hyphens-apostrophes' || rule === 'letter-max-2' || rule === 'shop-name') {
        v = tidy(v);
    } else {
        v = v.trim();
    }
    if (rule === 'sec-reg-no' || rule === 'drivers-license') v = v.toUpperCase();
    if (rule === 'contact-no') {
        const r = normalizeMobile(v);
        if (r.normalized) v = r.normalized; // +639... is stored the same as 09...
    }
    if (v !== field.value) field.value = v;
}

function initFieldValidation() {
    if (!once('fields')) return;

    document.addEventListener('blur', function (e) {
        const field = e.target;
        if (!field || !field.getAttribute) return;
        const rule = field.getAttribute('data-validate-rule');
        if (!rule) return;

        tidyOnBlur(field, rule);
        field.setAttribute('data-touched', 'true');
        validateField(field);
    }, true);

    document.addEventListener('input', function (e) {
        const field = e.target;
        if (!field || !field.getAttribute) return;
        const rule = field.getAttribute('data-validate-rule');
        if (!rule) return;

        // Contact number: live validation so warning message shows on time as user types
        if (rule === 'contact-no') {
            const val = fieldValue(field);
            const liveErr = checkContactNoLive(val);
            if (liveErr) {
                field.setAttribute('data-touched', 'true');
                setFieldError(field, liveErr);
            } else if (val && (val.length === 11 || (val.startsWith('+63') && val.length === 13))) {
                setFieldError(field, null);
            } else if (field.getAttribute('data-touched') === 'true') {
                validateField(field);
            }
            return;
        }

        if (field.getAttribute('data-touched') !== 'true') return;

        validateField(field);

        if (field.getAttribute('data-validate-rule') === 'password') {
            document.querySelectorAll('[data-password-confirm-for="' + esc(field.id) + '"]').forEach((cf) => {
                if (cf.value || cf.getAttribute('data-touched') === 'true') validateField(cf);
            });
        }
    });

    // checkboxes, selects, date inputs and file inputs report on change
    document.addEventListener('change', function (e) {
        const field = e.target;
        if (!field || !field.getAttribute) return;
        if (!field.getAttribute('data-validate-rule')) return;
        if (!(field.type === 'checkbox' || field.type === 'file' || field.type === 'date' || field.tagName === 'SELECT')) return;

        field.setAttribute('data-touched', 'true');
        validateField(field);
    });

    // Mobile: block letters and symbols while typing. A leading + is allowed.
    document.addEventListener('keypress', function (e) {
        const field = e.target;
        if (!field || !field.getAttribute) return;
        if (field.getAttribute('data-validate-rule') !== 'contact-no') return;
        if (e.ctrlKey || e.metaKey || e.key.length > 1) return;
        if (/\d/.test(e.key)) return;
        if (e.key === '+' && field.selectionStart === 0 && !field.value.slice(field.selectionEnd).includes('+')) return;
        e.preventDefault();
    });
}

// ============================================================
// BIRTHDAY FIELD HANDLING (selects)
// ============================================================

function pad2(n) {
    return String(n).padStart(2, '0');
}

function syncBirthday(group) {
    const { month, day, year } = birthdayParts(group);
    if (!month || !day || !year) return;

    const ageField = group.querySelector('[id*="Age"]');
    const birthdayInput = group.querySelector('[id*="irthday"][type="hidden"]');

    // day list follows month and year, the chosen day is kept when it still exists
    const max = month.value && year.value ? getDaysInMonth(parseInt(month.value, 10), parseInt(year.value, 10)) : 31;
    const current = day.value;
    if (day.options.length !== max + 1) {
        day.innerHTML = '<option value="" disabled selected>Day</option>';
        for (let d = 1; d <= max; d++) day.appendChild(new Option(d, d));
    }
    day.value = current && parseInt(current, 10) <= max ? current : '';

    if (month.value && day.value && year.value) {
        const y = parseInt(year.value, 10);
        const m = parseInt(month.value, 10);
        const d = parseInt(day.value, 10);
        if (birthdayInput) birthdayInput.value = y + '-' + pad2(m) + '-' + pad2(d);
        if (ageField) {
            const age = ageOn(y, m, d);
            ageField.value = age >= 0 ? age : '—';
        }
    } else {
        if (birthdayInput) birthdayInput.value = '';
        if (ageField) ageField.value = '—';
    }
}

function updateBirthdayFields() {
    if (once('birthday')) {
        document.addEventListener('change', function (e) {
            const field = e.target;
            if (!field || !field.getAttribute) return;
            if (!isBirthdayRule(field.getAttribute('data-validate-rule'))) return;

            const group = birthdayGroup(field);
            syncBirthday(group);

            // once all three are chosen, show the age or future-date message right away
            const g = birthdayParts(group);
            if ([g.month, g.day, g.year].every((el) => el && el.value)) {
                [g.month, g.day, g.year].forEach((el) => el.setAttribute('data-touched', 'true'));
                validateField(g.day);
            }
        });
    }

    // initial sync for forms already in the page
    const seen = new Set();
    document.querySelectorAll('[data-validate-rule="birthday-month"]').forEach((el) => {
        const group = birthdayGroup(el);
        if (!seen.has(group)) {
            seen.add(group);
            syncBirthday(group);
        }
    });

    // date pickers: no future dates, and the minimum age sets the latest pickable date
    document.querySelectorAll('input[type="date"][data-validate-rule="birthdate"]').forEach((el) => {
        const t = todayParts();
        const minAge = parseInt(el.getAttribute('data-min-age') || '18', 10);
        el.max = (t[0] - minAge) + '-' + pad2(t[1]) + '-' + pad2(t[2]);
    });
    document.querySelectorAll('input[type="date"][data-validate-rule="license-expiry"]').forEach((el) => {
        const t = todayParts();
        el.min = t[0] + '-' + pad2(t[1]) + '-' + pad2(t[2]);
    });
}

// ============================================================
// STEPS, NEXT BUTTON, SUBMIT
// ============================================================

function showPanel(form, panelNum) {
    const panels = form.querySelectorAll('[data-panel]');
    const stepper = form.closest('[role="dialog"]')?.querySelector('[id*="Stepper"]');

    panels.forEach((p) => {
        if (parseInt(p.getAttribute('data-panel'), 10) === panelNum) p.classList.remove('hidden');
        else p.classList.add('hidden');
    });

    if (stepper) {
        stepper.querySelectorAll('[data-step-circle]').forEach((circle, idx) => {
            if (idx + 1 <= panelNum) {
                circle.classList.add('border-[#9c5c68]', 'bg-[#9c5c68]', 'text-white');
                circle.classList.remove('border-neutral-200', 'bg-white', 'text-neutral-400');
            } else {
                circle.classList.remove('border-[#9c5c68]', 'bg-[#9c5c68]', 'text-white');
                circle.classList.add('border-neutral-200', 'bg-white', 'text-neutral-400');
            }
        });
    }
}

function validateScope(root) {
    const invalidFields = [];
    root.querySelectorAll('[data-validate-rule]').forEach((field) => {
        field.setAttribute('data-touched', 'true');
        if (!validateField(field)) invalidFields.push(field);
    });
    return { valid: invalidFields.length === 0, invalidFields: invalidFields, firstInvalid: invalidFields[0] || null };
}

function revealField(field) {
    const form = field.closest('form');
    const panel = field.closest('[data-panel]');
    if (form && panel) showPanel(form, parseInt(panel.getAttribute('data-panel'), 10));
    field.focus({ preventScroll: true });
    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function validateForm(form) {
    const result = validateScope(form);
    if (result.firstInvalid) revealField(result.firstInvalid);
    return result;
}

function initNextButtonValidation() {
    if (!once('next')) return;

    document.addEventListener('click', function (e) {
        const btn = e.target.closest && e.target.closest('.next-step-btn');
        if (!btn) return;

        const panel = btn.closest('[data-panel]');
        if (!panel) return;

        const result = validateScope(panel);
        let summary = panel.querySelector('[data-error-summary]');

        if (!result.valid) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            if (!summary) {
                summary = document.createElement('div');
                summary.setAttribute('data-error-summary', 'true');
                summary.className = 'mb-4 rounded-sm border border-[#e8a49c] bg-[#fbe9e7] px-4 py-3 font-sans text-[12.5px] text-[#a5333d]';
                btn.parentElement.insertBefore(summary, btn);
            }
            summary.textContent = result.invalidFields.length === 1
                ? 'Please fix the highlighted field below.'
                : 'Please fix the ' + result.invalidFields.length + ' highlighted fields below.';
            summary.style.display = 'block';
            revealField(result.firstInvalid);
            return;
        }

        if (summary) summary.style.display = 'none';

        const form = panel.closest('form');
        if (form) showPanel(form, parseInt(panel.getAttribute('data-panel'), 10) + 1);
    }, true);
}

function isRegForm(el) {
    return !!(el && el.tagName === 'FORM' && el.querySelector('[data-validate-rule]'));
}

function setSubmitButtons(form, busy) {
    form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])').forEach((b) => {
        b.disabled = busy;
        if (busy) b.setAttribute('aria-busy', 'true');
        else b.removeAttribute('aria-busy');
    });
}

function lockSubmit(form) {
    form.dataset.zfSubmitting = '1';
    setSubmitButtons(form, true);
}

function unlockSubmit(form) {
    delete form.dataset.zfSubmitting;
    setSubmitButtons(form, false);
}

function initSubmitGuard() {
    if (!once('submit')) return;

    // Capture phase: block a second submit, and block submit while any field is invalid.
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!isRegForm(form)) return;

        if (form.dataset.zfSubmitting === '1') {
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }
        if (!validateForm(form).valid) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    // Bubble phase: a native submit that nobody cancelled is going through, so lock the form.
    // Scripts that cancel the event and use fetch call lockSubmit / unlockSubmit themselves.
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!isRegForm(form) || e.defaultPrevented) return;
        form.dataset.zfSubmitting = '1';
        setTimeout(() => setSubmitButtons(form, true), 0); // after the browser has read the form data
    });

    // back button restoring a locked page from the browser cache
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('form[data-zf-submitting]').forEach(unlockSubmit);
    });
}

// Show Laravel-style errors ({ "email": ["..."] }) in the same style as the live ones.
function showServerErrors(form, errors) {
    let first = null;
    Object.keys(errors || {}).forEach((key) => {
        const bracket = key.replace(/\.([^.]+)/g, '[$1]');
        const field = form.querySelector('[name="' + esc(key) + '"]') || form.querySelector('[name="' + esc(bracket) + '"]');
        if (!field) return;
        const msg = Array.isArray(errors[key]) ? errors[key][0] : errors[key];
        field.setAttribute('data-touched', 'true');
        setFieldError(field, msg);
        if (!first) first = field;
    });
    if (first) revealField(first);
    return first;
}

// ============================================================
// INIT & EXPORT
// ============================================================

window.initRegistrationValidation = function () {
    // our messages replace the browser's own validation bubbles
    document.querySelectorAll('form').forEach((f) => {
        if (f.querySelector('[data-validate-rule]')) f.noValidate = true;
    });

    initPasswordToggles();
    initPasswordMeter();
    initFieldValidation();
    updateBirthdayFields();
    initNextButtonValidation();
    initSubmitGuard();
};

// Shared helpers for the buyer/seller modal scripts. Every init is guarded, so calling
// one of them after the auto-init does not register a second set of listeners.
ZF.initPasswordMeter = initPasswordMeter;
ZF.updatePasswordMeterDisplay = updatePasswordMeterDisplay;
ZF.getPasswordStrength = getPasswordStrength;
ZF.initPasswordToggles = initPasswordToggles;
ZF.initFieldValidation = initFieldValidation;
ZF.validateField = validateField;
ZF.validateForm = validateForm;
ZF.lockSubmit = lockSubmit;
ZF.unlockSubmit = unlockSubmit;
ZF.showServerErrors = showServerErrors;
ZF.normalizeMobile = normalizeMobile;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', window.initRegistrationValidation);
} else {
    window.initRegistrationValidation();
}

export {
    getPasswordStrength,
    validateField,
    validateForm,
    lockSubmit,
    unlockSubmit,
    showServerErrors,
    validators,
    nameCheck,
    normalizeMobile,
    checkBirthdate,
    checkNotExpired,
    checkDocFile,
};  