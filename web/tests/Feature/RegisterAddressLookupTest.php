<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for the buyer registration address step.
 *
 * The buyer register modal does NOT own the PSGC cascading dropdowns: it reads
 * window.zfAddressLookup at init (see buyer-register-modal.js) and skips the
 * province/municipality/barangay wiring when that module is missing — the
 * province select then stays on "Loading provinces..." forever. The module is
 * resources/js/buyer/address-lookup.js, so every view that hosts the modal must
 * load it, and must load it BEFORE buyer-register-modal.js.
 */
class RegisterAddressLookupTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Position of a Vite JS entry inside the rendered HTML. Build mode emits
     * build/assets/<entry>-<hash>.js and the dev server emits the source path,
     * so matching "<entry>...js" covers both — and deliberately ignores the CSS
     * entry that shares the component's name (buyer-register-modal.css).
     */
    private function jsEntryPosition(string $html, string $entry): int|false
    {
        $found = preg_match(
            '#'.preg_quote($entry, '#').'[^"\']*\.js#',
            $html,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        return $found === 1 ? $matches[0][1] : false;
    }

    private function assertLookupLoadsBeforeRegisterModal(string $html, string $page): void
    {
        $lookup = $this->jsEntryPosition($html, 'address-lookup');
        $modal = $this->jsEntryPosition($html, 'buyer-register-modal');

        $this->assertNotFalse($lookup, "{$page} must load resources/js/buyer/address-lookup.js.");
        $this->assertNotFalse($modal, "{$page} must load resources/js/buyer/buyer-register-modal.js.");
        $this->assertLessThan(
            $modal,
            $lookup,
            "{$page} must load address-lookup.js BEFORE buyer-register-modal.js."
        );
    }

    public function test_register_type_page_loads_the_address_lookup_before_the_buyer_modal(): void
    {
        $html = $this->get(route('register.type'))->assertOk()->getContent();

        $this->assertLookupLoadsBeforeRegisterModal($html, 'Auth/Register-Type');
    }

    public function test_buyer_layout_loads_the_address_lookup_before_the_buyer_modal(): void
    {
        // /buyer (buyer.home) is guest-accessible and renders Buyer/Layouts/app,
        // the layout that also @includes the register modal.
        $html = $this->get(route('buyer.home'))->assertOk()->getContent();

        $this->assertLookupLoadsBeforeRegisterModal($html, 'Buyer/Layouts/app');
    }
}
