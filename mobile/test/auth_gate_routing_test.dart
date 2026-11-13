// test/auth_gate_routing_test.dart
//
// Login safety: the shell is chosen from the session's role, not from a
// leftover role toggle. A courier lands in CourierShell and a buyer in
// BuyerShell after the same login path.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/config/dev_flags.dart' show kUseLiveApi;

import 'package:zefanya/data/dto/registration_payload.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/mock_data.dart' show DeliveryJob, Order;
import 'package:zefanya/data/repositories/courier_repository.dart';
import 'package:zefanya/data/repositories/order_repository.dart';
import 'package:zefanya/data/repositories/review_repository.dart';
import 'package:zefanya/data/google_identity.dart';
import 'package:zefanya/data/repositories/google_auth_repository.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/main.dart';
import 'package:zefanya/screens/auth/registration_screen.dart';
import 'package:zefanya/screens/buyer/buyer_shell.dart';
import 'package:zefanya/screens/buyer/cart_screen.dart';
import 'package:zefanya/screens/buyer/orders_status_screen.dart';
import 'package:zefanya/screens/courier/courier_shell.dart';
import 'package:zefanya/screens/courier/linehaul_portal.dart';
import 'package:zefanya/screens/landing_screen.dart';
import 'package:zefanya/screens/onboarding_screen.dart';
import 'package:zefanya/services/google_sign_in_service.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/chat_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/notification_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/startup_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

Future<AuthState> _signedIn(UserRole role) async {
  final auth = AuthState();
  await auth.login(
    role: role,
    email: switch (role) {
      UserRole.buyer => 'buyer@example.ph',
      UserRole.courier => 'courier@example.ph',
      UserRole.linehaul => 'linehaul@example.ph',
    },
    password: 'Password1!',
  );
  expect(auth.status, AccountStatus.active);
  expect(auth.role, role);
  return auth;
}

Future<void> _pumpGate(
  WidgetTester tester,
  AuthState auth, {
  OrderState? orders,
  ReviewState? reviews,
  CourierState? rider,
  bool showApp = false,
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;

  final repositories = Repositories.mock();
  await tester.pumpWidget(
    MultiProvider(
      providers: [
        Provider<Repositories>.value(value: repositories),
        ChangeNotifierProvider<AuthState>.value(value: auth),
        ChangeNotifierProvider(create: (_) => StartupState()),
        ChangeNotifierProvider(
          create: (_) => CartState(
            catalog: repositories.catalog,
            vouchers: repositories.cart,
          ),
        ),
        ChangeNotifierProvider(
          create: (_) => CatalogState(repository: repositories.catalog),
        ),
        ChangeNotifierProvider(
          create: (_) => ChatState(repository: repositories.chat),
        ),
        ChangeNotifierProvider(create: (_) => rider ?? CourierState()),
        ChangeNotifierProvider(create: (_) => NotificationState()),
        ChangeNotifierProvider(
          create: (_) => orders ?? OrderState(repository: repositories.order),
        ),
        ChangeNotifierProvider(
          create: (_) => RecentlyViewedState(catalog: repositories.catalog),
        ),
        ChangeNotifierProvider(
          create: (_) =>
              reviews ?? ReviewState(repository: repositories.review),
        ),
        ChangeNotifierProvider(
          create: (_) => WishlistState(catalog: repositories.catalog),
        ),
        ChangeNotifierProvider(create: (_) => ThemeState()),
      ],
      child: showApp
          ? const MarketplaceApp()
          : MaterialApp(theme: AppTheme.light, home: const AuthGate()),
    ),
  );
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 900));
}

class _LoginOrders extends MockOrderRepository {
  int requests = 0;

  @override
  Future<List<Order>> orders() async {
    if (++requests == 1) {
      throw const UnauthorizedException(message: 'Unauthenticated.');
    }
    return const [];
  }
}

class _LoginReviews extends MockReviewRepository {
  int requests = 0;

  @override
  Future<List<ProductReview>> all() async {
    if (++requests == 1) throw const UnauthorizedException();
    return [
      ProductReview(
        productId: '1',
        orderId: '2',
        stars: 5,
        editCount: 1,
        createdAt: DateTime.now(),
      ),
    ];
  }
}

class _DispatchRefresh extends MockCourierRepository {
  int reads = 0;

  @override
  bool get isServerAuthoritative => true;

  @override
  Future<List<DeliveryJob>> assignedJobs() async => ++reads == 1
      ? const []
      : const [
          DeliveryJob(
            id: 'dispatched-after-login',
            shopName: 'Newly dispatched shop',
            pickupAddress: 'Pila',
            buyerName: 'Demo Buyer',
            deliveryArea: 'Pila',
            packageSize: 'Small parcel',
            fee: 30,
            serverStatus: 'ASSIGNED_TO_RIDER',
          ),
        ];
}

