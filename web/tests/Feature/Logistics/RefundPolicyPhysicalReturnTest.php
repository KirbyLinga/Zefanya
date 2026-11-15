<?php

namespace Tests\Feature\Logistics;

use App\Support\RuntimeSchema;
use Tests\Support\MigratesPendingRefundPolicy;

/** The existing real QR/OTP handover journeys with the prospective policy activated. */
class RefundPolicyPhysicalReturnTest extends PhysicalReturnTest
{
    use MigratesPendingRefundPolicy;

    protected function setUp(): void
    {
        parent::setUp();
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
