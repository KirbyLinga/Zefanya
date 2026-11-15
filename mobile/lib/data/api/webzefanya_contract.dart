// lib/data/api/webzefanya_contract.dart
//
// The verified WebZefanya API route contract, as Dart constants.
//
// **Source of truth:** `test/contract/webzefanya_api_snapshot.json`, a read-only
// snapshot of WebZefanya's `artisan route:list --path=api`. A test
// (`test/contract/webzefanya_contract_test.dart`) fails if this list and the
// snapshot ever differ, so the runtime guard below cannot drift from the file
// that is reviewed in version control.
//
// Paths are relative to `/api` (the app's `baseUrl` already ends with it).
//
// Snapshot taken from WebZefanya `00eea861` (2026-11-14), 68 routes.

import 'package:http/http.dart' as http;

import '../../config/backend_profile.dart';
import 'api_client.dart';
import 'api_exception.dart';
import 'token_store.dart';

/// One WebZefanya API route: an HTTP method and a path template.
class ApiRoute {
  const ApiRoute(this.method, this.path);

  final String method;

  /// Relative to `/api`, with `{param}` placeholders (e.g. `/rider/jobs/{sellerOrder}/incidents`).
  final String path;

  @override
  String toString() => '$method $path';
}

/// Every route WebZefanya exposes under `/api`.
const List<ApiRoute> kWebZefanyaRoutes = [
  ApiRoute('GET', '/buyer/addresses'),
  ApiRoute('POST', '/buyer/addresses'),
  ApiRoute('DELETE', '/buyer/addresses/{address}'),
  ApiRoute('PUT', '/buyer/addresses/{address}'),
  ApiRoute('POST', '/buyer/addresses/{address}/default'),
  ApiRoute('GET', '/buyer/cart'),
  ApiRoute('DELETE', '/buyer/cart/items'),
  ApiRoute('POST', '/buyer/cart/items'),
  ApiRoute('DELETE', '/buyer/cart/items/{item}'),
  ApiRoute('PATCH', '/buyer/cart/items/{item}'),
  ApiRoute('DELETE', '/buyer/cart/vouchers'),
  ApiRoute('POST', '/buyer/cart/vouchers'),
  ApiRoute('GET', '/buyer/categories'),
  ApiRoute('POST', '/buyer/checkout'),
  ApiRoute('POST', '/buyer/checkout/quote'),
  ApiRoute('POST', '/buyer/checkout/reconcile'),
  ApiRoute('POST', '/buyer/login'),
  ApiRoute('POST', '/buyer/logout'),
  ApiRoute('GET', '/buyer/messages'),
  ApiRoute('POST', '/buyer/messages'),
  ApiRoute('POST', '/buyer/messages/open'),
  ApiRoute('GET', '/buyer/messages/{conversation}'),
  ApiRoute('GET', '/buyer/messages/{conversation}/images/{message}'),
  ApiRoute('GET', '/buyer/notifications'),
  ApiRoute('POST', '/buyer/notifications/read-all'),
  ApiRoute('POST', '/buyer/notifications/{notification}/read'),
  ApiRoute('GET', '/buyer/orders'),
  ApiRoute('GET', '/buyer/orders/{order}'),
  ApiRoute('POST', '/buyer/orders/{order}/shipments/{shipment}/cancel'),
  ApiRoute(
    'POST',
    '/buyer/orders/{order}/shipments/{shipment}/confirm-received',
  ),
  ApiRoute('POST', '/buyer/orders/{order}/shipments/{shipment}/return'),
  ApiRoute(
    'POST',
    '/buyer/orders/{order}/shipments/{shipment}/return/escalate',
  ),
  ApiRoute('GET', '/buyer/products'),
  ApiRoute('GET', '/buyer/products/{product}'),
  ApiRoute('GET', '/buyer/profile'),
  ApiRoute('GET', '/buyer/reviews/mine'),
  ApiRoute('GET', '/buyer/shipments'),
  ApiRoute('GET', '/buyer/shipments/{shipment}'),
  ApiRoute('PATCH', '/buyer/shipments/{shipment}/products/{product}/review'),
  ApiRoute('GET', '/buyer/vouchers'),
  ApiRoute('GET', '/buyer/wishlist'),
  ApiRoute('DELETE', '/buyer/wishlist/{product}'),
  ApiRoute('PUT', '/buyer/wishlist/{product}'),
  ApiRoute('GET', '/logistics/rider-applications'),
  ApiRoute('POST', '/logistics/rider-applications/{rider}/approve'),
  ApiRoute('GET', '/logistics/rider-applications/{rider}/documents/{document}'),
  ApiRoute('POST', '/logistics/rider-applications/{rider}/reject'),
  ApiRoute('POST', '/rider/availability'),
  ApiRoute('GET', '/rider/cod'),
  ApiRoute('POST', '/rider/deliveries/{sellerOrder}/deliver'),
  ApiRoute('POST', '/rider/deliveries/{sellerOrder}/failed'),
  ApiRoute('POST', '/rider/deliveries/{sellerOrder}/start'),
  ApiRoute('GET', '/rider/earnings'),
  ApiRoute('GET', '/rider/history'),
  ApiRoute('GET', '/rider/jobs'),
  ApiRoute('POST', '/rider/jobs/{sellerOrder}/incidents'),
  ApiRoute('POST', '/rider/login'),
  ApiRoute('POST', '/rider/logout'),
  ApiRoute('GET', '/rider/notifications'),
  ApiRoute('POST', '/rider/notifications/read-all'),
  ApiRoute('POST', '/rider/notifications/{notification}/read'),
  ApiRoute('POST', '/rider/parcels/{sellerOrder}/incidents'),
  ApiRoute('GET', '/rider/payouts'),
  ApiRoute('POST', '/rider/pickups/stops/{pickupSession}/finish'),
  ApiRoute('POST', '/rider/pickups/{sellerOrder}/confirm'),
  ApiRoute('POST', '/rider/pickups/{sellerOrder}/failed'),
  ApiRoute('GET', '/rider/profile'),
  ApiRoute('GET', '/rider/proofs/{deliveryProof}'),
  ApiRoute('GET', '/rider/providers'),
  ApiRoute('POST', '/rider/register'),
  ApiRoute('POST', '/rider/registration/resume'),
  ApiRoute('POST', '/rider/registration/{rider}/resend-otp'),
  ApiRoute('POST', '/rider/registration/{rider}/verify-otp'),
  ApiRoute('GET', '/rider/return-pickups'),
  ApiRoute('POST', '/rider/return-pickups/{parcelReturn}/collect'),
  ApiRoute('POST', '/rider/scan/verify'),
  ApiRoute('POST', '/rider/token/refresh'),
  ApiRoute('GET', '/rider/waybills/{sellerOrder}'),
  ApiRoute('POST', '/v1/buyer/checkout/quote'),
];

