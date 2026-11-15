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

    public function test_each_registration_form_links_its_own_policy_and_the_privacy_policy(): void
    {
        $html = $this->get(route('register.type'))->assertOk()->getContent();

        foreach ([
            'terms-of-service' => 'Terms of Service',
            'seller-agreement' => 'Seller Agreement',
            'logistics-agreement' => 'Logistics Provider Agreement',
            'rider-courier-rules' => 'Rider and Transport Courier Rules',
        ] as $slug => $label) {
            $this->assertMatchesRegularExpression('#href="'.preg_quote(route('policies.show', $slug), '#').'"[^>]*>'.preg_quote($label, '#').'</a>#', $html, $label);
        }
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'href="'.route('policies.show', 'privacy-policy').'"'));
        // The acceptance checkboxes are unchanged.
        foreach (['buyerTerms', 'sellerTerms', 'logisticsTerms', 'riderTerms'] as $checkbox) {
            $this->assertStringContainsString('id="'.$checkbox.'"', $html);
        }
    }
}
