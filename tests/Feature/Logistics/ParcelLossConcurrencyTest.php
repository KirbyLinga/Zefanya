<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelLossCase;
use App\Models\Seller\Seller;
use App\Models\Shared\Incidents;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Notifications\Incident\ParcelLostConfirmed;
use App\Services\Logistics\ParcelIncidentService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real concurrency for lost-parcel resolution: several PHP processes hit the
 * same MySQL rows at the same instant. SQLite has no row locks, so this needs
 * MySQL, and because it rebuilds the schema it refuses to run unless the
 * target is clearly a disposable database:
 *
 *   DB_CONNECTION=mysql DB_DATABASE=<name containing "iso" or "test"> \
 *   LOSS_CONCURRENCY_DB=1 php artisan test tests/Feature/Logistics/ParcelLossConcurrencyTest.php
 */
class ParcelLossConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        $name = (string) env('DB_DATABASE', '');
        if (env('DB_CONNECTION') !== 'mysql' || getenv('LOSS_CONCURRENCY_DB') !== '1'
            || ! preg_match('/(iso|test)/i', $name) || strcasecmp($name, 'Zefanya') === 0) {
            $this->markTestSkipped('Needs a disposable MySQL database (see the class comment). Nothing was changed.');
        }
        parent::setUp();
        // A clean schema for each test. No rollback afterwards: nothing here needs the migrations undone.
        $this->artisan('migrate:fresh', ['--force' => true]);
    }

    public function test_simultaneous_confirmations_of_one_parcel_create_one_loss_one_obligation_and_one_notice_each(): void
    {
        [$admin, $parcel, $incident] = $this->lostParcelFixture();

        $results = $this->race(array_map(fn () => ['op' => 'confirm', 'admin' => $admin->id, 'incident' => $incident->id], range(1, 5)));

        // Every request is answered safely: the first closes it, the others find it already closed.
        $this->assertSame([true], array_values(array_unique(array_column($results, 'ok'))), json_encode($results));
        $this->assertSame(1, ParcelLossCase::query()->count());
        $this->assertSame(1, $parcel->statusHistories()->where('to_status', 'LOST')->count());
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'parcel_lost_confirmed')->count());
        $this->assertSame(OrderStatus::Lost, $parcel->fresh()->status);
        $this->assertSame('cancelled', $parcel->fresh()->payment->status->value);
        // Each person is told exactly once.
        $sent = DB::table('notifications')->where('type', ParcelLostConfirmed::class)->get()->groupBy(fn ($n) => $n->notifiable_type.'#'.$n->notifiable_id);
        $this->assertNotEmpty($sent);
        $this->assertSame([1], $sent->map->count()->unique()->values()->all());
        $this->assertSame(50000, ParcelLossCase::query()->sole()->merchandise_minor);
    }

    public function test_two_admins_recording_different_payments_at_once_pays_the_seller_exactly_once(): void
    {
        [$admin, $parcel, $incident] = $this->lostParcelFixture();
        app(ParcelIncidentService::class)->resolve($incident, $admin, ['event' => 'CLOSE_LOST', 'reason' => 'Confirmed lost']);
        $case = ParcelLossCase::query()->sole();
        $second = Admin::factory()->create(['role' => 'admin']);

        $results = $this->race([
            ['op' => 'pay', 'admin' => $admin->id, 'case' => $case->id, 'reference' => 'REF-A', 'paid_on' => now()->toDateString()],
            ['op' => 'pay', 'admin' => $second->id, 'case' => $case->id, 'reference' => 'REF-B', 'paid_on' => now()->toDateString()],
            ['op' => 'pay', 'admin' => $admin->id, 'case' => $case->id, 'reference' => 'REF-A', 'paid_on' => now()->toDateString()],
        ]);

        $case->refresh();
        $this->assertTrue($case->isPaid());
        $this->assertContains($case->compensation_reference, ['REF-A', 'REF-B']);
        // One payment is recorded and audited; a competing different reference is refused, never double-recorded.
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'lost_compensation_paid')->count());
        $refused = collect($results)->where('ok', false);
        $this->assertTrue($refused->every(fn ($r) => str_contains($r['message'], 'already recorded as paid')), json_encode($results));
        $this->assertSame(1, ParcelLossCase::query()->count());
    }

    public function test_overlapping_bulk_confirmations_never_double_close_or_double_owe(): void
    {
        [$admin, $a, $incidentA] = $this->lostParcelFixture();
        [, $b, $incidentB] = $this->lostParcelFixture($admin);
        $other = Admin::factory()->create(['role' => 'admin']);
        $ids = [$incidentA->id, $incidentB->id];

        $results = $this->race([
            ['op' => 'confirm_many', 'admin' => $admin->id, 'incidents' => $ids],
            ['op' => 'confirm_many', 'admin' => $other->id, 'incidents' => array_reverse($ids)],
            ['op' => 'confirm', 'admin' => $admin->id, 'incident' => $incidentB->id],
        ]);

        $this->assertSame([true], array_values(array_unique(array_column($results, 'ok'))), json_encode($results));
        $this->assertSame(2, ParcelLossCase::query()->count());
        $this->assertSame(1, ParcelLossCase::query()->where('seller_order_id', $a->id)->count());
        $this->assertSame(1, ParcelLossCase::query()->where('seller_order_id', $b->id)->count());
        $this->assertSame([OrderStatus::Lost, OrderStatus::Lost], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertSame(2, OperationsAuditLog::query()->where('action', 'parcel_lost_confirmed')->count());
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** A collected, in-transit parcel held by an approved company, with an open "missing" report. @return array{0: Admin, 1: SellerOrder, 2: Incidents} */
    private function lostParcelFixture(?Admin $admin = null): array
    {
        $admin ??= Admin::factory()->create(['role' => 'admin']);
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $seller = Seller::factory()->create();
        $order = Order::factory()->create();
        $parcel = SellerOrder::factory()->status(OrderStatus::InTransit)->create(['seller_id' => $seller->id, 'order_id' => $order->id, 'subtotal_minor' => 50000]);
        $parcel->forceFill(['custody_provider_id' => $provider->id, 'carrier_provider_id' => $provider->id, 'handover_at' => now(), 'shipping_fee_minor' => 5000])->save();
        Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 55000]);

        $incident = (new Incidents)->forceFill([
            'incident_seller_order_id' => $parcel->id, 'reporter_id' => $provider->id, 'reporter_type' => 'logistics_provider',
            'type' => 'missing', 'reason' => 'Never arrived', 'happened_at' => now(), 'status' => 'open',
        ]);
        $incident->save();

        return [$admin, $parcel->fresh(), $incident];
    }

    /**
     * Start one PHP process per task, hold them all at a barrier, release them
     * together, and return each one's result.
     *
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array<string, mixed>>
     */
    private function race(array $tasks): array
    {
        $directory = sys_get_temp_dir().'/loss-race-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $barrier = $directory.'/go';
        $processes = [];

        foreach ($tasks as $i => $task) {
            $task += ['barrier' => $barrier, 'ready' => $directory.'/'.$i.'.ready', 'result' => $directory.'/'.$i.'.result.json'];
            file_put_contents($directory.'/'.$i.'.task.json', json_encode($task));
            $process = new Process([PHP_BINARY, base_path('tests/Support/loss_worker.php'), $directory.'/'.$i.'.task.json'], base_path());
            $process->setTimeout(60);
            $process->start();
            $processes[$i] = $process;
        }

        // Release only once every worker has booted, so they collide on the rows.
        $deadline = microtime(true) + 40;
        while (count(glob($directory.'/*.ready') ?: []) < count($tasks)) {
            $this->assertLessThan($deadline, microtime(true), 'A worker never became ready.');
            usleep(20000);
        }
        file_put_contents($barrier, '1');

        $results = [];
        foreach ($processes as $i => $process) {
            $process->wait();
            $file = $directory.'/'.$i.'.result.json';
            $results[] = is_file($file) ? json_decode((string) file_get_contents($file), true) : ['ok' => false, 'class' => 'NoResult', 'message' => $process->getErrorOutput()];
        }

        return $results;
    }
}
