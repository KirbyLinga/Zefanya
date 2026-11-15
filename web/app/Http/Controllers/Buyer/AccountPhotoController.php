<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The buyer's profile photo on Account Settings.
 *
 * A personal photo, so it is kept on the private `local` disk and streamed
 * only to its owner (no public URL). Its path lives in the existing `settings`
 * JSON column under `profile_photo`; no schema change. A replaced or removed
 * file is deleted once the new state is committed.
 */
class AccountPhotoController extends Controller
{
    public const DISK = 'local';

    /** GET /buyer/account/photo — the signed-in buyer's own photo. */
    public function show(Request $request): StreamedResponse
    {
        $path = $request->user('buyer')->profilePhotoPath();
        abort_if($path === null || ! Storage::disk(self::DISK)->exists($path), 404);

        return Storage::disk(self::DISK)->response($path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST /buyer/account/photo — upload or replace the photo. */
    public function update(Request $request): RedirectResponse
    {
        // Own error bag, so a photo problem is not shown as a profile-form error.
        $request->validateWithBag('photo', [
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64'],
        ], [
            'photo.required' => 'Choose a photo to upload.',
            'photo.image' => 'The profile photo must be an image.',
            'photo.mimes' => 'Use a JPG, PNG or WebP photo.',
            'photo.max' => 'The profile photo must be 2 MB or smaller.',
            'photo.dimensions' => 'The profile photo must be at least 64 × 64 pixels.',
        ]);

        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');
        $path = $request->file('photo')->store('buyer-photos/'.$buyer->id, self::DISK);
        abort_if($path === false, 503, 'The photo could not be saved. Please try again.');

        try {
            $previous = $this->setPhoto($buyer, $path);
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);
            throw $exception;
        }
        $this->deleteFile($previous, $buyer->id);

        return redirect()->route('buyer.account.index')->with('success', 'Profile photo updated.');
    }

    /** DELETE /buyer/account/photo — remove the photo (initials are shown again). */
    public function destroy(Request $request): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');
        $this->deleteFile($this->setPhoto($buyer, null), $buyer->id);

        return redirect()->route('buyer.account.index')->with('success', 'Profile photo removed.');
    }

    /**
     * Stores [path] under settings.profile_photo on a locked row, keeping every
     * other setting, and returns the path it replaced.
     */
    private function setPhoto(Buyer $buyer, ?string $path): ?string
    {
        return DB::transaction(function () use ($buyer, $path): ?string {
            $row = Buyer::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
            $previous = $row->profilePhotoPath();
            $settings = is_array($row->settings) ? $row->settings : [];
            if ($path === null) {
                unset($settings['profile_photo']);
            } else {
                $settings['profile_photo'] = $path;
            }
            $row->forceFill(['settings' => $settings === [] ? null : $settings])->save();

            return $previous;
        });
    }

    private function deleteFile(?string $path, int $buyerId): void
    {
        if ($path === null) {
            return;
        }

        try {
            Storage::disk(self::DISK)->delete($path);
        } catch (Throwable $exception) {
            Log::warning('Could not delete a replaced buyer profile photo.', ['buyer_id' => $buyerId]);
            report($exception);
        }
    }
}
