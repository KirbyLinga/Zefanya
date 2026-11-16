<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\ConsumerClaim;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Notifications\ConsumerClaimChanged;
use App\Services\Buyer\ConsumerClaimService;
use App\Services\Logistics\ParcelReturnService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsumerClaimWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $seller;

    private SellerOrder $shipment;

    private OrderItem $item;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Notification::fake();
        $this->buyer = Buyer::factory()->create();
        $migration = require database_path('migrations/pending/2026_11_16_210000_create_consumer_claims_tables.php');
        $migration->up();
        config(['consumer-claims.enabled' => true]);
        $this->directory = storage_path('framework/testing/consumer-claims-'.Str::uuid());
        Storage::set('local', Storage::build(['driver' => 'local', 'root' => $this->directory]));
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->shipment = SellerOrder::factory()->create(['order_id' => Order::factory()->create(['buyer_id' => $this->buyer->id])->id,
            'seller_id' => $this->seller->id, 'status' => OrderStatus::Delivered]);
        $this->item = OrderItem::factory()->create(['seller_order_id' => $this->shipment->id]);
        StatusHistory::record($this->shipment, OrderStatus::OutForDelivery, OrderStatus::Delivered)->forceFill(['created_at' => now()->subDays(30)])->save();
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && str_starts_with($this->directory, storage_path('framework/testing/consumer-claims-'))) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function data(): array
    {
        return ['submission_key' => (string) Str::uuid(), 'order_item_id' => $this->item->id,
            'basis' => 'consumer_remedy', 'category' => 'defect', 'description' => 'A documented product defect needs manual review.',
            'warranty_details' => 'Manufacturer warranty document will be supplied.'];
    }

    private function claim(): ConsumerClaim
    {
        return app(ConsumerClaimService::class)->submit($this->buyer, $this->shipment, $this->data());
    }

    private function decision(ConsumerClaim $claim, string $action, string $message = 'Documented decision and relevant next steps.'): array
    {
        return ['submission_key' => (string) Str::uuid(), 'revision' => $claim->revision, 'action' => $action, 'message' => $message];
    }

    public function test_claims_work_after_seven_days_without_altering_returns_or_financials(): void
    {
        StatusHistory::record($this->shipment, OrderStatus::Delivered, OrderStatus::Completed, actor: OrderActor::Buyer, actorId: $this->buyer->id);
        $this->assertFalse(app(ParcelReturnService::class)->canBuyerRequest($this->shipment));
        $before = $this->shipment->fresh()->getAttributes();
        $balances = collect(['payments', 'cod_remittances', 'seller_settlements', 'parcel_returns', 'inventory_transactions'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
        $response = $this->actingAs($this->buyer, 'buyer')->post(route('buyer.consumer-claims.store', $this->shipment), $this->data());
        $response->assertRedirect();
        $claim = ConsumerClaim::firstOrFail();
        $this->assertSame('submitted', $claim->status);
        $this->assertSame($before, $this->shipment->fresh()->getAttributes());
        foreach ($balances as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.consumer-claims.show', $claim->id))->assertOk()->assertSee('Manual review');
        $this->actingAs($this->seller, 'seller')->get(route('seller.consumer-claims.show', $claim->id))->assertOk()->assertDontSee($this->buyer->email);
        $admin = Admin::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'admin')->get(route('admin.consumer-claims.show', $claim->id))->assertOk()->assertSee('Recorded delivery context');
    }

    public function test_buyer_ownership_product_scoping_and_duplicate_submissions(): void
    {
        $other = Buyer::factory()->create();
        $this->actingAs($other, 'buyer')->postJson(route('buyer.consumer-claims.store', $this->shipment), $this->data())->assertForbidden();
        $data = $this->data();
        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.consumer-claims.store', $this->shipment), $data)->assertRedirect();
        $this->post(route('buyer.consumer-claims.store', $this->shipment), $data)->assertRedirect();
        $this->post(route('buyer.consumer-claims.store', $this->shipment), $this->data())->assertRedirect();
        $this->assertSame(1, ConsumerClaim::count());
        $this->assertSame(1, ConsumerClaim::first()->updates()->count());
        $claim = ConsumerClaim::first();
        $this->actingAs($other, 'buyer')->get(route('buyer.consumer-claims.show', $claim->id))->assertForbidden();
        $unrelatedSeller = Seller::factory()->create(['status' => 'approved']);
        $this->actingAs($unrelatedSeller, 'seller')->get(route('seller.consumer-claims.show', $claim->id))->assertForbidden();
        $wrongItem = OrderItem::factory()->create();
        $this->actingAs($this->buyer, 'buyer')->postJson(route('buyer.consumer-claims.store', $this->shipment), array_replace($this->data(), ['order_item_id' => $wrongItem->id]))->assertNotFound();
    }

    public function test_admin_permissions_justification_notifications_and_seller_response(): void
    {
        $admin = Admin::factory()->create(['role' => 'admin']);
        $support = Admin::factory()->create(['role' => 'support']);
        $claim = $this->claim();
        Notification::assertSentTo($admin, ConsumerClaimChanged::class);
        Notification::assertNotSentTo($support, ConsumerClaimChanged::class);
        Notification::assertNotSentTo($this->seller, ConsumerClaimChanged::class);
        $this->actingAs($support, 'admin')->postJson(route('admin.consumer-claims.update', $claim->id), $this->decision($claim, 'approve'))->assertForbidden();
        $this->actingAs($admin, 'admin')->postJson(route('admin.consumer-claims.update', $claim->id), $this->decision($claim, 'reject', '   '))->assertUnprocessable();
        $request = $this->decision($claim, 'request_seller_response');
        $this->post(route('admin.consumer-claims.update', $claim->id), $request)->assertRedirect();
        $this->post(route('admin.consumer-claims.update', $claim->id), $request)->assertRedirect();
        $claim->refresh();
        $this->assertSame('awaiting_seller', $claim->status);
        $this->assertSame(2, $claim->updates()->count());
        Notification::assertSentTo($this->seller, ConsumerClaimChanged::class);
        Notification::assertSentTo($this->buyer, ConsumerClaimChanged::class);
        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.consumer-claims.update', $claim->id), $this->decision($claim, 'response'))->assertRedirect();
        $claim->refresh();
        $this->assertSame('awaiting_seller', $claim->status);
        $this->actingAs($this->seller, 'seller')->post(route('seller.consumer-claims.update', $claim->id), $this->decision($claim, 'response'))->assertRedirect();
        $claim->refresh();
        $this->assertSame('under_review', $claim->status);
        $this->actingAs($admin, 'admin')->post(route('admin.consumer-claims.update', $claim->id), $this->decision($claim, 'approve'))->assertRedirect();
        $claim->refresh();
        $this->assertSame('approved', $claim->status);
        $this->post(route('admin.consumer-claims.update', $claim->id), $this->decision($claim, 'close'))->assertRedirect();
        $this->assertSame(3, OperationsAuditLog::where('entity_type', 'consumer_claim')->count());
    }

    public function test_evidence_download_is_private_and_invalid_files_are_rejected(): void
    {
        $data = $this->data();
        $data['evidence'] = [UploadedFile::fake()->image('proof.jpg')];
        $claim = app(ConsumerClaimService::class)->submit($this->buyer, $this->shipment, $data);
        $update = $claim->updates()->first();
        $url = route('buyer.consumer-claims.evidence', [$claim->id, $update->id, 0]);
        $this->actingAs(Buyer::factory()->create(), 'buyer')->get($url)->assertForbidden();
        $this->actingAs($this->buyer, 'buyer')->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $temporary = tempnam(sys_get_temp_dir(), 'claim-invalid-');
        file_put_contents($temporary, 'not image data');
        try {
            $invalid = $this->data();
            $invalid['evidence'] = [new UploadedFile($temporary, 'fake.jpg', null, null, true)];
            $this->postJson(route('buyer.consumer-claims.store', $this->shipment), $invalid)->assertUnprocessable();
        } finally {
            unlink($temporary);
        }
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_disabled_or_absent_schema_hides_routes_and_stale_revisions_fail(): void
    {
        $claim = $this->claim();
        config(['consumer-claims.enabled' => false]);
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.consumer-claims.index'))->assertNotFound();
        $this->get(route('buyer.consumer-claims.show', $claim->id))->assertNotFound();
        config(['consumer-claims.enabled' => true]);
        $admin = Admin::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'admin')->post(route('admin.consumer-claims.update', $claim->id), $this->decision($claim, 'request_buyer_information'))->assertRedirect();
        $this->postJson(route('admin.consumer-claims.update', $claim->id), $this->decision($claim, 'reject'))->assertUnprocessable();
        Schema::drop('consumer_claim_updates');
        Schema::drop('consumer_claims');
        $this->get(route('admin.consumer-claims.show', 100))->assertNotFound();
    }

    public function test_role_pages_render_and_notifications_link_only_to_owned_claims(): void
    {
        $claim = $this->claim();
        $admin = Admin::factory()->create(['role' => 'admin']);
        $pages = [];
        $pages['claim-create'] = $this->actingAs($this->buyer, 'buyer')->get(route('buyer.consumer-claims.create', $this->shipment))->assertOk()->getContent();
        $pages['claim-buyer'] = $this->get(route('buyer.consumer-claims.show', $claim->id))->assertOk()->getContent();
        $pages['claim-seller'] = $this->actingAs($this->seller, 'seller')->get(route('seller.consumer-claims.show', $claim->id))->assertOk()->getContent();
        $pages['claim-admin'] = $this->actingAs($admin, 'admin')->get(route('admin.consumer-claims.show', $claim->id))->assertOk()->getContent();
        $pages['claim-queue'] = $this->get(route('admin.consumer-claims.index'))->assertOk()->getContent();
        $notification = new ConsumerClaimChanged($claim, 'buyer', 'request_buyer_information');
        $this->buyer->notifications()->create(['id' => (string) Str::uuid(), 'type' => ConsumerClaimChanged::class, 'data' => $notification->toArray($this->buyer)]);
        $this->actingAs($this->buyer, 'buyer')->getJson(route('buyer.notifications.index'))->assertOk()
            ->assertJsonPath('notifications.0.url', route('buyer.consumer-claims.show', $claim->id));
        if ($directory = getenv('CONSUMER_CLAIM_PREVIEW_DIR')) {
            $this->assertStringStartsWith('C:/ProjectEcommerce/.recovery/', str_replace('\\', '/', $directory));
            File::ensureDirectoryExists($directory);
            foreach ($pages as $name => $html) {
                file_put_contents($directory.'/'.$name.'.html', $html);
            }
        }
    }

    public function test_rejection_is_documented_and_claim_decisions_have_no_financial_or_inventory_effect(): void
    {
        $claim = $this->claim();
        $admin = Admin::factory()->create(['role' => 'admin']);
        $tables = ['payments', 'cod_remittances', 'seller_settlements', 'parcel_returns', 'inventory_transactions'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        $stocks = DB::table('product_variants')->pluck('stock_quantity', 'id')->all();
        $data = $this->decision($claim, 'reject', 'Evidence does not establish this claim; documented manual review.');
        $this->actingAs($admin, 'admin')->post(route('admin.consumer-claims.update', $claim->id), $data)->assertRedirect();
        $claim->refresh();
        $this->assertSame('rejected', $claim->status);
        $this->assertSame($data['message'], OperationsAuditLog::where('entity_type', 'consumer_claim')->first()->reason);
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }
        $this->assertSame($stocks, DB::table('product_variants')->pluck('stock_quantity', 'id')->all());
        $this->expectException(\LogicException::class);
        $claim->updates()->update(['message' => 'History tampering']);
    }
}
