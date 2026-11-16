<?php

namespace Tests\Feature\Policies;

use App\Enums\AdminRole;
use App\Models\Admin\Admin;
use App\Support\Policies\PolicyRepository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Public policy pages, the public policy API and the internal SOP boundary. */
class PolicyIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function publicPolicies(): array
    {
        return [
            'privacy' => ['privacy-policy', 'Who we are and scope'],
            'terms' => ['terms-of-service', 'Application and hierarchy'],
            'buyer protection' => ['buyer-protection', 'Parcel Not Received'],
            'seller' => ['seller-agreement', 'Ready for Pickup'],
            'logistics' => ['logistics-agreement', 'Physical custody and parcel integrity'],
            'rider' => ['rider-courier-rules', 'Transport Courier duties'],
        ];
    }

    #[DataProvider('publicPolicies')]
    public function test_public_policy_is_readable_without_login_with_version_and_navigation(string $slug, string $marker): void
    {
        $response = $this->get(route('policies.show', $slug))->assertOk();
        $html = $response->getContent();

        $response->assertSee('Version 1.0')->assertSee('On this page', false);
        $this->assertStringContainsString($marker, html_entity_decode(strip_tags($html)));
        preg_match_all('/href="#([a-z0-9-]+)"/', $html, $anchors);
        $this->assertNotEmpty($anchors[1]);
        foreach (array_unique($anchors[1]) as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, $id);
        }
    }

    public function test_unconfirmed_operator_fields_are_flagged_and_no_effective_date_or_identity_is_invented(): void
    {
        $html = $this->get(route('policies.show', 'privacy-policy'))->assertOk()->getContent();

        $this->assertStringContainsString('data-policy-tbc', $html);
        $this->assertStringContainsString('Effective date to be announced', $html);
        $this->assertStringNotContainsString('[INSERT', $html);
        $this->assertStringNotContainsString('Publication fields', $html);
        $this->assertStringNotContainsString('Replace every', $html);
    }

    public function test_internal_documents_are_not_public(): void
    {
        $this->get('/policies/admin-sop')->assertNotFound();
        $this->get('/policies/publication-readiness')->assertNotFound();
        $this->getJson('/api/policies/admin-sop')->assertNotFound();

        $slugs = collect($this->getJson('/api/policies')->assertOk()->json('data'))->pluck('slug')->all();
        $this->assertNotContains('admin-sop', $slugs);
        $this->assertCount(6, $slugs);
    }

    public function test_internal_sop_requires_an_admin_login_and_role(): void
    {
        $this->get(route('admin.policies.internal-sop'))->assertRedirect();

        $moderator = Admin::factory()->role(AdminRole::Moderator)->create();
        $this->actingAs($moderator, 'admin')->get(route('admin.policies.internal-sop'))->assertForbidden();

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin')->get(route('admin.policies.internal-sop'))
            ->assertOk()->assertSee('Internal');
    }

    public function test_api_returns_the_same_version_and_hash_as_the_web_page(): void
    {
        $doc = app(PolicyRepository::class)->findPublic('terms-of-service');

        $json = $this->getJson('/api/policies/terms-of-service')->assertOk()->json('data');

        $this->assertSame($doc->version, $json['version']);
        $this->assertSame($doc->sha256, $json['sha256']);
        $this->assertSame(64, strlen($json['sha256']));
        $this->assertStringContainsString('Application and hierarchy', $json['markdown']);
        $this->assertStringNotContainsString('[INSERT', $json['markdown']);
        $this->get(route('policies.show', 'terms-of-service'))->assertSee('Version '.$doc->version);
    }

    public function test_api_policy_endpoints_need_no_token_and_do_not_change_other_api_contracts(): void
    {
        $this->getJson('/api/policies')->assertOk()->assertJsonStructure(['data' => [['slug', 'title', 'version', 'effective_date', 'sha256']]]);
        $this->getJson('/api/buyer/orders')->assertUnauthorized();
    }

    public function test_every_policy_link_on_the_site_points_to_a_real_page(): void
    {
        foreach (['/', route('register.type'), route('buyer.help.index'), route('buyer.pages.contact')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            preg_match_all('#href="[^"]*?/policies/([a-z0-9-]+)"#', $html, $m);
            foreach (array_unique($m[1]) as $slug) {
                $this->get(route('policies.show', $slug))->assertOk();
            }
        }
    }

    public function test_checkout_shows_buyer_protection_summary_without_extra_acceptance(): void
    {
        $html = view('Policies._links', ['context' => 'checkout'])->render();

        $this->assertStringContainsString('seven calendar days', $html);
        $this->assertStringContainsString('not limited', $html);
        $this->assertStringContainsString(route('policies.show', 'buyer-protection'), $html);
        $this->assertStringNotContainsString('type="checkbox"', $html);
    }

    public function test_seller_summary_does_not_hardcode_a_commission_rate(): void
    {
        $html = view('Policies._links', ['context' => 'seller-account'])->render();

        $this->assertStringContainsString('rate schedule', $html);
        $this->assertDoesNotMatchRegularExpression('/\d+(\.\d+)?\s?%/', $html);
    }

    public function test_every_configured_document_exists_and_has_a_version(): void
    {
        foreach (array_keys(config('policies.documents')) as $slug) {
            $this->assertNotSame('unversioned', app(PolicyRepository::class)->find($slug)->version, $slug);
        }
    }
}
