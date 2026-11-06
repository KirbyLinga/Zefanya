<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Notifications\Admin\AccountStatusChanged;
use App\Notifications\Buyer\BuyerRegistrationDecision;
use App\Notifications\Seller\SellerRegistrationDecision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminUserAccountTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'User Admin',
            'email' => 'users.'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    public function test_admin_can_paginate_each_account_type_with_and_without_search(): void
    {
        $this->actingAs($this->admin(), 'admin');

        foreach (['buyer', 'seller', 'logistics'] as $type) {
            foreach (['', 'example'] as $search) {
                $this->get(route('admin.users.index', compact('type', 'search')))
                    ->assertOk()
                    ->assertViewHas('accounts', fn ($accounts) => $accounts instanceof LengthAwarePaginator);
            }
        }
    }

    public function test_admin_can_filter_every_supported_status_for_each_account_type(): void
    {
        $this->actingAs($this->admin(), 'admin');

        foreach (['buyer' => Buyer::class, 'seller' => Seller::class, 'logistics' => LogisticsProvider::class] as $type => $model) {
            $accounts = [];
            foreach (['approved', 'pending', 'suspended', 'rejected', 'unverified'] as $status) {
                $accounts[$status] = $model::factory()->create(['status' => $status]);
            }

            foreach ($accounts as $status => $account) {
                $response = $this->get(route('admin.users.index', compact('type', 'status')))
                    ->assertOk()
                    ->assertViewHas('status', $status)
                    ->assertViewHas('accounts', fn ($results) => $results->modelKeys() === [$account->id]);

                $document = new \DOMDocument;
                @$document->loadHTML($response->getContent());
                $xpath = new \DOMXPath($document);
                $this->assertSame(1, $xpath->query('//select[@name="status"]/option[@value="'.$status.'" and @selected]')->length);
                $this->assertSame(1, $xpath->query('//tbody/tr/td/span[normalize-space(.)="'.ucfirst($status).'"]')->length);
                $this->assertSame($status === 'approved' ? 1 : 0, $xpath->query('//tbody//button[normalize-space(.)="Suspend"]')->length);
                $this->assertSame($status === 'suspended' ? 1 : 0, $xpath->query('//tbody//button[normalize-space(.)="Reactivate"]')->length);
            }

            foreach (['', 'unknown', ['pending']] as $status) {
                $this->get(route('admin.users.index', compact('type', 'status')))
                    ->assertOk()
                    ->assertViewHas('status', '')
                    ->assertViewHas('accounts', fn ($results) => $results->total() === 5);
            }
        }
    }

    public function test_status_filter_is_combined_with_each_search_field(): void
    {
        $this->actingAs($this->admin(), 'admin');

        foreach (['buyer' => Buyer::class, 'seller' => Seller::class, 'logistics' => LogisticsProvider::class] as $type => $model) {
            $fields = ['first_name', 'last_name', 'email', 'contact_no'];
            if ($type !== 'buyer') {
                $fields[] = 'business_name';
            }

            foreach ($fields as $field) {
                $search = $field === 'contact_no' ? '0999555' : 'Needle'.$type.str_replace('_', '', $field);
                $value = match ($field) {
                    'email' => $search.'@example.com',
                    'contact_no' => $search.'1234',
                    default => $search,
                };
                $matching = $model::factory()->create(['status' => 'pending', $field => $value]);
                $model::factory()->create([
                    'status' => 'approved',
                    $field => match ($field) {
                        'email' => 'approved.'.$value,
                        'contact_no' => $search.'5678',
                        default => $value,
                    },
                ]);
                $model::factory()->create(['status' => 'pending']);

                $this->get(route('admin.users.index', ['type' => $type, 'status' => 'pending', 'search' => $search]))
                    ->assertOk()
                    ->assertViewHas('accounts', fn ($results) => $results->modelKeys() === [$matching->id]);

            }
        }
    }

    public function test_status_search_and_type_are_preserved_in_pagination(): void
    {
        Seller::factory()->count(26)->create(['status' => 'pending', 'first_name' => 'PaginationNeedle']);
        Seller::factory()->create(['status' => 'approved', 'first_name' => 'PaginationNeedle']);
        $filters = ['type' => 'seller', 'status' => 'pending', 'search' => 'PaginationNeedle'];

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.users.index', $filters))
            ->assertOk()
            ->assertViewHas('accounts', fn ($accounts) => $accounts->total() === 26 && $accounts->count() === 25);
        $nextPage = $response->viewData('accounts')->nextPageUrl();
        parse_str(parse_url($nextPage, PHP_URL_QUERY), $query);
        $this->assertSame($filters + ['page' => '2'], $query);

        $this->get($nextPage)
            ->assertOk()
            ->assertViewHas('accounts', fn ($accounts) => $accounts->total() === 26 && $accounts->count() === 1);
    }

    public function test_admin_can_list_and_suspend_an_approved_seller(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $seller = Seller::factory()->create(['status' => 'approved']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.users.index', ['type' => 'seller']))
            ->assertOk()
            ->assertSee($seller->email);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.seller.suspend', $seller), ['reason' => 'Account status reviewed by Admin.'])
            ->assertRedirect();

        $this->assertSame('suspended', $seller->fresh()->status);

        Notification::assertSentTo($seller, AccountStatusChanged::class, function (AccountStatusChanged $n, array $channels): bool {
            return $n->status === 'suspended' && $channels === ['mail'];
        });
    }

    public function test_admin_can_reactivate_a_suspended_seller(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $seller = Seller::factory()->create(['status' => 'suspended']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.seller.reactivate', $seller), ['reason' => 'Account status reviewed by Admin.'])
            ->assertRedirect();

        $this->assertSame('approved', $seller->fresh()->status);

        Notification::assertSentTo($seller, AccountStatusChanged::class, function (AccountStatusChanged $n, array $channels): bool {
            return $n->status === 'approved' && $channels === ['mail'];
        });
    }

    public function test_registration_decision_notifications_use_mail(): void
    {
        $this->assertSame(['mail'], (new BuyerRegistrationDecision('approved'))->via((object) []));
        $this->assertSame(['mail'], (new SellerRegistrationDecision('rejected', 'bad ID'))->via((object) []));
    }

    public function test_suspended_seller_cannot_open_the_seller_panel(): void
    {
        $seller = Seller::factory()->create([
            'status' => 'suspended',
            'email' => 'suspended.'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($seller, 'seller')
            ->get(route('seller.dashboard'))
            ->assertRedirect(route('home', ['login' => 1]));

        $this->assertGuest('seller');
    }
}
