// test/otp_flow_test.dart
//
// The second registration gate: the emailed code.
//
// The gap this closes is the one the password step left open.
// `registration_flow_test` pins that a registration reaches the repository, and
// that the pending screen can tell its two gates apart; what it could not cover
// is the gate that contains an *action*, because until this screen existed there
// was no way to take the action. A pending screen saying "we emailed you a code"
// and then offering nothing to do with it is the app making a promise on someone
// else's behalf: `VerifyBuyerOtpController` is real, so a code really is sent,
// and the app was simply declining to take it.
//
// Three kinds of assertion, for three different claims:
//
//   * the mock's honesty rules — it must never claim an email gate it cannot
//     serve — which are state logic and need no widgets;
//   * the seam: what reaches the repository, and what a *failure* leaves behind;
//   * the screen: validation, the cooldown, and the round trip back to the
//     pending screen, which only a widget test can show.
//
// Note on pumping: the pending screen's arch holds a `ZefanyaLoader`, whose
// controller repeats forever, so `pumpAndSettle` never returns on it —
// `_settleReveal` below advances real durations instead.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/config/dev_flags.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/dto/registration_payload.dart';
import 'package:zefanya/data/dto/remote_status.dart';
import 'package:zefanya/data/repositories/auth_repository.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/screens/auth/otp_screen.dart';
import 'package:zefanya/screens/auth/pending_approval_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/theme/app_theme.dart';

/// A repository whose registration answers whichever gate the test asks for, and
/// whose confirmation records what it was asked.
///
/// The registration status is routed through [accountStatusFor] rather than
/// picked by hand, so the scripted answer is the one a real server's status
/// would produce — a test that decided `pendingApproval` itself could pass while
/// the real mapping was wrong.
class _ScriptedOtpRepository implements AuthRepository {
  _ScriptedOtpRepository({
    this.verificationId = 'buyer-77',
    this.verifyThrows,
    this.resendThrows,
  });

  /// Fixed at the email gate, because this file is about that gate: a knob no
  /// test turns is a knob that rots. The real mock's *own* answer — the admin
  /// gate, with no handle — is what `_registeredWithMock` exercises.
  RemoteRegistrationStatus get registrationStatus =>
      RemoteRegistrationStatus.pendingVerification;

  /// What the server hands back for this registration. Null is a real answer and
  /// the interesting one: the gate is open but nothing can be sent against it.
  final String? verificationId;

  final ApiException? verifyThrows;
  final ApiException? resendThrows;

  String? codeSeen;
  String? verificationIdSeen;
  String? passwordSeen;
  bool resendCalled = false;

  @override
  Future<AuthResult> login({
    required UserRole role,
    required String email,
    required String password,
  }) async =>
      AuthResult(role: role, status: AccountStatus.active, email: email);

  @override
  Future<AuthResult> register({
    required UserRole role,
    required String email,
    required String password,
    required RegistrationProfile profile,
  }) async {
    passwordSeen = password;
    return AuthResult(
      role: role,
      status: accountStatusFor(registrationStatus),
      email: email,
      registrationStatus: registrationStatus,
      verificationId: verificationId,
    );
  }

  @override
  Future<AuthResult> verifyOtp({
    required UserRole role,
    required String verificationId,
    required String code,
  }) async {
    verificationIdSeen = verificationId;
    codeSeen = code;
    final failure = verifyThrows;
    if (failure != null) throw failure;
    return AuthResult(
      role: role,
      status: AccountStatus.pendingApproval,
      registrationStatus: RemoteRegistrationStatus.pendingApproval,
    );
  }

  @override
  Future<void> resendOtp({
    required UserRole role,
    required String verificationId,
  }) async {
    resendCalled = true;
    final failure = resendThrows;
    if (failure != null) throw failure;
  }

  @override
  Future<void> logout() async {}

  @override
  Future<AuthResult?> refresh() async => null;
}

