// lib/data/repositories/address_repository.dart
//
// Managing saved buyer delivery addresses.

import '../address_entry.dart';
import '../api/api_client.dart';
import '../api/api_exception.dart';
import 'live_stub.dart';

abstract class AddressRepository {
  Future<List<AddressEntry>> getAddresses();
  Future<AddressEntry> createAddress(AddressEntry entry);
  Future<AddressEntry> updateAddress(AddressEntry entry);
  Future<void> deleteAddress(String id);
  Future<AddressEntry> setDefault(String id);
}

class MockAddressRepository implements AddressRepository {
  const MockAddressRepository([List<AddressEntry>? initial])
      : _initial = initial;

  final List<AddressEntry>? _initial;
  static final List<AddressEntry> _fallbackAddresses = [];

  List<AddressEntry> get _addresses => _initial ?? _fallbackAddresses;

  @override
  Future<List<AddressEntry>> getAddresses() async => List.unmodifiable(_addresses);

  @override
  Future<AddressEntry> createAddress(AddressEntry entry) async {
    final isFirst = _addresses.isEmpty;
    final created = entry.copyWith(
      isDefault: entry.isDefault || isFirst,
    );
    if (created.isDefault) {
      for (var i = 0; i < _addresses.length; i++) {
        _addresses[i] = _addresses[i].copyWith(isDefault: false);
      }
    }
    _addresses.add(created);
    return created;
  }

  @override
  Future<AddressEntry> updateAddress(AddressEntry entry) async {
    final idx = _addresses.indexWhere((a) => a.id == entry.id);
    if (idx < 0) {
      throw const RequestRejectedException(
        statusCode: 404,
        message: 'Address not found',
      );
    }
    if (entry.isDefault) {
      for (var i = 0; i < _addresses.length; i++) {
        _addresses[i] = _addresses[i].copyWith(isDefault: false);
      }
    }
    _addresses[idx] = entry;
    return entry;
  }

  @override
  Future<void> deleteAddress(String id) async {
    final idx = _addresses.indexWhere((a) => a.id == id);
    if (idx < 0) return;
    final wasDefault = _addresses[idx].isDefault;
    _addresses.removeAt(idx);
    if (wasDefault && _addresses.isNotEmpty) {
      _addresses[0] = _addresses[0].copyWith(isDefault: true);
    }
  }

  @override
  Future<AddressEntry> setDefault(String id) async {
    final idx = _addresses.indexWhere((a) => a.id == id);
    if (idx < 0) {
      throw const RequestRejectedException(
        statusCode: 404,
        message: 'Address not found',
      );
    }
    for (var i = 0; i < _addresses.length; i++) {
      _addresses[i] = _addresses[i].copyWith(isDefault: _addresses[i].id == id);
    }
    return _addresses[idx];
  }
}

/// WebZefanya address book (`/api/buyer/addresses`, BuyerAddressBook).
///
/// The server stores the PSGC location, street, postal code and the default
/// flag. It does not store a per-address recipient, phone, label, region or
/// map pin: orders are addressed to the buyer's profile name and phone. Those
/// fields are therefore not sent, and come back empty/defaulted.
class LiveAddressRepository extends LiveRepository implements AddressRepository {
  LiveAddressRepository(super.client);

  @override
  Future<List<AddressEntry>> getAddresses() async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final body = await client.getJson('/buyer/addresses');
    return [for (final row in unwrapList(body)) _fromWire(row)];
  }

  @override
  Future<AddressEntry> createAddress(AddressEntry entry) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final body = await client.postJson('/buyer/addresses', body: _toWire(entry));
    return _fromWire(unwrapMap(body));
  }

  @override
  Future<AddressEntry> updateAddress(AddressEntry entry) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final body = await client.putJson('/buyer/addresses/${entry.id}', body: _toWire(entry));
    return _fromWire(unwrapMap(body));
  }

  @override
  Future<void> deleteAddress(String id) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    await client.deleteJson('/buyer/addresses/$id');
  }

  @override
  Future<AddressEntry> setDefault(String id) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final body = await client.postJson('/buyer/addresses/$id/default');
    return _fromWire(unwrapMap(body));
  }

  /// `api` mode when the PSGC codes are known (the server validates them as a
  /// set), otherwise `manual` with names only, as the web form does.
  Map<String, Object?> _toWire(AddressEntry entry) {
    final coded = entry.provinceCode != null && entry.cityCode != null && entry.barangayCode != null;
    return {
      'address_mode': coded ? 'api' : 'manual',
      if (coded) 'province': entry.provinceCode,
      'province_name': entry.province.trim().isEmpty ? null : entry.province.trim(),
      if (coded) 'municipality': entry.cityCode,
      'municipality_name': entry.city.trim(),
      if (coded) 'barangay': entry.barangayCode,
      'barangay_name': entry.barangay.trim().isEmpty ? null : entry.barangay.trim(),
      'street': entry.street.trim(),
      if (entry.postalCode.trim().isNotEmpty) 'postal_code': entry.postalCode.trim(),
      'is_default': entry.isDefault,
    };
  }

  AddressEntry _fromWire(Map<String, Object?> row) {
    String text(String key) => row[key]?.toString().trim() ?? '';
    String? code(String key) => text(key).isEmpty ? null : text(key);
    final id = text('id');
    if (id.isEmpty) {
      throw const MalformedResponseException(message: 'Invalid address payload returned from server.');
    }
    final street = [text('house_number'), text('street')].where((p) => p.isNotEmpty).join(' ');
    return AddressEntry(
      id: id,
      recipient: '',
      phone: '',
      region: '',
      province: text('province_name'),
      provinceCode: code('province'),
      city: text('municipality_name'),
      cityCode: code('municipality'),
      barangay: text('barangay_name'),
      barangayCode: code('barangay'),
      street: street,
      postalCode: text('postal_code'),
      isDefault: row['is_default'] == true,
    );
  }
}
