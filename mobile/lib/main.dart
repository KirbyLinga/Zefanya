// lib/main.dart
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'theme/app_theme.dart';
import 'state/auth_state.dart';
import 'state/cart_state.dart';
import 'state/chat_state.dart';
import 'state/courier_state.dart';
import 'state/order_state.dart';
import 'state/recently_viewed_state.dart';
import 'state/wishlist_state.dart';
import 'screens/auth/login_screen.dart';
import 'screens/auth/pending_approval_screen.dart';
import 'screens/buyer/buyer_shell.dart';
import 'screens/courier/courier_shell.dart';
import 'screens/landing_screen.dart';
import 'utils/motion.dart';
import 'widgets/app_atmosphere.dart';

void main() async {
  // Needed because we're awaiting async work (state.load()) before
  // runApp — without this, plugin channels (shared_preferences here)
  // aren't guaranteed ready yet.
  WidgetsFlutterBinding.ensureInitialized();

  final authState = AuthState();
  final cartState = CartState();
  final chatState = ChatState();
  final courierState = CourierState();
  final orderState = OrderState();
  final recentlyViewedState = RecentlyViewedState();
  final wishlistState = WishlistState();
  // Restore any saved session/cart/chat/courier-job state before the
  // first frame, so nothing flashes empty then jumps to the real state,
  // and so AuthGate below has a real AccountStatus to gate the landing
  // screen on rather than a placeholder.
  await Future.wait([
    authState.load(),
    cartState.load(),
    chatState.load(),
    courierState.load(),
    orderState.load(),
    recentlyViewedState.load(),
    wishlistState.load(),
  ]);

  runApp(
    MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: authState),
        ChangeNotifierProvider.value(value: cartState),
        ChangeNotifierProvider.value(value: chatState),
        ChangeNotifierProvider.value(value: courierState),
        ChangeNotifierProvider.value(value: orderState),
        ChangeNotifierProvider.value(value: recentlyViewedState),
        ChangeNotifierProvider.value(value: wishlistState),
      ],
      child: const MarketplaceApp(),
    ),
  );
}

/// Widest the app's content column gets before it stops stretching.
const double kMaxContentWidth = 560;

class MarketplaceApp extends StatelessWidget {
  const MarketplaceApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Marketplace',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light,
      // Paints the app's background once, here, instead of every Scaffold
      // repeating a flat fill. The stack itself lives in
      // widgets/app_atmosphere.dart so the landing screen can render the
      // same thing with the glows drifting, without a second copy of the
      // gradient existing anywhere.
      builder: (context, child) {
        // App-wide responsiveness guards (apply to every screen):
        //  1. Cap the OS font-size setting. Big system text is the #1 cause
        //     of "RIGHT OVERFLOWED BY n PIXELS" on real phones. 1.3x still
        //     honors larger-text users without breaking fixed-size layouts.
        //  2. Cap content width and center it, so Chrome / tablets / desktop
        //     windows show a phone-width column instead of stretched layouts.
        //     MediaQuery.size is overridden to match, so screens that read
        //     it (e.g. chat bubbles) agree with the constrained width.
        final mq = MediaQuery.of(context);
        final width = mq.size.width < kMaxContentWidth ? mq.size.width : kMaxContentWidth;
        return MediaQuery(
          data: mq.copyWith(
            textScaler: mq.textScaler.clamp(minScaleFactor: 0.85, maxScaleFactor: 1.3),
            size: Size(width, mq.size.height),
          ),
          child: Stack(
            fit: StackFit.expand,
            children: [
              const AppAtmosphere(),
              Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: kMaxContentWidth),
                  child: SizedBox.expand(child: child!),
                ),
              ),
            ],
          ),
        );
      },
      home: const AuthGate(),
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
        return auth.role == UserRole.courier ? const CourierShell() : const BuyerShell();
      case AccountStatus.pendingApproval:
        return const PendingApprovalScreen();
      case AccountStatus.loggedOut:
        return const LoginScreen();
    }
  }
}
