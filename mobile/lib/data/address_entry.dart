// lib/data/address_entry.dart
//
// One saved delivery address. Kept as a plain value type rather than folded
// into AuthState's profile fields: a buyer can have several addresses, and the
// profile's "home address" is only the one the account was registered with.
//
// The field names are deliberately region/city/barangay rather than
// province/municipality: the workbook checks the Region → City → Barangay
// cascade, and using the same nouns in the model, the form and the tests keeps
// that requirement from getting lost in translation.

class AddressEntry {
  const AddressEntry({
    required this.id,
    required this.recipient,
    required this.phone,
    required this.region,
    required this.city,
    required this.barangay,
    required this.street,
    this.isDefault = false,
  });

  final String id;
  final String recipient;
  final String phone;
  final String region;
  final String city;
  final String barangay;
  final String street;
  final bool isDefault;

  /// The display form used on cards, checkout and the courier's drop-off.
  String get summary => [
        street.trim(),
        barangay.trim(),
        city.trim(),
        region.trim(),
      ].where((part) => part.isNotEmpty).join(', ');

  AddressEntry copyWith({
    String? recipient,
    String? phone,
    String? region,
    String? city,
    String? barangay,
    String? street,
    bool? isDefault,
  }) =>
      AddressEntry(
        id: id,
        recipient: recipient ?? this.recipient,
        phone: phone ?? this.phone,
        region: region ?? this.region,
        city: city ?? this.city,
        barangay: barangay ?? this.barangay,
        street: street ?? this.street,
        isDefault: isDefault ?? this.isDefault,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'recipient': recipient,
        'phone': phone,
        'region': region,
        'city': city,
        'barangay': barangay,
        'street': street,
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
    if ([id, recipient, phone, region, city, barangay, street]
        .any((value) => value is! String)) {
      return null;
    }
    return AddressEntry(
      id: id as String,
      recipient: recipient as String,
      phone: phone as String,
      region: region as String,
      city: city as String,
      barangay: barangay as String,
      street: street as String,
      isDefault: map['isDefault'] as bool? ?? false,
    );
  }
}
