// test/registration_flow_test.dart
//
// Registration, asserted at the seam and through the form.
//
// The gap this covers is the same shape as the one `auth_flow_test` closed for
// sign-in: registration used to be decided *inside* `AuthState`, which set
// `status = pendingApproval` itself because the form had no password field for
// `register()` to send. A live build could therefore show someone a pending
// screen for a registration the server had never received.
//
// Two halves, and they need different tools:
//
//   - what reaches the repository, which is state logic and needs no widgets
//     (`submitForApproval` is the sibling of the login test that pins
//     `passwordSeen`);
//   - what the *form* refuses, which only a widget test can show — the password
//     rules live in validators, and a validator that is never exercised is a
//     rule nobody has checked.
//
// The form half deliberately stops at the Address step: the Documents step
// needs a file picked through a platform channel, so there is no version of
// this test that reaches Submit without mocking the image picker. That is why
// the submit assertions below are made against the state class rather than by
// tapping "Submit Registration" — and why the form tests prove their point with
// *movement* (does the step advance?) instead of with a call that cannot happen
// in a test.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/dto/registration_payload.dart';
import 'package:zefanya/data/dto/remote_status.dart';
import 'package:zefanya/data/repositories/auth_repository.dart';
import 'package:zefanya/screens/auth/pending_approval_screen.dart';
import 'package:zefanya/screens/auth/registration_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/theme/app_theme.dart';

/// A repository that answers with whatever the test tells it to, and records
/// what it was asked.
///
/// The status it returns is routed through [accountStatusFor] rather than
/// picked by hand, so the scripted answer is the one a real server's
/// registration status would produce — a test that decided `pendingApproval`
/// on its own here could pass while the real mapping was wrong.
class _ScriptedAuthRepository implements AuthRepository {
  _ScriptedAuthRepository({
    this.registrationStatus = RemoteRegistrationStatus.pendingApproval,
  });

  final RemoteRegistrationStatus registrationStatus;

  String? passwordSeen;
  String? emailSeen;
  RegistrationProfile? profileSeen;
  String? otpCodeSeen;
  String? verificationIdSeen;
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
    emailSeen = email;
    passwordSeen = password;
    profileSeen = profile;
    return AuthResult(
      role: role,
      status: accountStatusFor(registrationStatus),
      email: email,
      registrationStatus: registrationStatus,
    );
  }

  @override
  Future<AuthResult> verifyOtp({
    required UserRole role,
    required String verificationId,
    required String code,
  }) async {
    verificationIdSeen = verificationId;
    otpCodeSeen = code;
    // Confirmed. The gate this repository answers afterwards is the *admin* one,
    // which is what the server sends once the email is settled — the same answer
    // its `register()` gives for a buyer, reached from the other side.
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
  }) async => resendCalled = true;

  @override
  Future<void> logout({UserRole role = UserRole.buyer}) async {}

  @override
  Future<AuthResult?> refresh({UserRole role = UserRole.buyer}) async => null;
}

