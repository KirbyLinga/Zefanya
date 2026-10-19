<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Waybill {{ $sellerOrder->tracking_number }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color: #111; background: #fff; }
        .toolbar { max-width: 720px; margin: 0 auto 16px; display: flex; justify-content: space-between; align-items: center; }
        .toolbar a, .toolbar button {
            font-size: 13px; font-weight: 600; padding: 8px 14px; border-radius: 8px; border: 1px solid #d4d4d8;
            background: #fff; cursor: pointer; text-decoration: none; color: #111;
        }
        .toolbar button { background: #111; color: #fff; border-color: #111; }
        .label { max-width: 720px; margin: 0 auto; border: 2px solid #111; border-radius: 10px; padding: 20px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 12px; }
        .brand { font-size: 22px; font-weight: 800; letter-spacing: 1px; }
        .brand small { display: block; font-weight: 500; font-size: 11px; letter-spacing: .5px; color: #555; }
        .track { text-align: right; }
        .track .code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 16px; font-weight: 700; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
        .box { border: 1px solid #d4d4d8; border-radius: 8px; padding: 12px; }
        .box h3 { margin: 0 0 6px; font-size: 10px; text-transform: uppercase; letter-spacing: .8px; color: #666; }
        .box p { margin: 0 0 3px; font-size: 13px; line-height: 1.4; }
        .box .name { font-weight: 700; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 12.5px; }
        th, td { border-bottom: 1px solid #e4e4e7; padding: 6px 4px; text-align: left; }
        th { font-size: 10px; text-transform: uppercase; letter-spacing: .6px; color: #666; }
        .cod { margin-top: 12px; padding: 10px 12px; border: 2px dashed #111; border-radius: 8px; font-size: 14px; font-weight: 700; }
        .foot { margin-top: 14px; font-size: 11px; color: #666; }
        @media print {
            .toolbar { display: none; }
            body { padding: 0; }
            .label { border-width: 2px; border-radius: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="{{ route('seller.shipments.index') }}">← Back to shipments</a>
        <button type="button" onclick="window.print()">Print waybill</button>
    </div>

    <div class="label">
        <div class="head">
            <div class="brand">
                ZEFANYA
                <small>Shipment waybill</small>
            </div>
            <div class="track">
                <div class="code">{{ $sellerOrder->tracking_number ?? '— PENDING —' }}</div>
                <div style="font-size:11px;color:#555;">Order {{ $sellerOrder->order?->reference ?? '#'.$sellerOrder->id }}</div>
            </div>
        </div>

        @php $addr = $sellerOrder->order?->shipping_address ?? []; @endphp

        <div class="grid">
            <div class="box">
                <h3>From (seller)</h3>
                <p class="name">{{ $sellerOrder->seller?->business_name ?? '—' }}</p>
                <p>{{ $sellerOrder->seller?->street ?? '' }}{{ $sellerOrder->seller?->municipality_name ? ', '.$sellerOrder->seller->municipality_name : '' }}</p>
                <p>{{ $sellerOrder->seller?->province_name ?? '' }}</p>
            </div>
            <div class="box">
                <h3>Deliver to (buyer)</h3>
                <p class="name">{{ $addr['name'] ?? $sellerOrder->order?->buyer?->fullName() ?? '—' }}</p>
                <p>{{ $addr['full'] ?? '—' }}</p>
                @isset($addr['phone'])<p>☎ {{ $addr['phone'] }}</p>@endisset
            </div>
        </div>

        <table>
            <thead>
                <tr><th>Item</th><th style="width:60px;">Qty</th><th style="width:110px;text-align:right;">Amount</th></tr>
            </thead>
            <tbody>
                @foreach ($sellerOrder->orderItems as $item)
                    <tr>
                        <td>{{ $item->product_name }}</td>
                        <td>× {{ $item->quantity }}</td>
                        <td style="text-align:right;">{{ $item->formattedLineTotal() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="grid" style="grid-template-columns: 1fr 1fr 1fr;">
            <div class="box"><h3>Parcel weight</h3><p>{{ $sellerOrder->formattedParcelWeight() }}</p></div>
            <div class="box"><h3>Subtotal</h3><p>{{ $sellerOrder->formattedSubtotal() }}</p></div>
            <div class="box"><h3>Payment</h3><p>{{ $sellerOrder->payment?->method?->label() ?? '—' }}</p></div>
        </div>

        @if (($sellerOrder->payment?->method?->value ?? null) === 'cod')
            <div class="cod">COLLECT ON DELIVERY: {{ $sellerOrder->payment?->formattedAmount() }}</div>
        @endif

        <p class="foot">Tracking: {{ $sellerOrder->tracking_number ?? 'not yet assigned' }}
            @if ($sellerOrder->handover_at) · Handed over {{ $sellerOrder->handover_at->format('M d, Y h:i A') }}@endif
        </p>
    </div>
</body>
</html>
