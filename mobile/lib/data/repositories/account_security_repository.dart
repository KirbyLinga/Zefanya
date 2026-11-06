// lib/data/repositories/account_security_repository.dart
//
// Changing the credentials on an account: password, email and phone.
//
// Kept separate from [AuthRepository] because they answer different questions.
// Signing in decides *which session this is*; these calls change the credential
// that a future sign-in will use, and the password one also ends other sessions.
// A fake auth repository used by a login-screen test should not have to grow
// five account-management methods it will never call, which is why this is its
// own interface and AuthState takes it as a second, optional dependency.
//
// ## Why every change is verified
//
// A token proves a phone is signed in, not that the person holding it owns the
// account. Changing a password therefore needs the current one, and changing an
// email or phone needs a code sent to the *new* destination before the old one
// stops working. The server is the authority for all of it; the mock below
// mirrors the rejections so the app's error paths are demonstrable without a
// backend.

import 'package:flutter/foundation.dart';

import '../../config/dev_flags.dart';
import '../../utils/validators.dart';
import '../api/api_client.dart';
import '../api/api_exception.dart';
import 'live_stub.dart';

/// One email/password pair can be checked without a network, which is exactly
/// what the mock login tests need.
abstract class AccountSecurityRepository {
  Future<void> changePassword({
    required String currentPassword,
    required String newPassword,
  });

  /// Sends a code to [email]. The account's current address is untouched until
  /// [confirmEmailChange] succeeds.
  Future<void> requestEmailChange(String email);

  /// Confirms the code and returns the address that is now on the account.
  Future<String> confirmEmailChange(String code);

  Future<void> requestPhoneChange(String phone);

  /// Confirms the code and returns the number that is now on the account.
  Future<String> confirmPhoneChange(String code);
}

/// In-memory account used by mock builds and tests.
///
/// Stateful on purpose: a change flow is a sequence (request, then verify), and
/// a stateless mock could not reject a wrong code or show that the old address
/// still worked before verification.
class MockAccountSecurityRepository implements AccountSecurityRepository {
  MockAccountSecurityRepository({
    this.currentEmail = 'buyer@example.com',
    this.currentPhone = '09170000000',
    this.password = 'Password1!',
    Set<String>? takenEmails,
    Set<String>? takenPhones,
    DateTime Function()? now,
  })  : _takenEmails = {
          'taken@example.com',
          'used@example.com',
          ...?takenEmails,
        },
        _takenPhones = {
          '09171111111',
          '09172222222',
          ...?takenPhones,
        },
        _now = now ?? DateTime.now;

  String currentEmail;
  String currentPhone;

  final DateTime Function() _now;
  final Set<String> _takenEmails;
  final Set<String> _takenPhones;

  /// The password the mock currently accepts. Public because a test that has
  /// just changed it needs to assert which value the account kept, and a mock
  /// has no security boundary to protect.
  String password;
  String? _pendingEmail;
  String? _pendingEmailCode;
  DateTime? _pendingEmailExpiresAt;
  String? _pendingPhone;
  String? _pendingPhoneCode;
  DateTime? _pendingPhoneExpiresAt;

  /// For tests and the dev-tools affordances that need a known code. The code
  /// itself is the same one registration uses; one mock vocabulary is easier to
  /// remember than two.
  String get debugPendingCode => kMockOtpCode;

  bool get hasPendingEmail => _pendingEmail != null;
  bool get hasPendingPhone => _pendingPhone != null;

  bool verifyPassword(String value) => value == password;

  @visibleForTesting
  void debugExpireEmailCode() {
    _pendingEmailExpiresAt = _now().subtract(const Duration(seconds: 1));
  }

  @visibleForTesting
  void debugExpirePhoneCode() {
    _pendingPhoneExpiresAt = _now().subtract(const Duration(seconds: 1));
  }

  @override
  Future<void> changePassword({
    required String currentPassword,
    required String newPassword,
  }) async {
    if (currentPassword != password) {
      throw const ValidationException(
        fieldErrors: {'current_password': 'Current password is incorrect.'},
      );
    }

    final rule = validatePassword(newPassword);
    if (rule != null) {
      throw ValidationException(fieldErrors: {'password': rule});
    }

    password = newPassword;
  }

  @override
  Future<void> requestEmailChange(String email) async {
    final value = email.trim().toLowerCase();
    if (!_emailPattern.hasMatch(value)) {
      throw const ValidationException(
        fieldErrors: {'email': 'Enter a valid email address'},
      );
    }
    if (value == currentEmail.toLowerCase()) {
      throw const ValidationException(
        fieldErrors: {'email': 'This is already your email address.'},
      );
    }
    if (_takenEmails.contains(value)) {
      throw const ValidationException(
        fieldErrors: {'email': 'This email is already in use.'},
      );
    }

    _pendingEmail = value;
    _pendingEmailCode = kMockOtpCode;
    _pendingEmailExpiresAt = _now().add(const Duration(minutes: 10));
  }