/// The Courier Mobile API (Phase 3), from the isolated WebZefanya build.
///
/// **Source of truth:** `test/contract/webzefanya_courier_api_snapshot.json`,
/// exported read-only (`route:list --path=api/courier`) from the isolated
/// WebZefanya copy. Supported only when the build enables
/// [kWebZefanyaCourierApp]; the live WebZefanya does not serve them yet.
/// Read-only apart from signing in, activating and renewing a session.
const List<ApiRoute> kWebZefanyaCourierRoutes = [
  ApiRoute('POST', '/courier/activate'),
  ApiRoute('POST', '/courier/login'),
  ApiRoute('POST', '/courier/logout'),
  ApiRoute('GET', '/courier/profile'),
  ApiRoute('POST', '/courier/token/refresh'),
  ApiRoute('GET', '/courier/transfers'),
  ApiRoute('GET', '/courier/transfers/history'),
];

/// The routes this build may call.
const List<ApiRoute> kWebZefanyaBuildRoutes = [
  ...kWebZefanyaRoutes,
  if (kWebZefanyaCourierApp) ...kWebZefanyaCourierRoutes,
];

/// Routes that stay callable even when writes are disabled: signing in and out,
/// activating an account and renewing a session are not business transactions.
const List<String> _alwaysAllowedWrites = [
  '/buyer/login',
  '/buyer/logout',
  '/rider/login',
  '/rider/logout',
  '/rider/token/refresh',
  '/courier/activate',
  '/courier/login',
  '/courier/logout',
  '/courier/token/refresh',
];

