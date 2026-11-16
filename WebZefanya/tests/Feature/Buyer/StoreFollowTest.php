<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\StoreFollow;
use App\Models\Seller\Seller;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StoreFollowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_toggle_follows_then_unfollows_and_updates_the_store_count(): void
    {
        $this->withoutVite();
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->actingAs($buyer, 'buyer')->postJson(route('buyer.stores.follow', $seller))
            ->assertOk()->assertJsonPath('following', true)->assertJsonPath('followers_count', 1);
        $this->assertDatabaseHas('store_follows', ['buyer_id' => $buyer->id, 'seller_id' => $seller->id]);
        $this->get(route('buyer.stores.show', $seller))->assertOk()
            ->assertViewHas('followersCount', 1)->assertViewHas('isFollowing', true)->assertSee('Unfollow');
        $this->postJson(route('buyer.stores.follow', $seller))->assertOk()
            ->assertJsonPath('following', false)->assertJsonPath('followers_count', 0);
        $this->assertDatabaseMissing('store_follows', ['buyer_id' => $buyer->id, 'seller_id' => $seller->id]);
    }

    public function test_buyers_follow_independently_and_cannot_submit_another_buyer_id(): void
    {
        $first = Buyer::factory()->create();
        $second = Buyer::factory()->create();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->actingAs($first, 'buyer')->postJson(route('buyer.stores.follow', $seller))->assertOk();
        $this->actingAs($second, 'buyer')->postJson(route('buyer.stores.follow', $seller), ['buyer_id' => $first->id])
            ->assertOk()->assertJsonPath('followers_count', 2);
        $this->postJson(route('buyer.stores.follow', $seller))->assertOk()->assertJsonPath('followers_count', 1);
        $this->assertDatabaseHas('store_follows', ['buyer_id' => $first->id, 'seller_id' => $seller->id]);
    }

    public function test_following_list_is_buyer_owned_and_supports_normal_form_unfollow(): void
    {
        $this->withoutVite();
        $buyer = Buyer::factory()->create();
        $own = Seller::factory()->create(['status' => 'approved', 'business_name' => 'My Followed Shop']);
        $foreign = Seller::factory()->create(['status' => 'approved', 'business_name' => 'Another Buyers Shop']);
        StoreFollow::create(['buyer_id' => $buyer->id, 'seller_id' => $own->id]);
        StoreFollow::create(['buyer_id' => Buyer::factory()->create()->id, 'seller_id' => $foreign->id]);
        $this->actingAs($buyer, 'buyer')->get(route('buyer.following.index'))->assertOk()
            ->assertSee('My Followed Shop')->assertDontSee('Another Buyers Shop')->assertSee(route('buyer.stores.show', $own), false);
        $this->from(route('buyer.following.index'))->post(route('buyer.stores.follow', $own))
            ->assertRedirect(route('buyer.following.index'))->assertSessionHas('success', 'Store unfollowed.');
        $this->get(route('buyer.following.index'))->assertOk()->assertSee('You are not following any stores yet.');
    }

    public function test_guest_can_view_store_but_must_log_in_to_follow_or_open_following(): void
    {
        $this->withoutVite();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->get(route('buyer.stores.show', $seller))->assertOk()->assertSee('Log in to follow this store');
        $this->postJson(route('buyer.stores.follow', $seller))->assertUnauthorized();
        $this->getJson(route('buyer.following.index'))->assertUnauthorized();
        $this->assertDatabaseCount('store_follows', 0);
    }

    public function test_seller_guard_cannot_follow_or_open_buyer_following_list(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->actingAs($seller, 'seller')->postJson(route('buyer.stores.follow', $seller))->assertUnauthorized();
        $this->getJson(route('buyer.following.index'))->assertUnauthorized();
    }

    public function test_unavailable_stores_cannot_be_followed_and_are_hidden_from_the_list(): void
    {
        $this->withoutVite();
        $buyer = Buyer::factory()->create();
        $this->actingAs($buyer, 'buyer');
        foreach ([['status' => 'pending'], ['status' => 'approved', 'archived_at' => now()]] as $attributes) {
            $seller = Seller::factory()->create($attributes);
            $this->postJson(route('buyer.stores.follow', $seller))->assertNotFound();
            StoreFollow::create(['buyer_id' => $buyer->id, 'seller_id' => $seller->id]);
        }
        $this->get(route('buyer.following.index'))->assertOk()->assertViewHas('follows', fn ($follows) => $follows->total() === 0);
    }

    public function test_database_rejects_duplicate_buyer_store_pairs(): void
    {
        $attributes = ['buyer_id' => Buyer::factory()->create()->id, 'seller_id' => Seller::factory()->create()->id];
        StoreFollow::create($attributes);
        $this->expectException(QueryException::class);
        StoreFollow::create($attributes);
    }

    public function test_following_list_is_paginated(): void
    {
        $this->withoutVite();
        $buyer = Buyer::factory()->create();
        foreach (Seller::factory()->count(13)->create(['status' => 'approved']) as $seller) {
            StoreFollow::create(['buyer_id' => $buyer->id, 'seller_id' => $seller->id]);
        }
        $this->actingAs($buyer, 'buyer')->get(route('buyer.following.index'))->assertOk()
            ->assertViewHas('follows', fn ($follows) => $follows->total() === 13 && $follows->count() === 12);
    }
}
