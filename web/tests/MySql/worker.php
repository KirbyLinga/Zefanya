<?php

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/Preflight.php';

use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelReturn;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Models\Seller\SellerSettlement;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Seller\SellerVacationService;
use App\Services\Seller\StockService;
use App\Services\Seller\VariantSyncService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
            'parcel' => str_starts_with($sql, 'select') && str_contains($sql, 'from `seller_orders`') && str_contains($sql, 'for update'),
            'provider' => str_starts_with($sql, 'select') && str_contains($sql, 'from `logistics_providers`') && str_contains($sql, 'for update'),
            'product' => str_starts_with($sql, 'select') && str_contains($sql, 'from `products`') && str_contains($sql, 'for update'),
            'seller' => str_starts_with($sql, 'select') && str_contains($sql, 'from `sellers`') && str_contains($sql, 'for update'),
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
        if ($task['action'] === 'vacation') {
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
                    ['amount_minor' => $task['amount_minor'], 'reference' => $task['reference']]);
            $result = ['status' => $response->getStatusCode(), 'success' => $response->getStatusCode() === 200,
                'validation_fields' => array_keys($response->json('errors') ?? [])];
        } elseif ($task['action'] === 'return_request') {
            $parcel = SellerOrder::query()->findOrFail($task['parcel']);
            $provider = LogisticsProvider::query()->findOrFail($task['provider']);
            $record = app(ParcelReturnService::class)->request($parcel, $provider, 'Concurrent physical return request.');
            $result = ['status' => 'requested', 'success' => true, 'return_id' => $record->id];
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
                $response = $this->withSession([CheckoutController::SESSION_KEY => $task['items']])
                    ->post(route('buyer.checkout.store'), ['checkout_token' => $task['token'], 'carriers' => $task['carriers'] ?? []]);
                $result = ['status' => $response->getStatusCode(),
                    'success' => str_contains((string) $response->headers->get('Location'), '/checkout/success/'),
                    'error' => session('error')];
            } elseif ($task['action'] === 'add') {
                $response = $this->postJson(route('buyer.cart.add'), ['product_id' => $task['product'], 'variant_id' => $task['variant'], 'quantity' => $task['quantity']]);
                $result = ['status' => $response->getStatusCode(), 'success' => $response->getStatusCode() === 200, 'cart_count' => $response->json('cart_count'), 'error' => $response->json('error')];
            } else {
                $response = $this->patchJson(route('buyer.cart.update', $task['item']), ['quantity' => $task['quantity']]);
                $result = ['status' => $response->getStatusCode(), 'success' => $response->getStatusCode() === 200, 'cart_count' => $response->json('cart_count'), 'error' => $response->json('error')];
            }
        }

        return $result + ['connection' => $connection, 'pid' => getmypid(), 'start' => $start,
            'end' => microtime(true), 'sql_errors' => $this->sqlErrors, 'cart_reads' => $this->cartReads, 'injected_failures' => $this->injectedFailures];
    }
}

$task = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$runRoot = match ($task['action'] ?? '') {
    'dispatch' => '/storage/logs/phase6-races', 'payout_request', 'settlement' => '/storage/logs/payout-races', 'return_request', 'return_receipt' => '/storage/logs/phase7-races', default => '/.codex/t05-runs'
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
