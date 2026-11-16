<?php

namespace Tests\MySql;

use App\Models\Logistics\LogisticsProvider;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BarangayCoverageConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        return Preflight::application();
    }

    public function test_native_barangay_claim_has_exactly_one_winner(): void
    {
        Preflight::prepareSchema();
        Preflight::clean();
        $provider = LogisticsProvider::factory()->create();
        $task = ['action' => 'coverage_claim', 'area' => [
            'logistics_provider_id' => $provider->id,
            'name' => 'Concurrent claim',
            'municipality' => 'San Fernando',
            'barangay_code' => '0305401001',
            'is_active' => true,
        ]];

        $results = Race::run($task + ['hold' => 'coverage_claim'], $task + ['watch' => 'coverage_claim']);

        $this->assertSame('claimed', $results['a']['status']);
        $this->assertSame('duplicate', $results['b']['status']);
        $this->assertDatabaseCount('areas', 1);
        $this->assertSame('0305401001', DB::table('areas')->sole()->active_barangay_code);
    }
}
