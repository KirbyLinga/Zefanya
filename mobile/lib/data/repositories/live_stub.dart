// lib/data/repositories/live_stub.dart
//
// The base every live (network) repository shares.
//
// ## Why a live repository is allowed to throw
//
// The Laravel API does not exist yet — no Sanctum, no `routes/api.php`. So a
// live repository cannot be *implemented*; it can only be *specified*. The
// choice this file makes is to specify it loudly rather than plausibly:
// every unimplemented method throws [ApiNotImplementedException] naming the
// endpoint and the section of `docs/api-contract.md` that defines it.
//
// The alternative — writing request-and-parse code against a contract nobody
// has implemented — was rejected on purpose. It would look like progress, it
// could not be tested, and its guesses (a field name, an envelope, a status
// string) would be indistinguishable from decisions when the API lands. A
// thrown exception naming the missing endpoint is worth more than a plausible
// function that has never once run.
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
  /// Returns `Never` so it can stand in for any return type. Each call site
  /// also records the contract section, which is what makes the failure
  /// actionable rather than merely accurate.
  Never notWired(String endpoint, {String? section}) =>
      throw ApiNotImplementedException(endpoint, contractSection: section);
}