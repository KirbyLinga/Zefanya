<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $port = (int) $connection->getConfig('port');
        $uuid = getenv('PHASE124_MYSQL_UUID');

        if (! app()->environment('local', 'testing') || $connection->getDriverName() !== 'mysql'
            || $connection->getConfig('host') !== '127.0.0.1' || $port <= 3306
            || ! preg_match('/\Azefanya_phase124_test(?:_[a-z0-9]+)?\z/', $database)
            || ! is_string($uuid) || $uuid === '') {
            throw new RuntimeException('The Phase 12.4 bridge requires a pinned isolated rehearsal database.');
        }

        $target = $connection->selectOne('SELECT DATABASE() AS name, @@port AS port, @@server_uuid AS uuid, @@foreign_key_checks AS fk_checks');
        if ($target->name !== $database || (int) $target->port !== $port || $target->uuid !== $uuid || (int) $target->fk_checks !== 1) {
            throw new RuntimeException('The Phase 12.4 target or its foreign key checks could not be verified.');
        }

        // Refuse a different export or a partially applied bridge before any DDL commits.
        if (! Schema::hasColumn('platform_setting_histories', 'admin_id')
            || Schema::hasColumn('platform_setting_histories', 'legacy_admin_id')
            || DB::table('platform_setting_histories as history')->join('admins', 'admins.id', '=', 'history.admin_id')->exists()
            || DB::table('platform_setting_histories')->whereNull('admin_id')->exists()
            || ! Schema::hasColumns('parcel_loss_cases', ['confirmed_by_admin_id', 'confirmed_at', 'reason'])
            || Schema::hasColumn('parcel_loss_cases', 'confirmed_by')
            || Schema::hasColumn('parcel_loss_cases', 'kind')
            || DB::table('parcel_loss_cases')->exists() || DB::table('parcel_loss_adjustments')->exists()
            || Schema::hasTable('seller_promotions') || Schema::hasTable('seller_promotion_product')) {
            throw new RuntimeException('The rehearsal schema differs from the verified export. Review the existing rows and migration state.');
        }

        $this->preserveRecordedAuditActors();
        $this->alignEmptyLossTables();
        (require dirname(__DIR__).'/2026_10_30_201000_create_seller_promotions.php')->up();
    }

    private function preserveRecordedAuditActors(): void
    {
        Schema::table('platform_setting_histories', function (Blueprint $table): void {
            $table->dropForeign(['admin_id']);
        });

        // Move the original column as a definition change, retaining every recorded value.
        DB::statement('ALTER TABLE platform_setting_histories CHANGE COLUMN admin_id legacy_admin_id BIGINT UNSIGNED NULL');

        Schema::table('platform_setting_histories', function (Blueprint $table): void {
            $table->foreignId('admin_id')->nullable();
            $table->foreign('admin_id', 'platform_setting_histories_current_admin_fk')->references('id')->on('admins')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE platform_setting_histories ADD CONSTRAINT platform_setting_histories_actor_check CHECK ((admin_id IS NULL) <> (legacy_admin_id IS NULL))');
    }

    private function alignEmptyLossTables(): void
    {
        Schema::table('parcel_loss_cases', function (Blueprint $table): void {
            $table->unsignedBigInteger('confirmed_by_admin_id')->nullable()->change();
            $table->timestamp('confirmed_at')->nullable()->change();
            $table->string('reason', 1000)->nullable()->change();
            $table->unsignedBigInteger('incident_id')->change();
            $table->string('compensation_status', 24)->change();
            $table->string('compensation_reference', 120)->nullable()->change();
            $table->date('compensation_paid_at')->nullable()->change();
            $table->string('disposition', 36)->nullable()->change();
            $table->foreignId('confirmed_by')->constrained('admins')->restrictOnDelete();
            $table->string('kind', 12);
            $table->foreign('seller_id')->references('id')->on('sellers')->restrictOnDelete();
            $table->foreign('incident_id')->references('id')->on('incidents')->restrictOnDelete();
            $table->foreign('parcel_return_id')->references('id')->on('parcel_returns')->restrictOnDelete();
            $table->foreign('recovered_hub_id')->references('id')->on('logistics_hubs')->restrictOnDelete();
        });

        Schema::table('parcel_loss_adjustments', function (Blueprint $table): void {
            $table->text('reason')->change();
            $table->foreign('admin_id')->references('id')->on('admins')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore the disposable snapshot to recover the original schema. Audit and operational records must be retained.');
    }
};
