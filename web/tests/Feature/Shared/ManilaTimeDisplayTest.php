<?php

namespace Tests\Feature\Shared;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Shared\Conversation;
use App\Models\Shared\Message;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Timestamps are stored in UTC; screens show Philippine time (UTC+8) through
 * manila() / @manila. These pin the conversion at the midnight boundary.
 */
class ManilaTimeDisplayTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_storage_stays_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Manila', config('app.schedule_timezone'));
    }

    public function test_utc_converts_to_manila_without_mutating_the_original(): void
    {
        $utc = Carbon::create(2026, 11, 13, 16, 8, 0, 'UTC');

        $this->assertSame('Nov 14, 2026 12:08 AM', manila($utc, 'M d, Y g:i A'));
        $this->assertSame('UTC', $utc->tzName);
        $this->assertSame('2026-11-13 16:08', $utc->format('Y-m-d H:i'));
        $this->assertSame('Nov 14, 2026 12:08 AM', manila('2026-11-13 16:08:00', 'M d, Y g:i A'));
    }

    public function test_midnight_boundary_in_manila(): void
    {
        $this->assertSame('2026-11-13 23:59', manila(Carbon::parse('2026-11-13 15:59:59', 'UTC'), 'Y-m-d H:i'));
        $this->assertSame('2026-11-14 00:00', manila(Carbon::parse('2026-11-13 16:00:00', 'UTC'), 'Y-m-d H:i'));
    }

    public function test_null_and_invalid_values_return_the_fallback(): void
    {
        $this->assertSame('', manila(null, 'Y'));
        $this->assertSame('', manila('', 'Y'));
        $this->assertSame('—', manila(null, 'Y', '—'));
        $this->assertSame('n/a', manila('not a date', 'Y', 'n/a'));
    }

    public function test_blade_directive_converts_and_handles_null(): void
    {
        $html = Blade::render("@manila(\$d, 'M j, g:i A')|@manila(\$n, 'Y')|", [
            'd' => Carbon::parse('2026-11-13 16:30:00', 'UTC'),
            'n' => null,
        ]);

        $this->assertSame('Nov 14, 12:30 AM||', $html);
    }

    public function test_admin_order_monitoring_fields_show_manila_time(): void
    {
        $html = view('Admin.OrderMonitoring._fields', ['fields' => [
            'Checkout at' => Carbon::parse('2026-11-13 16:06:53', 'UTC'),
            'Refund paid at' => null,
        ]])->render();

        $this->assertStringContainsString('2026-11-14 00:06:53', $html);
        $this->assertStringNotContainsString('2026-11-13 16:06:53', $html);
        $this->assertStringContainsString('Not recorded', $html);
    }

    public function test_finance_csv_dates_use_manila_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20 04:00:00', 'UTC'));
        $admin = Admin::factory()->create(['role' => 'admin']);
        $seller = Seller::factory()->create(['business_name' => 'Midnight Shop']);
        SellerPayoutRequest::query()->forceCreate([
            'seller_id' => $seller->id, 'idempotency_key' => (string) Str::uuid(), 'amount_minor' => 40000,
            'status' => SellerPayoutRequest::PAID, 'paid_at' => Carbon::parse('2026-11-13 16:30:00', 'UTC'),
            'payout_reference' => 'GCASH-MIDNIGHT-1',
        ]);

        $csv = $this->actingAs($admin, 'admin')
            ->get(route('admin.finance.export', ['month' => '2026-11']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('2026-11-14 00:30', $csv);
        $this->assertStringNotContainsString('2026-11-13 16:30', $csv);
    }

    public function test_buyer_inbox_uses_the_manila_calendar_day(): void
    {
        $this->withoutVite();
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create(['status' => 'approved']);

        // 11:00 PM Nov 13 Manila; viewed at 12:30 AM Nov 14 Manila. Both are Nov 13 in UTC.
        Conversation::create(['buyer_id' => $buyer->id, 'seller_id' => $seller->id, 'last_message_at' => Carbon::parse('2026-11-13 15:00:00', 'UTC')]);
        Carbon::setTestNow(Carbon::parse('2026-11-13 16:30:00', 'UTC'));

        $this->actingAs($buyer, 'buyer')->get(route('buyer.messages.index'))->assertOk()
            ->assertSee('<time>Nov 13</time>', false)
            ->assertDontSee('<time>11:00 PM</time>', false);

        // Same Manila day: shows the time.
        Carbon::setTestNow(Carbon::parse('2026-11-13 15:30:00', 'UTC'));
        $this->get(route('buyer.messages.index'))->assertOk()->assertSee('<time>11:00 PM</time>', false);
    }

    public function test_live_chat_bubble_html_shows_manila_time(): void
    {
        $this->withoutVite();
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $conversation = Conversation::create(['buyer_id' => $buyer->id, 'seller_id' => $seller->id]);

        Carbon::setTestNow(Carbon::parse('2026-11-13 16:05:00', 'UTC'));
        Message::create(['conversation_id' => $conversation->id, 'sender_role' => 'seller', 'sender_id' => $seller->id, 'body' => 'Late reply']);

        $json = $this->actingAs($buyer, 'buyer')->getJson(route('buyer.messages.show', $conversation))->assertOk()->json();
        $flat = json_encode($json);

        $this->assertStringContainsString('12:05 AM', $flat);
        $this->assertStringNotContainsString('4:05 PM', $flat);
    }
}
