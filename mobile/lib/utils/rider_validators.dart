// lib/utils/rider_validators.dart
//
// The rules a rider's own record has to satisfy: plate, licence number,
// licence expiry and document uploads.
//
// They live here rather than inside the registration form because the same
// rules run in two places — sign-up and the "Edit vehicle" screen — and a
// second copy is how the two drift. The server re-checks every one of them
// (unique plate, expiry boundary, file type/size); these copies exist so the
// person hears about a problem at the field instead of after a round-trip.

import 'dart:io';

/// What a rider's licence/OR-CR upload is allowed to be.
///
/// The workbook's RID-PROF-005 is deliberately strict: "only the 5.0 MB JPG is
/// accepted". The rider document endpoint therefore accepts JPEG only, even
/// though the buyer ID endpoint is wider. Keeping that narrower rule here (and
/// not in the picker) means the same check can run after a camera shot as after
/// a file chosen from storage.
const int kRiderDocumentMaxBytes = 5 * 1024 * 1024;

const Set<String> kRiderDocumentExtensions = {'jpg', 'jpeg'};

/// Plates already registered to other riders in mock mode.
///
/// A mock cannot know the server's whole plate table, so this is the one seeded
/// value the workbook names. The live path answers the same question from the
/// unique index / `PATCH /courier/profile` validation, which is the authority.
const Set<String> kMockRegisteredPlates = {'123ABC', 'ABC1234'};

String normalizePlate(String? value) =>
    (value ?? '').trim().toUpperCase().replaceAll(RegExp(r'[\s-]'), '');

/// The plate formats the server accepts: two or three letters then three or
/// four digits, or the older digits-first motorcycle plates (`123 ABC`), with
/// an optional space or dash. The workbook's own rider plate is `123ABC`, so
/// the digits-first shape has to be valid or the seeded record could never be
/// saved, let alone checked for duplicates.
String? validatePlateNumber(String? value) {
  final plate = (value ?? '').trim().toUpperCase();
  if (plate.isEmpty) return 'Plate number is required';
  if (!RegExp(
    r'^(?:[A-Z]{2,3}[\s-]?\d{3,4}|\d{3,4}[\s-]?[A-Z]{2,3})$',
  ).hasMatch(plate)) {
    return 'Enter a valid plate number (e.g. ABC 1234)';
  }
  return null;
}

/// PH rider licence number, e.g. `N01-23-456789`.
///
/// Kept deliberately loose about the letter/digit grouping (real licences vary)
/// but strict about the shape an obvious typo breaks: one letter, two digits,
/// a dash, two digits, a dash, six digits.
String? validateLicenseNumber(String? value) {
  final license = (value ?? '').trim().toUpperCase();
  if (license.isEmpty) return 'Licence number is required';
  if (!RegExp(r'^[A-Z]\d{2}-\d{2}-\d{6}$').hasMatch(license)) {
    return 'Enter a valid licence number (e.g. N01-23-456789)';
  }
  return null;
}

/// Parses the two spellings a rider can type: `MM/DD/YYYY` and `YYYY-MM-DD`.
DateTime? parseLicenseExpiry(String? raw) {
  final value = (raw ?? '').trim();
  if (value.isEmpty) return null;

  final slash = RegExp(r'^(\d{1,2})/(\d{1,2})/(\d{4})$').firstMatch(value);
  if (slash != null) {
    final month = int.tryParse(slash.group(1)!);
    final day = int.tryParse(slash.group(2)!);
    final year = int.tryParse(slash.group(3)!);
    return _validDate(year, month, day);
  }

  final iso = RegExp(r'^(\d{4})-(\d{1,2})-(\d{1,2})$').firstMatch(value);
  if (iso != null) {
    final year = int.tryParse(iso.group(1)!);
    final month = int.tryParse(iso.group(2)!);
    final day = int.tryParse(iso.group(3)!);
    return _validDate(year, month, day);
  }

  return null;
}

DateTime? _validDate(int? year, int? month, int? day) {
  if (year == null || month == null || day == null) return null;
  final date = DateTime(year, month, day);
  // Reject 02/31 rather than letting DateTime silently roll it to March.
  if (date.year != year || date.month != month || date.day != day) return null;
  return date;
}

/// The RID-PROF-004 boundary: an expiry *yesterday* is refused, *today* is
/// accepted. Compared date-only — a licence does not expire at a wall-clock
/// time, and using timestamps here would make "today" flaky after 00:00.
String? validateLicenseExpiry(DateTime? expiry, {DateTime? today}) {
  if (expiry == null) return 'Licence expiry is required';
  final now = today ?? DateTime.now();
  final expiryDay = DateTime(expiry.year, expiry.month, expiry.day);
  final todayDay = DateTime(now.year, now.month, now.day);
  if (expiryDay.isBefore(todayDay)) {
    return 'This licence has expired. Enter an expiry date of today or later.';
  }
  return null;
}

/// The upload rule RID-PROF-005 exercises.
///
/// Takes a path rather than an XFile so the check runs after `pickAndSaveImage`
/// has copied the file into app storage — the copy is what would actually be
/// submitted, and validating the temp original would accept a file this app
/// cannot later send.
String? validateRiderDocumentPath(String? path) {
  if (path == null || path.trim().isEmpty) {
    return 'Choose a document first.';
  }

  final file = File(path);
  if (!file.existsSync()) {
    return 'That file is no longer available. Choose it again.';
  }

  final extension = path.split('.').last.toLowerCase();
  if (!kRiderDocumentExtensions.contains(extension)) {
    return 'Only JPG files are accepted for rider documents.';
  }

  if (file.lengthSync() > kRiderDocumentMaxBytes) {
    return 'The file is larger than 5 MB. Choose a smaller JPG.';
  }

  return null;
}

/// Whether [plate] belongs to some other rider.
///
/// [currentPlate] is exempt: saving the form without touching the plate must
/// not fail against the rider's own record. The mock's seeded set is checked
/// after normalising, so `123 ABC` and `123ABC` collide like the server's
/// unique index would.
bool isPlateRegisteredElsewhere(String? plate, {String? currentPlate}) {
  final normalized = normalizePlate(plate);
  if (normalized.isEmpty) return false;
  if (currentPlate != null && normalizePlate(currentPlate) == normalized) {
    return false;
  }
  return kMockRegisteredPlates.contains(normalized);
}
