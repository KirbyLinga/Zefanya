// lib/data/ph_regions.dart
//
// The small synchronous province -> region lookup used when an old profile
// seeds the address book. The real Region -> Province -> City -> Barangay
// hierarchy lives in ph_address_catalog.dart / assets/data/ph_psgc.json.
//
// This map is generated from the same PSGC snapshot (see
// ph_province_regions.dart), so it cannot drift from the picker. It stays a
// Dart constant because seeding happens synchronously and must not wait for the
// JSON asset's first parse.

import 'ph_province_regions.dart';

/// Whether a region label is the province-less National Capital Region.
///
/// Used by [AddressEntry]'s display fallback so an older NCR row saved before
/// the province field existed still reads "Metro Manila" rather than repeating
/// the region.
bool isNcrRegionName(String? region) {
  final value = (region ?? '').trim().toLowerCase();
  if (value.isEmpty) return false;
  return value == 'ncr' ||
      value == 'metro manila' ||
      value == 'national capital region' ||
      value.contains('national capital region') ||
      value.contains('(ncr)');
}

/// The region label a legacy profile address should be filed under.
///
/// Registration historically stored a province, not a region. Unknown provinces
/// are kept as-is rather than guessed into a region: a wrong label is worse than
/// an ungrouped one, and the buyer can correct it on the form.
String regionForProvince(String? province) {
  final value = (province ?? '').trim();
  if (value.isEmpty) return '';

  switch (value.toLowerCase()) {
    case 'metro manila':
    case 'ncr':
    case 'national capital region':
      return 'National Capital Region (NCR)';
  }

  final direct = phProvinceRegionNames[value];
  if (direct != null) return direct;

  // Tolerate "Province of Cebu" and "Cebu Province" from older payloads.
  final cleaned = value
      .replaceFirst(RegExp(r'^Province of ', caseSensitive: false), '')
      .replaceFirst(RegExp(r' Province$', caseSensitive: false), '')
      .trim();
  return phProvinceRegionNames[cleaned] ?? value;
}
