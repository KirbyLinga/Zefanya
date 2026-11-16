<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin\Admin;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsReturnCharge;
use App\Models\Logistics\ParcelLossCase;
use App\Models\Logistics\ParcelReturn;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\FinancialReference;
use App\Services\Logistics\LogisticsRemittanceService;
use App\Services\Logistics\ParcelLossService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Logistics\ReturnChargeService;
use App\Services\Seller\SellerPayoutRequestService;
use App\Services\Seller\SellerSettlementService;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Recording money out, and authorizing or confirming a refund, needs a Super
 * Admin. An ordinary Admin is refused before anything is read or written.
 */
class FinancialApprovalTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{Closure(Admin): mixed}> */
    public static function moneyActions(): array
    {
        return [
            'seller payout request' => [fn (Admin $a) => app(SellerPayoutRequestService::class)->pay(new SellerPayoutRequest, $a, 'REF')],
            'direct settlement payout' => [fn (Admin $a) => app(SellerSettlementService::class)->confirmPayout(new SellerSettlement, $a, 100, 'REF')],
            'origin share payout' => [fn (Admin $a) => app(LogisticsRemittanceService::class)->payOriginShare($a, new LogisticsProvider, 1, 100, 'REF', '2026-11-01')],
            'destination share payout' => [fn (Admin $a) => app(LogisticsRemittanceService::class)->payDestinationShare($a, new CodRemittance, 'REF', '2026-11-01')],
            'lost parcel compensation' => [fn (Admin $a) => app(ParcelLossService::class)->recordCompensationPayment(new ParcelLossCase, $a, 'REF', '2026-11-01')],
            'return charge settlement' => [fn (Admin $a) => app(ReturnChargeService::class)->settle(new LogisticsReturnCharge, $a, 'REF')],
            'refund authorization' => [fn (Admin $a) => app(ParcelReturnService::class)->authorizeRefund(new ParcelReturn, $a, 100, 'seller', 'Documented reason')],
            'refund recording' => [fn (Admin $a) => app(ParcelReturnService::class)->recordRefund(new ParcelReturn, $a, 'REF', 100, 'Buyer', '2026-11-01')],
        ];
    }

    #[DataProvider('moneyActions')]
    public function test_an_admin_without_super_admin_role_cannot_record_money_out(Closure $action): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Admin->value]);

        try {
            $action($admin);
            $this->fail('An Admin recorded a payment without Super Admin approval.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Only a Super Admin can approve and record this payment.', $e->getMessage());
        }

        $this->assertSame(0, FinancialReference::query()->count());
    }

    #[DataProvider('moneyActions')]
    public function test_a_super_admin_passes_the_approval_gate(Closure $action): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        try {
            $action($admin);
        } catch (HttpException $e) {
            $this->assertNotSame('Only a Super Admin can approve and record this payment.', $e->getMessage());
        } catch (\Throwable) {
            // The empty record fails later business checks; only the approval gate is under test.
        }

        $this->assertTrue(true);
    }
}
