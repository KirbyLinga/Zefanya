<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_remittances', function (Blueprint $table): void {
            // Shipping split frozen at delivery, so a later rule change never rewrites settled money.
            $table->unsignedBigInteger('kept_shipping_minor')->nullable()->after('amount_minor');
            $table->unsignedBigInteger('origin_share_minor')->nullable()->after('kept_shipping_minor');
        });

        // A payment already sent to Zefanya keeps the rule it was sent under: the
        // 40/60 split if its recorded shipping matches it, otherwise the old rule
        // (destination kept nothing, origin share unallocated).
        $sentUnderSplit = [];
        $rows = DB::table('cod_remittances')
            ->join('seller_orders', 'seller_orders.id', '=', 'cod_remittances.seller_order_id')
            ->select('cod_remittances.id', 'cod_remittances.logistics_provider_id', 'cod_remittances.logistics_remittance_id',
                'cod_remittances.zefanya_received_at', 'seller_orders.carrier_provider_id', 'seller_orders.shipping_fee_minor')
            ->orderBy('cod_remittances.id')
            ->get();

        foreach ($rows->whereNotNull('logistics_remittance_id')->groupBy('logistics_remittance_id') as $paymentId => $parcels) {
            $recorded = (int) DB::table('logistics_remittances')->where('id', $paymentId)->value('shipping_total_minor');
            $sentUnderSplit[$paymentId] = $recorded === (int) $parcels->sum(fn ($row) => $this->split($row)[0]);
        }

        foreach ($rows as $row) {
            [$kept, $origin] = $this->split($row);
            $settled = $row->logistics_remittance_id !== null || $row->zefanya_received_at !== null;
            if ($settled && ! ($sentUnderSplit[$row->logistics_remittance_id] ?? false) && $origin > 0) {
                [$kept, $origin] = [0, 0];
            }

            DB::table('cod_remittances')->where('id', $row->id)
                ->update(['kept_shipping_minor' => $kept, 'origin_share_minor' => $origin]);
        }
    }

    public function down(): void
    {
        Schema::table('cod_remittances', function (Blueprint $table): void {
            $table->dropColumn(['kept_shipping_minor', 'origin_share_minor']);
        });
    }

    /** @return array{0: int, 1: int} [kept by the delivering company, origin carrier's 40%] */
    private function split(object $row): array
    {
        $fee = (int) $row->shipping_fee_minor;
        $cross = $row->carrier_provider_id !== null && (int) $row->carrier_provider_id !== (int) $row->logistics_provider_id;
        $origin = $cross ? intdiv($fee * 40 + 50, 100) : 0;

        return [$fee - $origin, $origin];
    }
};