RegistrationProfile _profile() => RegistrationProfile(
      lastName: 'Dela Cruz',
      firstName: 'Juan',
      middleInitial: 'D',
      sex: 'Male',
      phone: '09171234567',
      birthday: DateTime(1998, 4, 7),
      province: 'Cebu',
      municipality: 'Cebu City',
      barangay: 'Lahug',
      street: '12 Mabini Street',
      govIdPath: '/data/user/0/ph.zefanya/files/gov_id_01.jpg',
    );

/// A session that has just registered, at whichever gate [repository] answers.
Future<AuthState> _registered(AuthRepository repository) async {
  final auth = AuthState(repository: repository);
  await auth.submitForApproval(
    role: UserRole.buyer,
    email: 'tristan@example.ph',
    password: 'hunter2hunter2',
    name: 'Tristan Araneta',
    profile: _profile(),
  );
  return auth;
}

/// The same, against the real mock — for the tests about what the *mock* does
/// rather than about what `AuthState` does with an answer.
Future<AuthState> _registeredWithMock() async {
  final auth = AuthState();
  await auth.submitForApproval(
    role: UserRole.buyer,
    email: 'tristan@example.ph',
    password: 'hunter2hunter2',
    name: 'Tristan Araneta',
    profile: _profile(),
  );
  return auth;
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  // ---- What the mock is allowed to claim ------------------------------------

  group('the mock never claims an email gate it cannot serve', () {
    test('a mock registration lands on the admin gate, with no handle', () async {
      final auth = await _registeredWithMock();

      expect(auth.registrationStatus, RemoteRegistrationStatus.pendingApproval);
      expect(auth.awaitsEmailVerification, isFalse);
      // The half that makes the claim honest: no code was sent, so there is
      // nothing to confirm, so no handle is invented for one.
      expect(auth.verificationId, isNull);
      expect(auth.canConfirmEmail, isFalse);
    });

    test('the mock accepts its own code and rejects every other one', () async {
      // kMockOtpCode rather than the literal: a hardcoded '123456' here would
      // keep passing after the constant moved, and what has to agree is the
      // *pair* (mock, screen hint), not the digits.
      final accepted = await const MockAuthRepository().verifyOtp(
        role: UserRole.buyer,
        verificationId: kMockVerificationId,
        code: kMockOtpCode,
      );

      expect(accepted.status, AccountStatus.pendingApproval);
      expect(accepted.registrationStatus, RemoteRegistrationStatus.pendingApproval);

      await expectLater(
        const MockAuthRepository().verifyOtp(
          role: UserRole.buyer,
          verificationId: kMockVerificationId,
          code: '000000',
        ),
        throwsA(isA<RequestRejectedException>()),
      );
    });

    test('a wrong code is a rejection carrying the server sentence', () async {
      try {
        await const MockAuthRepository().verifyOtp(
          role: UserRole.buyer,
          verificationId: kMockVerificationId,
          code: '999999',
        );
        fail('a wrong code must not be accepted');
      } on RequestRejectedException catch (e) {
        expect(e.message, "That code doesn't match. Please try again.");
        // Not a ValidationException, and not retryable: the server's 422 for a
        // wrong code carries a message and no field bag, so a field-keyed type
        // would claim a precision the response does not have, and retrying the
        // same digits would fail the same way.
        expect(e.isRetryable, isFalse);
      }
    });
  });

  // ---- The seam -------------------------------------------------------------

  group('the confirmation goes through the repository', () {
    test('the handle the registration returned is what the code is sent against',
        () async {
      final repository = _ScriptedOtpRepository(verificationId: 'buyer-77');
      final auth = await _registered(repository);
      expect(auth.canConfirmEmail, isTrue);

      await auth.confirmEmail('123456');

      // Both halves of the request, and the handle is the *server's* string
      // rather than anything this app assembled — an email is not an id.
      expect(repository.verificationIdSeen, 'buyer-77');
      expect(repository.codeSeen, '123456');
    });

    test('the answer decides the new gate, and the handle goes with the old one',
        () async {
      final auth = await _registered(_ScriptedOtpRepository());
      await auth.confirmEmail('123456');

      expect(auth.registrationStatus, RemoteRegistrationStatus.pendingApproval);
      expect(auth.awaitsEmailVerification, isFalse);
      expect(auth.canConfirmEmail, isFalse);
      // Cleared by the answer rather than by anyone remembering to clear it: a
      // confirmed registration has nothing left to confirm.
      expect(auth.verificationId, isNull);
    });

    test('a gate with no handle offers nothing to confirm', () async {
      final auth = await _registered(_ScriptedOtpRepository(verificationId: null));

      expect(auth.awaitsEmailVerification, isTrue);
      // The half a screen forgets. Every call this app could make needs the
      // handle, so "the gate is open" is not on its own a reason to render a
      // field whose submit could not be addressed to anything.
      expect(auth.canConfirmEmail, isFalse);
    });

    test('a refused code leaves the gate open rather than half-closed', () async {
      final auth = await _registered(_ScriptedOtpRepository(
        verifyThrows: const RequestRejectedException(
          statusCode: 422,
          message: 'That code does not match.',
        ),
      ));

      await expectLater(
        auth.confirmEmail('000000'),
        throwsA(isA<RequestRejectedException>()),
      );

      expect(auth.registrationStatus, RemoteRegistrationStatus.pendingVerification);
      expect(auth.canConfirmEmail, isTrue);
    });

    test('the handle survives a restart, or the code could not be entered later',
        () async {
      await _registered(_ScriptedOtpRepository(verificationId: 'buyer-77'));

      // A cold start: a brand-new state reading what the previous one saved.
      final restarted = AuthState();
      await restarted.load();

      expect(restarted.canConfirmEmail, isTrue);
      expect(restarted.verificationId, 'buyer-77');
    });

    test('signing out forgets the registration, handle included', () async {
      final auth = await _registered(_ScriptedOtpRepository());
      await auth.logout();

      expect(auth.verificationId, isNull);
      expect(auth.canConfirmEmail, isFalse);
    });

    test('confirmation with nothing to confirm with sends nothing', () async {
      // Unreachable through the UI, which gates the screen on `canConfirmEmail`.
      // The point is that it does not invent a request to have something to do.
      final repository = _ScriptedOtpRepository(verificationId: null);
      final auth = await _registered(repository);

      await auth.confirmEmail('123456');

      expect(repository.codeSeen, isNull);
      expect(repository.verificationIdSeen, isNull);
    });

    test('the dev-tools gate cannot be opened without a registration', () async {
      // It is an affordance *inside* a pending registration, not a way to skip
      // one: a signed-out session has nothing to confirm and must not be handed
      // a handle.
      final auth = AuthState();
      auth.debugStartEmailVerification();

      expect(auth.canConfirmEmail, isFalse);
      expect(auth.verificationId, isNull);
    });

    test('the dev-tools gate opens the mock gate, and the mock still checks the code',
        () async {
      final auth = await _registeredWithMock();
      expect(auth.canConfirmEmail, isFalse);

      auth.debugStartEmailVerification();

      expect(auth.registrationStatus, RemoteRegistrationStatus.pendingVerification);
      expect(auth.verificationId, kMockVerificationId);
      expect(auth.canConfirmEmail, isTrue);

      // The affordance opens the gate; it does not open the answer.
      await expectLater(
        auth.confirmEmail('000000'),
        throwsA(isA<RequestRejectedException>()),
      );
      expect(auth.canConfirmEmail, isTrue);

      await auth.confirmEmail(kMockOtpCode);

      expect(auth.awaitsEmailVerification, isFalse);
      expect(auth.verificationId, isNull);
    });
  });

  // ---- Live mode -----------------------------------------------------------

  group('live mode names the missing endpoint instead of guessing at it', () {
    test('the routes come from the server, and are not dressed with a section',
        () async {
      final live = Repositories.forMode(
        useLive: true,
        baseUrl: 'https://api.example.test',
      ).auth;

      // The path segment is the server's own model name (`{buyer}`,
      // `{logistics_provider}`) and no § is cited: `web/routes/web.php` is in
      // this repository and the document those numbers point at is not. Read,
      // not guessed — which is the whole difference between this and a
      // plausible-looking request that has never run.
      // Wrapped in a closure on purpose: the live methods throw *synchronously*
      // (they are not `async`), so `expectLater(live.verifyOtp(...), ...)` would
      // throw while building the argument rather than while awaiting it. An
      // `async` closure turns either shape into a rejected future.
      await expectLater(
        () async => live.verifyOtp(role: UserRole.buyer, verificationId: '1', code: '123456'),
        throwsA(isA<ApiNotImplementedException>().having(
          (e) => e.endpoint,
          'endpoint',
          'POST /register/buyer/verify-otp/{buyer}',
        )),
      );

      // This app says `courier`; the server says `logistics`. The asymmetry is
      // recorded at the route rather than smoothed away, so whoever greps for
      // one of the two words finds the other one too.
      await expectLater(
        () async => live.resendOtp(role: UserRole.courier, verificationId: '1'),
        throwsA(isA<ApiNotImplementedException>().having(
          (e) => e.endpoint,
          'endpoint',
          'POST /register/logistics/verify-otp/{logistics_provider}/resend',
        )),
      );
    });

    test('a live build with no base URL says that, not "network error"', () async {
      final live = Repositories.forMode(useLive: true, baseUrl: '').auth;

      await expectLater(
        () async => live.verifyOtp(role: UserRole.buyer, verificationId: '1', code: '123456'),
        throwsA(isA<ApiNotConfiguredException>()),
      );
      await expectLater(
        () async => live.resendOtp(role: UserRole.buyer, verificationId: '1'),
        throwsA(isA<ApiNotConfiguredException>()),
      );
    });
  });

  // The widget half lives in its own function, declared below beside the harness
  // it needs: `_pump` and the settle helpers are top-level, and a screen test
  // three hundred lines away from the pump that builds it is a screen test nobody
  // re-runs when the harness changes.
  _widgetTests();
}

