@props([
    'verifyUrl',
    'heading' => 'Scan parcel',
    'subheading' => 'Scan the waybill QR or type the tracking number. Verification alone does not change parcel status.',
])

<section
    data-parcel-scanner
    data-verify-url="{{ $verifyUrl }}"
    class="space-y-4"
>
    <div>
        <h1 class="font-serif text-2xl text-[var(--text-ink)]">{{ $heading }}</h1>
        <p class="mt-1 font-sans text-[12.5px] text-[var(--text-muted)]">{{ $subheading }}</p>
    </div>

    <div class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 shadow-sm">
        <div class="mb-3 flex flex-wrap gap-2">
            <button
                type="button"
                data-scan-start
                class="inline-flex h-9 items-center justify-center rounded-sm bg-primary-700 px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-white hover:bg-primary-800"
            >
                Start camera
            </button>
            <button
                type="button"
                data-scan-stop
                class="hidden inline-flex h-9 items-center justify-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)]"
            >
                Stop camera
            </button>
            <button
                type="button"
                data-scan-retry
                class="inline-flex h-9 items-center justify-center rounded-sm border border-[var(--border-strong)] px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-[var(--text-ink)]"
            >
                Retry
            </button>
        </div>

        <div data-scan-camera-panel class="hidden overflow-hidden rounded-md border border-[var(--border-strong)] bg-black">
            <video data-scan-video playsinline muted class="mx-auto max-h-[420px] w-full object-cover"></video>
        </div>

        <p data-scan-status class="mt-3 font-sans text-[12px] text-[var(--text-muted)]" role="status"></p>
        <p data-scan-error class="mt-2 hidden font-sans text-[12px] text-red-700" role="alert"></p>

        <div class="mt-4 border-t border-[var(--border-strong)] pt-4">
            <label for="parcel-tracking-input" class="mb-1 block font-sans text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">
                Tracking number
            </label>
            <div class="flex flex-col gap-2 sm:flex-row">
                <input
                    id="parcel-tracking-input"
                    type="text"
                    data-scan-tracking-input
                    maxlength="64"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="TRK-YYYYMMDD-XXXXXX"
                    class="w-full rounded-sm border border-[var(--border-strong)] bg-[var(--bg-page)] px-3 py-2 font-mono text-[13px] text-[var(--text-ink)]"
                >
                <button
                    type="button"
                    data-scan-manual
                    class="inline-flex h-10 shrink-0 items-center justify-center rounded-sm border border-primary-700 px-4 font-sans text-[11.5px] font-semibold uppercase tracking-[0.5px] text-primary-800 hover:bg-primary-50"
                >
                    Verify
                </button>
            </div>
        </div>
    </div>

    <div data-scan-result class="hidden"></div>
</section>
