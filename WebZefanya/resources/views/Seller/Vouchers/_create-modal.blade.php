@php
    $marketplace = $marketplace ?? false;
    $voucherRoutes = $marketplace ? 'admin.vouchers' : 'seller.vouchers';
    $creationMarker = $marketplace ? 'admin-voucher-create' : 'voucher-create';
    $input = 'mt-2 w-full rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 py-2.5 text-sm text-[var(--text-ink)] outline-none transition focus:border-primary-600 focus:ring-2 focus:ring-primary-500/20 disabled:opacity-50';
    $label = 'block text-xs font-semibold text-[var(--text-muted)]';
    $option = 'flex cursor-pointer items-start gap-3 rounded-lg border border-[var(--border-input)] p-3 transition hover:border-primary-500 has-checked:border-primary-600 has-checked:bg-primary-500/5';
    $scalar = fn ($value) => is_scalar($value) ? $value : '';
    $selected = array_map('intval', (array) old('product_ids', []));
    $type = old('type', 'percent');
    $appliesTo = old('applies_to', 'all');
@endphp
<dialog id="create-voucher-dialog" aria-labelledby="create-voucher-heading" aria-describedby="create-voucher-description" data-open="{{ ($openCreateVoucher || ($errors->any() && old('_modal') === $creationMarker)) ? 'true' : 'false' }}" class="m-auto max-h-[calc(100dvh_-_1rem)] w-[calc(100%_-_1rem)] max-w-5xl overflow-hidden rounded-2xl border border-[var(--border-input)] bg-[var(--bg-surface)] p-0 font-sans text-[var(--text-ink)] shadow-2xl backdrop:bg-black/60 sm:max-h-[calc(100dvh_-_3rem)] sm:w-[calc(100%_-_3rem)]">

    <form id="create-voucher" data-marketplace="{{ $marketplace ? 'true' : 'false' }}" method="POST" action="{{ route($voucherRoutes.'.store') }}" data-code-url="{{ route($voucherRoutes.'.code-availability') }}" class="flex max-h-[calc(100dvh_-_1rem)] flex-col overflow-hidden bg-[var(--bg-surface)] sm:max-h-[calc(100dvh_-_3rem)]">
        @csrf
        <input type="hidden" name="_modal" value="{{ $creationMarker }}">
        <header class="mx-5 flex shrink-0 items-center justify-between gap-4 border-b border-[var(--border-input)] py-5 sm:mx-7">
            <div><h2 id="create-voucher-heading" class="font-sans text-[var(--text-ink)] text-lg font-semibold tracking-tight">Create Voucher</h2><p id="create-voucher-description" class="mt-1 text-xs text-[var(--text-muted)]">Set up a discount for your customers.</p></div>
            <div class="flex items-center gap-3"><span class="hidden rounded-full bg-primary-500/10 px-2.5 py-1 text-[10px] font-semibold text-[var(--text-muted)] sm:inline-block">{{ $marketplace ? 'Marketplace funded' : 'Seller funded' }}</span><button type="button" id="close-voucher-modal" aria-label="Close create voucher" class="flex h-8 w-8 items-center justify-center rounded-full text-[var(--text-ink)] hover:bg-primary-500/10 focus-visible:ring-2 focus-visible:ring-primary-500"><i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i></button></div>
        </header>
        <div id="voucher-modal-scroll" class="min-h-0 overflow-y-auto overscroll-contain">
        <div id="voucher-error-summary" role="alert" tabindex="-1" class="mx-5 mt-5 rounded-lg border border-error/40 bg-error/5 p-3 text-sm text-error sm:mx-7" @if(!$errors->any()) hidden @endif>
            @if($errors->any()) Please check the highlighted fields. Your entries have been kept. @endif
        </div>
        <noscript><p class="m-5 rounded-lg border border-error p-3 text-sm">Enable JavaScript to check your code, preview your discount, and review this voucher before creating it. Your voucher has not been submitted.</p></noscript>
        <div class="grid gap-8 p-5 sm:p-7 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.9fr)] lg:gap-10">
            <div id="voucher-details" class="space-y-7">
                <section aria-labelledby="details-heading" class="space-y-4">
                    <h2 id="details-heading" class="font-sans text-[var(--text-ink)] flex items-center gap-2 text-sm font-semibold"><i data-lucide="ticket-percent" class="h-4 w-4" aria-hidden="true"></i> 1. Voucher details</h2>
                    @if($marketplace)
                        <fieldset><legend class="{{ $label }}">Voucher kind</legend><div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach(['platform' => ['Platform voucher, such as PLAT100', 'Discounts merchandise.'], 'shipping' => ['Shipping voucher, such as FREESHIP', 'Discounts shipping fees.']] as $scope => [$title, $description])
                                <label class="{{ $option }}"><input type="radio" name="scope" value="{{ $scope }}" @checked(old('scope', 'platform') === $scope) class="mt-0.5 accent-primary-800"><span><span class="block text-xs font-semibold text-[var(--text-ink)]">{{ $title }}</span><span class="mt-1 block text-[11px] text-[var(--text-muted)]">{{ $description }}</span></span></label>
                            @endforeach
                        </div><p class="mt-2 text-[11px] text-[var(--text-muted)]">The marketplace funds this discount. Minimum spend is measured on merchandise, including for shipping vouchers.</p><p data-error-for="scope" class="mt-1 text-xs text-error">{{ $errors->first('scope') }}</p></fieldset>
                    @endif
                    <div>
                        <label for="voucher-code" class="{{ $label }}">Voucher code</label>
                        <div class="flex items-center gap-2"><input id="voucher-code" name="code" required minlength="4" maxlength="12" pattern="[A-Za-z0-9]{4,12}" autocomplete="off" spellcheck="false" placeholder="e.g. SAVE20" value="{{ $scalar(old('code')) }}" aria-describedby="code-hint code-status code-error" class="{{ $input }} uppercase"><button type="button" id="generate-code" class="text-[var(--text-ink)] mt-2 shrink-0 rounded-full border border-[var(--border-input)] px-3 py-2 text-xs font-semibold hover:border-primary-500 focus-visible:ring-2 focus-visible:ring-primary-500">Generate</button></div>
                        <p id="code-hint" class="mt-1.5 text-[11px] text-[var(--text-muted)]">4-12 letters and numbers. Codes must be unique.</p>
                        <p id="code-status" role="status" class="mt-1 text-xs text-[var(--text-muted)]"></p>
                        <button id="retry-code" type="button" hidden class="text-[var(--text-ink)] mt-1 text-xs font-semibold underline">Retry code check</button>
                        <p id="code-error" data-error-for="code" class="mt-1 text-xs text-error">{{ $errors->first('code') }}</p>
                    </div>
                    <fieldset><legend class="{{ $label }}">Discount type</legend><div class="mt-2 grid grid-cols-2 gap-2">
                        @foreach(['percent' => ['Percentage', 'A percentage off eligible items', 'badge-percent'], 'fixed' => ['Fixed amount', 'A set peso amount off', 'philippine-peso']] as $value => [$title, $description, $icon])
                            <label class="{{ $option }}"><input type="radio" name="type" value="{{ $value }}" @checked($type === $value) class="mt-0.5 accent-primary-800"><span><i data-lucide="{{ $icon }}" class="mb-1 h-4 w-4" aria-hidden="true"></i><span class="block text-xs font-semibold text-[var(--text-ink)]">{{ $title }}</span><span id="discount-type-{{ $value }}-description" class="mt-1 block text-[10px] leading-relaxed text-[var(--text-muted)]">{{ $description }}</span></span></label>
                        @endforeach
                    </div><p data-error-for="type" class="mt-1 text-xs text-error">{{ $errors->first('type') }}</p></fieldset>
                    <div data-type="percent" class="grid grid-cols-2 gap-3" @if($type !== 'percent') hidden @endif>
                        @foreach(['percent' => ['Discount (%)', '20', 1, 99, 1], 'max_discount' => ['Maximum discount (PHP)', '100.00', '0.01', '99999999.99', '0.01']] as $name => [$title, $placeholder, $min, $max, $step])
                            <div><label for="voucher-{{ $name }}" class="{{ $label }}">{{ $title }}</label><input id="voucher-{{ $name }}" name="{{ $name }}" type="number" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" placeholder="{{ $placeholder }}" value="{{ $scalar(old($name)) }}" aria-describedby="{{ $name }}-error" class="{{ $input }}" @disabled($type !== 'percent') @required($type === 'percent')><p id="{{ $name }}-error" data-error-for="{{ $name }}" class="mt-1 text-xs text-error">{{ $errors->first($name) }}</p></div>
                        @endforeach
                    </div>
                    <div data-type="fixed" @if($type !== 'fixed') hidden @endif><label for="voucher-fixed_amount" class="{{ $label }}">Fixed discount amount (PHP)</label><input id="voucher-fixed_amount" name="fixed_amount" type="number" min="0.01" max="99999999.99" step="0.01" placeholder="100.00" value="{{ $scalar(old('fixed_amount')) }}" aria-describedby="fixed_amount-error" class="{{ $input }}" @disabled($type !== 'fixed') @required($type === 'fixed')><p id="fixed_amount-error" data-error-for="fixed_amount" class="mt-1 text-xs text-error">{{ $errors->first('fixed_amount') }}</p></div>
                </section>
                <section aria-labelledby="requirements-heading" class="space-y-4">
                    <h2 id="requirements-heading" class="font-sans text-[var(--text-ink)] flex items-center gap-2 text-sm font-semibold"><i data-lucide="shopping-basket" class="h-4 w-4" aria-hidden="true"></i> 2. Requirements</h2>
                    <div><label for="voucher-min_spend" class="{{ $label }}">Minimum purchase amount (PHP) <span class="font-normal text-[var(--text-muted)]">Optional</span></label><input id="voucher-min_spend" name="min_spend" type="number" min="0" max="99999999.99" step="0.01" placeholder="No minimum spend" value="{{ $scalar(old('min_spend')) }}" aria-describedby="min-hint min_spend-error" class="{{ $input }}"><p id="min-hint" class="mt-1.5 text-[11px] text-[var(--text-muted)]">Eligible products count at their sale price. Leave blank for no minimum.</p><p id="min_spend-error" data-error-for="min_spend" class="mt-1 text-xs text-error">{{ $errors->first('min_spend') }}</p></div>
                    <fieldset><legend class="{{ $label }}">Eligible products</legend><div class="mt-2 flex flex-wrap gap-4 text-xs">
                        @foreach(['all' => $marketplace ? 'All products' : 'All my products', 'selected' => 'Selected products'] as $value => $title)
                            <label class="flex items-center gap-2 text-[var(--text-ink)]"><input type="radio" name="applies_to" value="{{ $value }}" @checked($appliesTo === $value) class="accent-primary-800">{{ $title }}</label>
                        @endforeach
                    </div><p data-error-for="applies_to" class="mt-1 text-xs text-error">{{ $errors->first('applies_to') }}</p></fieldset>
                    <div id="product-picker" @if($appliesTo !== 'selected') hidden @endif>
                        <div class="flex items-center justify-between gap-2"><label for="product-search" class="{{ $label }}">{{ $marketplace ? 'Search catalog products' : 'Search your products' }}</label><span id="product-count" class="text-[11px] text-[var(--text-muted)]">{{ count($selected) }} selected</span></div>
                        <input id="product-search" type="search" placeholder="Search by product name..." class="{{ $input }}" aria-controls="product-options">
                        <div id="product-options" class="mt-2 max-h-56 divide-y divide-[var(--border-input)] overflow-y-auto rounded-lg border border-[var(--border-input)]">
                            @forelse($products as $product)
                                <label data-product-name="{{ $product->name }}" class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-primary-500/5"><input type="checkbox" name="product_ids[]" value="{{ $product->id }}" @checked(in_array($product->id, $selected, true)) class="accent-primary-800" aria-describedby="product_ids-error">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-md bg-primary-500/10"><i data-lucide="image" class="h-4 w-4 text-[var(--text-muted)]" aria-hidden="true"></i>@if($image = $product->catalogImageUrl(requireExistingFile: false, thumbnail: true))<img src="{{ $image }}" alt="" loading="lazy" class="h-10 w-10 object-cover" onerror="this.hidden = true; this.previousElementSibling.hidden = false" onload="this.previousElementSibling.hidden = true">@endif</span>
                                    <span class="text-xs leading-relaxed text-[var(--text-ink)]">{{ $product->name }}</span>
                                </label>
                            @empty
                                <p class="p-3 text-xs text-[var(--text-muted)]">{{ $marketplace ? 'There are no catalog products yet.' : 'You have no products yet. Add products before choosing this option.' }}</p>
                            @endforelse
                        </div>
                        <p id="no-products-found" hidden class="mt-2 text-xs text-[var(--text-muted)]">No products match your search.</p>
                    </div>
                    <p id="product_ids-error" data-error-for="product_ids" class="text-xs text-error">{{ $errors->first('product_ids') ?: $errors->first('product_ids.*') }}</p>
                </section>
                <section aria-labelledby="schedule-heading" class="space-y-4">
                    <h2 id="schedule-heading" class="font-sans text-[var(--text-ink)] flex items-center gap-2 text-sm font-semibold"><i data-lucide="calendar-days" class="h-4 w-4" aria-hidden="true"></i> 3. Schedule & limits</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach(['usage_limit' => ['Total redemptions (optional)', 'Unlimited', null], 'per_buyer_limit' => ['Uses per buyer (optional)', 'Unlimited', 1]] as $name => [$title, $placeholder, $default])
                            <div><label for="voucher-{{ $name }}" class="{{ $label }}">{{ $title }}</label><input id="voucher-{{ $name }}" name="{{ $name }}" type="number" min="1" step="1" placeholder="{{ $placeholder }}" value="{{ $scalar(old($name, $default)) }}" aria-describedby="{{ $name }}-error" class="{{ $input }}"><p id="{{ $name }}-error" data-error-for="{{ $name }}" class="mt-1 text-xs text-error">{{ $errors->first($name) }}</p></div>
                        @endforeach
                        @foreach(['starts_at' => 'Start date & time', 'expires_at' => 'End date & time'] as $name => $title)
                            <div><label for="voucher-{{ $name }}" class="{{ $label }}">{{ $title }}</label><input id="voucher-{{ $name }}" name="{{ $name }}" type="datetime-local" required value="{{ $scalar(old($name)) }}" aria-describedby="timezone-hint {{ $name }}-error" class="{{ $input }} min-w-0"><p id="{{ $name }}-error" data-error-for="{{ $name }}" class="mt-1 text-xs text-error">{{ $errors->first($name) }}</p></div>
                        @endforeach
                    </div>
                    <p id="timezone-hint" class="text-[11px] text-[var(--text-muted)]">All dates and times are Philippine time (UTC+8).</p>
                </section>
                <section aria-labelledby="availability-heading" class="space-y-4">
                    <h2 id="availability-heading" class="font-sans text-[var(--text-ink)] flex items-center gap-2 text-sm font-semibold"><i data-lucide="sliders-horizontal" class="h-4 w-4" aria-hidden="true"></i> 4. Availability</h2>
                    <fieldset class="space-y-2"><legend class="sr-only">Voucher distribution</legend>
                        @foreach(($marketplace ? ['code_only' => ['Code only', 'Share the code with buyers. They enter it at checkout to redeem the discount.'], 'claimable' => ['Claimable', 'Marketplace voucher discovery and claiming are not currently available; buyers still enter the code.']] : ['claimable' => ['Claimable voucher', 'Buyers can discover and save this voucher from your store, product pages, and cart.'], 'code_only' => ['Code only', 'Share the code yourself. Buyers must enter it at checkout; it is not listed for discovery.']]) as $value => [$title, $description])
                            <label class="{{ $option }}"><input type="radio" name="availability" value="{{ $value }}" @checked(old('availability', $marketplace ? 'code_only' : 'claimable') === $value) class="mt-0.5 accent-primary-800"><span><span class="block text-xs font-semibold text-[var(--text-ink)]">{{ $title }}</span><span class="mt-1 block text-[11px] leading-relaxed text-[var(--text-muted)]">{{ $description }}</span></span></label>
                        @endforeach
                        <p data-error-for="availability" class="text-xs text-error">{{ $errors->first('availability') }}</p>
                    </fieldset>
                    <fieldset><legend class="{{ $label }}">Activation</legend><div class="mt-2 space-y-2 text-xs">
                        <label class="flex items-center gap-2 text-[var(--text-ink)]"><input type="radio" name="is_active" value="1" @checked((string) old('is_active', '1') === '1') class="accent-primary-800">Activate when the start date arrives</label>
                        <label class="flex items-center gap-2 text-[var(--text-ink)]"><input type="radio" name="is_active" value="0" @checked((string) old('is_active', '1') === '0') class="accent-primary-800">Save as inactive</label>
                    </div><p class="mt-2 text-[11px] leading-relaxed text-[var(--text-muted)]">Inactive vouchers stay unavailable until you activate them from Edit. The start date must also have arrived.</p><p data-error-for="is_active" class="mt-1 text-xs text-error">{{ $errors->first('is_active') }}</p></fieldset>
                </section>
            </div>
            <aside class="min-w-0" aria-label="Live voucher preview">
                <div class="space-y-5 lg:sticky lg:top-7">
                    <div class="flex items-center justify-between gap-2"><h2 class="font-sans text-[var(--text-ink)] flex items-center gap-2 text-sm font-semibold"><i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i> Buyer voucher preview</h2><span class="rounded-full bg-primary-500/10 px-2 py-0.5 text-[10px] text-[var(--text-muted)]">Example</span></div>
                    <div class="relative overflow-hidden rounded-xl border border-[var(--border-input)] bg-primary-500/10 p-5">
                        <div class="flex items-start justify-between gap-2"><p id="preview-offer" class="text-[var(--text-ink)] text-xl font-semibold tracking-tight">Your discount</p><i data-lucide="tickets" class="h-5 w-5 text-[var(--text-muted)]" aria-hidden="true"></i></div>
                        <p id="preview-terms" class="mt-2 text-xs leading-relaxed text-[var(--text-muted)]">No minimum spend</p>
                        <div class="mt-5 flex items-end justify-between gap-3 border-t border-[var(--border-input)] pt-3"><div><p class="text-[10px] text-[var(--text-muted)]">Code</p><p id="preview-code" class="text-[var(--text-ink)] mt-1 break-all text-sm font-semibold">YOURCODE</p></div><span class="text-[var(--text-ink)] shrink-0 rounded-full bg-primary-500/15 px-2 py-1 text-[10px] font-semibold">{{ $marketplace ? 'Marketplace voucher' : 'Seller voucher' }}</span></div>
                    </div>
                    <section aria-labelledby="calculation-heading" class="text-xs"><h3 id="calculation-heading" class="font-sans text-[var(--text-ink)] mb-3 font-semibold">Example calculation</h3>
                        <dl class="space-y-3"><div class="flex justify-between gap-3"><dt id="example-subtotal-label" class="text-[var(--text-muted)]">Eligible items</dt><dd id="example-subtotal">&#8369;600.00</dd></div><div id="example-shipping-row" hidden class="flex justify-between gap-3"><dt class="text-[var(--text-muted)]">Example shipping fee</dt><dd>&#8369;100.00</dd></div><div class="flex justify-between gap-3"><dt class="text-[var(--text-muted)]">Voucher discount</dt><dd id="example-discount">-&#8369;0.00</dd></div><div class="flex justify-between gap-3 border-t border-[var(--border-input)] pt-3 text-sm font-semibold"><dt id="example-total-label">Buyer pays for items</dt><dd id="example-total">&#8369;600.00</dd></div></dl>
                        <p id="example-note" class="mt-3 text-[11px] leading-relaxed text-[var(--text-muted)]">Illustration only, using eligible items at sale prices. Shipping and other adjustments excluded.</p>
                    </section>
                    <section class="rounded-lg border border-[var(--border-input)] p-4"><h3 class="font-sans text-[var(--text-ink)] flex items-center gap-2 text-xs font-semibold"><i data-lucide="wallet" class="h-4 w-4" aria-hidden="true"></i> {{ $marketplace ? 'Marketplace discount impact' : 'Your discount impact' }}</h3><p id="funding-impact" class="mt-2 text-xs leading-relaxed text-[var(--text-muted)]">{{ $marketplace ? 'The marketplace funds the full voucher discount.' : 'You fund the full voucher discount.' }} Set your discount and limits to estimate the maximum impact.</p></section>
                </div>
            </aside>
        </div>
        <section id="voucher-review" hidden tabindex="-1" aria-labelledby="review-heading" class="mx-5 mb-7 rounded-xl border border-primary-500/40 bg-primary-500/5 p-5 sm:mx-7">
            <div class="flex items-start justify-between gap-3"><div><h2 id="review-heading" class="font-sans text-[var(--text-ink)] text-base font-semibold">Review your voucher</h2><p class="mt-1 text-xs text-[var(--text-muted)]">Check these settings before creating your voucher.</p></div><button type="button" id="edit-voucher" class="text-[var(--text-ink)] shrink-0 text-xs font-semibold underline">Edit details</button></div>
            <dl id="review-settings" class="mt-5 grid gap-4 text-xs sm:grid-cols-2"></dl>
            <p class="mt-5 border-t border-[var(--border-input)] pt-3 text-xs leading-relaxed text-[var(--text-muted)]">Once a buyer claims or uses this voucher, its discount, eligible products, distribution, and activation are locked. You can only extend the end date, raise limits, or end it.</p>
        </section>
        </div>
        <footer class="mx-5 flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-[var(--border-input)] py-5 sm:mx-7"><button type="button" id="cancel-voucher-modal" class="rounded-full border border-[var(--border-input)] px-4 py-2.5 text-xs font-semibold text-[var(--text-ink)] hover:border-primary-500 focus-visible:ring-2 focus-visible:ring-primary-500">Cancel</button><button id="review-voucher" type="submit" disabled class="rounded-full bg-primary-800 px-5 py-2.5 text-xs font-semibold text-white transition hover:bg-primary-900 focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60">Review & Create Voucher</button><button id="confirm-voucher" type="button" hidden class="rounded-full bg-primary-800 px-5 py-2.5 text-xs font-semibold text-white hover:bg-primary-900 focus-visible:ring-2 focus-visible:ring-primary-500 disabled:cursor-wait disabled:opacity-60">Confirm & Create Voucher</button></footer>
    </form>
</dialog>
