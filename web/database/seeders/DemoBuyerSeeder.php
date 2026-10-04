<?php

namespace Database\Seeders;

use App\Models\Buyer\Buyer;
use Illuminate\Database\Seeder;

/**
 * One buyer who can sign in to a live build.
 *
 * ## Why a credential is seeded, and why that needs the paragraph
 *
 * Everything else in the demo data is content — shops, goods, prices, photos.
 * This is a *login*, and a login is the one fixture that is also a way in. It
 * earns its place because without it nothing else is reachable: a registration
 * made through the app lands at `pending_approval` with no admin to approve it,
 * so a live build had no account that could reach the shell at all, and every
 * screen behind it — the catalogue especially — could only be verified over HTTP.
 *
 * Three rules keep it a dev affordance rather than a back door:
 *
 *  * **It refuses to run in production.** Not "is not run": refuses, with a
 *    warning, before writing anything. The app has the same shape of guard for
 *    its test-only controls (`kShowDevTools`), for the same reason — a comment
 *    saying "dev only" protects nothing at runtime.
 *  * **The password is `password`**, the value every factory and test account in
 *    this project already uses, so it reads as a fixture rather than as something
 *    plausible.
 *  * **It is idempotent**, keyed on the email, so re-seeding neither duplicates
 *    the account nor resets a password changed while testing.
 */
class DemoBuyerSeeder extends Seeder
{
    /** The one thing in this project that is both a fixture and a way in. */
    public const EMAIL = 'buyer@zefanya.test';

    /** Documented above: the same password every factory here uses. */
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn(
                'DemoBuyerSeeder: skipped — a demo credential must never exist in production.'
            );

            return;
        }

        // `firstOrNew`, with the password written only for a row that does not
        // exist yet: re-seeding has to create the account when it is missing
        // without resetting one somebody has been using.
        $buyer = Buyer::firstOrNew(['email' => self::EMAIL]);

        $buyer->fill([
            'last_name' => 'Santos',
            'first_name' => 'Marites',
            'sex' => 'female',
            'contact_no' => '09171234567',
            'birthday' => '1995-06-20',
            'age' => 31,
            'address_mode' => 'manual',
            'province_name' => 'Metro Manila',
            'municipality_name' => 'Quezon City',
            'barangay_name' => 'Pinyahan',
            'street' => 'Maginhawa Street',
            'house_number' => '42',
            'upload_id_path' => 'buyers/demo/buyer-id.jpg',
            // Approved — and verified on the record below. The API's sign-in
            // gate answers for both, and an account that is one but not the
            // other is a state no real flow produces.
            'status' => 'approved',
        ]);

        if (! $buyer->exists) {
            $buyer->password = self::PASSWORD;
        }

        $buyer->save();

        // Neither timestamp is fillable — the registration flow writes them with
        // `forceFill` too — so they are written the same way here, and kept if
        // they already exist, so re-seeding never rewrites when an account was
        // verified.
        $buyer->forceFill([
            'email_verified_at' => $buyer->email_verified_at ?? now(),
            'approved_at' => $buyer->approved_at ?? now(),
        ])->save();

        $this->command?->info(
            'Demo buyer: '.self::EMAIL.' / '.self::PASSWORD.' (approved, so a live build can sign in).'
        );
    }
}
