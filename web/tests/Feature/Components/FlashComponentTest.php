<?php

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class FlashComponentTest extends TestCase
{
    public function test_success_error_and_warning_use_their_expected_live_region_roles(): void
    {
        $success = Blade::render('<x-flash type="success" message="Saved" />');
        $error = Blade::render('<x-flash type="error" message="Could not save" />');
        $warning = Blade::render('<x-flash type="warning" message="Review this" />');
        $urgentWarning = Blade::render('<x-flash type="warning" urgency="assertive" message="Action required" />');

        $this->assertStringContainsString('role="status"', $success);
        $this->assertStringContainsString('data-flash-type="success"', $success);
        $this->assertStringContainsString('role="alert"', $error);
        $this->assertStringContainsString('data-flash-type="error"', $error);
        $politeError = Blade::render('<x-flash type="error" urgency="polite" message="Still an error" />');
        $this->assertStringContainsString('role="alert"', $politeError);
        $this->assertStringContainsString('role="status"', $warning);
        $this->assertStringContainsString('role="alert"', $urgentWarning);
    }

    public function test_flash_can_omit_icon_and_dismiss_button_and_keeps_slot_content(): void
    {
        $html = Blade::render('<x-flash type="error" message="Checkout could not continue" :icon="false" :dismissible="false"><a href="/orders">Check orders</a></x-flash>');

        $this->assertStringContainsString('Checkout could not continue', $html);
        $this->assertStringContainsString('<a href="/orders">Check orders</a>', $html);
        $this->assertStringNotContainsString('<button type="button" data-flash-dismiss', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString('aria-live=', $html);
    }
}