RegistrationProfile _buyerProfile() => RegistrationProfile(
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

/// Wraps [child] in the providers and theme the auth screens read, at a phone
/// size — the stepped form's step band has to fit a real width, not the test
/// binding's 800px default.
Future<void> _pump(
  WidgetTester tester,
  Widget child, {
  required AuthState auth,
  Size size = const Size(390, 900),
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    ChangeNotifierProvider<AuthState>.value(
      value: auth,
      child: MaterialApp(
        theme: AppTheme.light,
        home: MediaQuery(
          data: MediaQueryData(size: size),
          child: child,
        ),
      ),
    ),
  );
  await tester.pump();
}

/// Types [value] into the field showing [hint].
///
/// Located by hint rather than by index because the field order on a step is a
/// layout detail, while "the field that says 09XXXXXXXXX" is the thing the test
/// actually means.
Future<void> _enterField(
  WidgetTester tester, {
  required String hint,
  required String value,
}) async {
  final field = find.ancestor(
    of: find.text(hint),
    matching: find.byType(TextFormField),
  );
  expect(field, findsOneWidget, reason: 'no field hinted "$hint"');
  await tester.ensureVisible(field);
  await tester.enterText(field, value);
  await tester.pump();
}

/// Fills the Personal step with answers that pass its own validation.
///
/// Date of birth is validated by the screen rather than by a FormField, so the
/// picker has to be driven too — there is no way to set it from outside, which
/// is the widget's own design.
Future<void> _completePersonalStep(WidgetTester tester) async {
  await _enterField(tester, hint: 'Dela Cruz', value: 'Dela Cruz');
  await _enterField(tester, hint: 'Juan', value: 'Juan');
  await _enterField(tester, hint: 'you@example.com', value: 'juan@example.ph');
  await _enterField(tester, hint: '09XXXXXXXXX', value: '09171234567');

  // Tapped by type rather than by its hint text: the hint is a Text inside the
  // decorated field, and tapping that spot lands on the decoration rather than
  // on the field itself.
  await tester.tap(find.byType(DropdownButtonFormField<String>));
  await tester.pumpAndSettle();
  await tester.tap(find.text('Male').last);
  await tester.pumpAndSettle();

  await tester.tap(find.text('Select date of birth'));
  await tester.pumpAndSettle();
  await tester.tap(find.text('OK'));
  await tester.pumpAndSettle();
}

/// Drives the form as far as the password step, so the password rules can be
/// exercised where they actually live.
Future<void> _reachCredentialsStep(WidgetTester tester) async {
  await _completePersonalStep(tester);
  await tester.tap(find.text('Continue'));
  await tester.pumpAndSettle();
  expect(find.text('STEP 2 · SET A PASSWORD'), findsOneWidget,
      reason: 'the password step should be the second one');
}

/// The two hints, named once — they are how the tests find the fields.
const _passwordHint = 'At least $kMinPasswordLength characters';
const _confirmHint = 'Type it again';

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;
  });

  group('the password step', () {
    testWidgets('a password under the server minimum is refused, and the form '
        'does not advance', (tester) async {
      final repository = _ScriptedAuthRepository();
      await _pump(
        tester,
        const RegistrationScreen(initialRole: UserRole.buyer),
        auth: AuthState(repository: repository),
      );
      await _reachCredentialsStep(tester);

      await _enterField(tester, hint: _passwordHint, value: 'short7!');
      await _enterField(tester, hint: _confirmHint, value: 'short7!');
      await tester.tap(find.text('Continue'));
      await tester.pumpAndSettle();

      // The refusal is specific and it sits next to the field that caused it,
      // which is the entire reason this form was stepped rather than left as one
      // long scroll with a generic toast at the end.
      expect(find.text('Use at least $kMinPasswordLength characters'),
          findsOneWidget);
      // The weight of this test is *here*: the step did not advance. Asserting
      // "the repository was not asked" would prove nothing on its own, because
      // Submit is unreachable in a widget test — see the file header.
      expect(find.text('STEP 3 · WHERE YOU ARE'), findsNothing);
      expect(repository.passwordSeen, isNull);
    });

    testWidgets('a mismatched confirmation is refused, and the form does not '
        'advance', (tester) async {
      final repository = _ScriptedAuthRepository();
      await _pump(
        tester,
        const RegistrationScreen(initialRole: UserRole.buyer),
        auth: AuthState(repository: repository),
      );
      await _reachCredentialsStep(tester);

      await _enterField(tester, hint: _passwordHint, value: 'longenough1');
      await _enterField(tester, hint: _confirmHint, value: 'longenough2');
      await tester.tap(find.text('Continue'));
      await tester.pumpAndSettle();

      expect(find.text("Passwords don't match"), findsOneWidget);
      expect(find.text('STEP 3 · WHERE YOU ARE'), findsNothing);
      expect(repository.passwordSeen, isNull);
    });

    testWidgets('a matching password of the required length does advance', (
      tester,
    ) async {
      // The positive control for the two tests above — and not a formality:
      // without it, both of those would still pass if Continue were broken, if
      // the step never validated, or if the address step simply were not there
      // to reach.
      await _pump(
        tester,
        const RegistrationScreen(initialRole: UserRole.buyer),
        auth: AuthState(repository: _ScriptedAuthRepository()),
      );
      await _reachCredentialsStep(tester);

      await _enterField(tester, hint: _passwordHint, value: 'longenough1');
      await _enterField(tester, hint: _confirmHint, value: 'longenough1');
      await tester.tap(find.text('Continue'));
      await tester.pumpAndSettle();

      // The eyebrow is asserted rather than the fields on the page, because it
      // reads `_currentStep` and only one of it exists: a page can be kept alive
      // off-screen, the step band cannot. It also pins the position-derived
      // numbering — this step is the third, not the second it would have been
      // before the password step existed.
      expect(find.text('STEP 3 · WHERE YOU ARE'), findsOneWidget);
    });
  });

  group('the step list', () {
    testWidgets('a Courier gets five steps, with the password step named in the '
        'progress row', (tester) async {
      await _pump(
        tester,
        const RegistrationScreen(initialRole: UserRole.courier),
        auth: AuthState(repository: _ScriptedAuthRepository()),
      );

      // All of these are in the progress row on step one, which is the point:
      // the row is derived from the same list the pages are, so a step that
      // exists in one and not the other is not representable.
      for (final label in ['Personal', 'Account', 'Address', 'Vehicle', 'Documents']) {
        expect(find.text(label), findsOneWidget, reason: 'missing node "$label"');
      }
      expect(find.text('STEP 1 · ABOUT YOU'), findsOneWidget);
    });
  });

  group('submitForApproval', () {
    test('the password and the form\'s answers reach the repository', () async {
      final repository = _ScriptedAuthRepository();
      final auth = AuthState(repository: repository);

      await auth.submitForApproval(
        role: UserRole.buyer,
        email: 'juan@example.ph',
        password: 'hunter2hunter2',
        name: 'Juan D. Dela Cruz',
        profile: _buyerProfile(),
      );

      // The credential has to actually arrive. Same assertion the login test
      // pins, and for the same reason: an empty string satisfying the signature
      // is precisely the fabrication that kept registration local until the form
      // had a field to type into.
      expect(repository.passwordSeen, 'hunter2hunter2');
      expect(repository.emailSeen, 'juan@example.ph');
      expect(repository.profileSeen?.lastName, 'Dela Cruz');
      expect(repository.profileSeen?.govIdPath, isNotNull);
      // And the status is the repository's answer, not this class's assertion.
      expect(auth.status, AccountStatus.pendingApproval);
      expect(auth.registrationStatus, RemoteRegistrationStatus.pendingApproval);
      expect(auth.isPendingApproval, isTrue);
      expect(auth.isLoggedIn, isFalse);
    });

    test('mock mode registers exactly as it always did, profile and all',
        () async {
      final auth = AuthState(); // MockAuthRepository

      await auth.submitForApproval(
        role: UserRole.buyer,
        email: 'tristan@example.ph',
        password: 'anything1',
        name: 'Tristan Araneta',
        profile: _buyerProfile(),
      );
      // The screen's second call, in the order the screen makes it. This pair is
      // what "registration submitted" means to the rest of the app, so if the
      // repository work had changed either half, an upgrade would notice.
      auth.updateProfile(
        lastName: 'Araneta',
        firstName: 'Tristan',
        middleInitial: 'D',
        sex: 'Male',
        phone: '09171234567',
        province: 'Cebu',
        municipality: 'Cebu City',
        barangay: 'Lahug',
        street: '12 Mabini Street',
      );

      expect(auth.status, AccountStatus.pendingApproval);
      expect(auth.registrationStatus, RemoteRegistrationStatus.pendingApproval);
      expect(auth.email, 'tristan@example.ph');
      expect(auth.name, 'Tristan D. Araneta');
      expect(auth.memberSince, isNotNull);
      expect(auth.addressSummary, '12 Mabini Street, Lahug, Cebu City, Cebu');
    });

    test('the pending gate survives a cold start, as the same gate', () async {
      final repository = _ScriptedAuthRepository(
        registrationStatus: RemoteRegistrationStatus.pendingVerification,
      );
      await AuthState(repository: repository).submitForApproval(
        role: UserRole.buyer,
        email: 'juan@example.ph',
        password: 'hunter2hunter2',
        name: 'Juan D. Dela Cruz',
        profile: _buyerProfile(),
      );

      // A cold start: a brand-new state reading what the previous one saved.
      final restarted = AuthState(repository: repository);
      await restarted.load();

      expect(restarted.status, AccountStatus.pendingApproval);
      expect(restarted.registrationStatus,
          RemoteRegistrationStatus.pendingVerification);
      // Persisting the *gate* rather than just "pending" is the point: without
      // it, a restart would tell someone who hasn't confirmed their email to
      // wait for a reviewer.
      expect(restarted.awaitsEmailVerification, isTrue);
    });

    test('an install that predates the gate reads as the admin gate', () async {
      // What an upgrade actually looks like: status and role written by the
      // previous build, and no gate key at all.
      SharedPreferences.setMockInitialValues({
        'auth_status': 'pendingApproval',
        'auth_role': 'buyer',
        'auth_email': 'tristan@example.ph',
      });
      final auth = AuthState();
      await auth.load();

      expect(auth.status, AccountStatus.pendingApproval);
      expect(auth.registrationStatus, isNull);
      // Null answers false deliberately — "wait for a reviewer" is the safe
      // thing to say when nothing is known about the gate, because it does not
      // send anyone to an inbox that may never receive anything.
      expect(auth.awaitsEmailVerification, isFalse);
    });
  });

  group('the pending screen', () {
    testWidgets('names the email gate when that is the gate, and the reviewer '
        'when it is not', (tester) async {
      // The email gate — something the person can go and do about it.
      final emailGate = AuthState(
        repository: _ScriptedAuthRepository(
          registrationStatus: RemoteRegistrationStatus.pendingVerification,
        ),
      );
      await emailGate.submitForApproval(
        role: UserRole.buyer,
        email: 'juan@example.ph',
        password: 'hunter2hunter2',
        name: 'Juan D. Dela Cruz',
        profile: _buyerProfile(),
      );

      await _pump(tester, const PendingApprovalScreen(), auth: emailGate);
      // Not pumpAndSettle: the waiting arch holds a loader that repeats forever,
      // so "settled" never arrives. The staggered reveal is a Future.delayed,
      // which this advances past instead.
      await tester.pump(const Duration(milliseconds: 600));

      expect(find.text('Check Your Email'), findsOneWidget);
      // The address is named, so "check your email" is actionable rather than a
      // hunt.
      expect(find.textContaining('juan@example.ph'), findsOneWidget);
      // And nobody is named as a reviewer, because nobody is reviewing yet. This
      // is the conflation the screen used to make by assumption, and it is worth
      // an assertion rather than a comment: telling someone to wait for a person
      // who was never asked is a small lie with a real cost.
      expect(find.textContaining('our admin team'), findsNothing);

      // The admin gate — the mock's answer, and only waiting.
      final adminGate = AuthState();
      await adminGate.submitForApproval(
        role: UserRole.buyer,
        email: 'juan@example.ph',
        password: 'anything1',
        name: 'Juan D. Dela Cruz',
        profile: _buyerProfile(),
      );

      await _pump(tester, const PendingApprovalScreen(), auth: adminGate);
      await tester.pump(const Duration(milliseconds: 600));

      expect(find.text('Check Your Email'), findsNothing);
      expect(find.textContaining('our admin team'), findsOneWidget);
    });
  });
}
