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

Future<void> _pumpGate(WidgetTester tester, AuthState auth) async {
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
          create: (_) => OrderState(repository: repositories.order),
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

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

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
