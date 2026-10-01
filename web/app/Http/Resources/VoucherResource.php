<?php

namespace App\Http\Resources;

use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One voucher from the catalogue the cart's voucher picker shows.
 *
 * ## `value` has two scales, and `type` is what tells them apart
 *
 * `vouchers.value` is centavos for a fixed voucher and a whole percentage for a
 * percent voucher — never a float, and never both. That is the server's own
 * storage rule (see `VoucherSeeder`), and this resource reports it faithfully
 * rather than flattening the two into fields that would have to be null half the
 * time. A client reads `type` first; the app's mapper does, and has a test for
 * each scale.
 *
 * ## What is *not* here, and why it matters more than it looks
 *
 * The app's `Voucher` also carries a `discountRate` and a `maxDiscount` cap, and
 * its pricing engine (`config/pricing.dart`) computes with them. Neither exists
 * server-side: Laravel's vouchers have no cap, no category scope and no shipping
 * rebate. A cap invented here would be a *money* number the server does not agree
 * with, so the honest thing is to send the facts (code, type, value, minimum
 * spend, expiry) and let the app decide what it can express — which it does, in
 * `remote_catalog.dart`, including refusing to offer what it cannot represent
 * correctly. See `docs/api-contract.md` OPEN QUESTIONS §0.1/§0.2 for the pricing
 * decision this defers to.
 *
 * @mixin Voucher
 */
class VoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            // 'fixed' | 'percent' — the scale of `value` below.
            'type' => $this->type->value,
            'value' => (int) $this->value,
            // Centavos here, like every other money field the app reads, so the
            // one conversion in `money.dart` applies.
            'min_spend_minor' => $this->min_spend,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
