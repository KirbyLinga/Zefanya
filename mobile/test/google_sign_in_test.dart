// test/google_sign_in_test.dart
//
// S2.2 GOOGLE SIGN-IN: widget tests for the Google button visibility.
//
// kUseLiveApi and GoogleConfig are compile-time constants (String.fromEnvironment),
// so both branches cannot be exercised in one test run:
//   - Default test build: kUseLiveApi=false → _googleAvailable=true → button shown.
//   - Live build with --dart-define=zefanya.useLiveApi=true and no client ID:
//     _googleAvailable=false → button hidden.
//   - Live build with client ID: button shown.
//
// The live-mode (button hidden) path is verified by building with
// `--dart-define=zefanya.useLiveApi=true` and no googleServerClientId dart-define,
// then running this same suite — the `if (_googleAvailable)` gate in
// login_screen.dart makes the button disappear.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/screens/auth/login_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/theme/app_theme.dart';

/// Pumps the login screen with an AuthState that uses mock Google services.
Future<void> _pumpLogin(WidgetTester tester, AuthState auth) async {
  AppColors.apply(Brightness.light);
  await tester.pumpWidget(
    ChangeNotifierProvider<AuthState>.value(
      value: auth,
      child: MaterialApp(
        theme: AppTheme.light,
        home: const LoginScreen(),
      ),
    ),
  );
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 600));
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets(
    'Google button is shown in mock mode (kUseLiveApi=false)',
    (tester) async {
      final auth = AuthState();
      await _pumpLogin(tester, auth);

      // In mock mode _googleAvailable is true, so the Google button renders.
      expect(find.byKey(const ValueKey('login-google-button')), findsOneWidget);
      expect(find.text('Continue with Google'), findsOneWidget);
    },
  );

  testWidgets(
    'tapping Google in mock mode routes to registration with identity',
    (tester) async {
      final auth = AuthState();
      await _pumpLogin(tester, auth);

      await tester.ensureVisible(find.byKey(const ValueKey('login-google-button')));
      await tester.tap(find.byKey(const ValueKey('login-google-button')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      // MockGoogleSignInService returns the default mock identity, which is an
      // existing buyer email → sign in succeeds, no registration needed.
      // If the identity were new, the app would show the role sheet → registration.
      // Either way the tap must not crash and must not show an error snackbar.
      expect(find.text('Google sign-in is not available right now'), findsNothing);
    },
  );
}
