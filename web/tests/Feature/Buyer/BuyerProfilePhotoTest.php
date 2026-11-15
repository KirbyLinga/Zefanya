<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Services\Buyer\BuyerAccountDeletionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Buyer Account Settings: change / remove the profile photo. */
class BuyerProfilePhotoTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');

        $this->buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now()->subYear(),
            'settings' => ['appearance' => 'dark'],
        ]);
        $this->actingAs($this->buyer, 'buyer');
    }

    public function test_account_settings_offers_a_profile_photo_upload(): void
    {
        $this->get(route('buyer.account.index'))->assertOk()
            ->assertSee('Profile photo')
            ->assertSee('Upload a profile photo')
            ->assertSee(route('buyer.account.photo.update'), false);
    }

    public function test_upload_stores_privately_keeps_other_settings_and_shows_the_photo(): void
    {
        $this->post(route('buyer.account.photo.update'), ['photo' => UploadedFile::fake()->image('me.jpg', 200, 200)])
            ->assertRedirect(route('buyer.account.index'))
            ->assertSessionHas('success', 'Profile photo updated.');

        $buyer = $this->buyer->fresh();
        $path = $buyer->profilePhotoPath();
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame('dark', $buyer->settings['appearance']);

        // A real request loads the buyer fresh; actingAs keeps the old instance.
        $this->actingAs($buyer, 'buyer');
        $this->get(route('buyer.account.index'))->assertOk()
            ->assertSee('Change profile photo')
            ->assertSee('Remove photo');
        // The navbar avatar shows the photo too, not only the initial.
        $this->get(route('buyer.home'))->assertOk()
            ->assertSee(route('buyer.account.photo.show', ['v' => md5($path)]), false);
        $this->get(route('buyer.account.photo.show'))->assertOk();
    }

    public function test_replacing_deletes_the_old_file_and_removing_restores_initials(): void
    {
        $this->post(route('buyer.account.photo.update'), ['photo' => UploadedFile::fake()->image('a.png', 100, 100)]);
        $first = $this->buyer->fresh()->profilePhotoPath();

        $this->post(route('buyer.account.photo.update'), ['photo' => UploadedFile::fake()->image('b.png', 100, 100)]);
        $second = $this->buyer->fresh()->profilePhotoPath();
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);

        $this->delete(route('buyer.account.photo.destroy'))->assertRedirect(route('buyer.account.index'));
        $buyer = $this->buyer->fresh();
        $this->assertNull($buyer->profilePhotoPath());
        Storage::disk('local')->assertMissing($second);
        $this->assertSame('dark', $buyer->settings['appearance']);
        $this->get(route('buyer.account.photo.show'))->assertNotFound();
    }

    public function test_invalid_files_are_refused_without_touching_the_profile_form(): void
    {
        $this->post(route('buyer.account.photo.update'), ['photo' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors(['photo'], null, 'photo');
        $this->post(route('buyer.account.photo.update'), ['photo' => UploadedFile::fake()->image('big.jpg', 300, 300)->size(3000)])
            ->assertSessionHasErrors(['photo'], null, 'photo');

        $this->assertNull($this->buyer->fresh()->profilePhotoPath());
        $this->assertSame([], Storage::disk('local')->allFiles('buyer-photos'));
    }

    public function test_a_photo_is_only_served_to_its_owner_and_guests_are_redirected(): void
    {
        $this->post(route('buyer.account.photo.update'), ['photo' => UploadedFile::fake()->image('me.jpg', 100, 100)]);
        $this->actingAs($this->buyer->fresh(), 'buyer')->get(route('buyer.account.photo.show'))->assertOk();

        $other = Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->actingAs($other, 'buyer')->get(route('buyer.account.photo.show'))->assertNotFound();

        auth('buyer')->logout();
        $this->get(route('buyer.account.photo.show'))->assertRedirect();
    }

    public function test_account_deletion_removes_the_photo_file(): void
    {
        $this->post(route('buyer.account.photo.update'), ['photo' => UploadedFile::fake()->image('me.jpg', 100, 100)]);
        $path = $this->buyer->fresh()->profilePhotoPath();

        app(BuyerAccountDeletionService::class)->delete($this->buyer->fresh());

        Storage::disk('local')->assertMissing($path);
        $this->assertNull($this->buyer->fresh()->settings);
    }
}
