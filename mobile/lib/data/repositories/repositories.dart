// lib/data/repositories/repositories.dart
//
// **The one place the mock/real decision is made.**
//
// ## How to read this file
//
// `Repositories` is a plain bundle of the ten data-source interfaces.
// `Repositories.forMode` picks an implementation for each, from
// `config/dev_flags.dart`. Nothing else in the app branches on the backend
// mode, and nothing else constructs a repository.
//
// That constraint is the point. If a screen could ask "am I in mock mode?" it
// would eventually have two behaviours to keep in step — a mock-only empty
// state, a live-only error state — and they would drift. Here, a screen is
// handed interfaces and cannot tell which mode it is in, which is exactly the
// position it needs to be in for the switch to be a *config* change rather than
// a code change.
//
// ## Why there is no service locator / no Provider
//
// The bundle is passed explicitly: `Repositories` goes into `main()`'s provider
// list as a plain value, and a state class takes what it needs in its
// constructor. A global singleton would make the mode untestable (a test could
// not swap in a fake without global state), and Provider-based lookup would
// couple every state class to a widget tree it does not otherwise need.
//
// ## Live-mode caution, stated here so nobody is surprised
//
// The Laravel surface is real but partial — see `docs/api-contract.md` §12.
// Endpoints that exist are wired to live repositories; the ones that do not
// yet exist throw `ApiNotImplementedException` naming the missing route (see
// `live_stub.dart`) rather than guessing. A live build is therefore runnable
// and honest, not complete.

import '../../config/backend_profile.dart';
import '../../config/dev_flags.dart' show GoogleConfig;
import '../../services/google_sign_in_service.dart';
import '../api/api_client.dart';
import '../api/backend_scope.dart';
import '../api/token_store.dart';
import '../api/webzefanya_contract.dart';
import 'account_security_repository.dart';
import 'address_repository.dart';
import 'auth_repository.dart';
import 'cart_repository.dart';
import 'catalog_repository.dart';
import 'chat_repository.dart';
import 'courier_repository.dart';
import 'google_auth_repository.dart';
import 'courier_transfer_repository.dart';
import 'order_repository.dart';
import 'return_repository.dart';
import 'review_repository.dart';

/// Re-exported so a caller needs one import to name an exception it has to
/// handle. Screens that render an error state switch on these types.
export '../api/api_exception.dart' show ApiException, PendingApprovalException;

/// Every data source the app needs, resolved for one mode.
class Repositories {
  const Repositories({
    required this.auth,
    required this.accountSecurity,
    required this.googleAuth,
    required this.googleSignIn,
    required this.catalog,
    required this.cart,
    required this.order,
    required this.review,
    required this.chat,
    required this.courier,
    required this.returns,
    AddressRepository? address,
    this.linehaul,
    this.client,
    this.capabilities = const BackendCapabilities.demo(),
  }) : address = address ?? const MockAddressRepository();

  /// What the connected backend supports. The demo profile (and mock mode)
  /// has everything; the WebZefanya profile turns off what it lacks, and
  /// screens hide those actions from this one value.
  final BackendCapabilities capabilities;

  final AuthRepository auth;
  final AccountSecurityRepository accountSecurity;
  final AddressRepository address;

  /// The server exchange for a Google identity, separate from [auth] because
  /// it answers "which role is this?" rather than taking a role as an argument.
  final GoogleAuthRepository googleAuth;

  /// The native Google account sheet. Mock mode returns a deterministic
  /// identity instead, so the flow is testable without a Google project.
  final GoogleSignInService googleSignIn;

  final CatalogRepository catalog;
  final CartRepository cart;
  final OrderRepository order;
  final ReviewRepository review;
  final ChatRepository chat;
  final CourierRepository courier;
  final ReturnRepository returns;
  final CourierTransferRepository? linehaul;

  /// The network client, present only in live mode.
  ///
  /// Null in mock mode rather than a client pointing at nothing, so "is there a
  /// network?" is answerable from the bundle itself and a mock build cannot
  /// quietly make a request.
  final ApiClient? client;

