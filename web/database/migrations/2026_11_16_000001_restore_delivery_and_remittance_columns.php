<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('delivery_proofs', 'recipient_acknowledgement')) {
            Schema::table('delivery_proofs', fn (Blueprint $table) => $table->json('recipient_acknowledgement')->nullable());
        }

        $columns = Schema::getColumnListing('cod_remittances');
        Schema::table('cod_remittances', function (Blueprint $table) use ($columns): void {
            $definitions = [
                'shortage_minor' => fn () => $table->unsignedBigInteger('shortage_minor')->default(0),
                'shortage_recovered_minor' => fn () => $table->unsignedBigInteger('shortage_recovered_minor')->default(0),
                'shortage_rider_id' => fn () => $table->foreignId('shortage_rider_id')->nullable()->constrained('riders')->restrictOnDelete(),
                'shortage_reason' => fn () => $table->text('shortage_reason')->nullable(),
                'shortage_recovery_reference' => fn () => $table->string('shortage_recovery_reference')->nullable(),
                'shortage_recovery_note' => fn () => $table->text('shortage_recovery_note')->nullable(),
                'shortage_recovered_at' => fn () => $table->timestamp('shortage_recovered_at')->nullable(),
                'shortage_recovered_by_id' => fn () => $table->foreignId('shortage_recovered_by_id')->nullable()->constrained('logistics_providers')->restrictOnDelete(),
            ];

            foreach ($definitions as $column => $define) {
                if (! in_array($column, $columns, true)) {
                    $define();
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Delivery and cash-recovery evidence must be retained. Roll back the application independently.');
    }
};
