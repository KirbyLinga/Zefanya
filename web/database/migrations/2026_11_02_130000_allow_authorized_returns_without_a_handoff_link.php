<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An Admin-authorized return moves without a partnership. Every handoff that
     * names a link stays bound to that link's exact direction by the same key.
     */
    public function up(): void
    {
        $this->setLinkNullable(true);
    }

    public function down(): void
    {
        if (DB::table('parcel_handoffs')->whereNull('logistics_provider_link_id')->exists()) {
            throw new RuntimeException('Cannot require a link while Admin-authorized return handoffs exist.');
        }

        $this->setLinkNullable(false);
    }

    private function setLinkNullable(bool $nullable): void
    {
        // SQLite rebuilds the table on change() and keeps the key; it cannot drop one by name.
        $rebuildKey = DB::getDriverName() !== 'sqlite';

        if ($rebuildKey) {
            Schema::table('parcel_handoffs', function (Blueprint $table): void {
                $table->dropForeign('handoffs_link_route_fk');
            });
        }

        Schema::table('parcel_handoffs', function (Blueprint $table) use ($nullable): void {
            $table->unsignedBigInteger('logistics_provider_link_id')->nullable($nullable)->change();
        });

        if ($rebuildKey) {
            Schema::table('parcel_handoffs', function (Blueprint $table): void {
                $table->foreign(['logistics_provider_link_id', 'origin_provider_id', 'destination_provider_id'], 'handoffs_link_route_fk')
                    ->references(['id', 'origin_provider_id', 'destination_provider_id'])->on('logistics_provider_links')->restrictOnDelete();
            });
        }
    }
};
