<?php

namespace Tests\Feature\Buyer;

use App\Models\Shared\Category;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Stage 0 (guest land): migrate archive columns, shared login modal, footer links,
 * and a working category grid. Guards the audit L-1 / L-3 / L-4 fixes.
 */
class Stage0GuestLandingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_buyer_home_and_products_load_after_archive_columns_exist(): void
    {
        $this->assertTrue(
            Schema::hasColumn('sellers', 'archived_at'),
            'sellers.archived_at must exist (run pending migrations on MySQL).'
        );

        $this->get(route('buyer.home'))->assertOk();
        $this->get(route('buyer.products.index'))->assertOk();
    }

    public function test_shared_login_modal_renders_email_form_on_landing_page(): void
    {
        $this->get(route('home', ['login' => 1]))
            ->assertOk()
            ->assertViewIs('LandingPage.index')
            ->assertSee('id="loginModalOverlay"', false)
            ->assertSee('Welcome back', false)
            ->assertSee('name="email"', false)
            ->assertDontSee('How would you like to get started?', false);
    }

    public function test_public_footer_links_point_at_real_pages(): void
    {
        $html = $this->get(route('register.type'))->assertOk()->getContent();

        $this->assertStringContainsString(route('buyer.help.index', absolute: false), $html);
        $this->assertStringContainsString(route('buyer.pages.privacy-policy', absolute: false), $html);
        $this->assertStringContainsString(route('buyer.pages.terms-of-service', absolute: false), $html);
        $this->assertStringContainsString(route('buyer.pages.contact', absolute: false), $html);
        $this->assertStringContainsString(route('buyer.pages.shipping-returns', absolute: false), $html);
    }

    public function test_categories_index_renders_category_grid(): void
    {
        $category = Category::query()->first()
            ?? Category::query()->create(['icon' => 'book-open', 'label' => 'Books and Media']);

        $this->get(route('buyer.categories.index'))
            ->assertOk()
            ->assertViewIs('Buyer.Categories.index')
            ->assertViewHas('categories')
            ->assertSee($category->label, false)
            ->assertSee(route('buyer.categories.show', $category, absolute: false), false);
    }

    public function test_help_center_is_reachable_by_guests(): void
    {
        $this->get(route('buyer.help.index'))->assertOk();
    }
}
