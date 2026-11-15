// lib/config/backend_profile.dart
//
// Which backend the live build talks to, and what that backend can do.
//
// Two profiles exist, chosen at build time:
//
//   * `courier-demo` (default) — the Laravel fork this app was built against.
//     Everything the app ever called exists there. Behaviour is unchanged.
//   * `webzefanya` — the protected main WebZefanya application. It exposes a
//     smaller, different API (see `data/api/webzefanya_contract.dart`), so the
//     app gates, adapts or hides what WebZefanya does not provide.
//
// Selecting a profile never turns the live API on by itself: `USE_LIVE_API`
// still decides mock vs live, and mock mode is identical for both profiles.
//
//   flutter run --dart-define=USE_LIVE_API=true \
//     --dart-define=zefanya.backend=webzefanya \
//     --dart-define=zefanya.webzefanya.apiBaseUrl=https://host/api
//
// Writes to a WebZefanya server are disabled unless the build also passes
// `--dart-define=zefanya.webzefanya.allowWrites=true` (there is no staging
// backend, so nothing may change production data by accident).

import 'dev_flags.dart' show kApiBaseUrl, kUseLiveApi;

/// The backend a live build is configured for.
enum BackendProfile {
  /// The courier demo's own Laravel fork. The historical default.
  courierDemo,

  /// The protected main WebZefanya application.
  webzefanya;

  /// The value used in `--dart-define=zefanya.backend=...`.
  String get wireName => switch (this) {
    BackendProfile.courierDemo => 'courier-demo',
    BackendProfile.webzefanya => 'webzefanya',
  };
}

/// Parses a profile name. Anything unknown falls back to the demo profile so a
/// typo can never silently select the protected backend.
BackendProfile backendProfileFromName(String? name) =>
    (name ?? '').trim().toLowerCase() == 'webzefanya'
    ? BackendProfile.webzefanya
    : BackendProfile.courierDemo;

const String kBackendProfileName = String.fromEnvironment(
  'zefanya.backend',
  defaultValue: 'courier-demo',
);

/// The WebZefanya API base URL (ends with `/api`). Separate from
/// [kApiBaseUrl] so the two backends can never share a configured address.
const String kWebZefanyaBaseUrl = String.fromEnvironment(
  'zefanya.webzefanya.apiBaseUrl',
);

/// Whether a WebZefanya-profile build may send writes. Off by default.
const bool kWebZefanyaAllowWrites = bool.fromEnvironment(
  'zefanya.webzefanya.allowWrites',
  defaultValue: false,
);

/// Whether the configured WebZefanya has the Courier Mobile API (Phase 3:
/// `/api/courier/*`, approved and built in an isolated WebZefanya copy). Off by
/// default: the live WebZefanya does not serve these routes until the owner
/// releases them, so a normal build never offers Courier sign-in.
///
///   --dart-define=zefanya.webzefanya.courierApp=true
const bool kWebZefanyaCourierApp = bool.fromEnvironment(
  'zefanya.webzefanya.courierApp',
  defaultValue: false,
);

/// The profile this build was compiled for.
BackendProfile get kBackendProfile =>
    backendProfileFromName(kBackendProfileName);

/// The API base URL for [profile]. An empty string means "not configured".
String apiBaseUrlFor(
  BackendProfile profile, {
  String demoUrl = kApiBaseUrl,
  String webzefanyaUrl = kWebZefanyaBaseUrl,
}) => profile == BackendProfile.webzefanya ? webzefanyaUrl : demoUrl;

/// The API base URL of the profile this build was compiled for. Direct
/// consumers (share links, relative image URLs, the settings line) use this
/// rather than the demo flag, so a WebZefanya build never resolves against the
/// demo host.
String get kActiveApiBaseUrl => apiBaseUrlFor(kBackendProfile);

/// Whether this build points at a configured live API for its profile.
bool get isProfileApiConfigured =>
    kUseLiveApi && apiBaseUrlFor(kBackendProfile).isNotEmpty;