  /// The mock set — every implementation wrapping today's `mock_data`
  /// behaviour.
  ///
  /// A named constructor rather than the default so that a live build has to
  /// say so out loud. A default of "mock" is safe; a default that *looks* like
  /// production is how a build ships pointing at nothing.
  factory Repositories.mock() => Repositories(
    auth: const MockAuthRepository(),
    accountSecurity: MockAccountSecurityRepository(),
    googleAuth: const MockGoogleAuthRepository(),
    googleSignIn: const MockGoogleSignInService(),
    catalog: const MockCatalogRepository(),
    cart: const MockCartRepository(),
    address: const MockAddressRepository(),
    // These two are not `const`, and can't be: each holds the mutable,
    // persisted collection it is the data source for (`MockOrderRepository`
    // owns the placed-order list, `MockChatRepository` owns the threads).
    // That is the shape a real data source has.
    order: MockOrderRepository(),
    review: MockReviewRepository(),
    returns: MockReturnRepository(),
    chat: MockChatRepository(),
    courier: const MockCourierRepository(),
    linehaul: const CourierTransferRepository.demo(),
  );

  /// The live set, built against [client].
  ///
  /// Takes an already-constructed client rather than a URL so that the token
  /// store and the base URL are decided by the caller (see [forMode]), which is
  /// what lets a test build a live bundle against a fake HTTP client.
  factory Repositories.live(ApiClient client) => Repositories(
    auth: LiveAuthRepository(client),
    accountSecurity: LiveAccountSecurityRepository(client),
    googleAuth: LiveGoogleAuthRepository(client),
    googleSignIn: NativeGoogleSignInService(
      clientId: GoogleConfig.current.iosClientId,
      serverClientId: GoogleConfig.current.serverClientId,
    ),
    catalog: LiveCatalogRepository(client),
    cart: LiveCartRepository(client),
    address: LiveAddressRepository(client),
    order: LiveOrderRepository(client),
    review: LiveReviewRepository(client),
    returns: LiveReturnRepository(client),
    chat: LiveChatRepository(client),
    courier: LiveCourierRepository(client),
    client: client,
  );

  /// The live set for WebZefanya, built against [client].
  ///
  /// Reuses the live repositories (WebZefanya has those routes) and swaps in
  /// the adapters where the two backends differ. Courier (linehaul) sign-in is
  /// not wired: WebZefanya has no Courier authentication yet.
  ///
  /// [client] must carry the WebZefanya route contract (build it with
  /// [guardedWebZefanyaClient]); a client without it is refused so no caller can
  /// reach WebZefanya through an unguarded path.
  factory Repositories.webzefanya(ApiClient client) {
    if (client.routePolicy is! WebZefanyaContract) {
      throw ArgumentError(
        'A WebZefanya bundle needs a client built with guardedWebZefanyaClient.',
      );
    }
    return Repositories._webzefanya(client);
  }

  factory Repositories._webzefanya(ApiClient client) => Repositories(
    auth: LiveAuthRepository(client),
    accountSecurity: LiveAccountSecurityRepository(client),
    googleAuth: LiveGoogleAuthRepository(client),
    googleSignIn: NativeGoogleSignInService(
      clientId: GoogleConfig.current.iosClientId,
      serverClientId: GoogleConfig.current.serverClientId,
    ),
    catalog: LiveCatalogRepository(client),
    cart: LiveCartRepository(client),
    address: LiveAddressRepository(client),
    order: LiveOrderRepository(
      client,
      quotePath: '/buyer/checkout/quote',
      deliveryDisputesSupported: false,
      previewThroughCart: true,
    ),
    review: LiveReviewRepository(client, listSupported: false),
    returns: LiveReturnRepository(client),
    chat: LiveChatRepository(client),
    courier: WebZefanyaCourierRepository(client),
    linehaul: const BackendCapabilities.webzefanya().courierLogin
        ? CourierTransferRepository(client)
        : null,
    client: client,
    capabilities: const BackendCapabilities.webzefanya(),
  );

