<?php

namespace Database\Seeders;

use App\Enums\VoucherType;
use App\Models\Voucher;
use Illuminate\Database\Seeder;

/**
 * The curated starter vouchers.
 *
 * `value` is stored in centavos for fixed vouchers and as a whole percentage
 * for percent vouchers — never a float.
 */
class VoucherSeeder extends Seeder
{
    public function run(): void
    {
        $vouchers = [
            [
                'code' => 'ZEFANYANEW',
                'type' => VoucherType::Fixed,
                'value' => 20000,          // ₱200.00
                'min_spend' => null,
                'usage_limit' => null,
            ],
            [
                'code' => 'WELCOME10',
                'type' => VoucherType::Percent,
                'value' => 10,             // 10%
                'min_spend' => 100000,     // ₱1,000.00
                'usage_limit' => null,
            ],
        ];

        foreach ($vouchers as $voucher) {
            Voucher::updateOrCreate(
                ['code' => $voucher['code']],
                [
                    'type' => $voucher['type'],
                    'value' => $voucher['value'],
                    'min_spend' => $voucher['min_spend'],
                    'usage_limit' => $voucher['usage_limit'],
                    'is_active' => true,
                    'expires_at' => null,
                ],
            );
        }
    }
}