/// What the connected backend supports. Screens read this instead of asking
/// "which backend am I on?", so a gated feature is a capability decision, not a
/// scattering of mode checks.
class BackendCapabilities {
  const BackendCapabilities({
    required this.profile,
    required this.riderReturnCollect,
    required this.riderCodLedger,
    required this.riderPayouts,
    required this.riderWaybill,
    required this.riderProofPhotos,
    required this.riderPickupFailure,
    required this.riderPickupStops,
    required this.deliveryCompletionStatus,
    required this.courierLogin,
    required this.buyerReviewsList,
    required this.buyerReviewSubmit,
    required this.buyerCheckoutReconcile,
    required this.buyerDeliveryDispute,
    required this.proofOfDeliveryPreview,
  });

  /// Everything the demo fork provides; WebZefanya-only limits are off.
  const BackendCapabilities.demo()
    : profile = BackendProfile.courierDemo,
      riderReturnCollect = true,
      riderCodLedger = true,
      riderPayouts = true,
      riderWaybill = true,
      riderProofPhotos = true,
      riderPickupFailure = true,
      riderPickupStops = true,
      deliveryCompletionStatus = true,
      courierLogin = true,
      buyerReviewsList = true,
      buyerReviewSubmit = true,
      buyerCheckoutReconcile = true,
      buyerDeliveryDispute = true,
      proofOfDeliveryPreview = false;

  /// What WebZefanya provides today, derived from the verified contract
  /// (`test/contract/webzefanya_api_snapshot.json`).
  ///
  /// Courier login is offered only when the build says the backend has the
  /// Courier Mobile API ([kWebZefanyaCourierApp]); the Courier then sees the
  /// read-only WebZefanya transfer portal.
  const BackendCapabilities.webzefanya()
    : profile = BackendProfile.webzefanya,
      riderReturnCollect = false,
      riderCodLedger = false,
      riderPayouts = false,
      riderWaybill = false,
      riderProofPhotos = false,
      riderPickupFailure = false,
      riderPickupStops = false,
      deliveryCompletionStatus = false,
      courierLogin = kWebZefanyaCourierApp,
      buyerReviewsList = false,
      buyerReviewSubmit = false,
      buyerCheckoutReconcile = false,
      buyerDeliveryDispute = false,
      proofOfDeliveryPreview = true;

  final BackendProfile profile;

  /// `POST /rider/return-pickups/{id}/collect` exists.
  final bool riderReturnCollect;

  /// `GET /rider/cod` exists.
  final bool riderCodLedger;

  /// `GET /rider/payouts` exists.
  final bool riderPayouts;

  /// `GET /rider/waybills/{id}` exists.
  final bool riderWaybill;

  /// `GET /rider/proofs/{id}` exists.
  final bool riderProofPhotos;

  /// `POST /rider/pickups/{id}/failed` exists.
  final bool riderPickupFailure;

  /// `POST /rider/pickups/stops/{id}/finish` exists.
  final bool riderPickupStops;

  /// `GET /rider/deliveries/{id}/completion-status` exists.
  final bool deliveryCompletionStatus;

  /// A Courier (linehaul) account can sign in against this backend.
  final bool courierLogin;

  /// `GET /buyer/reviews/mine` exists.
  final bool buyerReviewsList;

  /// `PATCH /buyer/shipments/{id}/products/{id}/review` exists.
  final bool buyerReviewSubmit;

  /// `POST /buyer/checkout/reconcile` exists.
  final bool buyerCheckoutReconcile;

  /// `GET/POST /buyer/shipments/{id}/delivery-dispute` exists.
  final bool buyerDeliveryDispute;

  /// The mocked Proof of Delivery preview screen is reachable.
  final bool proofOfDeliveryPreview;

  /// True for the WebZefanya profile.
  bool get isWebZefanya => profile == BackendProfile.webzefanya;

  /// The capabilities for [profile].
  static BackendCapabilities forProfile(BackendProfile profile) =>
      profile == BackendProfile.webzefanya
      ? const BackendCapabilities.webzefanya()
      : const BackendCapabilities.demo();
}
