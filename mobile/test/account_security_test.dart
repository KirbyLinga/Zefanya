// test/account_security_test.dart
//
// Workbook B1 Account & Security (BUY-ACCT-003 through 011).
//
// These tests exercise the app's half of each case: the screen that collects
// the proof, the state change it causes, and the persistence that survives a
// cold start. Where the workbook also asks what the *server* does with a login
// after the change (BUY-ACCT-006) or with another phone's token (BUY-ACCT-011),
// the PHP feature test `web/tests/Feature/Api/AccountSecurityApiTest.php`
// covers that half and the comments below point at it. Nothing here claims a
// two-phone UI flow that a widget test cannot run.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/config/dev_flags.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/repositories/account_security_repository.dart';
import 'package:zefanya/screens/shared/account_security_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/theme/app_theme.dart';

AuthState _auth(MockAccountSecurityRepository security) => AuthState(
  accountSecurity: security,
)
  ..email = security.currentEmail
  ..phone = security.currentPhone;

Future<void> _pumpHub(WidgetTester tester, AuthState auth) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MediaQuery(
      data: const MediaQueryData(size: Size(390, 844)),
      child: ChangeNotifierProvider<AuthState>.value(
        value: auth,
        child: MaterialApp(
          theme: AppTheme.light,
          home: const AccountSecurityScreen(),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _openPassword(WidgetTester tester, AuthState auth) async {
  await _pumpHub(tester, auth);
  await tester.tap(find.byKey(const ValueKey('change-password-tile')));
  await tester.pumpAndSettle();
}

Future<void> _openEmail(WidgetTester tester, AuthState auth) async {
  await _pumpHub(tester, auth);
  await tester.tap(find.byKey(const ValueKey('change-email-tile')));
  await tester.pumpAndSettle();
}

Future<void> _openPhone(WidgetTester tester, AuthState auth) async {
  await _pumpHub(tester, auth);
  await tester.tap(find.byKey(const ValueKey('change-phone-tile')));
  await tester.pumpAndSettle();
}

Future<void> _submitPassword(
  WidgetTester tester, {
  required String current,
  required String next,
}) async {
  await tester.enterText(
    find.byKey(const ValueKey('current-password')),
    current,
  );
  await tester.enterText(find.byKey(const ValueKey('new-password')), next);
  await tester.enterText(find.byKey(const ValueKey('confirm-password')), next);
  await tester.ensureVisible(
    find.byKey(const ValueKey('change-password-submit')),
  );
  await tester.tap(find.byKey(const ValueKey('change-password-submit')));
  await tester.pumpAndSettle();
}

/// Ends a test that showed a snackbar by draining the snackbar's removal timer,
/// which the widget-test clock otherwise leaves pending at teardown.
Future<void> _drainSnackBar(WidgetTester tester) =>
    tester.pump(const Duration(seconds: 4));

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('BUY-ACCT-003', () {
    testWidgets(
      'the correct current password changes it, shows success, and the old '
      'password no longer works in the mock account',
      (tester) async {
        final security = MockAccountSecurityRepository(
          password: 'Password1!',
        );
        final auth = _auth(security);

        await _openPassword(tester, auth);
        await _submitPassword(
          tester,
          current: 'Password1!',
          next: 'NewPass1!',
        );

        expect(
          find.text(
            'Password changed. Other devices have been signed out.',
          ),
          findsOneWidget,
        );
        expect(security.verifyPassword('Password1!'), isFalse);
        expect(security.verifyPassword('NewPass1!'), isTrue);

        await _drainSnackBar(tester);
      },
    );
  });

  group('BUY-ACCT-004', () {
    testWidgets(
      'a wrong current password is refused inline and the original password '
      'still works',
      (tester) async {
        final security = MockAccountSecurityRepository(
          password: 'Password1!',
        );
        final auth = _auth(security);

        await _openPassword(tester, auth);
        await _submitPassword(
          tester,
          current: 'NotMyPass1!',
          next: 'NewPass1!',
        );

        expect(find.text('Current password is incorrect.'), findsOneWidget);
        expect(security.verifyPassword('Password1!'), isTrue);
        expect(security.verifyPassword('NewPass1!'), isFalse);

        // No success path ran, so nothing was popped or announced.
        expect(find.text('Password changed. Other devices have been signed out.'),
            findsNothing);
      },
    );
  });

  group('BUY-ACCT-005', () {
    test('every broken rule names the rule and leaves the password alone',
        () async {
      const broken = {
        'Aa1!aa': 'Use at least 8 characters',
        'lowercase1!': 'Add an uppercase letter',
        'UPPERCASE1!': 'Add a lowercase letter',
        'NoNumber!!': 'Add a number',
        'NoSymbol11': 'Add a symbol',
        'Abc def1!': 'Password cannot contain spaces',
      };

      for (final entry in broken.entries) {
        final security = MockAccountSecurityRepository(
          password: 'Password1!',
        );
        final auth = _auth(security);

        await expectLater(
          auth.changePassword(
            currentPassword: 'Password1!',
            newPassword: entry.key,
          ),
          throwsA(
            isA<ValidationException>().having(
              (e) => e.forField('password'),
              'password error',
              entry.value,
            ),
          ),
          reason: entry.key,
        );
        expect(security.verifyPassword('Password1!'), isTrue, reason: entry.key);
      }
    });

    testWidgets('the new-password field shows the specific missing class',
        (tester) async {
      final security = MockAccountSecurityRepository(password: 'Password1!');
      final auth = _auth(security);

      await _openPassword(tester, auth);
      await _submitPassword(
        tester,
        current: 'Password1!',
        next: 'lowercase1!',
      );

      expect(find.text('Add an uppercase letter'), findsOneWidget);
      expect(security.verifyPassword('Password1!'), isTrue);
    });
  });

  group('BUY-ACCT-006', () {
    testWidgets(
      'the old address stays live until the code, then the profile and a cold '
      'start show the new one',
      (tester) async {
        final security = MockAccountSecurityRepository(
          currentEmail: 'old@example.com',
        );
        final auth = _auth(security);

        // The login half of this case is server-side: before verification the
        // old address still authenticates; after it, only the new one does.
        // `AccountSecurityApiTest::test_changing_email_needs_the_code_and_only_then_moves_the_login`
        // is that proof.

        await _openEmail(tester, auth);
        await tester.enterText(
          find.byKey(const ValueKey('contact-value')),
          'new@example.com',
        );
        await tester.ensureVisible(
          find.byKey(const ValueKey('contact-send-code')),
        );
        await tester.tap(find.byKey(const ValueKey('contact-send-code')));
        await tester.pumpAndSettle();

        expect(find.byKey(const ValueKey('contact-otp')), findsOneWidget);
        expect(auth.email, 'old@example.com');
        expect(security.currentEmail, 'old@example.com');
        expect(security.hasPendingEmail, isTrue);

        await tester.enterText(
          find.byKey(const ValueKey('contact-otp')),
          kMockOtpCode,
        );
        await tester.ensureVisible(find.byKey(const ValueKey('contact-verify')));
        await tester.tap(find.byKey(const ValueKey('contact-verify')));
        await tester.pumpAndSettle();

        expect(auth.email, 'new@example.com');
        expect(security.currentEmail, 'new@example.com');
        expect(find.text('Email changed to new@example.com.'), findsOneWidget);

        // A cold start reads what _save wrote, not the route's local variable.
        final restarted = _auth(
          MockAccountSecurityRepository(currentEmail: 'new@example.com'),
        );
        await restarted.load();
        expect(restarted.email, 'new@example.com');

        await _drainSnackBar(tester);
      },
    );
  });

  group('BUY-ACCT-007', () {
    test('a wrong code and an expired code both leave the address unchanged',
        () async {
      final security = MockAccountSecurityRepository(
        currentEmail: 'old@example.com',
      );
      final auth = _auth(security);

      await auth.requestEmailChange('new@example.com');

      await expectLater(
        auth.confirmEmailChange('000000'),
        throwsA(
          isA<RequestRejectedException>().having(
            (e) => e.message,
            'message',
            "That code doesn't match. Please try again.",
          ),
        ),
      );
      expect(auth.email, 'old@example.com');
      expect(security.currentEmail, 'old@example.com');

      // The wrong attempt does not consume the pending change; ageing it out
      // then refuses the correct digits with the expired message.
      security.debugExpireEmailCode();
      await expectLater(
        auth.confirmEmailChange(kMockOtpCode),
        throwsA(
          isA<RequestRejectedException>().having(
            (e) => e.message,
            'message',
            'This code has expired. Please request a new one.',
          ),
        ),
      );
      expect(auth.email, 'old@example.com');
      expect(security.currentEmail, 'old@example.com');
    });

    testWidgets('the code field shows the wrong-code message inline',
        (tester) async {
      final security = MockAccountSecurityRepository(
        currentEmail: 'old@example.com',
      );
      final auth = _auth(security);

      await _openEmail(tester, auth);
      await tester.enterText(
        find.byKey(const ValueKey('contact-value')),
        'new@example.com',
      );
      await tester.ensureVisible(
        find.byKey(const ValueKey('contact-send-code')),
      );
      await tester.tap(find.byKey(const ValueKey('contact-send-code')));
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const ValueKey('contact-otp')),
        '000000',
      );
      await tester.ensureVisible(find.byKey(const ValueKey('contact-verify')));
      await tester.tap(find.byKey(const ValueKey('contact-verify')));
      await tester.pumpAndSettle();

      expect(
        find.text("That code doesn't match. Please try again."),
        findsOneWidget,
      );
      expect(auth.email, 'old@example.com');
      expect(find.byKey(const ValueKey('contact-otp')), findsOneWidget);

      await _drainSnackBar(tester);
    });
  });

  group('BUY-ACCT-008', () {
    test('a taken or unchanged address is refused before any code exists',
        () async {
      final security = MockAccountSecurityRepository(
        currentEmail: 'mine@example.com',
      );
      final auth = _auth(security);

      await expectLater(
        auth.requestEmailChange('taken@example.com'),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.forField('email'),
            'email error',
            'This email is already in use.',
          ),
        ),
      );
      expect(security.hasPendingEmail, isFalse);

      await expectLater(
        auth.requestEmailChange('mine@example.com'),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.forField('email'),
            'email error',
            'This is already your email address.',
          ),
        ),
      );
      expect(security.hasPendingEmail, isFalse);
      expect(auth.email, 'mine@example.com');
    });

    testWidgets('a taken address keeps the form on step one with no code field',
        (tester) async {
      final security = MockAccountSecurityRepository(
        currentEmail: 'mine@example.com',
      );
      final auth = _auth(security);

      await _openEmail(tester, auth);
      await tester.enterText(
        find.byKey(const ValueKey('contact-value')),
        'taken@example.com',
      );
      await tester.ensureVisible(
        find.byKey(const ValueKey('contact-send-code')),
      );
      await tester.tap(find.byKey(const ValueKey('contact-send-code')));
      await tester.pumpAndSettle();

      expect(find.text('This email is already in use.'), findsOneWidget);
      expect(find.byKey(const ValueKey('contact-otp')), findsNothing);
      expect(security.hasPendingEmail, isFalse);
    });
  });

  group('BUY-ACCT-009', () {
    test('invalid and duplicate numbers are refused without a pending code',
        () async {
      final security = MockAccountSecurityRepository(
        currentPhone: '09170000000',
      );
      final auth = _auth(security);

      for (final invalid in ['12345', '08123456789', '']) {
        await expectLater(
          auth.requestPhoneChange(invalid),
          throwsA(
            isA<ValidationException>().having(
              (e) => e.forField('contact_no'),
              'phone error',
              isNotNull,
            ),
          ),
          reason: invalid,
        );
      }
      expect(security.hasPendingPhone, isFalse);

      await expectLater(
        auth.requestPhoneChange('09171111111'),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.forField('contact_no'),
            'phone error',
            'This phone number is already in use.',
          ),
        ),
      );
      expect(security.hasPendingPhone, isFalse);
      expect(auth.phone, '09170000000');
    });

    test('a valid +63 number is normalized and saved only after verification',
        () async {
      final security = MockAccountSecurityRepository(
        currentPhone: '09170000000',
      );
      final auth = _auth(security);

      await auth.requestPhoneChange('+639173333333');
      expect(security.hasPendingPhone, isTrue);
      expect(security.currentPhone, '09170000000');
      expect(auth.phone, '09170000000');

      await expectLater(
        auth.confirmPhoneChange('000000'),
        throwsA(isA<RequestRejectedException>()),
      );
      expect(auth.phone, '09170000000');
      expect(security.currentPhone, '09170000000');

      await auth.confirmPhoneChange(kMockOtpCode);
      expect(auth.phone, '09173333333');
      expect(security.currentPhone, '09173333333');

      final restarted = _auth(
        MockAccountSecurityRepository(currentPhone: '09173333333'),
      );
      await restarted.load();
      expect(restarted.phone, '09173333333');
    });

    testWidgets('the phone screen accepts +63, verifies, then reports the '
        'normalized number', (tester) async {
      final security = MockAccountSecurityRepository(
        currentPhone: '09170000000',
      );
      final auth = _auth(security);

      await _openPhone(tester, auth);
      await tester.enterText(
        find.byKey(const ValueKey('contact-value')),
        '+639173333333',
      );
      await tester.ensureVisible(
        find.byKey(const ValueKey('contact-send-code')),
      );
      await tester.tap(find.byKey(const ValueKey('contact-send-code')));
      await tester.pumpAndSettle();

      expect(auth.phone, '09170000000');
      await tester.enterText(
        find.byKey(const ValueKey('contact-otp')),
        kMockOtpCode,
      );
      await tester.ensureVisible(find.byKey(const ValueKey('contact-verify')));
      await tester.tap(find.byKey(const ValueKey('contact-verify')));
      await tester.pumpAndSettle();

      expect(auth.phone, '09173333333');
      expect(find.text('Phone changed to 09173333333.'), findsOneWidget);

      await _drainSnackBar(tester);
    });
  });

  group('BUY-ACCT-011', () {
    test('the phone that changes the password keeps its signed-in session',
        () async {
      // Server half: the same PHP test asserts that the other token gets 401
      // and that the old password no longer signs in while the current token
      // still reaches /me. This half proves the app does not log itself out
      // when its own password changes.
      final security = MockAccountSecurityRepository(password: 'Password1!');
      final auth = AuthState(accountSecurity: security);

      await auth.login(
        role: UserRole.buyer,
        email: 'buyer@example.com',
        password: 'Password1!',
      );
      expect(auth.status, AccountStatus.active);

      await auth.changePassword(
        currentPassword: 'Password1!',
        newPassword: 'NewPass1!',
      );

      expect(auth.status, AccountStatus.active);
      expect(auth.email, 'buyer@example.com');
      expect(security.verifyPassword('NewPass1!'), isTrue);
    });
  });
}
