<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();

            // seller_order_id → seller_orders.id: RESTRICT — payments are
            // permanent financial records (project rule: never deleted), so a
            // seller_order with a payment must not be removable.
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();

            // Amount to collect for this shop's parcel, in unsigned integer centavos.
            $table->unsignedInteger('amount_minor');

            // COD is the only method for now; new methods extend this later.
            $table->string('method', 20)->default('cod');

            // pending | paid — App\Enums\PaymentStatus cast on the model.
            $table->string('status', 20)->default('pending');

            // Set when the COD parcel is handed over and paid (later pass).
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            // One payment per seller_order (enforces the hasOne).
            $table->unique('seller_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
