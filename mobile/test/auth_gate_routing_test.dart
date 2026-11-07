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

import 'package:zefanya/data/dto/registration_payload.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/mock_data.dart' show Order;
import 'package:zefanya/data/repositories/order_repository.dart';
import 'package:zefanya/data/google_identity.dart';
import 'package:zefanya/data/repositories/google_auth_repository.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/main.dart';
import 'package:zefanya/screens/buyer/buyer_shell.dart';
import 'package:zefanya/screens/courier/courier_shell.dart';
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
    email: role == UserRole.courier ? 'courier@example.ph' : 'buyer@example.ph',
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
        ChangeNotifierProvider(create: (_) => CourierState()),
        ChangeNotifierProvider(create: (_) => NotificationState()),
        ChangeNotifierProvider(
          create: (_) => orders ?? OrderState(repository: repositories.order),
        ),
        ChangeNotifierProvider(
          create: (_) => RecentlyViewedState(catalog: repositories.catalog),
        ),
        ChangeNotifierProvider(
          create: (_) => ReviewState(repository: repositories.review),
        ),
        ChangeNotifierProvider(
          create: (_) => WishlistState(catalog: repositories.catalog),
        ),
        ChangeNotifierProvider(create: (_) => ThemeState()),
      ],
      child: MaterialApp(theme: AppTheme.light, home: const AuthGate()),
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

void main() {
  for (final role in UserRole.values) {
    testWidgets('logout and Back cannot reopen the ${role.name} shell', (tester) async {
      SharedPreferences.setMockInitialValues({});
      final auth = await _signedIn(role);
      await _pumpGate(tester, auth);
      await auth.logout();
      await tester.pumpAndSettle();
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.byType(BuyerShell), findsNothing);
      expect(find.byType(CourierShell), findsNothing);
      expect(auth.isLoggedIn, isFalse);
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