// ---- Widget harness ---------------------------------------------------------

/// Wraps [child] in the provider and theme the auth screens read, at a real
/// phone width rather than the test binding's 800px default.
Future<void> _pump(
  WidgetTester tester,
  Widget child, {
  required AuthState auth,
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  const size = Size(390, 900);
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    ChangeNotifierProvider<AuthState>.value(
      value: auth,
      child: MaterialApp(
        theme: AppTheme.light,
        home: MediaQuery(data: const MediaQueryData(size: size), child: child),
      ),
    ),
  );
  // Advance past the reveal rather than merely building it. `StaggeredReveal`
  // owns a Timer per block (25ms x index), and a timer still pending when the
  // tree is disposed is itself a test failure — waiting for the screen's own
  // animation is the test's job, not the screen's compromise.
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 600));
}

/// Advances past the reveal animation and any route transition **without** asking
/// for "settled": the pending screen's arch holds a `ZefanyaLoader`, whose
/// controller repeats forever, so `pumpAndSettle` never returns on that screen.
/// 600ms covers `StaggeredReveal` (25ms x index, then 280ms) and a 320ms
/// `AppPageRoute`.
Future<void> _settleReveal(WidgetTester tester) async {
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 600));
}

/// Runs a submit and lets its future resolve, then drains whatever the result
/// built. Two pumps rather than a settle, for the same reason as [_settleReveal]:
/// while the call is in flight the button is showing a `ZefanyaLoader`. The
/// second pump also covers an error block that just appeared, since
/// `StaggeredReveal` would otherwise leave its timer pending at teardown.
Future<void> _settleSubmit(WidgetTester tester) async {
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 600));
}

