@extends('Layouts.seller')

@section('title', 'Bundle and Buy X Get Y')

@use('App\Enums\PromotionKind')
@use('App\Models\Seller\StoreSale')

@php
    $inputClass = 'w-full rounded-md border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-ink)]';
    $labelClass = 'block text-sm font-semibold text-[var(--text-ink)]';
    $kind = old('kind', PromotionKind::BundlePercent->value);
@endphp

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="font-serif text-3xl text-[var(--text-ink)]">Bundle and Buy X Get Y</h1>
        <p class="mt-2 text-sm text-[var(--text-muted)]">A bundle takes a percentage off every product in the deal when they are bought together. Buy X Get Y makes the free units of one product cost nothing. Times are Philippine time. A running store sale keeps its own price.</p>
    </div>

    @if(session('success'))
        <div role="status" class="rounded-md border border-tertiary-300 bg-tertiary-50 p-4 text-sm text-tertiary-800">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-md border border-error p-4 text-sm text-error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('seller.promotions.store') }}" class="space-y-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4">
        @csrf
        <fieldset>
            <legend class="{{ $labelClass }}">Deal</legend>
            <div class="mt-2 flex flex-wrap gap-5 text-sm text-[var(--text-ink)]">
                @foreach(PromotionKind::cases() as $case)
                    <label class="flex items-center gap-2">
                        <input type="radio" name="kind" value="{{ $case->value }}" @checked($kind === $case->value)>
                        {{ $case->label() }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div>
            <label class="{{ $labelClass }}" for="deal-name">Name</label>
            <input id="deal-name" name="name" type="text" maxlength="120" value="{{ old('name') }}" class="mt-1 {{ $inputClass }}">
        </div>

        <div data-deal="{{ PromotionKind::BundlePercent->value }}">
            <label class="{{ $labelClass }}" for="deal-percent">Percentage off (1–99)</label>
            <input id="deal-percent" name="percent" type="number" min="1" max="99" step="1" value="{{ old('percent') }}" class="mt-1 {{ $inputClass }}">
        </div>

        <div data-deal="{{ PromotionKind::BuyXGetY->value }}" class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $labelClass }}" for="deal-buy">Buy</label>
                <input id="deal-buy" name="buy_quantity" type="number" min="1" max="99" step="1" value="{{ old('buy_quantity') }}" class="mt-1 {{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}" for="deal-get">Get free</label>
                <input id="deal-get" name="get_quantity" type="number" min="1" max="99" step="1" value="{{ old('get_quantity') }}" class="mt-1 {{ $inputClass }}">
            </div>
        </div>

        <div>
            <label class="{{ $labelClass }}" for="deal-products">Products</label>
            <select id="deal-products" name="product_ids[]" multiple required class="mt-1 {{ $inputClass }} min-h-32">
                @foreach($products as $product)
                    <option value="{{ $product->id }}" @selected(in_array($product->id, array_map('intval', (array) old('product_ids', [])), true))>{{ $product->name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-[12px] text-[var(--text-muted)]">Choose two or more products for a bundle, or one product for Buy X Get Y.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $labelClass }}" for="deal-starts">Starts (Philippine time)</label>
                <input id="deal-starts" name="starts_at" type="datetime-local" required value="{{ old('starts_at') }}" class="mt-1 {{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}" for="deal-ends">Ends (Philippine time)</label>
                <input id="deal-ends" name="ends_at" type="datetime-local" required value="{{ old('ends_at') }}" class="mt-1 {{ $inputClass }}">
            </div>
        </div>

        <button type="submit" class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">Create deal</button>
    </form>

    @if($promotions->isEmpty())
        <p class="text-sm text-[var(--text-muted)]">No deals yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)]">
            <table class="w-full border-collapse text-[13.5px] text-[var(--text-ink)]">
                <thead>
                    <tr class="text-left text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)]">
                        <th class="border-b border-[var(--border-strong)] px-3 py-2">Deal</th>
                        <th class="border-b border-[var(--border-strong)] px-3 py-2">Products</th>
                        <th class="border-b border-[var(--border-strong)] px-3 py-2">When</th>
                        <th class="border-b border-[var(--border-strong)] px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($promotions as $promotion)
                        <tr>
                            <td class="border-b border-[var(--border-strong)] px-3 py-2">{{ $promotion->name ?: $promotion->label() }}<br><span class="text-[12px] text-[var(--text-muted)]">{{ $promotion->label() }}</span></td>
                            <td class="border-b border-[var(--border-strong)] px-3 py-2">{{ $promotion->products->pluck('name')->join(', ') }}</td>
                            <td class="border-b border-[var(--border-strong)] px-3 py-2">{{ $promotion->starts_at->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }} – {{ $promotion->ends_at->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }}</td>
                            <td class="border-b border-[var(--border-strong)] px-3 py-2">
                                <form method="POST" action="{{ route('seller.promotions.destroy', $promotion) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-sm font-semibold text-error">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const sync = () => {
            const chosen = document.querySelector('input[name="kind"]:checked')?.value;
            document.querySelectorAll('[data-deal]').forEach((block) => {
                const active = block.dataset.deal === chosen;
                block.classList.toggle('hidden', !active);
                block.querySelectorAll('input').forEach((input) => {
                    input.disabled = !active;
                    input.required = active;
                });
            });
        };
        document.querySelectorAll('input[name="kind"]').forEach((input) => input.addEventListener('change', sync));
        sync();
    })();
</script>
@endpush
