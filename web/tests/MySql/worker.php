<?php

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/Preflight.php';

use App\Enums\PromotionKind;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerPromotion;
use App\Models\Seller\SellerSettlement;
use App\Models\Seller\StoreSale;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\BuyerShipmentConfirmation;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Logistics\ProviderAllocationService;
use App\Services\Logistics\StationInventoryService;
use App\Services\Rider\RiderOperationsService;
use App\Services\Seller\ProductPublishingService;
use App\Services\Seller\SellerPromotionService;
use App\Services\Seller\SellerVacationService;
use App\Services\Seller\StockService;
use App\Services\Seller\StoreSaleService;
use App\Services\Seller\VariantSyncService;
use App\Services\Shared\CommissionFinalizationService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\MySql\CheckoutShippingFixture;
use Tests\MySql\Preflight;
use Tests\TestCase;

final class T05Worker extends TestCase
{
    private array $task;

    private bool $held = false;

    private bool $watched = false;

    private array $sqlErrors = [];

    private int $cartReads = 0;

    private ?string $firstOrderLock = null;

    private int $injectedFailures = 0;

    public function createApplication()
    {
        return Preflight::application();
    }

    private function signal(string $event, string $value = ''): void
    {
        file_put_contents($this->task['directory'].'/'.$this->task['name'].'.'.$event, $value, LOCK_EX);
    }

