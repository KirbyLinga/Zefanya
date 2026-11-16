<?php

namespace Tests\Feature\Auth;

use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetBrokerIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_buyer_and_seller_reset_tokens_for_the_same_email_live_in_separate_tables(): void
    {
        Notification::fake();

        Buyer::factory()->create(['email' => 'same@example.com']);
        Seller::factory()->create(['email' => 'same@example.com']);

        $this->assertSame(Password::RESET_LINK_SENT, Password::broker('buyers')->sendResetLink(['email' => 'same@example.com']));
        $this->assertSame(Password::RESET_LINK_SENT, Password::broker('sellers')->sendResetLink(['email' => 'same@example.com']));

        $this->assertDatabaseCount('password_reset_tokens_buyers', 1);
        $this->assertDatabaseCount('password_reset_tokens_sellers', 1);
        $this->assertDatabaseHas('password_reset_tokens_buyers', ['email' => 'same@example.com']);
        $this->assertDatabaseHas('password_reset_tokens_sellers', ['email' => 'same@example.com']);
    }
}
