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

class LiveAddressRepository extends LiveRepository implements AddressRepository {
  LiveAddressRepository(super.client);

  @override
  Future<List<AddressEntry>> getAddresses() async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final body = await client.getJson('/buyer/addresses');
    final data = body['data'];
    if (data is! List) return const [];
    return data
        .whereType<Map<String, dynamic>>()
        .map(AddressEntry.fromJson)
        .whereType<AddressEntry>()
        .toList();
  }

  @override
  Future<AddressEntry> createAddress(AddressEntry entry) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final body = await client.postJson(
      '/buyer/addresses',
      body: _toWire(entry),
    );
    final payload = unwrapMap(body);
    final parsed = AddressEntry.fromJson(payload);
    if (parsed == null) {
      throw const MalformedResponseException(
        message: 'Invalid address payload returned from server.',
      );
    }
    return parsed;
  }

  @override
  Future<AddressEntry> updateAddress(AddressEntry entry) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final body = await client.patchJson(
      '/buyer/addresses/${entry.id}',
      body: _toWire(entry),
    );
    final payload = unwrapMap(body);
    final parsed = AddressEntry.fromJson(payload);
    if (parsed == null) {
      throw const MalformedResponseException(
        message: 'Invalid address payload returned from server.',
      );
    }
    return parsed;
  }

  @override
  Future<void> deleteAddress(String id) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    await client.deleteJson('/buyer/addresses/$id');
  }

  @override
  Future<AddressEntry> setDefault(String id) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final body = await client.patchJson('/buyer/addresses/$id/default');
    final payload = unwrapMap(body);
    final parsed = AddressEntry.fromJson(payload);
    if (parsed == null) {
      throw const MalformedResponseException(
        message: 'Invalid address payload returned from server.',
      );
    }
    return parsed;
  }

  Map<String, dynamic> _toWire(AddressEntry entry) => {
    'recipient': entry.recipient.trim(),
    'phone': entry.phone.trim(),
    'label': entry.label.trim(),
    'region': entry.region.trim(),
    if (entry.regionCode != null) 'region_code': entry.regionCode,
    'province': entry.province.trim().isNotEmpty ? entry.province.trim() : null,
    if (entry.provinceCode != null) 'province_code': entry.provinceCode,
    'city': entry.city.trim(),
    if (entry.cityCode != null) 'city_code': entry.cityCode,
    'barangay': entry.barangay.trim(),
    if (entry.barangayCode != null) 'barangay_code': entry.barangayCode,
    'street': entry.street.trim(),
    if (entry.postalCode.trim().isNotEmpty) 'postal_code': entry.postalCode.trim(),
    if (entry.latitude != null) 'latitude': entry.latitude,
    if (entry.longitude != null) 'longitude': entry.longitude,
    'is_default': entry.isDefault,
  };
}
