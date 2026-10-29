import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/address_entry.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/address_repository.dart';

class _FakeServer {
  _FakeServer(this._script);

  final List<http.Response> _script;
  int _served = 0;

  final List<String> methods = [];
  final List<String> paths = [];
  final List<String> bodies = [];

  late final http.Client client = MockClient.streaming((request, bodyStream) async {
    methods.add(request.method);
    paths.add(request.url.path);
    bodies.add(await bodyStream.bytesToString());

    final response = _script[_served < _script.length ? _served++ : _script.length - 1];
    return http.StreamedResponse(
      Stream.value(utf8.encode(response.body)),
      response.statusCode,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  });
}

http.Response _json(Map<String, Object?> body, [int status = 200]) =>
    http.Response(jsonEncode(body), status);

void main() {
  const sampleEntry = AddressEntry(
    id: '1',
    recipient: 'Juan Dela Cruz',
    phone: '09171234567',
    label: 'Home',
    region: 'National Capital Region (NCR)',
    province: 'Metro Manila',
    city: 'Makati City',
    barangay: 'Bel-Air',
    street: 'Ayala Ave',
    postalCode: '1226',
    isDefault: true,
  );

  test('getAddresses calls /buyer/addresses and parses response list', () async {
    final server = _FakeServer([
      _json({
        'data': [
          sampleEntry.toJson(),
        ]
      }),
    ]);
    final tokens = InMemoryTokenStore();
    await tokens.write('test-token');
    final client = ApiClient(baseUrl: 'https://api.zefanya.test', tokenStore: tokens, httpClient: server.client);
    final repo = LiveAddressRepository(client);

    final addresses = await repo.getAddresses();

    expect(server.paths.single, '/buyer/addresses');
    expect(addresses.length, 1);
    expect(addresses.first.city, 'Makati City');
    expect(addresses.first.displayProvince, 'Metro Manila');
    expect(addresses.first.isDefault, isTrue);
  });

  test('createAddress posts to /buyer/addresses and returns created entry', () async {
    final server = _FakeServer([
      _json({
        'data': sampleEntry.toJson(),
      }, 201),
    ]);
    final tokens = InMemoryTokenStore();
    await tokens.write('test-token');
    final client = ApiClient(baseUrl: 'https://api.zefanya.test', tokenStore: tokens, httpClient: server.client);
    final repo = LiveAddressRepository(client);

    final result = await repo.createAddress(sampleEntry);

    expect(server.methods.single, 'POST');
    expect(server.paths.single, '/buyer/addresses');
    expect(result.recipient, 'Juan Dela Cruz');
  });

  test('updateAddress patches /buyer/addresses/{id}', () async {
    final server = _FakeServer([
      _json({
        'data': sampleEntry.copyWith(street: 'Updated Street').toJson(),
      }),
    ]);
    final tokens = InMemoryTokenStore();
    await tokens.write('test-token');
    final client = ApiClient(baseUrl: 'https://api.zefanya.test', tokenStore: tokens, httpClient: server.client);
    final repo = LiveAddressRepository(client);

    final result = await repo.updateAddress(sampleEntry.copyWith(street: 'Updated Street'));

    expect(server.methods.single, 'PATCH');
    expect(server.paths.single, '/buyer/addresses/1');
    expect(result.street, 'Updated Street');
  });

  test('deleteAddress calls DELETE /buyer/addresses/{id}', () async {
    final server = _FakeServer([
      _json({'ok': true}),
    ]);
    final tokens = InMemoryTokenStore();
    await tokens.write('test-token');
    final client = ApiClient(baseUrl: 'https://api.zefanya.test', tokenStore: tokens, httpClient: server.client);
    final repo = LiveAddressRepository(client);

    await repo.deleteAddress('1');

    expect(server.methods.single, 'DELETE');
    expect(server.paths.single, '/buyer/addresses/1');
  });

  test('setDefault patches /buyer/addresses/{id}/default', () async {
    final server = _FakeServer([
      _json({
        'data': sampleEntry.toJson(),
      }),
    ]);
    final tokens = InMemoryTokenStore();
    await tokens.write('test-token');
    final client = ApiClient(baseUrl: 'https://api.zefanya.test', tokenStore: tokens, httpClient: server.client);
    final repo = LiveAddressRepository(client);

    final result = await repo.setDefault('1');

    expect(server.methods.single, 'PATCH');
    expect(server.paths.single, '/buyer/addresses/1/default');
    expect(result.isDefault, isTrue);
  });
}
