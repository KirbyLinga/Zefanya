<?php

namespace Tests\Feature\Buyer;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Public policy and help pages describe the implemented system, not generic promises. */
class PolicyPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** Claims the platform does not implement. */
    private const OUTDATED = [
        '14-day', '14 days', 'Use License', 'processed securely by third parties', 'tracking number via email',
        '7-10 business days', '3-5 business days', 'unused and in original packaging', 'within 24 hours', '9 AM',
        'chat button',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function pages(): array
    {
        return [
            'shipping and returns' => ['buyer.pages.shipping-returns', ['within seven days of delivery', 'three days', 'review a rejection once', 'Cash on delivery', 'does not remove any rights']],
            'privacy policy' => ['buyer.pages.privacy-policy', ['Republic Act No. 10173', 'Logistics Providers:', 'Riders:', 'Delivery evidence:', 'Financial records:', 'National Privacy Commission', 'Deleted buyer']],
            'terms of service' => ['buyer.pages.terms-of-service', ['Buyer terms and protection', 'Seller terms and protection', 'Logistics Provider agreement', 'Rider code of conduct', 'Disputes and account enforcement', 'no automatic fraud score']],
            'help' => ['buyer.help.index', ['My Orders', 'seven days from delivery', 'review the rejection once', 'cash on delivery', 'support@zefanya.shop']],
            'contact' => ['buyer.pages.contact', ['support@zefanya.shop', 'Go to My Orders']],
            'about' => ['buyer.pages.about', ['within 7 days of delivery']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('pages')]
    public function test_page_shows_current_rules_and_no_outdated_claims(string $route, array $expected): void
    {
        $response = $this->get(route($route))->assertOk();

        foreach ($expected as $text) {
            $response->assertSee($text);
        }
        foreach (self::OUTDATED as $text) {
            $this->assertStringNotContainsStringIgnoringCase($text, $response->getContent(), "Outdated claim on {$route}: {$text}");
        }
    }

    public function test_terms_has_a_table_of_contents_with_a_target_for_every_role(): void
    {
        $html = $this->get(route('buyer.pages.terms-of-service'))->assertOk()->getContent();

        foreach (['general', 'buyers', 'sellers', 'logistics', 'riders', 'disputes', 'contact'] as $anchor) {
            $this->assertStringContainsString('id="'.$anchor.'"', $html);
            $this->assertStringContainsString('href="#'.$anchor.'"', $html);
        }
        $this->assertStringContainsString('aria-label="On this page"', $html);
    }

    public function test_each_registration_form_links_its_own_terms_section_and_the_privacy_policy(): void
    {
        $html = $this->get(route('register.type'))->assertOk()->getContent();
        $terms = route('buyer.pages.terms-of-service');

        foreach ([
            'buyers' => 'Terms of Service for Buyers',
            'sellers' => 'Terms of Service for Sellers',
            'logistics' => 'Logistics Provider Agreement',
            'riders' => 'Rider Terms and Code of Conduct',
        ] as $anchor => $label) {
            $this->assertMatchesRegularExpression('#href="'.preg_quote($terms.'#'.$anchor, '#').'"[^>]*>'.preg_quote($label, '#').'</a>#', $html, $label);
        }
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'href="'.route('buyer.pages.privacy-policy').'"'));
        // The acceptance checkboxes are unchanged.
        foreach (['buyerTerms', 'sellerTerms', 'logisticsTerms', 'riderTerms'] as $checkbox) {
            $this->assertStringContainsString('id="'.$checkbox.'"', $html);
        }
    }
}