  @override
  Future<String> confirmEmailChange(String code) async {
    final pending = _pendingEmail;
    final expected = _pendingEmailCode;
    if (pending == null || expected == null) {
      throw const RequestRejectedException(
        statusCode: 422,
        message: 'No email change is pending. Request a new code.',
      );
    }
    if (code.trim() != expected) {
      throw const RequestRejectedException(
        statusCode: 422,
        message: "That code doesn't match. Please try again.",
      );
    }
    if (_pendingEmailExpiresAt == null ||
        _now().isAfter(_pendingEmailExpiresAt!)) {
      throw const RequestRejectedException(
        statusCode: 422,
        message: 'This code has expired. Please request a new one.',
      );
    }

    currentEmail = pending;
    _pendingEmail = null;
    _pendingEmailCode = null;
    _pendingEmailExpiresAt = null;
    return pending;
  }

  @override
  Future<void> requestPhoneChange(String phone) async {
    final value = normalizePhMobile(phone);
    if (validatePhMobile(value) != null) {
      throw const ValidationException(
        fieldErrors: {
          'contact_no': 'Use a PH mobile number in 09XXXXXXXXX format.',
        },
      );
    }
    if (value == currentPhone) {
      throw const ValidationException(
        fieldErrors: {'contact_no': 'This is already your phone number.'},
      );
    }
    if (_takenPhones.contains(value)) {
      throw const ValidationException(
        fieldErrors: {'contact_no': 'This phone number is already in use.'},
      );
    }

    _pendingPhone = value;
    _pendingPhoneCode = kMockOtpCode;
    _pendingPhoneExpiresAt = _now().add(const Duration(minutes: 10));
  }

  @override
  Future<String> confirmPhoneChange(String code) async {
    final pending = _pendingPhone;
    final expected = _pendingPhoneCode;
    if (pending == null || expected == null) {
      throw const RequestRejectedException(
        statusCode: 422,
        message: 'No phone change is pending. Request a new code.',
      );
    }
    if (code.trim() != expected) {
      throw const RequestRejectedException(
        statusCode: 422,
        message: "That code doesn't match. Please try again.",
      );
    }
    if (_pendingPhoneExpiresAt == null ||
        _now().isAfter(_pendingPhoneExpiresAt!)) {
      throw const RequestRejectedException(
        statusCode: 422,
        message: 'This code has expired. Please request a new one.',
      );
    }

    currentPhone = pending;
    _pendingPhone = null;
    _pendingPhoneCode = null;
    _pendingPhoneExpiresAt = null;
    return pending;
  }
}

final RegExp _emailPattern = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

/// The live half. Paths and field names are read from
/// `web/app/Http/Controllers/Api/Buyer/AccountSecurityController.php` and
/// `web/routes/api.php`, not guessed: `password_confirmation` is Laravel's
/// `confirmed` convention and `contact_no` is the column the server validates.
class LiveAccountSecurityRepository extends LiveRepository
    implements AccountSecurityRepository {
  LiveAccountSecurityRepository(super.client);

  @override
  Future<void> changePassword({
    required String currentPassword,
    required String newPassword,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    await client.postJson('/account/password', body: {
      'current_password': currentPassword,
      'password': newPassword,
      'password_confirmation': newPassword,
    });
  }

  @override
  Future<void> requestEmailChange(String email) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    await client.postJson('/account/email', body: {'email': email.trim()});
  }

  @override
  Future<String> confirmEmailChange(String code) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    final body = await client.postJson(
      '/account/email/verify',
      body: {'otp': code.trim()},
    );
    return _changedValue(body, 'email');
  }

  @override
  Future<void> requestPhoneChange(String phone) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    await client.postJson(
      '/account/phone',
      body: {'contact_no': normalizePhMobile(phone)},
    );
  }

  @override
  Future<String> confirmPhoneChange(String code) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    final body = await client.postJson(
      '/account/phone/verify',
      body: {'otp': code.trim()},
    );
    return _changedValue(body, 'contact_no');
  }

  /// A confirmation must say what the account now holds. Falling back to the
  /// value the screen typed would let a malformed answer look like a success,
  /// and the profile would show something the server never confirmed.
  String _changedValue(Map<String, Object?> body, String key) {
    final value = unwrapMap(body)[key];
    if (value is! String || value.trim().isEmpty) {
      throw const MalformedResponseException(
        message: 'The server did not say what the new contact detail is.',
      );
    }
    return value;
  }
}
