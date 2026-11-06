// lib/data/address_entry.dart
//
// One saved delivery address. Kept as a plain value type rather than folded
// into AuthState's profile fields: a buyer can have several addresses, and the
// profile's "home address" is only the one the account was registered with.
//
// The field names are deliberately region/city/barangay rather than
// province/municipality: the workbook checks the Region → City → Barangay
// cascade, and using the same nouns in the model, the form and the tests keeps
// that requirement from getting lost in translation. [province] is carried
// alongside because the PSGC cascade has one for every non-NCR locality and
// because the display format the rest of the app uses includes it.

import 'ph_regions.dart';

class AddressEntry {
  const AddressEntry({
    required this.id,
    required this.recipient,
    required this.phone,
    required this.region,
    required this.city,
    required this.barangay,
    required this.street,
    this.province = '',
    this.postalCode = '',
    this.label = 'Home',
    this.regionCode,
    this.provinceCode,
    this.cityCode,
    this.barangayCode,
    this.latitude,
    this.longitude,
    this.isDefault = false,
  });

  static const List<String> labels = ['Home', 'Work', 'Other'];

  final String id;
  final String recipient;
  final String phone;
  final String region;
  final String province;
  final String city;
  final String barangay;
  final String street;
  final String postalCode;
  final String label;

  /// PSGC identifiers, kept for the eventual backend hand-off. They are null
  /// for addresses saved before the catalogue existed, and the form can rebuild
  /// them from the names.
  final String? regionCode;
  final String? provinceCode;
  final String? cityCode;
  final String? barangayCode;

  /// Optional coordinates from "Use my location". No map tiles or API key are
  /// involved; the form only shows a stylized pin preview.
  final double? latitude;
  final double? longitude;
  final bool isDefault;

  /// The province label the summary should use. NCR has no PSGC province, so
  /// its mailing label is Metro Manila. Older NCR rows have no [province] at
  /// all and still get the useful label here.
  String get displayProvince {
    final value = province.trim();
    if (value.isNotEmpty) return value;
    return isNcrRegionName(region) ? 'Metro Manila' : region.trim();
  }

  /// The display form used on cards, checkout and the courier's drop-off.
  String get summary => [
    street.trim(),
    barangay.trim(),
    city.trim(),
    displayProvince,
    postalCode.trim(),
  ].where((part) => part.isNotEmpty).join(', ');

  bool get hasLocation => latitude != null && longitude != null;

  AddressEntry copyWith({
    String? recipient,
    String? phone,
    String? region,
    String? province,
    String? city,
    String? barangay,
    String? street,
    String? postalCode,
    String? label,
    String? regionCode,
    String? provinceCode,
    String? cityCode,
    String? barangayCode,
    double? latitude,
    double? longitude,
    bool? isDefault,
    bool clearLocation = false,
  }) => AddressEntry(
    id: id,
    recipient: recipient ?? this.recipient,
    phone: phone ?? this.phone,
    region: region ?? this.region,
    province: province ?? this.province,
    city: city ?? this.city,
    barangay: barangay ?? this.barangay,
    street: street ?? this.street,
    postalCode: postalCode ?? this.postalCode,
    label: label ?? this.label,
    regionCode: regionCode ?? this.regionCode,
    provinceCode: provinceCode ?? this.provinceCode,
    cityCode: cityCode ?? this.cityCode,
    barangayCode: barangayCode ?? this.barangayCode,
    latitude: clearLocation ? null : latitude ?? this.latitude,
    longitude: clearLocation ? null : longitude ?? this.longitude,
    isDefault: isDefault ?? this.isDefault,
  );

  Map<String, dynamic> toJson() => {
    'id': id,
    'recipient': recipient,
    'phone': phone,
    'region': region,
    'province': province,
    'city': city,
    'barangay': barangay,
    'street': street,
    'postalCode': postalCode,
    'label': label,
    if (regionCode != null) 'regionCode': regionCode,
    if (provinceCode != null) 'provinceCode': provinceCode,
    if (cityCode != null) 'cityCode': cityCode,
    if (barangayCode != null) 'barangayCode': barangayCode,
    if (latitude != null) 'latitude': latitude,
    if (longitude != null) 'longitude': longitude,
    'isDefault': isDefault,
  };

  /// Null for a corrupt entry: one bad row should cost one address, not the
  /// whole book. The caller filters nulls.
  static AddressEntry? fromJson(Map<String, dynamic> map) {
    final id = map['id'];
    final recipient = map['recipient'];
    final phone = map['phone'];
    final region = map['region'];
    final city = map['city'];
    final barangay = map['barangay'];
    final street = map['street'];
    if ([
      id,
      recipient,
      phone,
      region,
      city,
      barangay,
      street,
    ].any((value) => value is! String)) {
      return null;
    }
    final labelValue = map['label'];
    final label = labelValue is String && labelValue.trim().isNotEmpty
        ? labelValue.trim()
        : 'Home';
    return AddressEntry(
      id: id as String,
      recipient: recipient as String,
      phone: phone as String,
      region: region as String,
      province: _stringOrEmpty(map['province']),
      city: city as String,
      barangay: barangay as String,
      street: street as String,
      postalCode: _stringOrEmpty(map['postalCode']),
      label: labels.contains(label) ? label : 'Other',
      regionCode: _optionalString(map['regionCode']),
      provinceCode: _optionalString(map['provinceCode']),
      cityCode: _optionalString(map['cityCode']),
      barangayCode: _optionalString(map['barangayCode']),
      latitude: _optionalDouble(map['latitude']),
      longitude: _optionalDouble(map['longitude']),
      isDefault: map['isDefault'] as bool? ?? false,
    );
  }

  static String _stringOrEmpty(Object? value) =>
      value is String ? value.trim() : '';

  static String? _optionalString(Object? value) {
    if (value is! String) return null;
    final trimmed = value.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  static double? _optionalDouble(Object? value) =>
      value is num ? value.toDouble() : null;
}
