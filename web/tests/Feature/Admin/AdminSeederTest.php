<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\Admin;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_PASSWORD = 'Str0ng-Seed-Pass!';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('admin.seed_email', 'owner@example.test');
        config()->set('admin.seed_password', self::VALID_PASSWORD);
    }

    public function test_it_refuses_to_run_without_a_seed_email(): void
    {
        config()->set('admin.seed_email', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_SEED_EMAIL');

        $this->seed(AdminSeeder::class);
    }

    public function test_it_refuses_to_run_with_an_invalid_seed_email(): void
    {
        config()->set('admin.seed_email', 'not-an-email');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_SEED_EMAIL');

        $this->seed(AdminSeeder::class);
    }

    public function test_it_refuses_to_run_without_a_seed_password(): void
    {
        config()->set('admin.seed_password', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_SEED_PASSWORD');

        $this->seed(AdminSeeder::class);
    }

    public function test_it_refuses_to_run_when_the_seed_password_is_empty(): void
    {
        config()->set('admin.seed_password', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_SEED_PASSWORD');

        $this->seed(AdminSeeder::class);
    }

    public function test_it_refuses_a_short_or_predictable_password(): void
    {
        config()->set('admin.seed_password', 'short');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least 12 characters');

        $this->seed(AdminSeeder::class);
    }

    public function test_it_does_not_create_an_admin_when_it_refuses(): void
    {
        config()->set('admin.seed_password', null);

        try {
            $this->seed(AdminSeeder::class);
            $this->fail('The seeder should have refused to run.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ADMIN_SEED_PASSWORD', $e->getMessage());
        }

        $this->assertSame(0, Admin::query()->count());
    }

    public function test_it_creates_the_super_admin_with_the_configured_credentials(): void
    {
        $this->seed(AdminSeeder::class);

        $admin = Admin::query()->where('email', 'owner@example.test')->firstOrFail();

        $this->assertSame('Super Zefanya Admin', $admin->name);
        $this->assertSame('super_admin', $admin->role);
        $this->assertTrue(Hash::check(self::VALID_PASSWORD, $admin->password));
    }

    public function test_it_normalizes_the_seed_email_to_lowercase(): void
    {
        config()->set('admin.seed_email', 'Owner@Example.Test');

        $this->seed(AdminSeeder::class);

        $this->assertSame(1, Admin::query()->where('email', 'owner@example.test')->count());
    }
}
