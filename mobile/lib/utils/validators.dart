// lib/utils/validators.dart
//
// Field rules shared by the registration wizard and Edit Profile.
//
// The server remains the authority. These mirror the rules the forms can
// check without a round-trip, so a typo is answered next to the field that
// caused it instead of after the whole form has been filled in.

/// Letters (including enye), spaces, hyphens, apostrophes and periods.
///
/// The name fields are free text, not identifiers: "Dela Cruz", "O'Neil",
/// "Maria-Jose" and "Pena" are all real names and all have to survive.
final RegExp _personNamePattern = RegExp('^[A-Za-z\u00D1\u00F1.\' -]+\$');

/// Longest accepted first/last name, per A2.
const int kPersonNameMaxLength = 50;

/// Shortest accepted first/last name, per A2.
const int kPersonNameMinLength = 2;

/// Validates a first or last name. [label] is the field's own noun
/// ("First name", "Last name") so the message can point at it.
String? validatePersonName(String? value, {required String label}) {
  final name = (value ?? '').trim();
  if (name.isEmpty) return '$label is required';
  if (name.length < kPersonNameMinLength ||
      name.length > kPersonNameMaxLength) {
    return '$label must be $kPersonNameMinLength-$kPersonNameMaxLength characters';
  }
  if (!_personNamePattern.hasMatch(name)) {
    return '$label may only contain letters, spaces, hyphens, apostrophes and periods';
  }
  return null;
}

/// Minimum password length, per A5 and the server's registration request.
const int kPasswordMinLength = 8;

final RegExp _lowercase = RegExp(r'[a-z]');
final RegExp _uppercase = RegExp(r'[A-Z]');
final RegExp _digit = RegExp(r'[0-9]');
final RegExp _special = RegExp(r'[^A-Za-z0-9\s]');
final RegExp _whitespace = RegExp(r'\s');

/// Validates the A5 password rule: 8+ characters with at least one
/// lowercase letter, uppercase letter, number and symbol.
///
/// Deliberately no "maximum" here — the server owns that limit, and
/// silently truncating a long passphrase is worse than letting the
/// server answer. A space is refused because the registration payload
/// form-encodes it and the rule is easier to explain without one.
String? validatePassword(String? value) {
  final password = value ?? '';
  if (password.isEmpty) return 'Choose a password';
  if (password.length < kPasswordMinLength) {
    return 'Use at least $kPasswordMinLength characters';
  }
  if (_whitespace.hasMatch(password)) {
    return 'Password cannot contain spaces';
  }
  final hasLower = _lowercase.hasMatch(password);
  final hasUpper = _uppercase.hasMatch(password);
  final hasDigit = _digit.hasMatch(password);
  final hasSpecial = _special.hasMatch(password);
  if (!hasLower) return 'Add a lowercase letter';
  if (!hasUpper) return 'Add an uppercase letter';
  if (!hasDigit) return 'Add a number';
  if (!hasSpecial) return 'Add a symbol';
  return null;
}

/// A required address-book field (recipient, street, barangay and the two
/// dropdown levels). The message names the field because the form shows it
/// under that field rather than in a summary banner.
String? validateRequiredAddressField(String? value, {required String label}) {
  if ((value ?? '').trim().isEmpty) return '$label is required';
  return null;
}

/// PH mobile numbers in either of the two forms the address book accepts:
/// `09XXXXXXXXX` (11 digits) or `+639XXXXXXXXX` (12 digits after the plus).
///
/// Deliberately strict: a saved delivery address whose number is missing a
/// digit is a failed handoff at the door, and there is no second channel to
/// rescue it. Spaces, dashes and parentheses are not accepted; the field asks
/// for the digits directly.
final RegExp _phMobilePattern = RegExp(r'^(09\d{9}|\+639\d{9})$');

String? validatePhMobile(String? value) {
  final phone = (value ?? '').trim();
  if (phone.isEmpty) return 'Phone number is required';
  if (!_phMobilePattern.hasMatch(phone)) {
    return 'Use 09XXXXXXXXX or +639XXXXXXXXX';
  }
  return null;
}

/// The form the server stores: eleven digits starting `09`.
///
/// The address book accepts `+639XXXXXXXXX` because people type what their SIM
/// shows. The buyer table's `contact_no` is eleven characters wide and its
/// uniqueness check compares the stored form, so the two spellings have to
/// collapse to one before the request leaves the phone.
String normalizePhMobile(String? value) {
  final raw = (value ?? '').trim().replaceAll(RegExp(r'[\s-]'), '');
  if (raw.startsWith('+639') && raw.length == 13) {
    return '0${raw.substring(3)}';
  }
  return raw;
}

/// Whether [birthday] makes someone at least [years] old on [today].
///
/// Calendar-based, not "365 days times years": a birthday that falls
/// today counts, and 29 February is handled by comparing month/day
/// rather than by subtracting days.
bool isAtLeastYearsOld(DateTime birthday, int years, {DateTime? today}) {
  final now = today ?? DateTime.now();
  var age = now.year - birthday.year;
  if (now.month < birthday.month ||
      (now.month == birthday.month && now.day < birthday.day)) {
    age--;
  }
  return age >= years;
}

/// The minimum age for registration, per A6.
const int kMinimumAge = 18;

/// Returns the age in whole years on [today].
int ageOn(DateTime birthday, {DateTime? today}) {
  final now = today ?? DateTime.now();
  var age = now.year - birthday.year;
  if (now.month < birthday.month ||
      (now.month == birthday.month && now.day < birthday.day)) {
    age--;
  }
  return age;
}