    private function hold(): void
    {
        if ($this->held) {
            return;
        }
        $this->held = true;
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Hold point must be inside a real transaction.');
        }
        $this->signal('held', json_encode(['time' => microtime(true), 'transaction_level' => DB::transactionLevel()]));
        $release = $this->task['directory'].'/'.$this->task['name'].'.release';
        $deadline = microtime(true) + 18;
        while (! is_file($release)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Worker barrier deadline.');
            }
            usleep(10000);
        }
    }

    private function queryMatches(string $sql, string $point): bool
    {
        $sql = strtolower($sql);

        return match ($point) {
            'cod_collection_write' => str_starts_with($sql, 'update `payments`'),
            'return_event' => str_starts_with($sql, 'insert into `parcel_return_events`'),
            'parcel' => str_starts_with($sql, 'select') && str_contains($sql, 'from `seller_orders`') && str_contains($sql, 'for update'),
            'order' => str_starts_with($sql, 'select') && str_contains($sql, 'from `orders`') && str_contains($sql, 'for update'),
            'provider' => str_starts_with($sql, 'select') && str_contains($sql, 'from `logistics_providers`') && str_contains($sql, 'for update'),
            'product' => str_starts_with($sql, 'select') && str_contains($sql, 'from `products`') && str_contains($sql, 'for update'),
            'seller' => str_starts_with($sql, 'select') && str_contains($sql, 'from `sellers`') && str_contains($sql, 'for update'),
            'promotion_insert' => str_starts_with($sql, 'insert into `seller_promotions`'),
            'promotion_delete' => str_starts_with($sql, 'delete from `seller_promotions`'),
            'voucher' => str_starts_with($sql, 'select') && str_contains($sql, 'from `vouchers`') && str_contains($sql, 'for update'),
            'buyer' => str_starts_with($sql, 'select') && str_contains($sql, 'from `buyers`') && str_contains($sql, 'for update'),
            'seller_settlement' => str_starts_with($sql, 'select') && str_contains($sql, 'from `seller_settlements`') && str_contains($sql, 'for update'),
            'seller_debt' => str_starts_with($sql, 'select') && str_contains($sql, 'from `seller_debts`') && str_contains($sql, 'for update'),
            'seller_debt_write' => str_starts_with($sql, 'update `seller_debts`'),
            'seller_debt_insert' => str_starts_with($sql, 'insert into `seller_debts`'),
            'courier' => str_starts_with($sql, 'select') && str_contains($sql, 'from `couriers`') && str_contains($sql, 'for update'),
            'logistics_remittance' => str_starts_with($sql, 'select') && str_contains($sql, 'from `logistics_remittances`') && str_contains($sql, 'for update'),
            'token' => str_starts_with($sql, 'update `checkout_tokens`'),
            'cartread' => str_starts_with($sql, 'select') && str_contains($sql, 'from `cart_items`') && str_contains($sql, 'for update'),
            'cartwrite' => str_starts_with($sql, 'update `cart_items`'),
            'variant' => str_starts_with($sql, 'select') && str_contains($sql, 'from `product_variants`') && str_contains($sql, 'for update'),
            default => false,
        };
    }

    public function execute(array $task): array
    {
        $this->task = $task;
        parent::setUp();
        $connection = (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->signal('connection', (string) $connection);
        $this->app->make(ExceptionHandler::class)->reportable(function (QueryException $exception): void {
            $this->sqlErrors[] = (int) ($exception->errorInfo[1] ?? 0);
        });
        DB::connection()->beforeExecuting(function ($sql, $bindings): void {
            if ($this->queryMatches($sql, 'cartread')) {
                $this->cartReads++;
            }
            if ($this->firstOrderLock === null) {
                if ($this->queryMatches($sql, 'order')) {
                    $this->firstOrderLock = 'order';
                } elseif ($this->queryMatches($sql, 'parcel')) {
                    $this->firstOrderLock = 'parcel';
                }
            }
            if (isset($this->task['inject_error']) && str_starts_with(strtolower($sql), 'insert into `cart_items`')) {
                $this->injectedFailures++;
                $code = $this->task['inject_error'];
                $previous = new PDOException($code === 1213 ? 'Deadlock found when trying to get lock' : 'Lock wait timeout exceeded', 40001);
                $previous->errorInfo = ['40001', $code, 'Controlled exhausted-retry test'];
                throw new QueryException('mysql', $sql, $bindings, $previous);
            }
            if (! $this->watched && $this->queryMatches($sql, $this->task['watch'] ?? '')) {
                $this->watched = true;
                $this->signal('attempting', json_encode(['time' => microtime(true), 'transaction_level' => DB::transactionLevel()]));
            }
        });
        DB::listen(function ($query): void {
            if ($this->queryMatches($query->sql, $this->task['hold'] ?? '')) {
                $this->hold();
            }
            if ($this->watched && $this->queryMatches($query->sql, $this->task['watch'] ?? '')) {
                $this->signal('passed');
            }
        });
        $start = microtime(true);
        if (in_array($task['action'], ['phase6_reroute', 'phase6_receive'], true)) {
            $provider = LogisticsProvider::query()->findOrFail($task['provider']);
            try {
                if ($task['action'] === 'phase6_reroute') {
                    app(ParcelIncidentService::class)->resolveForProvider(
                        Incidents::query()->findOrFail($task['incident']), $provider,
                        ['event' => 'AUTHORIZE_REROUTE', 'reason' => 'Verified alternate connection approved by logistics.']);
                } else {
                    $manifest = Manifest::query()->findOrFail($task['manifest']);
                    $item = ManifestItem::query()->findOrFail($task['item']);
                    $service = app(ManifestService::class);
                    $scan = $service->verifyScan($manifest, $provider, $item->sellerOrder->tracking_number, 'receive');
                    $service->receive($manifest, $provider, $item, $scan['verification_token']);
                }
                $result = ['status' => 'completed', 'success' => true];
            } catch (ValidationException) {
                $result = ['status' => 'rejected', 'success' => false];
            }
        } elseif (in_array($task['action'], ['refund_policy_authorize', 'refund_policy_confirm'], true)) {
            config(['filesystems.disks.local.root' => storage_path('framework/testing/disks/refund-policy-mysql-'.$task['return'])]);
            Notification::fake();
            $return = ParcelReturn::query()->findOrFail($task['return']);
            $route = $task['action'] === 'refund_policy_authorize' ? 'admin.returns.refund.authorize' : 'admin.returns.refund.confirm';
            $response = $this->actingAs(Admin::query()->findOrFail($task['admin']), 'admin')->postJson(route($route, $return), $task['payload']);
            $result = ['status' => $response->getStatusCode(), 'success' => $response->getStatusCode() === 302,
                'validation_fields' => $response->isClientError() ? array_keys($response->json('errors') ?? []) : []];
        } elseif ($task['action'] === 'rider_cod_delivery') {
            $parcel = SellerOrder::query()->findOrFail($task['parcel']);
            config(['filesystems.disks.local.root' => storage_path('framework/testing/disks/audit16-mysql-'.$task['parcel'])]);
            Notification::fake();
            try {
                app(RiderOperationsService::class)->completeDelivery(
                    Rider::query()->findOrFail($task['rider']), $parcel,
                    ['photo' => UploadedFile::fake()->image('audit16-proof.jpg'),
                        'delivery_otp' => $task['otp'], 'cod_amount_minor' => $task['amount']]);
                $result = ['success' => true];
            } catch (ValidationException $exception) {
                $result = ['success' => false, 'validation_fields' => array_keys($exception->errors())];
            }
        } elseif (str_starts_with($task['action'], 'cod_')) {
            $adminAction = in_array($task['action'], ['cod_platform_confirm', 'cod_platform_reject'], true);
            $actor = $adminAction ? Admin::query()->findOrFail($task['admin']) : LogisticsProvider::query()->findOrFail($task['provider']);
            $this->actingAs($actor, $adminAction ? 'admin' : 'logistics');
            $url = match ($task['action']) {
                'cod_handover' => route('logistics.cod-remittances.confirm', $task['remittance']),
                'cod_recovery' => route('logistics.cod-remittances.shortage-recovery', $task['remittance']),
                'cod_submit' => route('logistics.earnings.remit'),
                'cod_platform_confirm' => route('admin.logistics-remittances.confirm', $task['batch']),
                'cod_platform_reject' => route('admin.logistics-remittances.reject', $task['batch']),
            };
            $response = $this->postJson($url, $task['data']);
            $result = ['status' => $response->getStatusCode(),
                'validation_fields' => $response->isClientError() ? array_keys($response->json('errors') ?? []) : []];
        } elseif (str_starts_with($task['action'], 'financial_')) {
            $order = SellerOrder::query()->findOrFail($task['parcel']);
            if ($task['action'] === 'financial_finalize') {
                app(CommissionFinalizationService::class)->finalize($order);
            } elseif ($task['action'] === 'financial_complete') {
                app(BuyerShipmentConfirmation::class)->confirm($order->order, $order->order->buyer, $order->id);
            } else {
                // The production delivery caller holds this same parcel lock.
                DB::transaction(function () use ($order): void {
                    $locked = SellerOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                    app(ProviderAllocationService::class)->prepare($locked);
                });
            }
            $result = ['status' => 200, 'success' => true];
        } elseif ($task['action'] === 'vacation') {
            app(SellerVacationService::class)->set(Seller::query()->findOrFail($task['seller']), (bool) $task['enabled']);
            $result = ['status' => 200, 'success' => true];
        } elseif ($task['action'] === 'payout_request') {
            $response = $this->actingAs(Seller::query()->findOrFail($task['seller']), 'seller')
                ->postJson(route('seller.payout-requests.store'), ['amount' => $task['amount'], 'idempotency_key' => $task['key'], 'destination' => 'GCash 09171234567']);
            $result = ['status' => $response->getStatusCode(), 'success' => in_array($response->getStatusCode(), [200, 201], true),
                'payout_id' => $response->json('id'), 'validation_fields' => array_keys($response->json('errors') ?? [])];
        } elseif ($task['action'] === 'settlement') {
            $response = $this->actingAs(Admin::query()->findOrFail($task['admin']), 'admin')
                ->postJson(route('admin.settlements.confirm', SellerSettlement::query()->findOrFail($task['settlement'])),
                    ['amount_minor' => $task['amount_minor'], 'reference' => $task['reference'], 'reason' => $task['reason']]);
            $result = ['status' => $response->getStatusCode(), 'success' => $response->getStatusCode() === 200,
                'validation_fields' => array_keys($response->json('errors') ?? [])];
        } elseif ($task['action'] === 'payout_pay') {
            $response = $this->actingAs(Admin::query()->findOrFail($task['admin']), 'admin')
                ->postJson(route('admin.seller-payouts.pay', $task['payout']), ['reference' => $task['reference']]);
            $result = ['status' => $response->getStatusCode(), 'message' => session('success'),
                'validation_fields' => $response->isClientError() ? array_keys($response->json('errors') ?? []) : []];
        } elseif (in_array($task['action'], ['payout_refund', 'payout_refund_void'], true)) {
            $void = $task['action'] === 'payout_refund_void';
            $response = $this->actingAs(Admin::query()->findOrFail($task['admin']), 'admin')
                ->postJson(route($void ? 'admin.returns.refund.void' : 'admin.returns.refund', $task['return']), $void ? [
                    'void_reason' => 'The original transfer bounced back.', 'refund_revision' => $task['refund_revision'],
                ] : [
                    'reference' => $task['reference'] ?? 'MYSQL-REFUND', 'amount' => $task['amount'], 'recipient' => $task['recipient'] ?? 'Buyer',
                    'paid_on' => today()->toDateString(), 'refund_reason' => 'Buyer kept the remaining items.',
                    'refund_revision' => $task['refund_revision'] ?? 0,
                ]);
            $result = ['status' => $response->getStatusCode(),
                'validation_fields' => $response->isClientError() ? array_keys($response->json('errors') ?? []) : []];
        } elseif ($task['action'] === 'rider_pay' || $task['action'] === 'rider_reverse') {
            $this->actingAs(LogisticsProvider::query()->findOrFail($task['provider']), 'logistics');
            $url = $task['action'] === 'rider_pay'
                ? route('logistics.earnings.pay-rider', $task['courier'])
                : route('logistics.earnings.reverse-rider-payment', $task['payout']);
            $response = $this->postJson($url, $task['data']);
            $result = ['status' => $response->getStatusCode(),
                'validation_fields' => $response->isClientError() ? array_keys($response->json('errors') ?? []) : []];
        } elseif ($task['action'] === 'voucher_cancel') {
            $parcel = SellerOrder::query()->findOrFail($task['parcel']);
            $response = $this->actingAs(Buyer::query()->findOrFail($task['buyer']), 'buyer')
                ->postJson(route('buyer.orders.cancelSellerOrder', [$parcel->order_id, $parcel]), ['reason' => 'Changed my mind.']);
            $result = ['status' => $response->getStatusCode(), 'success' => $response->isSuccessful()];
        } elseif ($task['action'] === 'confirm_received') {
            $response = $this->actingAs(Buyer::query()->findOrFail($task['buyer']), 'buyer')
                ->post(route('buyer.orders.confirmReceived', $task['order']));
            $result = ['status' => $response->getStatusCode(), 'success' => session()->has('success')];
        } elseif ($task['action'] === 'return_refund_void') {
            $response = $this->actingAs(Admin::query()->findOrFail($task['admin']), 'admin')->postJson(
                route('admin.returns.refund.void', ParcelReturn::query()->findOrFail($task['return'])),
                ['void_reason' => 'The GCash transfer bounced back.', 'refund_revision' => $task['refund_revision']]);
            $result = ['status' => $response->getStatusCode(), 'success' => $response->getStatusCode() === 302,
                'validation_fields' => $response->isClientError() ? array_keys($response->json('errors') ?? []) : []];
        } elseif ($task['action'] === 'return_fault') {
            $response = $this->actingAs(Admin::query()->findOrFail($task['admin']), 'admin')->postJson(
                route('admin.returns.fault', ParcelReturn::query()->findOrFail($task['return'])),
                ['fault_party' => $task['party'], 'fault_note' => 'Decided from the courier incident report.']);
            $result = ['status' => $response->getStatusCode(), 'success' => $response->getStatusCode() === 302,
                'validation_fields' => $response->isClientError() ? array_keys($response->json('errors') ?? []) : []];
        } elseif (in_array($task['action'], ['return_escalate', 'return_approve', 'return_reject', 'return_refund'], true)) {
            // Audit 1.13: the real Buyer, Seller and Admin return routes, on the default run root.
            $return = ParcelReturn::query()->with('sellerOrder')->findOrFail($task['return']);
            $response = $task['action'] === 'return_refund'
                ? $this->actingAs(Admin::query()->findOrFail($task['admin']), 'admin')->postJson(route('admin.returns.refund', $return), ($task['extra'] ?? []) + [
                    'reference' => 'MYSQL-RETURN-REFUND', 'amount' => $task['amount'], 'recipient' => 'Buyer GCash',
                    'paid_on' => today()->toDateString(), 'refund_revision' => 0])
                : ($task['action'] === 'return_escalate'
                ? $this->actingAs(Buyer::query()->findOrFail($task['buyer']), 'buyer')->postJson(
                    route('buyer.orders.escalateReturn', [$return->sellerOrder->order_id, $return->sellerOrder]),
                    ['reason' => 'The photos show the damage the seller denied.'])
                : $this->actingAs(Seller::query()->findOrFail($task['seller']), 'seller')->postJson(
                    route($task['action'] === 'return_approve' ? 'seller.returns.approve' : 'seller.returns.reject', $return),
                    $task['action'] === 'return_reject' ? ['reason' => 'The item was fine when shipped.'] : []));
            $result = ['status' => $response->getStatusCode(), 'success' => $response->getStatusCode() === 302,
                'validation_fields' => $response->isClientError() ? array_keys($response->json('errors') ?? []) : []];
        } elseif ($task['action'] === 'create_bundle') {
            $seller = Seller::query()->findOrFail($task['seller']);
            app(SellerPromotionService::class)->create($seller, [
                'kind' => PromotionKind::BundlePercent->value, 'name' => 'Race bundle',
                'percent' => 20, 'buy_quantity' => null, 'get_quantity' => null, 'product_ids' => $task['products'],
                'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
            ]);
            $result = ['status' => 200, 'success' => true];
        } elseif ($task['action'] === 'delete_bundle') {
            $response = $this->actingAs(Seller::query()->findOrFail($task['seller']), 'seller')
                ->delete(route('seller.promotions.destroy', SellerPromotion::query()->findOrFail($task['promotion'])));
            $result = ['status' => $response->getStatusCode(), 'success' => session()->has('success')];
        } elseif ($task['action'] === 'return_request') {
            $parcel = SellerOrder::query()->findOrFail($task['parcel']);
            $provider = LogisticsProvider::query()->findOrFail($task['provider']);
            $record = app(ParcelReturnService::class)->request($parcel, $provider, 'Concurrent physical return request.');
            $result = ['status' => 'requested', 'success' => true, 'return_id' => $record->id];
        } elseif ($task['action'] === 'station_inventory') {
            $inventory = app(StationInventoryService::class);
            $result = ['success' => true, 'counts' => $inventory->counts($inventory->rows($task['provider'], collect([$task['station']])))];
        } elseif (in_array($task['action'], ['return_cancel', 'return_loss', 'return_reverse', 'return_reverse_dispatch'], true)) {
            $record = ParcelReturn::query()->findOrFail($task['return']);
            $service = app(ParcelReturnService::class);
            try {
                if ($task['action'] === 'return_cancel') {
                    $service->cancelByBuyer($record, Buyer::query()->findOrFail($task['buyer']), 'Concurrent cancellation request.');
                } elseif ($task['action'] === 'return_loss') {
                    app(ParcelIncidentService::class)->resolve(Incidents::query()->findOrFail($task['incident']),
                        Admin::query()->findOrFail($task['admin']), ['event' => 'CLOSE_LOST', 'reason' => 'Verified missing after the network search.']);
                } elseif ($task['action'] === 'return_reverse_dispatch') {
                    $provider = LogisticsProvider::query()->findOrFail($task['provider']);
                    $handoff = ParcelHandoff::query()->findOrFail($task['handoff']);
                    $handoffs = app(ParcelHandoffService::class);
                    $scan = $handoffs->verifyScan($handoff, $provider, $record->sellerOrder->tracking_number, 'dispatch');
                    $handoffs->dispatch($handoff, $provider, $scan['verification_token']);
                } else {
                    $service->reverseHandoff($record, LogisticsProvider::query()->findOrFail($task['provider']));
                }
                $result = ['status' => 'accepted', 'success' => true];
            } catch (ValidationException $exception) {
                $result = ['status' => 'rejected', 'success' => false, 'errors' => $exception->errors()];
            }
        } elseif ($task['action'] === 'return_pickup') {
            $record = ParcelReturn::query()->findOrFail($task['return']);
            $service = app(ParcelReturnService::class);
            try {
                if (isset($task['station'])) {
                    $service->receiveFromBuyerCollection($record, LogisticsProvider::query()->findOrFail($task['provider']),
                        $task['station'], $record->sellerOrder->tracking_number);
                } elseif (isset($task['courier'])) {
                    $service->assignBuyerCollection($record, LogisticsProvider::query()->findOrFail($task['provider']), $task['courier']);
                } else {
                    $service->confirmBuyerCollection($record, Rider::query()->findOrFail($task['rider']), $record->sellerOrder->tracking_number);
                }
                $result = ['status' => 'accepted', 'success' => true];
            } catch (ValidationException) {
                $result = ['status' => 'rejected', 'success' => false];
            }
        } elseif ($task['action'] === 'return_receipt') {
            config(['returns.restore_verified_resellable' => true]);
            $record = ParcelReturn::query()->findOrFail($task['return']);
            $seller = Seller::query()->findOrFail($task['seller']);
            $service = app(ParcelReturnService::class);
            try {
                $token = $service->verifySeller($record, $seller, $record->sellerOrder->tracking_number, $task['handover_code'], 'resellable');
                $service->receiveBySeller($record, $seller, 'resellable', $token);
                $result = ['status' => 'completed', 'success' => true];
            } catch (ValidationException) {
                $result = ['status' => 'rejected', 'success' => false];
            }
        } elseif ($task['action'] === 'end_sale') {
            app(StoreSaleService::class)->end(StoreSale::query()->findOrFail($task['sale']));
            $result = ['status' => 200, 'success' => true];
        } elseif ($task['action'] === 'edit_stock') {
            $product = Product::query()->with('variants')->findOrFail($task['product']);
            $service = app(ProductPublishingService::class);
            try {
                $service->save($product, $product->seller, [
                    ...$product->attributesToArray(),
                    'stock_quantity' => $task['quantity'],
                    'inventory_revision' => $service->inventoryRevision($product),
                ]);
                $result = ['status' => 200, 'success' => true];
            } catch (ValidationException $exception) {
                $result = ['status' => 422, 'success' => false, 'validation_fields' => array_keys($exception->errors())];
            }
        } elseif ($task['action'] === 'stock_set') {
            $variant = ProductVariant::query()->findOrFail($task['variant']);
            app(StockService::class)->set($variant, (int) $task['quantity']);
            $result = ['status' => 200, 'success' => true];
        } elseif ($task['action'] === 'dispatch') {
            $dispatch = app(CourierAssignmentService::class)->dispatch(
                SellerOrder::query()->findOrFail($task['parcel']), $task['role']);
            $result = ['status' => $dispatch['status'], 'success' => in_array($dispatch['status'], ['reserved', 'assigned'], true), 'courier_id' => $dispatch['courier']?->id];
        } elseif (in_array($task['action'], ['price', 'suspend', 'variations'], true)) {
            DB::transaction(function () use ($task): void {
                if ($task['action'] === 'suspend') {
                    DB::table('sellers')->where('id', $task['seller'])->lockForUpdate()->first();
                    DB::table('sellers')->where('id', $task['seller'])->update(['status' => 'suspended']);
                } else {
                    $product = Product::query()->whereKey($task['product'])->lockForUpdate()->firstOrFail();
                    if ($task['action'] === 'price') {
                        DB::table('product_variants')->where('id', $task['variant'])->update(['price' => '125.99']);
                        $product->syncVariantRollups();
                    } else {
                        app(VariantSyncService::class)->sync($product,
                            [['name' => 'Color', 'values' => ['Green', 'Black']], ['name' => 'Size', 'values' => ['Small']]],
                            [
                                ['values' => ['Green', 'Small'], 'price' => '100.00', 'stock_quantity' => 2, 'is_available' => true],
                                ['values' => ['Black', 'Small'], 'price' => '100.00', 'stock_quantity' => 3, 'is_available' => true],
                            ], true);
                    }
                }
                $this->hold();
            });
            $result = ['status' => 200, 'success' => true];
        } else {
            $this->actingAs(Buyer::query()->findOrFail($task['buyer']), 'buyer');
            if ($task['action'] === 'checkout') {
                CheckoutShippingFixture::primeCache();
                $session = [CheckoutController::SESSION_KEY => $task['items'], 'cart_vouchers' => $task['vouchers'] ?? []];
                if (isset($task['reviewed_token'], $task['reviewed_price'])) {
                    // The real checkout page wrote these in the parent process;
                    // replay its review state instead of reconstructing it.
                    $session[CheckoutController::TOKEN_KEY] = $task['reviewed_token'];
                    $session['checkout_reviewed_price'] = $task['reviewed_price'];
                }
                $response = $this->withSession($session)
                    ->post(route('buyer.checkout.store'), ['checkout_token' => $task['token'], 'carriers' => $task['carriers'] ?? []]);
                $result = ['status' => $response->getStatusCode(),
                    'success' => str_contains((string) $response->headers->get('Location'), '/checkout/success/'),
                    'error' => session('error')];
            } elseif ($task['action'] === 'buy_now') {
                $response = $this->postJson(route('buyer.checkout.buy-now'), [
                    'product_id' => $task['product'], 'variant_id' => $task['variant'], 'quantity' => $task['quantity'],
                ]);
                $result = ['status' => $response->getStatusCode(), 'success' => $response->isSuccessful()];
            } elseif ($task['action'] === 'add') {
                $response = $this->postJson(route('buyer.cart.add'), ['product_id' => $task['product'], 'variant_id' => $task['variant'], 'quantity' => $task['quantity']]);
                $result = ['status' => $response->getStatusCode(), 'success' => $response->getStatusCode() === 200, 'cart_count' => $response->json('cart_count'), 'error' => $response->json('error')];
            } else {
                $response = $this->patchJson(route('buyer.cart.update', $task['item']), ['quantity' => $task['quantity']]);
                $result = ['status' => $response->getStatusCode(), 'success' => $response->getStatusCode() === 200, 'cart_count' => $response->json('cart_count'), 'error' => $response->json('error')];
            }
        }

        return $result + ['connection' => $connection, 'pid' => getmypid(), 'start' => $start,
            'end' => microtime(true), 'sql_errors' => $this->sqlErrors, 'cart_reads' => $this->cartReads,
            'first_order_lock' => $this->firstOrderLock, 'injected_failures' => $this->injectedFailures];
    }
}

$task = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$runRoot = match ($task['action'] ?? '') {
    'dispatch', 'phase6_reroute', 'phase6_receive' => '/storage/logs/phase6-races', 'payout_request', 'settlement', 'payout_pay', 'payout_refund', 'payout_refund_void' => '/storage/logs/payout-races', 'return_request', 'return_receipt' => '/storage/logs/phase7-races', default => '/.codex/t05-runs'
};
$root = realpath(dirname(__DIR__, 2).$runRoot);
if ($root === false || ! str_starts_with(realpath($task['directory']), $root.DIRECTORY_SEPARATOR)) {
    exit(2);
}
try {
    $result = (new T05Worker('execute'))->execute($task);
} catch (Throwable $exception) {
    $result = ['status' => 0, 'success' => false, 'connection' => getmypid(),
        'exception' => get_class($exception), 'sql_errors' => $exception instanceof QueryException ? [(int) ($exception->errorInfo[1] ?? 0)] : [],
        'preflight_failed' => ! Preflight::$passed];
    // Authentication exceptions are never printed with their messages or traces.
}
file_put_contents($task['directory'].'/'.$task['name'].'.result.json', json_encode($result), LOCK_EX);
