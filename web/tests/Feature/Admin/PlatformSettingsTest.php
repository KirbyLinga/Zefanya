<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\Admin;
use App\Models\Admin\PlatformSetting;
use App\Models\Admin\PlatformSettingHistory;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function setting(string $key = 'platform_name'): PlatformSetting
    {
        return PlatformSetting::query()->where('key', $key)->sole();
    }

    public static function authorizedRoles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('authorizedRoles')]
    public function test_authorized_admins_can_view_settings_and_the_navigation_link(string $role): void
    {
        $this->actingAs(Admin::factory()->create(['role' => $role]), 'admin')
            ->get(route('admin.settings.index'))->assertOk()
            ->assertSee('Public contact email')->assertSee('Change history')
            ->assertSee(route('admin.settings.index'), false)->assertSee('No settings have been changed.');

        $this->assertDatabaseCount('platform_settings', 3);
        $this->assertSame(config('app.name'), $this->setting()->value);
        $this->assertSame(config('mail.from.address'), $this->setting('contact_email')->value);
        $this->assertDatabaseCount('platform_setting_histories', 0);
    }

    public function test_guests_cannot_read_or_change_settings(): void
    {
        $setting = $this->setting();

        $this->get(route('admin.settings.index'))->assertRedirect(route('admin.login'));
        $this->patch(route('admin.settings.update', $setting), ['value' => 'Unauthorized', 'expected_value' => $setting->value])
            ->assertRedirect(route('admin.login'));

        $this->assertSame($setting->value, $setting->fresh()->value);
        $this->assertDatabaseCount('platform_setting_histories', 0);
    }

    public static function restrictedRoles(): array
    {
        return [['moderator'], ['support']];
    }

    #[DataProvider('restrictedRoles')]
    public function test_restricted_admins_cannot_read_or_change_settings(string $role): void
    {
        $setting = $this->setting();
        $this->actingAs(Admin::factory()->create(['role' => $role]), 'admin');

        $this->get(route('admin.settings.index'))->assertForbidden();
        $this->patch(route('admin.settings.update', $setting), ['value' => 'Unauthorized', 'expected_value' => $setting->value])->assertForbidden();
        $this->get(route('admin.dashboard'))->assertDontSee(route('admin.settings.index'), false);

        $this->assertSame($setting->value, $setting->fresh()->value);
        $this->assertDatabaseCount('platform_setting_histories', 0);
    }

    public static function otherGuards(): array
    {
        return [['buyer', Buyer::class], ['seller', Seller::class], ['logistics', LogisticsProvider::class], ['rider', Rider::class]];
    }

    #[DataProvider('otherGuards')]
    public function test_other_roles_cannot_read_or_change_settings(string $guard, string $model): void
    {
        $setting = $this->setting();
        $this->actingAs($model::factory()->create(), $guard);

        $this->get(route('admin.settings.index'))->assertRedirect(route('admin.login'));
        $this->patch(route('admin.settings.update', $setting), ['value' => 'Unauthorized', 'expected_value' => $setting->value])->assertRedirect(route('admin.login'));

        $this->assertSame($setting->value, $setting->fresh()->value);
        $this->assertDatabaseCount('platform_setting_histories', 0);
    }

    #[DataProvider('authorizedRoles')]
    public function test_setting_and_audit_persist_with_the_real_actor_and_time(string $role): void
    {
        $this->freezeTime();
        $admin = Admin::factory()->create(['role' => $role]);
        $otherAdmin = Admin::factory()->create();
        $setting = $this->setting();
        $oldValue = $setting->value;

        $this->actingAs($admin, 'admin')->patch(route('admin.settings.update', $setting), [
            'value' => 'Updated platform record', 'expected_value' => $oldValue,
            'admin_id' => $otherAdmin->id, 'setting_key' => 'commission_bps',
            'previous_value' => 'forged', 'created_at' => '2000-01-01 00:00:00',
        ])->assertRedirect(route('admin.settings.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('platform_settings', ['id' => $setting->id, 'key' => 'platform_name', 'value' => 'Updated platform record']);
        $this->assertDatabaseCount('platform_setting_histories', 1);
        $this->assertDatabaseHas('platform_setting_histories', [
            'platform_setting_id' => $setting->id, 'admin_id' => $admin->id, 'setting_key' => 'platform_name',
            'previous_value' => $oldValue, 'new_value' => 'Updated platform record', 'created_at' => now()->toDateTimeString(),
        ]);
        $this->get(route('admin.settings.index'))->assertSee('Updated platform record')->assertSee($admin->name)->assertSee(now()->format('Y-m-d H:i:s'));
    }

    public static function invalidValues(): array
    {
        return [
            'missing name' => ['platform_name', null], 'blank name' => ['platform_name', '   '],
            'non-string name' => ['platform_name', ['name']], 'long name' => ['platform_name', str_repeat('x', 101)],
            'missing email' => ['contact_email', null], 'invalid email' => ['contact_email', 'not-an-email'],
            'non-string email' => ['contact_email', ['admin@example.com']], 'long email' => ['contact_email', str_repeat('a', 245).'@example.com'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_cannot_change_settings_or_create_history(string $key, mixed $value): void
    {
        $setting = $this->setting($key);

        $this->actingAs(Admin::factory()->create(), 'admin')->from(route('admin.settings.index'))
            ->patch(route('admin.settings.update', $setting), ['value' => $value, 'expected_value' => $setting->value])
            ->assertRedirect(route('admin.settings.index'))->assertSessionHasErrors('value');

        $this->assertSame($setting->value, $setting->fresh()->value);
        $this->assertDatabaseCount('platform_setting_histories', 0);
    }

    public function test_contact_email_can_be_saved_without_changing_live_configuration(): void
    {
        $setting = $this->setting('contact_email');
        $originalMail = config('mail.from');
        $originalName = config('app.name');
        $originalTimezone = config('app.timezone');

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->patch(route('admin.settings.update', $setting), ['value' => 'contact@example.com', 'expected_value' => $setting->value])->assertSessionHasNoErrors();

        $this->assertSame('contact@example.com', $setting->fresh()->value);
        $this->assertSame($originalMail, config('mail.from'));
        $this->assertSame($originalName, config('app.name'));
        $this->assertSame($originalTimezone, config('app.timezone'));
        $this->assertDatabaseCount('platform_setting_histories', 1);
    }

    public function test_missing_expected_value_is_rejected(): void
    {
        $setting = $this->setting();

        $this->actingAs(Admin::factory()->create(), 'admin')->patch(route('admin.settings.update', $setting), ['value' => 'Updated record'])->assertSessionHasErrors('expected_value');

        $this->assertSame($setting->value, $setting->fresh()->value);
        $this->assertDatabaseCount('platform_setting_histories', 0);
    }

    public function test_unchanged_values_and_replayed_submissions_do_not_duplicate_history(): void
    {
        $setting = $this->setting();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->patch(route('admin.settings.update', $setting), ['value' => $setting->value, 'expected_value' => $setting->value])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('platform_setting_histories', 0);
        $payload = ['value' => 'New name', 'expected_value' => $setting->value];
        $this->patch(route('admin.settings.update', $setting), $payload)->assertSessionHasNoErrors();
        $this->patch(route('admin.settings.update', $setting), $payload)->assertSessionHasNoErrors();

        $this->assertSame('New name', $setting->fresh()->value);
        $this->assertDatabaseCount('platform_setting_histories', 1);
    }

    public function test_stale_edits_cannot_overwrite_a_later_admin_change(): void
    {
        $setting = $this->setting();
        $original = $setting->value;
        $firstAdmin = Admin::factory()->create();
        $secondAdmin = Admin::factory()->create();

        $this->actingAs($firstAdmin, 'admin')->patch(route('admin.settings.update', $setting), ['value' => 'First change', 'expected_value' => $original])->assertSessionHasNoErrors();
        $this->actingAs($secondAdmin, 'admin')->patch(route('admin.settings.update', $setting), ['value' => 'Stale change', 'expected_value' => $original])->assertSessionHasErrors('value');

        $this->assertSame('First change', $setting->fresh()->value);
        $this->assertDatabaseCount('platform_setting_histories', 1);
        $this->assertSame($firstAdmin->id, PlatformSettingHistory::query()->sole()->admin_id);
    }

    public static function protectedKeys(): array
    {
        return [['commission_bps'], ['automatic_completion_hours'], ['cancellation_timeout_hours'], ['voucher_stacking'], ['rider_capacity']];
    }

    #[DataProvider('protectedKeys')]
    public function test_operational_settings_are_not_exposed_for_editing(string $key): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->patch(route('admin.settings.update', $key), ['value' => 'changed', 'expected_value' => 'original'])->assertNotFound();

        $this->assertDatabaseCount('platform_settings', 3);
        $this->assertDatabaseCount('platform_setting_histories', 0);
    }

    public function test_a_database_row_does_not_make_an_unapproved_key_editable(): void
    {
        $setting = (new PlatformSetting)->forceFill(['key' => 'commission_bps', 'value' => '1000']);
        $setting->save();

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->patch(route('admin.settings.update', $setting), ['value' => '500', 'expected_value' => '1000'])->assertNotFound();
        $this->get(route('admin.settings.index'))->assertDontSee('commission_bps');

        $this->assertSame('1000', $setting->fresh()->value);
        $this->assertDatabaseCount('platform_setting_histories', 0);
    }

    public static function failedWrites(): array
    {
        return [['creating', PlatformSettingHistory::class], ['updating', PlatformSetting::class]];
    }

    #[DataProvider('failedWrites')]
    public function test_failed_writes_roll_back_both_setting_and_history(string $operation, string $model): void
    {
        Exceptions::fake();
        $setting = $this->setting();
        $event = 'eloquent.'.$operation.': '.$model;
        Event::listen($event, function (): never {
            throw new \RuntimeException('Storage unavailable.');
        });

        try {
            $this->actingAs(Admin::factory()->create(), 'admin')
                ->patch(route('admin.settings.update', $setting), ['value' => 'Must not persist', 'expected_value' => $setting->value])->assertServerError();
        } finally {
            Event::forget($event);
        }

        $this->assertSame($setting->value, $setting->fresh()->value);
        $this->assertDatabaseCount('platform_setting_histories', 0);
    }

    public static function historyMutations(): array
    {
        return [['update'], ['delete']];
    }

    #[DataProvider('historyMutations')]
    public function test_setting_history_cannot_be_edited_or_deleted(string $action): void
    {
        $setting = $this->setting();
        $this->actingAs(Admin::factory()->create(), 'admin')->patch(route('admin.settings.update', $setting), ['value' => 'Recorded value', 'expected_value' => $setting->value])->assertSessionHasNoErrors();
        $entry = PlatformSettingHistory::query()->sole();

        try {
            $action === 'update' ? $entry->forceFill(['new_value' => 'Forged'])->save() : $entry->delete();
            $this->fail('History mutation must be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cannot be', $exception->getMessage());
        }

        $this->assertDatabaseCount('platform_setting_histories', 1);
        $this->assertSame('Recorded value', $entry->fresh()->new_value);
    }

    public function test_changes_form_a_chain_and_are_escaped_in_the_history_page(): void
    {
        $setting = $this->setting();
        $original = $setting->value;
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->patch(route('admin.settings.update', $setting), ['value' => 'First change', 'expected_value' => $original])->assertSessionHasNoErrors();
        $script = '<script>alert(1)</script>';
        $this->patch(route('admin.settings.update', $setting), ['value' => $script, 'expected_value' => 'First change'])->assertSessionHasNoErrors();

        $entries = PlatformSettingHistory::query()->orderBy('id')->get();
        $this->assertSame([$original, 'First change'], $entries->pluck('previous_value')->all());
        $this->assertSame(['First change', $script], $entries->pluck('new_value')->all());
        $this->get(route('admin.settings.index'))->assertSee(e($script), false)->assertDontSee($script, false);
    }

    public function test_an_empty_legacy_contact_value_can_be_configured(): void
    {
        $setting = $this->setting('contact_email');
        $setting->forceFill(['value' => ''])->save();

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->patch(route('admin.settings.update', $setting), ['value' => 'contact@example.com', 'expected_value' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('contact@example.com', $setting->fresh()->value);
        $this->assertDatabaseHas('platform_setting_histories', ['setting_key' => 'contact_email', 'previous_value' => '', 'new_value' => 'contact@example.com']);
    }

    public function test_the_name_length_boundary_is_accepted(): void
    {
        $setting = $this->setting();
        $name = str_repeat('a', 100);

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->patch(route('admin.settings.update', $setting), ['value' => $name, 'expected_value' => $setting->value])
            ->assertSessionHasNoErrors();

        $this->assertSame($name, $setting->fresh()->value);
        $this->assertDatabaseCount('platform_setting_histories', 1);
    }

    public function test_history_is_paginated_newest_first_without_losing_older_changes(): void
    {
        $setting = $this->setting();
        $this->actingAs(Admin::factory()->create(), 'admin');
        for ($number = 1; $number <= 21; $number++) {
            $this->patch(route('admin.settings.update', $setting), ['value' => 'History entry '.$number, 'expected_value' => $setting->fresh()->value])
                ->assertSessionHasNoErrors();
        }

        $this->get(route('admin.settings.index'))->assertOk()
            ->assertViewHas('history', fn ($history): bool => $history->total() === 21 && $history->count() === 20 && $history->first()->new_value === 'History entry 21');
        $this->get(route('admin.settings.index', ['page' => 2]))->assertOk()
            ->assertViewHas('history', fn ($history): bool => $history->count() === 1 && $history->first()->new_value === 'History entry 1');

        $this->assertDatabaseCount('platform_setting_histories', 21);
    }
}
