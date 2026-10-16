# Pass 2 — Test harness red baseline → GREEN after Passes 3–6

- Red (2026-10-16): `php artisan test tests/Feature/RiderRegistration --compact` → 11 failed (routes `logistics.riders.*` / `rider.activate` undefined, tables `riders` / `rider_activation_tokens` missing). Harness proof: register-type page asserted no RIDER card (passed) while `logistics.riders.create` failed — harness sound, feature missing.
- Green (2026-10-16): `vendor/bin/phpunit tests/Unit/RiderValidationTest.php tests/Feature/RiderRegistration` → **OK (44 tests, 193 assertions)**: 32 spec + 6 unit + 6 hardening. Pint clean.
- Kit: `tests/fixtures/rider/generate.php` + 10 magic-byte exact-size files; `RiderTestBase` (unique providers, Manila clock, mail/storage fakes, payload, token helper with `token_hash`); 8 test classes; note Pint lowercases test methods to `test_tc_rid_XXX_…` (still ID-prefixed).

