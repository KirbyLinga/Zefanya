<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\Admin;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class InvalidAdminRoleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_saving_an_unknown_admin_role_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid admin role');

        Admin::factory()->create(['role' => 'typo_admin']);
    }
}
