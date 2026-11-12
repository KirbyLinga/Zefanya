@if ($dispute['case'])
    <p class="mt-3 text-sm">Non-receipt case #{{ $dispute['case']['id'] }} · {{ ucfirst($dispute['case']['status']) }}{{ $dispute['case']['outcome'] ? ' · '.ucfirst($dispute['case']['outcome']) : '' }}</p>
@elseif ($dispute['can_report'])
    <details class="mt-3 rounded-lg border p-3">
        <summary class="cursor-pointer text-sm font-semibold">I didn't receive this parcel</summary>
        <form class="mt-2 space-y-2" method="POST" enctype="multipart/form-data" action="{{ route('buyer.shipments.dispute.store', $sellerOrder) }}">
            @csrf
            <label class="block text-sm">What happened? <textarea name="explanation" required maxlength="1000" class="block w-full rounded border p-2"></textarea></label>
            <label class="block text-sm">Supporting photo (optional, up to 5 MB) <input class="block" type="file" name="evidence" accept="image/jpeg,image/png,image/webp"></label>
            <button class="min-h-10 rounded bg-primary-800 px-4 text-sm text-white">Send for investigation</button>
        </form>
    </details>
@endif
@error('delivery_dispute')<p class="mt-2 text-sm text-red-800">{{ $message }}</p>@enderror
@error('evidence')<p class="mt-2 text-sm text-red-800">{{ $message }}</p>@enderror
