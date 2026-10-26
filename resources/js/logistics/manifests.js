/**
 * Manifest dispatch verification.
 *
 * Each parcel scan is verified server-side against the manifest + origin hub
 * and returns a single-use token bound to that manifest item. The manifest can
 * only be dispatched once every parcel row holds a verified token.
 */
(() => {
    const root = document.querySelector('[data-manifest-dispatch]');
    if (!root) {
        return;
    }

    const verifyUrl = root.dataset.verifyUrl;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const form = root.querySelector('[data-manifest-dispatch-form]');
    if (!form || !verifyUrl) {
        return;
    }

    const tokenInputs = Array.from(form.querySelectorAll('[data-manifest-token]'));
    const itemCount = tokenInputs.length || Number(root.dataset.itemCount || 0);
    const submitBtn = form.querySelector('[data-manifest-dispatch-submit]');
    const verifiedCountEl = root.querySelector('[data-manifest-verified-count]');
    const trackingInput = root.querySelector('[data-manifest-scan-input]');
    const startBtn = root.querySelector('[data-manifest-scan-start]');
    const stopBtn = root.querySelector('[data-manifest-scan-stop]');
    const cameraPanel = root.querySelector('[data-manifest-scan-panel]');
    const video = root.querySelector('[data-manifest-scan-video]');
    const statusEl = root.querySelector('[data-manifest-scan-status]');
    const errorEl = root.querySelector('[data-manifest-scan-error]');
    const verifyBtn = root.querySelector('[data-manifest-scan-verify]');

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

    function verifiedCount() {
        return tokenInputs.filter((input) => input.value.trim() !== '').length;
    }

    function syncProgress() {
        const verified = verifiedCount();
        if (verifiedCountEl) {
            verifiedCountEl.textContent = String(verified);
        }

        const ready = itemCount > 0 && verified >= itemCount;
        if (submitBtn) {
            submitBtn.disabled = !ready;
            submitBtn.classList.toggle('opacity-50', !ready);
            submitBtn.classList.toggle('cursor-not-allowed', !ready);
        }
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
        const value = String(tracking || '').trim();
        if (value === '' || verifying) {
            return;
        }

        if (lastSubmitted === value) {
            setStatus('That parcel was just verified. Confirm the dispatch when all rows are verified.');
            return;
        }

        verifying = true;
        lastSubmitted = value;
        setError('');
        setStatus(source === 'camera' ? 'QR detected — verifying against this manifest…' : 'Verifying tracking number…');
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
                    phase: 'dispatch',
                    method: source === 'camera' ? 'camera' : 'manual',
                }),
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                lastSubmitted = null;
                setError(
                    data.message
                    || data.errors?.tracking_number?.[0]
                    || 'Could not verify that parcel against this manifest.'
                );
                setStatus('');
                return;
            }

            const itemId = data.item?.id;
            const token = data.verification_token;
            const tokenInput = itemId !== undefined
                ? form.querySelector(`[data-manifest-token="${String(itemId)}"]`)
                : null;

            if (!tokenInput || !token) {
                lastSubmitted = null;
                setError('The verified parcel does not belong to this manifest.');
                setStatus('');
                return;
            }

            tokenInput.value = token;
            const row = form.querySelector(`[data-manifest-dispatch-item="${String(itemId)}"]`);
            const state = row?.querySelector('[data-manifest-dispatch-state]');
            if (state) {
                state.textContent = 'Verified';
                state.className = 'inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2 py-1 font-sans text-[10px] font-semibold text-emerald-800';
            }

            if (trackingInput) {
                trackingInput.value = '';
            }
            setStatus(`Verified ${data.parcel?.tracking_number || value}. Scan the next parcel.`);
            syncProgress();
        } catch (e) {
            lastSubmitted = null;
            setError('Network error while verifying. Try again.');
            setStatus('');
        } finally {
            verifying = false;
        }
    }

    startBtn?.addEventListener('click', startCamera);
    stopBtn?.addEventListener('click', stopCamera);
    verifyBtn?.addEventListener('click', () => submitTracking(trackingInput?.value || '', 'manual'));
    trackingInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            submitTracking(trackingInput.value, 'manual');
        }
    });

    form.addEventListener('submit', (event) => {
        if (verifiedCount() < itemCount) {
            event.preventDefault();
            setError('Verify every parcel on the manifest before dispatching.');
        }
    });

    syncProgress();
})();
