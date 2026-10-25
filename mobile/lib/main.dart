// lib/main.dart
import 'dart:async';

import 'package:app_links/app_links.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'config/dev_flags.dart';
import 'data/repositories/repositories.dart';
import 'theme/app_theme.dart';
import 'state/auth_state.dart';
import 'state/cart_state.dart';
import 'state/catalog_state.dart';
import 'state/chat_state.dart';
import 'state/courier_state.dart';
import 'state/notification_state.dart';
import 'state/order_state.dart';
import 'state/recently_viewed_state.dart';
import 'state/return_state.dart';
import 'state/review_state.dart';
import 'state/startup_state.dart';
import 'state/theme_state.dart';
import 'state/wishlist_state.dart';
import 'screens/auth/login_screen.dart';
import 'screens/auth/pending_approval_screen.dart';
import 'screens/buyer/buyer_shell.dart';
import 'screens/buyer/product_link_screen.dart';
import 'screens/courier/courier_shell.dart';
import 'screens/landing_screen.dart';
import 'utils/motion.dart';
import 'utils/product_link.dart';
import 'widgets/app_atmosphere.dart';
import 'widgets/app_page_route.dart';
import 'widgets/startup_notice.dart';
import 'widgets/zefanya_mark.dart';

void main() async {
  // Needed because we're awaiting async work (state.load()) before
  // runApp — without this, plugin channels (shared_preferences here)
  // aren't guaranteed ready yet.
  WidgetsFlutterBinding.ensureInitialized();

  // The one place the mock/real decision is made.
  final repositories = Repositories.forMode(
    useLive: kUseLiveApi,
    baseUrl: kApiBaseUrl,
  );

  final authState = AuthState(
    repository: repositories.auth,
    accountSecurity: repositories.accountSecurity,
  );
  final cartState = CartState(
    catalog: repositories.catalog,
    vouchers: repositories.cart,
  );
  final catalogState = CatalogState(repository: repositories.catalog);
  final chatState = ChatState(repository: repositories.chat);
  final courierState = CourierState(repository: repositories.courier);
  final notificationState = NotificationState();
  final returnState = ReturnState(repository: repositories.returns);
  final orderState = OrderState(
    repository: repositories.order,
    returns: returnState,
  );
  final recentlyViewedState = RecentlyViewedState(catalog: repositories.catalog);
  final reviewState = ReviewState(repository: repositories.review);
  final wishlistState = WishlistState(catalog: repositories.catalog);
  final themeState = ThemeState();
  final startupState = StartupState();

  // Fire runApp immediately — before the loads finish — so the person
  // sees the app's own loading state instead of just the native splash.
  // The ValueNotifier flips once Future.wait resolves, and the
  // BootSplash widget below swaps itself out for the real app at that
  // moment. The architecture is unchanged: every state is still loaded
  // before the shell becomes visible.
  final booted = ValueNotifier<bool>(false);

  runApp(
    MultiProvider(
      providers: [
        Provider<Repositories>.value(value: repositories),
        ChangeNotifierProvider.value(value: authState),
        ChangeNotifierProvider.value(value: startupState),
        ChangeNotifierProvider.value(value: cartState),
        ChangeNotifierProvider.value(value: catalogState),
        ChangeNotifierProvider.value(value: chatState),
        ChangeNotifierProvider.value(value: courierState),
        ChangeNotifierProvider.value(value: notificationState),
        ChangeNotifierProvider.value(value: orderState),
        ChangeNotifierProvider.value(value: recentlyViewedState),
        ChangeNotifierProvider.value(value: reviewState),
        ChangeNotifierProvider.value(value: wishlistState),
        ChangeNotifierProvider.value(value: themeState),
      ],
      child: ValueListenableBuilder<bool>(
        valueListenable: booted,
        builder: (context, ready, child) =>
          ready ? const MarketplaceApp() : const _BootSplash(),
      ),
    ),
  );

  await Future.wait([
    startupState.guard('your account', authState.load),
    startupState.guard('your appearance settings', themeState.load),
  ]);

  await Future.wait([
    startupState.guard('the store catalogue', catalogState.load),
    startupState.guard('your cart', cartState.load),
    startupState.guard('your chats', chatState.load),
    startupState.guard('your delivery jobs', courierState.load),
    startupState.guard('your notifications', notificationState.load),
    startupState.guard('your orders', orderState.load),
    startupState.guard('your recently viewed items', recentlyViewedState.load),
    startupState.guard('your reviews', reviewState.load),
    startupState.guard('your saved items', wishlistState.load),
  ]);

  booted.value = true;
}



