<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The delivery handover record: the code the buyer reads out, the cash the
     * rider collects, and the failed-attempt bookkeeping around both.
     *
     * ## Why these live on seller_orders
     *
     * A seller_order is already the parcel — one seller, one buyer address, one
     * rider. The handover happens once, at that parcel's door, so a separate
     * table would add a join without adding a fact. It also keeps the
     * "never trust the client" rules (attempt limit, OTP, COD amount) in the
     * same row the controller locks and updates.
     *
     * ## OTP storage
     *
     * Only a hash is stored. The buyer's app shows the code; the rider's app
     * submits it, and this server compares. A database leak must not hand out
     * working delivery codes.
     */
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            // The waybill/reference the rider scans or types at pickup. Unique
            // because two parcels with one tracking number is an operational
            // error, and the pickup check relies on it identifying one job.
            $table->string('tracking_number', 40)
                ->nullable()
                ->unique()
                ->after('delivery_area');

            // How many delivery attempts have failed. The client shows
            // "attempt N of 3"; this is the number the server trusts.
            $table->unsignedTinyInteger('delivery_attempts')
                ->default(0)
                ->after('tracking_number');

            // Why the most recent attempt failed. Kept after a retry so the
            // buyer/Logistics can read the history of what went wrong.
            $table->string('failure_reason', 80)
                ->nullable()
                ->after('delivery_attempts');

            // Hash of the 6-digit code shown to the buyer. Null means no code
            // has been issued for this parcel yet.
            $table->string('delivery_otp_hash')
                ->nullable()
                ->after('failure_reason');

            $table->unsignedTinyInteger('delivery_otp_attempts')
                ->default(0)
                ->after('delivery_otp_hash');

            $table->timestamp('delivery_otp_locked_until')
                ->nullable()
                ->after('delivery_otp_attempts');

            // When the parcel actually changed hands. Distinct from updated_at:
            // a later correction to the row must not move the handover time.
            $table->timestamp('delivered_at')
                ->nullable()
                ->after('delivery_otp_locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropUnique(['tracking_number']);
            $table->dropColumn([
                'tracking_number',
                'delivery_attempts',
                'failure_reason',
                'delivery_otp_hash',
                'delivery_otp_attempts',
                'delivery_otp_locked_until',
                'delivered_at',
            ]);
        });
    }
};
