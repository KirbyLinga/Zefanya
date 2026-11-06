<?php

namespace Tests\Feature\Console;

use Database\Seeders\ScaleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ScaleSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_refuses_any_database_not_named_scale(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refuses to write');

        (new ScaleSeeder)->run('small');
    }

    public function test_the_scale_database_is_always_a_separate_name(): void
    {
        $this->assertSame('zefanya_scale', ScaleSeeder::databaseNameFor('Zefanya'));
        $this->assertSame('zefanya_scale', ScaleSeeder::databaseNameFor('zefanya_scale'));
    }

    public function test_the_command_refuses_an_unknown_tier_and_a_non_mysql_connection(): void
    {
        $this->artisan('scale:seed', ['--tier' => 'huge'])->assertFailed();
        $this->artisan('scale:seed', ['--tier' => 'small'])->assertFailed();
    }
}