  /// Resolves the bundle for [useLive].
  ///
  /// [useLive] is passed rather than read from `dev_flags` directly so a test
  /// can exercise both branches without a compile-time flag, and so the one
  /// call site in `main.dart` is visibly the place the decision is made.
  ///
  /// When [useLive] is true but [baseUrl] is empty, this does **not** silently
  /// fall back to mock. It builds a live bundle whose client is unconfigured,
  /// so the first request throws `ApiNotConfiguredException` naming the flag.
  /// Falling back would mean a misconfigured build appears to work — with mock
  /// data — which is the worst of both worlds.
  ///
  /// [profile] selects the backend a live build talks to. The default keeps
  /// today's behaviour (the demo fork). [WebZefanyaContract] guards every
  /// request in the WebZefanya profile, and writes stay disabled unless
  /// [allowWrites] is set. Mock mode ignores the profile.
  static Repositories forMode({
    required bool useLive,
    String baseUrl = '',
    TokenStore? tokenStore,
    ApiClient? client,
    BackendProfile profile = BackendProfile.courierDemo,
    bool allowWrites = false,
    bool allowVerifiedWrites = false,
    String otherBaseUrl = '',
  }) {
    if (!useLive) return Repositories.mock();

    _refuseCrossBackendOrigin(profile, baseUrl, otherBaseUrl);

    if (profile == BackendProfile.webzefanya) {
      // Always built here with the contract installed. An injected client
      // is accepted only if it already carries it ([Repositories.webzefanya]
      // refuses one that does not).
      final resolved =
          client ??
          guardedWebZefanyaClient(
            baseUrl: baseUrl,
            tokenStore:
                tokenStore ??
                scopedTokenStore(BackendProfile.webzefanya, baseUrl),
            allowWrites: allowWrites,
            allowVerifiedWrites: allowVerifiedWrites,
          );
      return Repositories.webzefanya(resolved);
    }

    final resolved =
        client ??
        ApiClient(
          baseUrl: baseUrl,
          tokenStore:
              tokenStore ??
              scopedTokenStore(BackendProfile.courierDemo, baseUrl),
        );
    return Repositories.live(resolved);
  }

  /// A live build must not be pointed at the *other* backend's host: the demo
  /// profile refuses the configured WebZefanya origin and vice versa. Separate
  /// flag names are not host identity checks, so the origins are compared.
  static void _refuseCrossBackendOrigin(
    BackendProfile profile,
    String baseUrl,
    String otherBaseUrl,
  ) {
    if (baseUrl.trim().isEmpty || otherBaseUrl.trim().isEmpty) return;
    final a = Uri.tryParse(baseUrl.trim());
    final b = Uri.tryParse(otherBaseUrl.trim());
    if (a == null || b == null) return;
    int port(Uri u) => u.hasPort ? u.port : (u.isScheme('https') ? 443 : 80);
    if (a.scheme.toLowerCase() == b.scheme.toLowerCase() &&
        a.host.toLowerCase() == b.host.toLowerCase() &&
        port(a) == port(b)) {
      throw StateError(
        'The ${profile.wireName} profile is configured with the same host as '
        'the other backend. Check zefanya.apiBaseUrl and '
        'zefanya.webzefanya.apiBaseUrl.',
      );
    }
  }

  /// Restores whatever each data source keeps locally, before the first frame.
  ///
  /// Called from `main()` in one `Future.wait`, exactly as the state classes'
  /// own `load()` calls were, so there is no empty-then-jump flash. In live
  /// mode only the *local* sources do anything (see each implementation); the
  /// rest return immediately rather than blocking startup on a network call
  /// that may be slow or unreachable.
  ///
  /// Deliberately *not* called from `main()` any more. Every state class now
  /// restores its own repository inside its own `load()` — which is also what
  /// lets a test build a single state and get a working data source — so
  /// calling this as well would read the same storage twice for one result.
  /// Kept as the bundle-level "restore everything" entry point for a caller
  /// that holds no states; there is none today, and one that appears should use
  /// this rather than reaching for `shared_preferences` itself.
  Future<void> loadLocal() =>
      Future.wait([order.load(), review.load(), returns.load(), chat.load()]);
}
