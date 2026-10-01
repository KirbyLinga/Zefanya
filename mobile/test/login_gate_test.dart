// test/login_gate_test.dart
//
// A refused sign-in that is a *gate* rather than a failure.
//
// The gap this closes: `PENDING_VERIFICATION` is the one refusal the app can do
// something about. The credentials were accepted, and the server sends the
// handle its code can be confirmed against — but the login screen caught every
// `ApiException` the same way, showed its message, and stopped. So someone told
// to confirm their email had no way to confirm it: the OTP screen needs a handle
// the app had just thrown away, and registering again would open a second
// account rather than confirm the first.
//
// Two claims, and the second is what keeps the first honest:
//
//   * the gate refusal moves the session onto the gate, handle and all, so the
//     pending screen's confirm step is live rather than dead;
//   * every *other* refusal still ends on the form with the server's sentence,
//     because "some failures sign you in" would be a far worse bug than the dead
//     end being replaced.
//
// Where the types come from: `ApiClient` is what turns a 403 into
// [PendingApprovalException] with its handle attached, and `live_auth_test` is
// what pins that. This file starts from the exception the screen actually
// receives, so it tests the screen's decision rather than the mapping again.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/dto/registration_payload.dart';
import 'package:zefanya/data/dto/remote_status.dart';
import 'package:zefanya/data/repositories/auth_repository.dart';
import 'package:zefanya/screens/auth/login_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/theme/app_theme.dart';

/// A repository whose `login` fails exactly the way the test says it does, and
/// which records the password it was handed.
class _RefusingAuthRepository implements AuthRepository {
  _RefusingAuthRepository({this.loginFailure});

  final ApiException? loginFailure;
  String? passwordSeen;

  @override
  Future<AuthResult> login({
    required UserRole role,
    required String email,
    required String password,
  }) async {
    passwordSeen = password;
    final failure = loginFailure;
    if (failure != null) throw failure;
    return AuthResult(role: role, status: AccountStatus.active, email: email);
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
  Future<void> logout() async {}

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
  Future<AuthResult?> refresh() async => null;
}

/// The refusal `Api\Buyer\LoginController` sends for an account whose address is
/// not confirmed yet — the gate, with the handle its code can be sent against.
const _emailGate = PendingApprovalException(
  registrationStatus: 'pending_verification',
  verificationId: '42',
  message: 'Confirm your email address to continue.',
);

/// Pumps the login screen in the provider and theme it reads.
///
/// Deliberately at the test binding's default size rather than a phone width,
/// unlike the other auth-screen harnesses — and the reason is the harness's font,
/// not the screen. `_LoginMasthead` is a Row containing the wordmark at
/// `AppType.masthead` (54pt) plus a 20pt mark, and in a widget test the default
/// font gives every glyph the width of the font size, so "Zefanya" measures
/// ~370pt and overflows the 342pt column a 390pt phone leaves after the screen's
/// 24pt padding. A real proportionally-spaced face sets the same wordmark at
/// roughly 230pt wide. Nothing in this file asserts on layout, and the auth
/// screens' phone-width layout is covered by `otp_flow_test` and
/// `registration_flow_test`, whose screens have no wordmark in them.
Future<void> _pumpLogin(WidgetTester tester, AuthState auth) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;

  await tester.pumpWidget(
    ChangeNotifierProvider<AuthState>.value(
      value: auth,
      child: MaterialApp(
        theme: AppTheme.light,
        home: const LoginScreen(),
      ),
    ),
  );
  // Past `StaggeredReveal`'s timers: one still pending at teardown is itself a
  // failure, and waiting out the screen's own reveal is the test's job.
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 600));
}

/// Fills both fields with answers the form's own validators accept, then submits.
///
/// Fields are located by hint because field order is a layout detail, while "the
/// field that says Enter your password" is what the test actually means.
Future<void> _signIn(WidgetTester tester, {required String email}) async {
  final emailField = find.ancestor(
    of: find.text('you@example.com or 09XXXXXXXXX'),
    matching: find.byType(TextFormField),
  );
  final passwordField = find.ancestor(
    of: find.text('Enter your password'),
    matching: find.byType(TextFormField),
  );
  expect(emailField, findsOneWidget, reason: 'no email field');
  expect(passwordField, findsOneWidget, reason: 'no password field');

  await tester.enterText(emailField, email);
  await tester.enterText(passwordField, 'Password1!');
  await tester.pump();

  final button = find.widgetWithText(ElevatedButton, 'Login');
  await tester.ensureVisible(button);
  await tester.tap(button);

  // Two pumps rather than a settle: while the call is in flight the button shows
  // a `ZefanyaLoader`, whose animation repeats forever.
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 600));
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('a refused sign-in at the email gate opens that gate',
      (tester) async {
    final repository = _RefusingAuthRepository(loginFailure: _emailGate);
    final auth = AuthState(repository: repository);

    await _pumpLogin(tester, auth);
    await _signIn(tester, email: 'juan@example.com');

    // The password really reached the repository, so this cannot pass by
    // short-circuiting before the call.
    expect(repository.passwordSeen, 'Password1!');

    expect(auth.status, AccountStatus.pendingApproval);
    expect(
      auth.registrationStatus,
      RemoteRegistrationStatus.pendingVerification,
    );
    // The address is the one that was typed: the server's refusal deliberately
    // does not echo it, and the form is the only place that already knows.
    expect(auth.email, 'juan@example.com');
    expect(auth.verificationId, '42');
    // The two facts `PendingApprovalScreen` reads to decide whether it can offer
    // its confirm step at all.
    expect(auth.canConfirmEmail, isTrue);

    // The refusal was *not* reported as an error on the form. Showing it would
    // tell someone about a problem while doing nothing about it — the dead end
    // this replaced.
    expect(find.text('Confirm your email address to continue.'), findsNothing);
  });

  testWidgets('every other refusal stays on the form, with its sentence',
      (tester) async {
    final repository = _RefusingAuthRepository(
      loginFailure: const UnauthorizedException(
        message: 'These credentials do not match our records.',
      ),
    );
    final auth = AuthState(repository: repository);

    await _pumpLogin(tester, auth);
    await _signIn(tester, email: 'juan@example.com');

    expect(
      find.text('These credentials do not match our records.'),
      findsOneWidget,
    );
    // Nothing moved. A wrong password must not put anyone anywhere, and a
    // refusal with no handle must not be offered as a gate either.
    expect(auth.status, AccountStatus.loggedOut);
    expect(auth.role, isNull);
    expect(auth.verificationId, isNull);
    expect(auth.canConfirmEmail, isFalse);

    // Drain `showTimedSnackBar`'s removal timer before teardown.
    await tester.pump(const Duration(seconds: 4));
  });
}
