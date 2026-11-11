@extends('Admin.Layouts.app')

@section('title', 'Vouchers')
@section('content-width', 'max-w-none')

@use('App\Enums\VoucherType')
@use('App\Models\Seller\StoreSale')

@section('content')
<div class="voucher-workspace">
    <header class="voucher-header">
        <div>
            <p class="voucher-eyebrow">Marketplace promotions</p>
            <h1>Vouchers</h1>
            <p class="voucher-intro">Manage merchandise and shipping discounts funded by the marketplace.</p>
        </div>
        <a id="open-create-voucher" aria-haspopup="dialog" aria-controls="create-voucher-dialog" href="{{ route('admin.vouchers.create') }}" class="voucher-button voucher-primary"><i data-lucide="plus" aria-hidden="true"></i>New voucher</a>
    </header>
    <div class="voucher-context"><span><i data-lucide="badge-percent" aria-hidden="true"></i>Marketplace funded</span><span><i data-lucide="clock-3" aria-hidden="true"></i>All schedules in Philippine time (UTC+8)</span></div>

    <noscript><p class="voucher-notice">Enable JavaScript to create a voucher in the modal, check its code, and review the discount before submitting.</p></noscript>
    @if(session('success'))
        <div role="status" class="voucher-notice voucher-success"><i data-lucide="circle-check" aria-hidden="true"></i>{{ session('success') }}</div>
    @endif
    @if($errors->any() && old('_modal') !== 'admin-voucher-create' && !isset($editVoucher))
        <div role="alert" class="voucher-notice voucher-error">{{ $errors->first() }}</div>
    @endif

    @if($vouchers->total() === 0)
        <section class="voucher-empty" aria-labelledby="voucher-empty-title">
            <div class="voucher-ticket"><i data-lucide="tickets" aria-hidden="true"></i></div>
            <p class="voucher-eyebrow">Your next promotion starts here</p>
            <h2 id="voucher-empty-title">Create your first voucher</h2>
            <p>Give buyers a reason to check out. Choose a discount, set its limits, and decide when it runs.</p>
            <a href="{{ route('admin.vouchers.create') }}" class="voucher-button voucher-primary"><i data-lucide="plus" aria-hidden="true"></i>Create a voucher</a>
            <div class="voucher-examples">
                <div><i data-lucide="shopping-bag" aria-hidden="true"></i><div><h3>Platform voucher</h3><p>Discount merchandise at checkout.<br><span>Example: ₱100 off an eligible purchase.</span></p></div></div>
                <div><i data-lucide="truck" aria-hidden="true"></i><div><h3>Shipping voucher</h3><p>Reduce eligible shipping fees.<br><span>Example: ₱50 off delivery.</span></p></div></div>
            </div>
        </section>
    @else
        <section class="voucher-directory" aria-labelledby="voucher-directory-title">
            <header class="voucher-directory-header">
                <div><h2 id="voucher-directory-title">Voucher directory <span>{{ number_format($vouchers->total()) }}</span></h2><p>@if($vouchers->isNotEmpty())Showing {{ $vouchers->firstItem() }}–{{ $vouchers->lastItem() }} of {{ number_format($vouchers->total()) }} vouchers @else No vouchers on this page @endif</p></div>
                <span class="voucher-sort"><i data-lucide="arrow-down-wide-narrow" aria-hidden="true"></i>Newest created first</span>
            </header>
            @if($vouchers->isEmpty())
                <div class="voucher-page-empty"><h3>This page is empty</h3><p>Your vouchers are still available in the directory.</p><a class="voucher-button voucher-secondary" href="{{ $vouchers->url(1) }}">Return to first page</a></div>
            @else
                <p id="voucher-scroll-help" class="voucher-scroll-hint"><i data-lucide="move-horizontal" aria-hidden="true"></i>Scroll horizontally to see the schedule and actions. Keyboard: focus the table, then use arrow keys.</p>
                <div class="voucher-table-scroll" role="region" aria-label="Voucher directory table" aria-describedby="voucher-scroll-help" tabindex="0">
                    <table class="voucher-table">
                        <thead><tr><th scope="col">Voucher</th><th scope="col">Discount & eligibility</th><th scope="col">Usage</th><th scope="col">Schedule <span>Philippine time</span></th><th scope="col">Status</th><th scope="col" class="voucher-actions-heading">Actions</th></tr></thead>
                        <tbody>
                        @foreach($vouchers as $voucher)
                            @php
                                $status = $voucher->statusLabel();
                                $tone = match ($status) { 'Active' => 'active', 'Upcoming' => 'upcoming', 'Ended', 'Expired', 'Used up' => 'closed', default => 'inactive' };
                            @endphp
                            <tr>
                                <td><p class="voucher-code">{{ $voucher->code }}</p><p class="voucher-kind"><i data-lucide="{{ $voucher->effectiveScope()->value === 'shipping' ? 'truck' : 'shopping-bag' }}" aria-hidden="true"></i>{{ $voucher->effectiveScope()->label() }}</p><span class="voucher-detail">{{ $voucher->is_claimable ? 'Claimable' : 'Code only' }}</span></td>
                                <td><p class="voucher-discount">@if($voucher->type === VoucherType::Percent){{ $voucher->value }}% off @else ₱{{ number_format($voucher->value / 100, 2) }} off @endif</p>
                                    @if($voucher->type === VoucherType::Percent && $voucher->max_discount !== null)<p class="voucher-detail">Up to ₱{{ number_format($voucher->max_discount / 100, 2) }}</p>@endif
                                    <p class="voucher-detail">{{ $voucher->min_spend ? 'Min. spend ₱'.number_format($voucher->min_spend / 100, 2) : 'No minimum spend' }}</p>
                                    <p class="voucher-eligibility">{{ $voucher->applies_to_all_products ? 'All products' : trans_choice('{1} :count product|[2,*] :count products', $voucher->products_count, ['count' => $voucher->products_count]) }}</p>
                                </td>
                                <td><p class="voucher-usage"><strong>{{ number_format($voucher->times_used) }}</strong> / {{ $voucher->usage_limit !== null ? number_format($voucher->usage_limit) : 'Unlimited' }}</p>
                                    @if($voucher->usage_limit !== null && $voucher->usage_limit > 0)<div class="voucher-meter" aria-hidden="true"><span style="width:{{ min(100, max(0, $voucher->times_used / $voucher->usage_limit * 100)) }}%"></span></div>@endif
                                    <p class="voucher-detail">{{ $voucher->per_buyer_limit !== null ? $voucher->per_buyer_limit.' per buyer' : 'No per-buyer limit' }}</p>
                                </td>
                                <td class="voucher-schedule"><p><span>Starts</span>{{ $voucher->starts_at?->copy()->timezone(StoreSale::TIMEZONE)->format('M j, Y · g:i A') ?? 'No start date' }}</p><p><span>Ends</span>{{ $voucher->expires_at?->copy()->timezone(StoreSale::TIMEZONE)->format('M j, Y · g:i A') ?? 'No expiry' }}</p></td>
                                <td><span class="voucher-status voucher-status-{{ $tone }}"><span aria-hidden="true"></span>{{ $status }}</span></td>
                                <td class="voucher-row-actions">
                                    <div class="voucher-action-row">
                                        @unless($voucher->hasEnded())
                                            <a href="{{ route('admin.vouchers.edit', $voucher) }}" aria-haspopup="dialog" class="voucher-action voucher-edit" aria-label="Edit voucher {{ $voucher->code }}"><i data-lucide="pencil" aria-hidden="true"></i>Edit</a>
                                            <form method="POST" action="{{ route('admin.vouchers.end', $voucher) }}" onsubmit="return confirm('End this voucher for good? Buyers can no longer use it, and it leaves every wallet where it was not used.')">@csrf<button type="submit" class="voucher-action voucher-end" aria-label="End voucher {{ $voucher->code }} permanently"><i data-lucide="circle-stop" aria-hidden="true"></i>End</button></form>
                                        @endunless
                                        <form method="POST" action="{{ route('admin.vouchers.destroy', $voucher) }}" onsubmit="return confirm('Delete this voucher?')">@csrf @method('DELETE')<button type="submit" class="voucher-action voucher-delete" aria-label="Delete voucher {{ $voucher->code }}"><i data-lucide="trash-2" aria-hidden="true"></i>Delete</button></form>
                                    </div>
                                    <p class="voucher-action-help">Only unclaimed, unused vouchers can be deleted.</p>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if($vouchers->hasPages())<div class="voucher-pagination">{{ $vouchers->links() }}</div>@endif
        </section>
    @endif
</div>
@isset($editVoucher)
@include('Admin.Vouchers._edit-modal', ['voucher' => $editVoucher])
@endisset
@include('Seller.Vouchers._create-modal', ['marketplace' => true, 'errors' => old('_modal') === 'admin-voucher-create' ? $errors : new \Illuminate\Support\ViewErrorBag])
@endsection

@push('scripts')
@include('Seller.Vouchers._create-script')
@isset($editVoucher)
@include('Admin.Vouchers._edit-script')
@endisset
@endpush