/// Widest the app's content column gets before it stops stretching.
const double kMaxContentWidth = 560;

/// In-app loading state shown while `main()`'s nine loads run.
/// so the person sees something in Flutter's own rendering rather than
/// just the native splash screen. The design deliberately matches the
/// atmosphere and mark that are already in the app so the transition
/// from this screen to the real shell is seamless rather than jarring.
///
/// Brightness: `ThemeState` hasn't loaded yet, so we initialize the
/// palette to light mode here. This is the same default the palette
/// has before any call to `AppColors.apply`, so there is no flicker —
/// it simply stays at the mode it would have been in anyway.
class _BootSplash extends StatelessWidget {
  const _BootSplash();

  @override
  Widget build(BuildContext context) {
    // Do NOT call AppColors.watch or AppColors.apply here — _BootSplash is
    // shown before AppPaletteScope is in the tree (it replaces MarketplaceApp
    // which wraps that scope). AppColors starts at its light default.
    return Directionality(
      // Required by Stack and other widgets below MaterialApp.
      textDirection: TextDirection.ltr,
      child: Stack(
        fit: StackFit.expand,
        children: [
          const AppAtmosphere(),
          Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                ZefanyaMark(size: 48, strokeWidth: 6),
                const SizedBox(height: 28),
                ZefanyaLoader(size: 28, strokeWidth: 4),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class MarketplaceApp extends StatefulWidget {
  const MarketplaceApp({super.key});

  @override
  State<MarketplaceApp> createState() => _MarketplaceAppState();
}

class _MarketplaceAppState extends State<MarketplaceApp> {
  final _navigatorKey = GlobalKey<NavigatorState>();
  StreamSubscription<Uri>? _linkSubscription;
  AuthState? _auth;
  Uri? _pendingLink;

  @override
  void initState() {
    super.initState();
    _listenForAppLinks();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final auth = context.read<AuthState>();
    if (!identical(auth, _auth)) {
      _auth?.removeListener(_onAuthChanged);
      _auth = auth;
      _auth!.addListener(_onAuthChanged);
    }
    _tryOpenPendingLink();
  }

  @override
  void dispose() {
    _auth?.removeListener(_onAuthChanged);
    _linkSubscription?.cancel();
    super.dispose();
  }

  void _onAuthChanged() => _tryOpenPendingLink();

  Future<void> _listenForAppLinks() async {
    try {
      final appLinks = AppLinks();
      final initial = await appLinks.getInitialLink();
      if (initial != null) _queueLink(initial);
      _linkSubscription = appLinks.uriLinkStream.listen(
        _queueLink,
        onError: (_) {
          // A malformed link must not take down the app. It is simply ignored;
          // the web page remains the fallback for whatever sent it.
        },
      );
    } catch (_) {
      // No app-links implementation (unit tests, unsupported platform). The
      // rest of the app must boot normally.
    }
  }

  void _queueLink(Uri uri) {
    if (productIdFromLink(uri) == null) return;
    _pendingLink = uri;
    _tryOpenPendingLink();
  }

  void _tryOpenPendingLink() {
    final uri = _pendingLink;
    if (uri == null || !mounted) return;
    final auth = _auth;
    // The buyer shell is the only place a product route makes sense. If the
    // person is logged out, keep the link pending until auth becomes active
    // rather than dropping them on an empty route.
    if (auth == null || !auth.isLoggedIn || auth.role != UserRole.buyer) return;
    final productId = productIdFromLink(uri);
    if (productId == null) {
      _pendingLink = null;
      return;
    }
    _pendingLink = null;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      _navigatorKey.currentState?.push(
        AppPageRoute(builder: (_) => ProductLinkScreen(productId: productId)),
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final themeState = context.watch<ThemeState>();
    // Apply once here so any widget above the Navigator (e.g. _BootSplash
    // sibling, AppAtmosphere in builder) already has the right palette.
    AppColors.apply(themeState.brightness);

    // _AppPaletteScope sits above MaterialApp's Navigator. When brightness
    // changes, Flutter marks every element that called
    // _AppPaletteScope.of(context) as dirty — including every screen inside
    // the Navigator — without recreating the Navigator or resetting the
    // route stack. _PaletteRepaint in the builder registers that dependency
    // once per frame so every route's subtree repaints with the new palette.
    return AppPaletteScope(
      brightness: themeState.brightness,
      child: MaterialApp(
        navigatorKey: _navigatorKey,
        title: 'Zefanya',
        debugShowCheckedModeBanner: false,
        theme: AppTheme.light,
        darkTheme: AppTheme.dark,
        themeMode: themeState.mode,
        themeAnimationDuration: Duration.zero,
        builder: (context, child) {
          // Register palette dependency so this builder context rebuilds
          // when brightness changes — which propagates to child below.
          AppColors.watch(context);
          final mq = MediaQuery.of(context);
          final width = mq.size.width < kMaxContentWidth
              ? mq.size.width
              : kMaxContentWidth;
          return MediaQuery(
            data: mq.copyWith(
              textScaler: mq.textScaler
                  .clamp(minScaleFactor: 0.85, maxScaleFactor: 1.3),
              size: Size(width, mq.size.height),
            ),
            child: Stack(
              fit: StackFit.expand,
              children: [
                const AppAtmosphere(),
                Center(
                  child: ConstrainedBox(
                    constraints:
                        const BoxConstraints(maxWidth: kMaxContentWidth),
                    child: SizedBox.expand(child: child!),
                  ),
                ),
              ],
            ),
          );
        },
        home: const AuthGate(),
      ),
    );
  }
}

/// Root router: watches [AuthState] and shows whichever screen matches
/// the current session -- login, pending approval, or the right shell.
/// This widget IS the app's single Navigator root, so every "go back
/// to the start" action (logout, back-to-login) should
/// `Navigator.popUntil((route) => route.isFirst)` rather than pushing
/// a new route -- that unwind is what makes AuthGate's rebuild visible.
///
/// The landing/intro screen is shown from HERE, as a swap of what this
/// widget returns, rather than as its own `home:` or a pushed route.
/// That is load-bearing, not a style choice: login_screen,
/// registration_screen, pending_approval_screen and profile_screen all
/// call `popUntil((route) => route.isFirst)` and all four of them trust
/// that "first route" means "whatever AuthGate currently resolves to".
/// Give Landing a route of its own and all four start unwinding to the
/// intro screen instead — logout, post-registration, everything. There
/// is still exactly one route in this app. Keep it that way.
class AuthGate extends StatefulWidget {
  const AuthGate({super.key});

  @override
  State<AuthGate> createState() => _AuthGateState();
}

class _AuthGateState extends State<AuthGate> {
  /// Ephemeral and deliberately unpersisted — this is "once per process
  /// start", not "once per install". Android can kill and restore the
  /// process while the app is backgrounded; landing decides again on
  /// that restart, same as it did on the original cold start.
  bool _introDone = false;

  /// Whether we've made the one-time decision below. Runs before the
  /// first build (didChangeDependencies always fires before build on
  /// mount), so build() never sees an undecided state.
  bool _decided = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_decided) return;
    _decided = true;

    // Landing greets a signed-out cold start. It does NOT greet an
    // active or a pending-approval session: those people don't need a
    // "Begin", they need back into the app they already have. This
    // matters more now than it would have for the old auto-advancing
    // version — Landing now requires a tap to get past, and a required
    // tap on every cold launch is a real, if small, tax on exactly the
    // person (a daily-returning courier) who benefits least from it.
    //
    // context.read, not watch: this is a one-time decision made at
    // process start, not something that should re-fire if AuthState
    // changes later (e.g. the person logs out mid-session — that
    // should return them to Login, not back to this screen).
    if (context.read<AuthState>().status != AccountStatus.loggedOut) {
      _introDone = true;
    }
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    if (!_introDone) {
      return LandingScreen(
        reduceMotion: reduceMotion(context),
        onDone: () {
          if (mounted) setState(() => _introDone = true);
        },
      );
    }

    final auth = context.watch<AuthState>();

    switch (auth.status) {
      case AccountStatus.active:
        // The startup notice sits above the shell rather than inside either one:
        // a single place covers both roles, and it only matters once there is
        // something to look at — a cart that failed to load says nothing useful
        // on the login screen, and nothing at all on the landing screen.
        return Column(
          children: [
            const StartupNotice(),
            Expanded(
              child: auth.role == UserRole.courier ? const CourierShell() : const BuyerShell(),
            ),
          ],
        );
      case AccountStatus.pendingApproval:
        return const PendingApprovalScreen();
      case AccountStatus.loggedOut:
        return const LoginScreen();
    }
  }
}