/// Opt-in operations exercised over HTTP against the repaired Laravel/MySQL
/// backend. Broader write access is reserved for isolated test builds.
const List<ApiRoute> _verifiedWrites = [
  ApiRoute('POST', '/buyer/cart/items'),
  ApiRoute('POST', '/buyer/checkout/quote'),
  ApiRoute('POST', '/buyer/checkout/reconcile'),
  ApiRoute('POST', '/buyer/checkout'),
  ApiRoute('POST', '/rider/scan/verify'),
  ApiRoute('POST', '/rider/deliveries/{sellerOrder}/deliver'),
];

/// Answers "may the app send this request to WebZefanya?".
class WebZefanyaContract implements ApiRoutePolicy {
  const WebZefanyaContract({
    this.routes = kWebZefanyaRoutes,
    this.allowWrites = false,
    this.allowVerifiedWrites = false,
  });

  /// The routes considered supported. Overridable for tests.
  final List<ApiRoute> routes;

  /// Whether non-GET requests (other than sign-in/out) may leave the device.
  ///
  /// Off by default: there is no staging backend (owner decision D7), so a
  /// build must opt in with `--dart-define=zefanya.webzefanya.allowWrites=true`
  /// before it can change anything on a WebZefanya server.
  final bool allowWrites;
  final bool allowVerifiedWrites;

  /// A contract configured from the build flags.
  const WebZefanyaContract.fromEnvironment()
    : routes = kWebZefanyaBuildRoutes,
      allowWrites = kWebZefanyaAllowWrites,
      allowVerifiedWrites = kWebZefanyaAllowVerifiedWrites;

  /// True when [method] [path] matches a WebZefanya route. [path] is relative
  /// to `/api`; a query string is ignored.
  bool supports(String method, String path) {
    final clean = _normalize(path);
    final verb = method.toUpperCase();
    for (final route in routes) {
      if (route.method == verb && _matches(route.path, clean)) return true;
    }
    return false;
  }

  /// Throws [ApiNotImplementedException] when the request must not be sent.
  ///
  /// Installed as the `ApiClient` route policy: an unsupported call fails
  /// locally and visibly instead of reaching the server as a 404.
  @override
  void check(String method, String path) {
    final verb = method.toUpperCase();
    final clean = _normalize(path);
    if (!supports(verb, clean)) {
      throw ApiNotImplementedException(
        '$verb /api$clean',
        contractSection:
            'WebZefanya profile: route not in the verified contract',
        message: 'This is not available with the WebZefanya backend.',
      );
    }
    if (!allowWrites &&
        verb != 'GET' &&
        !_alwaysAllowedWrites.contains(clean) &&
        !(allowVerifiedWrites &&
            _verifiedWrites.any(
              (route) => route.method == verb && _matches(route.path, clean),
            ))) {
      throw ApiNotImplementedException(
        '$verb /api$clean',
        contractSection:
            'WebZefanya profile: writes are disabled (zefanya.webzefanya.allowWrites)',
        message: allowVerifiedWrites
            ? 'This change is unavailable in the current WebZefanya release.'
            : 'Changes are turned off in this build (read-only WebZefanya mode).',
      );
    }
  }

  static String _normalize(String path) {
    var value = path.split('?').first;
    if (!value.startsWith('/')) value = '/$value';
    if (value.length > 1 && value.endsWith('/')) {
      value = value.substring(0, value.length - 1);
    }
    return value;
  }

  static bool _matches(String template, String path) {
    final t = template.split('/');
    final p = path.split('/');
    if (t.length != p.length) return false;
    for (var i = 0; i < t.length; i++) {
      final segment = t[i];
      if (segment.startsWith('{') && segment.endsWith('}')) {
        if (p[i].isEmpty) return false;
        continue;
      }
      if (segment != p[i]) return false;
    }
    return true;
  }
}

/// The one way to build the WebZefanya API client: the verified route contract
/// (and, unless [allowWrites], the read-only rule) is installed here, so no
/// caller has to remember to attach it.
ApiClient guardedWebZefanyaClient({
  required String baseUrl,
  required TokenStore tokenStore,
  http.Client? httpClient,
  bool allowWrites = false,
  bool allowVerifiedWrites = false,
  Duration timeout = const Duration(seconds: 20),
}) => ApiClient(
  baseUrl: baseUrl,
  tokenStore: tokenStore,
  httpClient: httpClient,
  timeout: timeout,
  routePolicy: WebZefanyaContract(
    routes: kWebZefanyaBuildRoutes,
    allowWrites: allowWrites,
    allowVerifiedWrites: allowVerifiedWrites,
  ),
);
