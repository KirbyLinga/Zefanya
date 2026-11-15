<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;

trait MigratesPendingRefundPolicy
{
    use LazilyRefreshDatabase {
        refreshTestDatabase as baseRefreshTestDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        if (RefreshDatabaseState::$migrated && ! Schema::hasColumn('parcel_returns', 'refund_policy_version')) {
            RefreshDatabaseState::$migrated = false;
        }

        $this->baseRefreshTestDatabase();
    }

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing() + [
            '--path' => [
                'database/migrations',
                'database/migrations/pending/2026_11_14_120000_activate_refund_payment_policy.php',
            ],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }
}
