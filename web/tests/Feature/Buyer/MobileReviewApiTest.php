<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\Review;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Rider\RiderTestCase;

class MobileReviewApiTest extends RiderTestCase
{
    private function api(?Buyer $buyer = null): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $token = ($buyer ?? $this->buyer)->createToken('review-test', ['buyer'])->plainTextToken;

        return $this->withToken($token);
    }

    private function purchase(?\Illuminate\Support\Carbon $completedAt = null): SellerOrder
    {
        $shipment = $this->parcel(OrderStatus::Completed);
        $history = StatusHistory::record($shipment, OrderStatus::Delivered, OrderStatus::Completed);
        $history->forceFill(['created_at' => $completedAt ?? now()])->save();

        return $shipment;
    }

    private function path(SellerOrder $shipment): string
    {
        return '/api/buyer/shipments/'.$shipment->id.'/products/'.$shipment->orderItems()->firstOrFail()->product_id.'/review';
    }

    public function test_guest_and_rider_cannot_read_or_write_buyer_reviews(): void
    {
        $shipment = $this->purchase();
        $this->getJson('/api/buyer/reviews/mine')->assertUnauthorized();
        $this->patchJson($this->path($shipment), ['stars' => 5])->assertUnauthorized();
        $this->withToken($this->rider->createToken('rider-test', ['rider'])->plainTextToken);
        $this->getJson('/api/buyer/reviews/mine')->assertForbidden();
        $this->patchJson($this->path($shipment), ['stars' => 5])->assertForbidden();
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_another_buyer_cannot_review_my_shipment_or_read_my_reviews(): void
    {
        $shipment = $this->purchase();
        $stranger = Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->api($stranger)->patchJson($this->path($shipment), ['stars' => 5])->assertForbidden();
        $this->api()->patchJson($this->path($shipment), ['stars' => 5])->assertCreated();
        $this->api($stranger)->getJson('/api/buyer/reviews/mine')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('reviews', ['buyer_id' => $this->buyer->id]);
    }

    public function test_completed_purchase_can_be_reviewed_then_edited_once(): void
    {
        $shipment = $this->purchase(now()->subDays(29));
        $this->api()->patchJson($this->path($shipment), [
            'stars' => 5, 'comment' => 'Original', 'buyer_id' => 99999, 'edit_count' => -1,
        ])->assertCreated()->assertJsonPath('data.edit_count', 0);
        $this->api()->getJson('/api/buyer/reviews/mine')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_id', (string) $shipment->id);
        $this->api()->patchJson($this->path($shipment), ['stars' => 4, 'comment' => 'Edited'])
            ->assertOk()->assertJsonPath('data.edit_count', 1);
        $this->api()->patchJson($this->path($shipment), ['stars' => 1, 'edit_count' => 0])->assertUnprocessable();
        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseHas('reviews', ['buyer_id' => $this->buyer->id, 'rating' => 4, 'body' => 'Edited']);
    }

    public function test_delivered_unknown_future_and_expired_completion_are_refused(): void
    {
        foreach (['delivered', 'unknown', 'future', 'boundary', 'expired'] as $case) {
            $shipment = $this->purchase(match ($case) {
                'future' => now()->addDay(),
                'boundary' => now()->subDays(30),
                'expired' => now()->subDays(31),
                default => now(),
            });
            if ($case === 'delivered') {
                $shipment->forceFill(['status' => OrderStatus::Delivered])->save();
            }
            if ($case === 'unknown') {
                $shipment->statusHistories()->delete();
            }
            $this->api()->patchJson($this->path($shipment), ['stars' => 5])->assertUnprocessable();
        }
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_recent_submission_does_not_restart_the_completion_window(): void
    {
        $this->travelTo(now()->startOfSecond());
        $shipment = $this->purchase(now()->subDays(29));
        $this->api()->patchJson($this->path($shipment), ['stars' => 5])->assertCreated();
        $this->travel(1)->days();
        $this->api()->patchJson($this->path($shipment), ['stars' => 1])->assertUnprocessable();
        $this->assertSame(5, Review::query()->sole()->rating);
        $this->travelBack();
    }

    public function test_stars_comment_and_content_checked_photo_limits_are_enforced(): void
    {
        Storage::fake('public');
        $shipment = $this->purchase();
        foreach ([[], ['stars' => 0], ['stars' => 6], ['stars' => 5, 'comment' => str_repeat('x', 501)]] as $payload) {
            $this->api()->patchJson($this->path($shipment), $payload)->assertUnprocessable();
        }
        foreach ([
            [UploadedFile::fake()->image('bad.webp')],
            [UploadedFile::fake()->create('disguised.jpg', 10, 'text/plain')],
            [UploadedFile::fake()->image('big.png')->size(5121)],
            array_map(fn ($i) => UploadedFile::fake()->image("photo-$i.jpg"), range(1, 6)),
        ] as $photos) {
            $this->api()->patchJson($this->path($shipment), ['stars' => 5, 'photos' => $photos])->assertUnprocessable();
        }
        $this->assertDatabaseCount('reviews', 0);
        $photos = array_map(fn ($i) => UploadedFile::fake()->image("photo-$i.png")->size(5120), range(1, 5));
        $this->api()->post($this->path($shipment), ['_method' => 'PATCH', 'stars' => 5, 'photos' => $photos], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(5, 'data.photo_urls');
        $review = Review::query()->sole();
        foreach ($review->photos as $photo) {
            Storage::disk('public')->assertExists($photo->path);
        }
        $this->assertDatabaseCount('review_photos', 5);
    }

    public function test_write_route_is_throttled(): void
    {
        $shipment = $this->purchase();
        $this->api();
        for ($i = 0; $i < 20; $i++) {
            $this->patchJson($this->path($shipment), ['stars' => 0])->assertUnprocessable();
        }
        $this->patchJson($this->path($shipment), ['stars' => 5])->assertTooManyRequests();
        $this->assertDatabaseCount('reviews', 0);
    }
}
