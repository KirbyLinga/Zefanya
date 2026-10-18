// lib/utils/password_strength.dart
//
// Inline password-strength hint for registration (Claude QOL follow-up).
//
// Pure score-in/label-out: the field's own rules (server minimum,
// mirrored in the validator) stay the authority — this is a hint for
// the person's benefit, never a gate. Three bands, words not numbers:
// "Weak" (below minimum — the validator will refuse it), "Fair"
// (meets minimum, short or single-class), "Strong" (long enough with
// mixed classes). Pure so it can be unit-tested.

/// Minimum length the server requires (mirrors kMinPasswordLength in
/// registration_screen.dart — the validator, not this hint, enforces).
const int passwordMinLength = 8;

enum PasswordStrength { weak, fair, strong }

/// Scores [password] into a display band.
PasswordStrength scorePassword(String password) {
  if (password.length < passwordMinLength) return PasswordStrength.weak;
  final hasLower = RegExp(r'[a-z]').hasMatch(password);
  final hasUpper = RegExp(r'[A-Z]').hasMatch(password);
  final hasDigit = RegExp(r'[0-9]').hasMatch(password);
  final classes = [hasLower, hasUpper, hasDigit].where((c) => c).length;
  if (password.length >= 12 && classes >= 2) return PasswordStrength.strong;
  if (password.length >= 10 || classes >= 2) return PasswordStrength.fair;
  return PasswordStrength.weak;
}

/// Display word for a band. Null when there is nothing typed yet — the
/// hint row hides rather than announcing "Weak" over an empty field.
String? passwordStrengthLabel(PasswordStrength strength) => switch (strength) {
      PasswordStrength.weak => 'Weak',
      PasswordStrength.fair => 'Fair',
      PasswordStrength.strong => 'Strong',
    };
