<?php

namespace Tests\Feature\Logistics;

use App\Support\RuntimeSchema;
use Illuminate\Support\Facades\DB;

/** The existing real QR/OTP handover journeys with the prospective policy activated. */
class RefundPolicyPhysicalReturnTest extends PhysicalReturnTest
{
    protected function setUp(): void
    {
        parent::setUp();
        // Trigger LazilyRefreshDatabase before applying the isolated activation migration.
        DB::select('select 1');
        (require database_path('migrations/pending/2026_11_14_120000_activate_refund_payment_policy.php'))->up();
        $this->clearPolicySchemaMemo();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->clearPolicySchemaMemo();
    }

    private function clearPolicySchemaMemo(): void
    {
        foreach (['columns', 'tables'] as $property) {
            (new \ReflectionProperty(RuntimeSchema::class, $property))->setValue(null, []);
        }
    }
}
