<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This is an explicit import rehearsal, outside the regular migration path.
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $port = (int) $connection->getConfig('port');
        if (! app()->environment('local', 'testing') || $connection->getDriverName() !== 'mysql'
            || $connection->getConfig('host') !== '127.0.0.1'
            || ! preg_match('/\Azefanya_friend_test(?:_[a-z0-9]+)?\z/', $database)
            || $port === 3306) {
            throw new RuntimeException('This migration requires an explicit isolated friend-import database.');
        }
        $target = $connection->selectOne('SELECT DATABASE() AS name, @@port AS port, @@foreign_key_checks AS fk_checks');
        if ($target->name !== $database || (int) $target->port !== $port || (int) $target->fk_checks !== 1) {
            throw new RuntimeException('The import target or its foreign key checks could not be verified.');
        }

        $relationships = [
            ['cod_remittances', 'shortage_rider_id', 'riders', 'cod_remittances_shortage_rider_id_foreign'],
            ['cod_remittances', 'shortage_recovered_by_id', 'logistics_providers', 'cod_remittances_shortage_recovered_by_id_foreign'],
            ['manifests', 'transport_assigned_by_id', 'logistics_providers', 'manifests_transport_assigned_by_id_foreign'],
            ['parcel_handoffs', 'transport_assigned_by_id', 'logistics_providers', 'parcel_handoffs_transport_assigned_by_id_foreign'],
            ['transfer_courier_assignments', 'manifest_id', 'manifests', 'transfer_courier_assignments_manifest_restrict'],
        ];

        // Validate all references before any MySQL DDL can commit.
        $missing = [];
        $cascades = [];
        foreach ($relationships as [$table, $column, $parent, $name]) {
            $existing = array_filter(Schema::getForeignKeys($table), fn (array $key) => in_array($column, $key['columns'], true));
            $restricted = false;
            foreach ($existing as $key) {
                if ($key['columns'] !== [$column] || $key['foreign_schema'] !== $database
                    || $key['foreign_table'] !== $parent || $key['foreign_columns'] !== ['id']
                    || ! in_array($key['on_update'], ['restrict', 'no action'], true)) {
                    throw new RuntimeException("Unexpected reference on {$table}.{$column}.");
                }
                if ($key['on_delete'] === 'restrict') {
                    $restricted = true;
                } elseif ($key['on_delete'] === 'cascade' && $table === 'transfer_courier_assignments') {
                    $cascades[] = [$table, $key['name']];
                } else {
                    throw new RuntimeException("Unexpected deletion rule on {$table}.{$column}.");
                }
            }

            if (DB::table($table.' as child')->leftJoin($parent.' as parent', 'parent.id', '=', 'child.'.$column)
                ->whereNotNull('child.'.$column)->whereNull('parent.id')->exists()) {
                throw new RuntimeException("Unresolved references on {$table}.{$column}.");
            }
            if (! $restricted) {
                $missing[] = [$table, $column, $parent, $name];
            }
        }

        if (! Schema::hasColumn('delivery_proofs', 'recipient_acknowledgement')) {
            Schema::table('delivery_proofs', fn (Blueprint $table) => $table->json('recipient_acknowledgement')->nullable());
        }
        foreach ($missing as [$table, $column, $parent, $name]) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column, $name)->references('id')->on($parent)->restrictOnDelete());
        }
        foreach ($cascades as [$table, $name]) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($name));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Restore the isolated snapshot to roll back this rehearsal and retain all delivery and custody evidence.');
    }
};