/// Types into the code field, located by type. It is the only `TextFormField` on
/// the screen, and the hint-based finder the registration tests use would stop
/// working the moment a digit is typed (the hint disappears).
Future<void> _enterCode(WidgetTester tester, String value) async {
  await tester.enterText(find.byType(TextFormField), value);
  await tester.pump();
}

void _widgetTests() {
  group('the pending screen', () {
    testWidgets('offers the code entry only when there is a handle to confirm',
        (tester) async {
      final auth = await _registered(_ScriptedOtpRepository(verificationId: 'buyer-77'));
      await _pump(tester, const PendingApprovalScreen(), auth: auth);
      await _settleReveal(tester);

      expect(find.text('Enter Code'), findsOneWidget);
      // The email gate's own status rather than the reviewer's: this person has
      // something to do, and the badge says which one it is.
      expect(find.text('Status: Awaiting Confirmation'), findsOneWidget);
      expect(find.text('Under Review'), findsNothing);
    });

    testWidgets('offers nothing when the gate arrived with no handle', (tester) async {
      final auth = await _registered(_ScriptedOtpRepository(verificationId: null));
      await _pump(tester, const PendingApprovalScreen(), auth: auth);
      await _settleReveal(tester);

      // No field, because this app has nothing to send a code *against* — and the
      // copy points at the email, which is where the action still is.
      expect(find.text('Enter Code'), findsNothing);
      expect(find.textContaining('check that inbox'), findsOneWidget);
    });

    testWidgets('a confirmed code lands back here, at the review gate',
        (tester) async {
      final repository = _ScriptedOtpRepository(verificationId: 'buyer-77');
      final auth = await _registered(repository);
      await _pump(tester, const PendingApprovalScreen(), auth: auth);
      await _settleReveal(tester);

      await tester.tap(find.text('Enter Code'));
      await _settleReveal(tester);
      expect(find.text('Confirm Code'), findsOneWidget);

      await _enterCode(tester, '123456');
      await tester.tap(find.text('Confirm Code'));
      await _settleSubmit(tester);
      await _settleReveal(tester);

      // Unwound to the single route root, which now resolves to the *other* gate:
      // the email step is genuinely behind this person rather than merely
      // described as being behind them.
      expect(find.text('Confirm Code'), findsNothing);
      expect(find.text('Enter Code'), findsNothing);
      expect(find.text('Under Review'), findsOneWidget);
      expect(repository.codeSeen, '123456');
    });
  });

  group('the OTP screen', () {
    testWidgets('a short code is refused before it reaches the repository',
        (tester) async {
      final repository = _ScriptedOtpRepository();
      final auth = await _registered(repository);
      await _pump(tester, const OtpScreen(), auth: auth);

      await _enterCode(tester, '1234');
      await tester.tap(find.text('Confirm Code'));
      await _settleSubmit(tester);

      expect(find.text('The code is six digits.'), findsOneWidget);
      // The point of validating locally: a shape the server would reject never
      // costs a round-trip, and never counts against a rate limit.
      expect(repository.codeSeen, isNull);
    });

    testWidgets('the field cannot hold anything but six digits', (tester) async {
      final auth = await _registered(_ScriptedOtpRepository());
      await _pump(tester, const OtpScreen(), auth: auth);

      await _enterCode(tester, 'ab12cd34567');

      final field = tester.widget<TextFormField>(find.byType(TextFormField));
      // `regex:/^\d{6}$/` is the formatter's job here, which leaves the validator
      // answering only for completeness.
      expect(field.controller!.text, '123456');
    });

    testWidgets('a refused code shows the server sentence and stays put',
        (tester) async {
      final repository = _ScriptedOtpRepository(
        verifyThrows: const RequestRejectedException(
          statusCode: 422,
          message: "That code doesn't match. Please try again.",
        ),
      );
      final auth = await _registered(repository);
      await _pump(tester, const OtpScreen(), auth: auth);

      await _enterCode(tester, '000000');
      await tester.tap(find.text('Confirm Code'));
      await _settleSubmit(tester);

      // The server's sentence, inline: it is about the field directly above it.
      expect(find.text("That code doesn't match. Please try again."), findsOneWidget);
      expect(find.text('Confirm Code'), findsOneWidget);
      // And the gate is still open, because the answer said nothing changed.
      expect(auth.canConfirmEmail, isTrue);
    });

    testWidgets('editing the code clears the error about the old one',
        (tester) async {
      final repository = _ScriptedOtpRepository(
        verifyThrows: const RequestRejectedException(
          statusCode: 422,
          message: "That code doesn't match. Please try again.",
        ),
      );
      final auth = await _registered(repository);
      await _pump(tester, const OtpScreen(), auth: auth);

      await _enterCode(tester, '000000');
      await tester.tap(find.text('Confirm Code'));
      await _settleSubmit(tester);
      expect(find.text("That code doesn't match. Please try again."), findsOneWidget);

      await _enterCode(tester, '1');

      // Ends *without* advancing time, deliberately: clearing the error rebuilds
      // this list, and if the blocks after the error were being re-inflated by
      // that rebuild (unkeyed children shifting slots — see the key note in the
      // screen), their `StaggeredReveal` delays would still be pending here and
      // the framework would fail this test on the timer. So this assertion is
      // also the pin against the Confirm button visibly re-fading on a retype.
      expect(find.text("That code doesn't match. Please try again."), findsNothing);
    });

    testWidgets('resending says so, counts down, and comes back', (tester) async {
      final repository = _ScriptedOtpRepository();
      final auth = await _registered(repository);
      await _pump(tester, const OtpScreen(), auth: auth);

      await tester.tap(find.text('Send a new code'));
      await _settleSubmit(tester);

      expect(repository.resendCalled, isTrue);
      // The server's sentence, because only the server sent a mail.
      expect(find.text('A new code has been sent.'), findsOneWidget);
      expect(find.text('Send another in ${kResendCooldown.inSeconds}s'), findsOneWidget);

      await tester.pump(const Duration(seconds: 1));
      expect(
        find.text('Send another in ${kResendCooldown.inSeconds - 1}s'),
        findsOneWidget,
      );

      // Back on its own, which is the point of a cooldown being a timer rather
      // than a permanent disable.
      await tester.pump(kResendCooldown);
      expect(find.text('Send a new code'), findsOneWidget);
    });

    testWidgets('a resend that fails says why, and starts no cooldown',
        (tester) async {
      final repository = _ScriptedOtpRepository(resendThrows: const NetworkException());
      final auth = await _registered(repository);
      await _pump(tester, const OtpScreen(), auth: auth);

      await tester.tap(find.text('Send a new code'));
      await _settleSubmit(tester);

      expect(find.text('Could not reach the server. Check your connection.'),
          findsOneWidget);
      // Nothing was sent, so there is nothing to wait for before asking again —
      // a cooldown here would punish the person for the network's failure.
      expect(find.text('Send a new code'), findsOneWidget);
      expect(find.textContaining('Send another in'), findsNothing);
    });

    testWidgets('the test hint names the code the mock actually accepts',
        (tester) async {
      final auth = await _registered(_ScriptedOtpRepository());
      await _pump(tester, const OtpScreen(), auth: auth);

      if (kShowDevTools) {
        // The pair that has to agree, in one assertion: the screen's hint and the
        // mock's answer are the same constant, so a changed mock cannot leave a
        // hint pointing at a code it no longer takes.
        expect(find.textContaining(kMockOtpCode), findsOneWidget);
      }
    });
  });
}