void main() {
  testWidgets('a rapid repeat cart tap needs only one Back action', (
    tester,
  ) async {
    await _pumpGate(tester, await _signedIn(UserRole.buyer));
    final button = find.byTooltip('Open cart').hitTestable();
    final target = tester.getCenter(button);
    await tester.tapAt(target);
    await tester.tapAt(target);
    await tester.pumpAndSettle();
    expect(find.byType(CartScreen, skipOffstage: false), findsOneWidget);
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.byType(CartScreen), findsNothing);
    expect(find.byType(BuyerShell), findsOneWidget);
  });

  testWidgets('checkout navigation selects the mounted Orders tab', (
    tester,
  ) async {
    final orders = OrderState();
    await _pumpGate(tester, await _signedIn(UserRole.buyer), orders: orders);
    final existing = tester.state(
      find.byType(OrdersStatusScreen, skipOffstage: false),
    );
    orders.requestOrdersTab();
    await tester.pumpAndSettle();
    final visible = find.byType(OrdersStatusScreen).hitTestable();
    expect(visible, findsOneWidget);
    expect(tester.state(visible), same(existing));
    expect(Navigator.of(tester.element(visible)).canPop(), isFalse);
    await tester.tap(find.text('Home').hitTestable());
    await tester.pumpAndSettle();
    orders.requestOrdersTab();
    await tester.pumpAndSettle();
    expect(tester.state(visible), same(existing));
  });

  if (!kUseLiveApi) {
    testWidgets('all demo roles open locally with a persistent demo label', (
      tester,
    ) async {
      SharedPreferences.setMockInitialValues({
        OnboardingScreen.completionKey: true,
        'auth_role': 'buyer',
      });
      final auth = AuthState();
      await _pumpGate(tester, auth, showApp: true);
      await tester.tap(find.text('Sign in'));
      await tester.pumpAndSettle();
      final banner = find.text('DEMO MODE · Sample data');
      expect(banner, findsOneWidget);

      for (final (label, shell) in [
        ('Buyer demo', BuyerShell),
        ('Rider demo', CourierShell),
        ('Transport Courier demo', LinehaulPortal),
      ]) {
        await tester.tap(find.text('Choose a demo role ▾'));
        await tester.pumpAndSettle();
        await tester.tap(find.text(label));
        await tester.pumpAndSettle();
        expect(find.byType(shell), findsOneWidget);
        expect(banner, findsOneWidget);
        if (shell != LinehaulPortal) {
          await auth.logout();
          await tester.pumpAndSettle();
        }
      }

      expect(find.text('Trip #102'), findsOneWidget);
      final scan = find.widgetWithText(FilledButton, 'Scan or enter parcel');
      expect(tester.widget<FilledButton>(scan).onPressed, isNull);
      for (final (tab, content) in [
        ('History', 'Trip #101'),
        ('Earnings', 'Sample earned pay'),
        (
          'Profile',
          'Sample duty status. Location and duty changes are disabled.',
        ),
      ]) {
        await tester.tap(find.text(tab));
        await tester.pumpAndSettle();
        expect(find.text(content), findsOneWidget);
        expect(banner, findsOneWidget);
      }

      final profile = find.text('Company & profile');
      await tester.ensureVisible(profile);
      await tester.tap(profile);
      await tester.pumpAndSettle();
      expect(find.text('Zefanya Demo Logistics'), findsOneWidget);
      final vehicle = find.widgetWithText(OutlinedButton, 'Vehicle records');
      await tester.scrollUntilVisible(
        vehicle,
        160,
        scrollable: find.byType(Scrollable).first,
      );
      expect(tester.widget<OutlinedButton>(vehicle).onPressed, isNull);

      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString('auth_role'), 'buyer');
      expect(prefs.getString('auth_status'), isNull);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('opening Rider Deliveries refreshes a new server assignment', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({});
    final repository = _DispatchRefresh();
    await _pumpGate(
      tester,
      await _signedIn(UserRole.courier),
      rider: CourierState(repository: repository),
    );
    expect(repository.reads, 1);
    expect(find.text('Newly dispatched shop'), findsNothing);
    await tester.tap(find.text('Deliveries'));
    await tester.pumpAndSettle();
    expect(repository.reads, 2);
    expect(find.text('Newly dispatched shop'), findsOneWidget);
  });

  testWidgets('welcome opens the existing Buyer registration directly', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({
      OnboardingScreen.completionKey: true,
    });
    await _pumpGate(tester, AuthState());
    await tester.pumpAndSettle();
    await tester.tap(find.text('Create Buyer account'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 900));
    expect(find.byType(RegistrationScreen), findsOneWidget);
    expect(find.byType(LandingScreen), findsNothing);
  });

  testWidgets('buyer entry refreshes server reviews after signed-out startup', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({});
    final repository = _LoginReviews();
    final reviews = ReviewState(repository: repository);
    await expectLater(reviews.load(), throwsA(isA<UnauthorizedException>()));
    await _pumpGate(tester, await _signedIn(UserRole.buyer), reviews: reviews);
    await tester.pump(const Duration(milliseconds: 100));
    expect(repository.requests, 2);
    expect(reviews.all.single.editCount, 1);
  });
  for (final role in UserRole.values) {
    testWidgets('logout and Back cannot reopen the ${role.name} shell', (
      tester,
    ) async {
      SharedPreferences.setMockInitialValues({});
      final auth = await _signedIn(role);
      await _pumpGate(tester, auth);
      expect(find.byType(LandingScreen), findsNothing);
      expect(find.byType(OnboardingScreen), findsNothing);
      await auth.logout();
      await tester.pumpAndSettle();
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.byType(BuyerShell), findsNothing);
      expect(find.byType(CourierShell), findsNothing);
      expect(find.byType(LinehaulPortal), findsNothing);
      expect(auth.isLoggedIn, isFalse);
      await tester.pumpWidget(const SizedBox.shrink());
      await _pumpGate(tester, AuthState());
      await tester.pumpAndSettle();
      expect(find.byType(LandingScreen), findsOneWidget);
      expect(find.byType(OnboardingScreen), findsNothing);
    });
  }
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('buyer entry reloads orders after a signed-out startup failure', (
    tester,
  ) async {
    final repository = _LoginOrders();
    final orders = OrderState(repository: repository);
    await orders.load();
    expect(orders.loadFailure, isA<UnauthorizedException>());

    final auth = await _signedIn(UserRole.buyer);
    await _pumpGate(tester, auth, orders: orders);
    await tester.pump(const Duration(milliseconds: 100));

    expect(repository.requests, 2);
    expect(orders.loadFailure, isNull);
    expect(find.byType(BuyerShell), findsOneWidget);
  });

  testWidgets('an active courier session lands in CourierShell', (
    tester,
  ) async {
    final auth = await _signedIn(UserRole.courier);
    await _pumpGate(tester, auth);

    expect(find.byType(CourierShell), findsOneWidget);
    expect(find.byType(BuyerShell), findsNothing);
  });

  testWidgets('an active buyer session lands in BuyerShell', (tester) async {
    final auth = await _signedIn(UserRole.buyer);
    await _pumpGate(tester, auth);

    expect(find.byType(BuyerShell), findsOneWidget);
    expect(find.byType(CourierShell), findsNothing);
  });

  testWidgets(
    'linehaul session opens only the Courier portal and fails closed without a live client',
    (tester) async {
      await _pumpGate(tester, await _signedIn(UserRole.linehaul));
      expect(find.byType(LinehaulPortal), findsOneWidget);
      expect(find.byType(CourierShell), findsNothing);
      expect(find.byType(BuyerShell), findsNothing);
      expect(find.text('Accept trip'), findsNothing);
    },
  );

  test(
    'a first-time Google identity becomes pending, never an active courier',
    () async {
      final auth = AuthState(
        googleAuth: const MockGoogleAuthRepository(),
        googleSignIn: const MockGoogleSignInService(
          identity: GoogleIdentity(
            idToken: 'test-id-token',
            email: 'new.person@gmail.com',
            displayName: 'New Person',
          ),
        ),
      );

      final outcome = await auth.signInWithGoogle();
      expect(outcome.needsRole, isTrue);
      expect(auth.status, AccountStatus.loggedOut);
      expect(auth.role, isNull);

      await auth.registerWithGoogle(
        role: UserRole.courier,
        profile: RegistrationProfile(
          lastName: 'Person',
          firstName: 'New',
          sex: 'Male',
          phone: '09171234567',
          birthday: DateTime(1998, 4, 7),
          province: 'Cebu',
          municipality: 'Cebu City',
          barangay: 'Lahug',
          street: '12 Mabini Street',
        ),
      );

      expect(auth.role, UserRole.courier);
      expect(auth.status, AccountStatus.pendingApproval);
      expect(auth.isLoggedIn, isFalse);
    },
  );
}
