<?php

namespace Tests\Feature\Rider;

use App\Models\Rider\Rider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Rows saved before Rider moved folders hold the old class name, and they must
 * still belong to the rider.
 */
class RiderStoredClassNameTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const STORED_CLASS = 'App\Models\Rider';

    public function test_a_notification_saved_under_the_old_class_name_still_reaches_the_rider(): void
    {
        $rider = Rider::factory()->create();
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => self::STORED_CLASS,
            'notifiable_id' => $rider->id,
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, $rider->notifications()->count());
    }

    public function test_an_api_token_saved_under_the_old_class_name_still_finds_its_rider(): void
    {
        $rider = Rider::factory()->create();
        $tokenId = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => self::STORED_CLASS,
            'tokenable_id' => $rider->id,
            'name' => 'old-token',
            'token' => hash('sha256', 'old-token'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($rider->is(PersonalAccessToken::query()->findOrFail($tokenId)->tokenable));
    }

    public function test_new_rows_keep_the_same_class_name(): void
    {
        $token = Rider::factory()->create()->createToken('new-token');

        $this->assertSame(self::STORED_CLASS, $token->accessToken->tokenable_type);
    }
}
