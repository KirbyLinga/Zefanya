// test/login_switch_test.dart
//
// Tests for Phase B1: Login buyer <-> rider presentation switch.
//
// Key requirements:
//  - LABELED link ("Are you a rider? Sign in here" / "Back to shopper sign in"),
//    min 48dp, Semantics label.
//  - Mode changes presentation only: heading, accent, subcopy, and which
//    registration link shows.
//  - The mode is never sent as the role: role comes from the server.
//  - If credentials belong to the other role, sign in normally, route by the real
//    role from the server, and show a one-time snackbar "Signed in as a buyer account".
//  - Google in rider mode leads to the rider application, never an active rider.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zefanya/data/dto/registration_payload.dart';
import 'package:zefanya/data/google_identity.dart';
import 'package:zefanya/data/repositories/auth_repository.dart';
import 'package:zefanya/data/repositories/google_auth_repository.dart';
import 'package:zefanya/screens/auth/login_screen.dart';
import 'package:zefanya/screens/auth/registration_screen.dart';
import 'package:zefanya/services/google_sign_in_service.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/theme/app_theme.dart';

class _FakeAuthRepository implements AuthRepository {
  _FakeAuthRepository({this.returningRole = UserRole.buyer});

  final UserRole returningRole;
  UserRole? roleReceived;

  @override
  Future<AuthResult> login({
    required UserRole role,
    required String email,
    required String password,
  }) async {
    roleReceived = role;
    return AuthResult(
      role: returningRole,
      status: AccountStatus.active,
      email: email,
    );
  }

  @override
  Future<AuthResult> register({
    required UserRole role,
    required String email,
    required String password,
    required RegistrationProfile profile,
  }) async =>
      AuthResult(role: role, status: AccountStatus.pendingApproval, email: email);

  @override
  Future<void> logout({UserRole role = UserRole.buyer}) async {}

  @override
  Future<AuthResult> verifyOtp({
    required UserRole role,
    required String verificationId,
    required String code,
  }) async =>
      AuthResult(role: role, status: AccountStatus.active);

  @override
  Future<void> resendOtp({
    required UserRole role,
    required String verificationId,
  }) async {}

  @override
  Future<AuthResult?> refresh({UserRole role = UserRole.buyer}) async => null;
}

Future<void> _pumpLogin(
  WidgetTester tester, {
  required AuthState auth,
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;

  await tester.pumpWidget(
    ChangeNotifierProvider<AuthState>.value(
      value: auth,
      child: MaterialApp(
        theme: AppTheme.light,
        home: const Scaffold(body: LoginScreen()),
      ),
    ),
  );
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 600));
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets(
    'switch link toggles presentation mode with semantics and min 48dp target',
    (tester) async {
      final auth = AuthState(repository: _FakeAuthRepository());
      await _pumpLogin(tester, auth: auth);

      // Initial state: Buyer presentation
      expect(find.text('WELCOME BACK'), findsOneWidget);
      expect(find.text('Zefanya'), findsOneWidget);
      expect(
        find.text('Sign in to browse, shop, and track your orders'),
        findsOneWidget,
      );
      expect(find.text('Are you a rider? Sign in here'), findsOneWidget);
      expect(find.text('Create a Buyer Account'), findsOneWidget);
      expect(find.text('Apply as a Rider'), findsNothing);

      // Verify semantics and touch target size (at least 48x48 dp)
      final switchFinder = find.byKey(const ValueKey('role-mode-switch-link'));
      expect(switchFinder, findsOneWidget);
      final size = tester.getSize(switchFinder);
      expect(size.height, greaterThanOrEqualTo(48.0));
      expect(size.width, greaterThanOrEqualTo(48.0));

      final semantics = tester.getSemantics(switchFinder);
      expect(semantics.label, 'Are you a rider? Sign in here');

      // Tap link to toggle into Rider mode
      await tester.tap(switchFinder);
      await tester.pumpAndSettle();

      // Rider presentation active
      expect(find.text('RIDER PORTAL'), findsOneWidget);
      expect(find.text('Zefanya Rider'), findsOneWidget);
      expect(
        find.text('Sign in to accept jobs and deliver parcels'),
        findsOneWidget,
      );
      expect(find.text('Back to shopper sign in'), findsOneWidget);
      expect(find.text('Apply as a Rider'), findsOneWidget);
      expect(find.text('Create a Buyer Account'), findsNothing);

      final riderSemantics = tester.getSemantics(switchFinder);
      expect(riderSemantics.label, 'Back to shopper sign in');

      // Toggle back to Shopper mode
      await tester.tap(switchFinder);
      await tester.pumpAndSettle();

      expect(find.text('WELCOME BACK'), findsOneWidget);
      expect(find.text('Are you a rider? Sign in here'), findsOneWidget);
    },
  );

  testWidgets(
    'credentials of another role in rider mode sign in normally and show snackbar',
    (tester) async {
      // Fake repository returns UserRole.buyer (simulating account is a buyer)
      final repo = _FakeAuthRepository(returningRole: UserRole.buyer);
      final auth = AuthState(repository: repo);
      await _pumpLogin(tester, auth: auth);

      // Switch to rider mode
      final switchFinder = find.byKey(const ValueKey('role-mode-switch-link'));
      await tester.tap(switchFinder);
      await tester.pumpAndSettle();

      // Enter buyer credentials
      final emailFinder = find.byType(TextFormField).first;
      final passwordFinder = find.byType(TextFormField).at(1);
      await tester.enterText(emailFinder, 'buyer@example.ph');
      await tester.enterText(passwordFinder, 'Password123!');
      await tester.pump();

      // Submit login
      await tester.tap(find.text('Login as Rider'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      // Verifies the user was signed in and one-time snackbar shown
      expect(find.text('Signed in as a buyer account'), findsOneWidget);
      expect(auth.isLoggedIn, isTrue);
      expect(auth.role, UserRole.buyer);
      await tester.pump(const Duration(seconds: 4));
    },
  );

  testWidgets(
    'Google in rider mode leads to the rider application, never an active rider',
    (tester) async {
      final auth = AuthState(
        repository: _FakeAuthRepository(),
        googleAuth: const MockGoogleAuthRepository(),
        googleSignIn: const MockGoogleSignInService(
          identity: GoogleIdentity(
            idToken: 'test-rider-token',
            email: 'new.rider@gmail.com',
            displayName: 'New Rider',
          ),
        ),
      );

      await _pumpLogin(tester, auth: auth);

      // Switch to rider mode
      final switchFinder = find.byKey(const ValueKey('role-mode-switch-link'));
      await tester.tap(switchFinder);
      await tester.pumpAndSettle();

      // Tap Continue with Google
      final googleButton = find.byKey(const ValueKey('login-google-button'));
      await tester.tap(googleButton);
      await tester.pumpAndSettle();

      // Verify that RegistrationScreen opened directly with courier/rider role
      final registration = tester.widget<RegistrationScreen>(
        find.byType(RegistrationScreen),
      );
      expect(registration.initialRole, UserRole.courier);
      expect(auth.isLoggedIn, isFalse);
    },
  );
}
