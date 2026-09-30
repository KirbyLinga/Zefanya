// lib/data/repositories/live_stub.dart
//
// The base every live (network) repository shares.
//
// ## Why some live methods are still allowed to throw
//
// The Laravel API now exists for the buyer half of registration and for sign-in
// (`web/routes/api.php`), and [LiveAuthRepository] calls it for real — multipart
// upload and all. Everything else (products, orders, cart, chat, courier jobs)
// has no endpoint yet, and those methods throw [ApiNotImplementedException]
// naming the endpoint instead of containing request code that has never run.
//
// That rule is what this file is for, and it is worth restating now that part of
// the API is real: request-and-parse code written against an endpoint nobody has
// implemented would look like progress, could not be tested, and its guesses (a
// field name, an envelope, a status string) would be indistinguishable from
// decisions when the endpoint landed. The OTP body is the proof — the field is
// named `otp` because that was *read* off the frozen web client
// (`resources/js/buyer/buyer-register-modal.js`), not because it was plausible.
//
// ## What this base actually buys
//
//  * one place that holds the client, so every live repository has the same
//    shape and the same single dependency;
//  * [notWired] as a `Never`-returning call, so an implementation reads as
//    `return notWired('GET /products');` — type-correct against any return
//    type, with no unreachable-code warning and nothing to remember;

import '../api/api_client.dart';
import '../api/api_exception.dart';

abstract class LiveRepository {
  LiveRepository(this.client);

  /// The one network entry point. Shared, not per-repository.
  final ApiClient client;

  /// Throws, naming what is missing.
  ///
  /// Returns `Never` so it can stand in for any return type. Naming the endpoint
  /// is what makes the failure actionable; `section` is passed only where the
  /// missing piece is specified by a document this repository does not contain
  /// (`docs/api-contract.md`). For a route that already exists in a route file
  /// that *is* here, a section would be a pointer to nothing.
  Never notWired(String endpoint, {String? section}) =>
      throw ApiNotImplementedException(endpoint, contractSection: section);
}