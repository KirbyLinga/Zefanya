/**
 * Mobile-friendly parcel QR scanner + manual tracking entry.
 * Uses the browser BarcodeDetector API when available; always offers manual entry.
 * Camera permission is requested only when the user starts scanning.
 */
(() => {
    const root = document.querySelector('[data-parcel-scanner]');
    if (!root) {
        return;
    }

    const verifyUrl = root.dataset.verifyUrl;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const video = root.querySelector('[data-scan-video]');
    const cameraPanel = root.querySelector('[data-scan-camera-panel]');
    const statusEl = root.querySelector('[data-scan-status]');
    const errorEl = root.querySelector('[data-scan-error]');
    const resultEl = root.querySelector('[data-scan-result]');
    const trackingInput = root.querySelector('[data-scan-tracking-input]');
    const startBtn = root.querySelector('[data-scan-start]');
    const stopBtn = root.querySelector('[data-scan-stop]');
    const retryBtn = root.querySelector('[data-scan-retry]');
    const manualBtn = root.querySelector('[data-scan-manual]');

    let stream = null;
    let detector = null;
    let rafId = null;
    let verifying = false;
    let lastSubmitted = null;

    function setStatus(message) {
        if (statusEl) {
            statusEl.textContent = message || '';
        }
    }

    function setError(message) {
        if (!errorEl) {
            return;
        }
        errorEl.textContent = message || '';
        errorEl.classList.toggle('hidden', !message);
    }

    function stopCamera() {
        if (rafId !== null) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
        if (video) {
            video.srcObject = null;
        }
        cameraPanel?.classList.add('hidden');
        startBtn?.classList.remove('hidden');
        stopBtn?.classList.add('hidden');
    }

    function supportsCameraScan() {
        return typeof window.BarcodeDetector === 'function'
            && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    }

    async function startCamera() {
        setError('');
        if (!supportsCameraScan()) {
            setError('Camera QR scanning is not supported in this browser. Enter the tracking number manually.');
            trackingInput?.focus();
            return;
        }

        try {
            detector = new window.BarcodeDetector({ formats: ['qr_code'] });
        } catch (e) {
            setError('QR detection is unavailable on this device. Enter the tracking number manually.');
            trackingInput?.focus();
            return;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                },
            });
        } catch (err) {
            const name = err?.name || '';
            if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                setError('Camera permission was denied. Allow camera access or enter the tracking number manually.');
            } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
                setError('No camera was found on this device. Enter the tracking number manually.');
            } else {
                setError('Could not open the camera. Enter the tracking number manually.');
            }
            trackingInput?.focus();
            return;
        }

        video.srcObject = stream;
        await video.play();
        cameraPanel?.classList.remove('hidden');
        startBtn?.classList.add('hidden');
        stopBtn?.classList.remove('hidden');
        setStatus('Point the camera at the waybill QR code…');
        lastSubmitted = null;
        scanLoop();
    }

    async function scanLoop() {
        if (!detector || !stream || verifying) {
            return;
        }

        try {
            const codes = await detector.detect(video);
            if (codes.length > 0) {
                const raw = (codes[0].rawValue || '').trim();
                if (raw !== '') {
                    await submitTracking(raw, 'camera');
                    return;
                }
            }
        } catch (e) {
            // Transient detect failures are ignored; keep scanning.
        }

        rafId = requestAnimationFrame(scanLoop);
    }

    async function submitTracking(tracking, source) {
        const value = tracking.trim();
        if (value === '' || verifying) {
            return;
        }

        if (lastSubmitted === value) {
            setStatus('Already verified this tracking number. Confirm an action below, or scan another parcel.');
            return;
        }

        verifying = true;
        lastSubmitted = value;
        setError('');
        setStatus(source === 'camera' ? 'QR detected — verifying…' : 'Verifying tracking number…');
        stopCamera();

        try {
            const response = await fetch(verifyUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    tracking_number: value,
                    method: source === 'camera' ? 'camera' : 'manual',
                }),
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                lastSubmitted = null;
                const message = data.message
                    || data.errors?.tracking_number?.[0]
                    || 'Could not verify that tracking number.';
                setError(message);
                setStatus('');
                renderResult(null);
                return;
            }

            if (trackingInput) {
                trackingInput.value = value;
            }
            setStatus(data.message || `Verified ${data.parcel?.tracking_number || value}`);
            renderResult(data);
        } catch (e) {
            lastSubmitted = null;
            setError('Network error while verifying. Try again.');
            setStatus('');
        } finally {
            verifying = false;
        }
    }

    function renderResult(data) {
        if (!resultEl) {
            return;
        }

        if (!data || !data.parcel) {
            resultEl.innerHTML = '';
            resultEl.classList.add('hidden');
            return;
        }

        const parcel = data.parcel;
        const actions = Array.isArray(data.actions) ? data.actions : [];

        const noChangeNote = actions.some((action) => action.method !== 'GET')
            ? ''
            : '<p class="w-full font-sans text-[12px] text-[var(--text-muted)]">No handoff action is available for this status. Verification only — status was not changed.</p>';
        const actionHtml = noChangeNote + actions.map((action) => {
                if (action.method === 'GET') {
                    return `<a href="${escapeAttr(action.url)}" class="inline-flex h-9 items-center justify-center rounded-sm border border-[var(--border-strong)] bg-[var(--bg-surface)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)] hover:bg-[var(--bg-page)]">${escapeHtml(action.label)}</a>`;
                }

                const confirmAttr = action.confirm_message
                    ? ` data-confirm="${escapeAttr(action.confirm_message)}"`
                    : '';

                const tokenField = action.verification_token
                    ? `<input type="hidden" name="verification_token" value="${escapeAttr(action.verification_token)}">`
                    : '';

                const sealFields = action.requires_seal
                    ? `<div class="mb-3 space-y-3 font-sans text-xs">
                        <label class="block">Seal observed on arrival
                            <input name="observed_seal" required pattern="BH-[0-9]{7}" maxlength="10" placeholder="BH-0004521" autocomplete="off" class="mt-1 block w-full rounded border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2 text-[var(--text-ink)]">
                        </label>
                        <label class="block">Seal discrepancy note (required if the seal differs)
                            <textarea name="seal_note" maxlength="500" rows="3" class="mt-1 block w-full rounded border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2 text-[var(--text-ink)]"></textarea>
                        </label>
                        <p>Check the physical seal before opening the load. A mismatch is recorded and requires a note.</p>
                    </div>` : '';

                return `<form method="POST" action="${escapeAttr(action.url)}" class="inline"${confirmAttr}>
                    <input type="hidden" name="_token" value="${escapeAttr(csrf)}">
                    ${tokenField}
                    ${sealFields}
                    <button type="submit" class="inline-flex h-9 items-center justify-center rounded-sm bg-primary-700 px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-white hover:bg-primary-800">${escapeHtml(action.label)}</button>
                </form>`;
            }).join(' ');

        resultEl.innerHTML = `
            <article class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
                <header class="mb-3 flex items-start justify-between gap-3">
                    <div>
                        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)]">Parcel #${escapeHtml(String(parcel.id))}</p>
                        <p class="font-mono text-[11px] text-[var(--text-muted)]">${escapeHtml(parcel.tracking_number || '')}</p>
                    </div>
                    <span class="shrink-0 rounded-full border border-[var(--border-strong)] px-2 py-0.5 font-sans text-[10.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-muted)]">${escapeHtml(parcel.status_label || '')}</span>
                </header>
                <dl class="mb-4 grid grid-cols-2 gap-3 font-sans text-[12px]">
                    <div><dt class="text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Seller</dt><dd class="text-[var(--text-ink)]">${escapeHtml(parcel.seller_name || '—')}</dd></div>
                    <div><dt class="text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Destination</dt><dd class="text-[var(--text-ink)]">${escapeHtml(parcel.buyer_municipality || '—')}</dd></div>
                    <div><dt class="text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Items</dt><dd class="text-[var(--text-ink)]">${escapeHtml(String(parcel.item_count ?? '—'))}</dd></div>
                    <div><dt class="text-[10.5px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">Weight</dt><dd class="text-[var(--text-ink)]">${escapeHtml(parcel.weight || '—')}</dd></div>
                </dl>
                <div class="flex flex-wrap gap-2">${actionHtml}</div>
            </article>
        `;
        resultEl.classList.remove('hidden');

        resultEl.querySelectorAll('form[data-confirm]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                const message = form.getAttribute('data-confirm');
                if (message) {
                    event.preventDefault();
                    confirmInPage(message, () => form.submit());
                }
            });
        });
    }

    // In-page replacement for window.confirm(): never blocks the browser, so it works with screen readers and browser automation.
    function confirmInPage(message, onConfirm) {
        let dialog = document.getElementById('scanner-confirm-dialog');
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.id = 'scanner-confirm-dialog';
            dialog.style.cssText = 'margin:auto;max-width:26rem;width:calc(100% - 2rem);padding:1.25rem;border:1px solid var(--border-strong);border-radius:0.5rem;background:var(--bg-surface);color:var(--text-ink);font-family:inherit';
            dialog.innerHTML = '<p data-message style="margin:0 0 1rem;font-size:0.875rem;line-height:1.5"></p>'
                + '<div style="display:flex;justify-content:flex-end;gap:0.5rem">'
                + '<button type="button" data-cancel style="min-height:2.25rem;padding:0 1rem;border:1px solid var(--border-strong);border-radius:0.25rem;background:transparent;color:inherit;font-weight:600;cursor:pointer">Cancel</button>'
                + '<button type="button" data-ok style="min-height:2.25rem;padding:0 1rem;border:0;border-radius:0.25rem;background:var(--brand);color:#fff;font-weight:600;cursor:pointer">Confirm</button>'
                + '</div>';
            document.body.appendChild(dialog);
        }

        dialog.querySelector('[data-message]').textContent = message;
        // Replace the buttons so a previous parcel's handler can never fire for this one.
        const ok = dialog.querySelector('[data-ok]').cloneNode(true);
        const cancel = dialog.querySelector('[data-cancel]').cloneNode(true);
        dialog.querySelector('[data-ok]').replaceWith(ok);
        dialog.querySelector('[data-cancel]').replaceWith(cancel);
        ok.addEventListener('click', () => { dialog.close(); onConfirm(); });
        cancel.addEventListener('click', () => dialog.close());

        dialog.showModal();
        cancel.focus();
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/'/g, '&#39;');
    }

    startBtn?.addEventListener('click', () => {
        startCamera();
    });

    stopBtn?.addEventListener('click', () => {
        stopCamera();
        setStatus('Camera stopped.');
    });

    retryBtn?.addEventListener('click', () => {
        lastSubmitted = null;
        setError('');
        renderResult(null);
        setStatus('');
        if (trackingInput) {
            trackingInput.value = '';
        }
        startCamera();
    });

    manualBtn?.addEventListener('click', () => {
        const value = trackingInput?.value || '';
        submitTracking(value, 'manual');
    });

    trackingInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            submitTracking(trackingInput.value, 'manual');
        }
    });

    window.addEventListener('pagehide', stopCamera);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopCamera();
        }
    });

    if (!supportsCameraScan()) {
        setStatus('Camera QR scanning is unavailable here. Use manual tracking entry.');
        startBtn?.classList.add('hidden');
    }

    // Opened from a parcel's "Scan to confirm" link: verify that tracking number straight away.
    if (root.hasAttribute('data-auto-verify') && trackingInput?.value.trim()) {
        submitTracking(trackingInput.value, 'manual');
    }
})();
