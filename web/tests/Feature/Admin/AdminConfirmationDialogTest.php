<?php

namespace Tests\Feature\Admin;

use Tests\TestCase;

class AdminConfirmationDialogTest extends TestCase
{
    public function test_admin_layout_includes_an_accessibly_named_shared_modal_dialog(): void
    {
        $layout = file_get_contents(resource_path('views/Admin/Layouts/app.blade.php'));
        $component = file_get_contents(resource_path('views/Components/confirm-dialog.blade.php'));

        $this->assertStringContainsString("@include('Components.confirm-dialog')", $layout);
        $this->assertStringContainsString("setAttribute('role', 'dialog')", $component);
        $this->assertStringContainsString("setAttribute('aria-modal', 'true')", $component);
        $this->assertStringContainsString("setAttribute('aria-labelledby', 'app-confirm-title')", $component);
        $this->assertStringContainsString("setAttribute('aria-describedby', 'app-confirm-message')", $component);
        $this->assertStringContainsString('data-cancel', $component);
        $this->assertStringContainsString('data-ok', $component);
    }

    public function test_admin_native_confirmations_use_record_specific_shared_dialogs(): void
    {
        $views = [
            'Admin/Registrations/index.blade.php' => ['Approve Registration', 'registration for'],
            'Admin/Products/index.blade.php' => ['Restrict Product', 'Product #'],
            'Admin/Users/index.blade.php' => ['Suspend ', 'Account #'],
            'Admin/Returns/index.blade.php' => ['Close Return', 'Return #'],
            'Admin/Incidents/index.blade.php' => ['Confirm Lost Parcels', 'selected parcels'],
            'Admin/Incidents/detail.blade.php' => ['Record Resolution', 'Incident #'],
            'Admin/Hubs/index.blade.php' => ['Approve Hub', 'Hub #'],
            'Admin/Vouchers/index.blade.php' => ['Delete Voucher', 'voucher'],
            'Admin/LostParcels/index.blade.php' => ['Record Payment', 'does not transfer money'],
        ];

        foreach ($views as $view => $needles) {
            $contents = file_get_contents(resource_path('views/'.$view));
            $this->assertStringContainsString('data-app-confirm', $contents, $view);
            $this->assertStringNotContainsString('confirm(', $contents, $view);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $contents, $view);
            }
        }

        $payouts = file_get_contents(resource_path('views/Admin/SellerPayouts/index.blade.php'));
        $this->assertStringContainsString('id="payout-review"', $payouts);
        $this->assertStringContainsString('data-review-amount', $payouts);
        $this->assertStringContainsString('data-review-reference', $payouts);
    }
}
