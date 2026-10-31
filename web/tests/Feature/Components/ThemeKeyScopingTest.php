<?php

namespace Tests\Feature\Components;

use App\Enums\LogisticsProviderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Dark mode is a per-role preference. Every shell persists it under its OWN
 * localStorage key, declared once on the <html> tag (data-theme-key), so
 * toggling dark mode in one panel cannot switch another. All four shells used
 * to read the single shared 'zf-theme' key, which is why a toggle in the
 * buyer/seller/logistics UI leaked into the other panels.
 *
 * These assertions guard the per-shell key wiring itself.
 */
class ThemeKeyScopingTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Each shell's rendered HTML, keyed by shell name. Guest-accessible shells
     * are fetched first so no actingAs() state is in play for them.
     *
     * @return array<string, string>
     */
    private function shellHtml(): array
    {
        $seller = Seller::factory()->create();
        $provider = LogisticsProvider::factory()->create([
            'status' => LogisticsProviderStatus::Approved->value,
            'email_verified_at' => now(),
        ]);

        return [
            'public' => $this->get('/')->assertOk()->getContent(),
            'buyer' => $this->get(route('buyer.home'))->assertOk()->getContent(),
            'logistics' => $this->actingAs($provider, 'logistics')
                ->get(route('logistics.dashboard'))->assertOk()->getContent(),
            'seller' => $this->actingAs($seller, 'seller')
                ->get(route('seller.dashboard'))->assertOk()->getContent(),
        ];
    }

    public function test_each_shell_uses_only_its_own_theme_key(): void
    {
        $html = $this->shellHtml();

        $keys = [
            'public' => 'public.theme',
            'buyer' => 'buyer.theme',
            'logistics' => 'logistics.theme',
            'seller' => 'seller.theme',
        ];

        foreach ($keys as $shell => $ownKey) {
            $this->assertStringContainsString(
                'data-theme-key="'.$ownKey.'"',
                $html[$shell],
                "The {$shell} shell must declare its own theme key ({$ownKey}).",
            );
        }

        // A key belonging to another panel in this shell's markup is exactly the
        // cross-panel leak this test guards against (e.g. a copy-paste that
        // makes the buyer shell read seller.theme).
        foreach ($keys as $shell => $ownKey) {
            foreach ($keys as $otherShell => $otherKey) {
                if ($shell === $otherShell) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    'data-theme-key="'.$otherKey.'"',
                    $html[$shell],
                    "The {$shell} shell must not use the {$otherShell} theme key ({$otherKey}).",
                );
            }
        }
    }
}
