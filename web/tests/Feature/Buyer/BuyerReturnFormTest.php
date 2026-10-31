<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\ParcelReturn;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BuyerReturnFormTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function parcel(): SellerOrder
    {
        $this->withoutVite();
        Storage::fake('local');
        $parcel = SellerOrder::factory()->create([
            'order_id' => Order::factory()->create(['buyer_id' => Buyer::factory()->create()->id])->id,
            'status' => OrderStatus::Delivered,
        ]);
        StatusHistory::create(['seller_order_id' => $parcel->id, 'from_status' => OrderStatus::OutForDelivery, 'to_status' => OrderStatus::Delivered]);
        $this->actingAs($parcel->order->buyer, 'buyer');

        return $parcel;
    }

    private function payload(): array
    {
        return ['reason' => 'Item arrived damaged', 'description' => 'The delivered item is broken.', 'photos' => [UploadedFile::fake()->image('damage.jpg')]];
    }

    public static function invalidDescriptions(): array
    {
        return [[null], [''], ['         '], [str_repeat('a', 9)], [str_repeat('a', 501)]];
    }

    #[DataProvider('invalidDescriptions')]
    public function test_description_is_required_and_between_ten_and_five_hundred_characters(?string $description): void
    {
        $parcel = $this->parcel();
        $this->postJson(route('buyer.orders.requestReturn', [$parcel->order, $parcel]), ['description' => $description] + $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->assertDatabaseCount('parcel_returns', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_reason_must_come_from_the_list_and_photos_are_required(): void
    {
        $parcel = $this->parcel();
        $url = route('buyer.orders.requestReturn', [$parcel->order, $parcel]);
        $this->postJson($url, ['reason' => 'Invented reason'] + $this->payload())->assertUnprocessable()->assertJsonValidationErrors('reason');
        foreach ([null, []] as $photos) {
            $this->postJson($url, ['photos' => $photos] + $this->payload())->assertUnprocessable()->assertJsonValidationErrors('photos');
        }
        $this->assertDatabaseCount('parcel_returns', 0);
    }

    public function test_only_one_to_five_real_jpg_or_png_photos_are_accepted(): void
    {
        $parcel = $this->parcel();
        foreach ([
            [UploadedFile::fake()->image('damage.gif')],
            [UploadedFile::fake()->create('damage.jpg', 10, 'application/pdf')],
            [UploadedFile::fake()->image('damage.png')->size(5121)],
            array_map(fn ($n) => UploadedFile::fake()->image("{$n}.jpg"), range(1, 6)),
        ] as $photos) {
            $this->postJson(route('buyer.orders.requestReturn', [$parcel->order, $parcel]), ['photos' => $photos] + $this->payload())->assertUnprocessable();
        }
        $this->assertDatabaseCount('parcel_returns', 0);
    }

    public static function validLengths(): array
    {
        return [[10], [500]];
    }

    #[DataProvider('validLengths')]
    public function test_form_has_a_reason_list_and_accepts_boundary_lengths_and_five_photos(int $length): void
    {
        $parcel = $this->parcel();
        $this->get(route('buyer.orders.show', $parcel->order))->assertOk()->assertSee('Choose a reason')->assertSee('Wrong item received');
        $photos = array_map(fn ($n) => UploadedFile::fake()->image($n % 2 ? "{$n}.jpg" : "{$n}.png"), range(1, 5));
        $this->postJson(route('buyer.orders.requestReturn', [$parcel->order, $parcel]), [
            'description' => str_repeat('a', $length), 'photos' => $photos,
        ] + $this->payload())->assertCreated();
        $record = ParcelReturn::where('seller_order_id', $parcel->id)->sole();
        $this->assertCount(5, $record->photos);
        $this->assertSame($length, mb_strlen($record->description));
        foreach ($record->photos as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_before_delivery_and_after_completion_the_form_and_request_are_blocked(): void
    {
        foreach ([OrderStatus::OutForDelivery, OrderStatus::Completed] as $status) {
            $parcel = $this->parcel();
            $parcel->forceFill(['status' => $status])->save();
            $url = route('buyer.orders.requestReturn', [$parcel->order, $parcel]);
            $this->get(route('buyer.orders.show', $parcel->order))->assertOk()->assertDontSee('action="'.$url.'"', false);
            $this->postJson($url, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('return');
        }
    }
}
