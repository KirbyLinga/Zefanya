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
            if (!Schema::hasColumn('seller_orders', 'tracking_number')) {
                $table->string('tracking_number', 40)
                    ->nullable()
                    ->unique()
                    ->after('delivery_area');
            }

            if (!Schema::hasColumn('seller_orders', 'delivery_attempts')) {
                $table->unsignedTinyInteger('delivery_attempts')
                    ->default(0)
                    ->after('tracking_number');
            }

            if (!Schema::hasColumn('seller_orders', 'failure_reason')) {
                $table->string('failure_reason', 80)
                    ->nullable()
                    ->after('delivery_attempts');
            }

            if (!Schema::hasColumn('seller_orders', 'delivery_otp_hash')) {
                $table->string('delivery_otp_hash')
                    ->nullable()
                    ->after('failure_reason');
            }

            if (!Schema::hasColumn('seller_orders', 'delivery_otp_attempts')) {
                $table->unsignedTinyInteger('delivery_otp_attempts')
                    ->default(0)
                    ->after('delivery_otp_hash');
            }

            if (!Schema::hasColumn('seller_orders', 'delivery_otp_locked_until')) {
                $table->timestamp('delivery_otp_locked_until')
                    ->nullable()
                    ->after('delivery_otp_attempts');
            }

            if (!Schema::hasColumn('seller_orders', 'delivered_at')) {
                $table->timestamp('delivered_at')
                    ->nullable()
                    ->after('delivery_otp_locked_until');
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('seller_orders', 'tracking_number')) {
                $table->dropUnique(['tracking_number']);
            }
            $dropCols = array_filter([
                Schema::hasColumn('seller_orders', 'tracking_number') ? 'tracking_number' : null,
                Schema::hasColumn('seller_orders', 'delivery_attempts') ? 'delivery_attempts' : null,
                Schema::hasColumn('seller_orders', 'failure_reason') ? 'failure_reason' : null,
                Schema::hasColumn('seller_orders', 'delivery_otp_hash') ? 'delivery_otp_hash' : null,
                Schema::hasColumn('seller_orders', 'delivery_otp_attempts') ? 'delivery_otp_attempts' : null,
                Schema::hasColumn('seller_orders', 'delivery_otp_locked_until') ? 'delivery_otp_locked_until' : null,
                Schema::hasColumn('seller_orders', 'delivered_at') ? 'delivered_at' : null,
            ]);
            if (!empty($dropCols)) {
                $table->dropColumn(array_values($dropCols));
            }
        });
    }
};
