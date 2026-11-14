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
    region: 'Central Luzon',
    province: 'Pampanga',
    provinceCode: '030540000',
    city: 'San Fernando',
    cityCode: '030540100',
    barangay: 'First',
    barangayCode: '0305401001',
    street: 'Ayala Ave',
    postalCode: '2000',
    isDefault: true,
  );

  // WebZefanya's AddressController::present shape.
  Map<String, Object?> wire({String street = 'Ayala Ave', bool isDefault = true}) => {
        'id': 1,
        'is_default': isDefault,
        'address_mode': 'api',
        'province': '030540000',
        'province_name': 'Pampanga',
        'municipality': '030540100',
        'municipality_name': 'San Fernando',
        'barangay': '0305401001',
        'barangay_name': 'First',
        'street': street,
        'house_number': null,
        'address_detail': null,
        'postal_code': '2000',
      };

  Future<(LiveAddressRepository, _FakeServer)> live(List<http.Response> script) async {
    final server = _FakeServer(script);
    final tokens = InMemoryTokenStore();
    await tokens.write('test-token');
    final client = ApiClient(baseUrl: 'https://api.zefanya.test', tokenStore: tokens, httpClient: server.client);
    return (LiveAddressRepository(client), server);
  }

  test('getAddresses calls /buyer/addresses and parses the response list', () async {
    final (repo, server) = await live([
      _json({'success': true, 'data': [wire()]}),
    ]);

    final addresses = await repo.getAddresses();

    expect(server.paths.single, '/buyer/addresses');
    expect(addresses.single.city, 'San Fernando');
    expect(addresses.single.cityCode, '030540100');
    expect(addresses.single.province, 'Pampanga');
    expect(addresses.single.isDefault, isTrue);
  });

  test('createAddress posts PSGC codes in api mode and returns the saved entry', () async {
    final (repo, server) = await live([
      _json({'success': true, 'data': wire()}, 201),
    ]);

    final result = await repo.createAddress(sampleEntry);

    expect(server.methods.single, 'POST');
    expect(server.paths.single, '/buyer/addresses');
    final body = jsonDecode(server.bodies.single) as Map;
    expect(body['address_mode'], 'api');
    expect(body['municipality'], '030540100');
    expect(body['municipality_name'], 'San Fernando');
    // Not stored by WebZefanya, so not sent.
    expect(body.containsKey('recipient'), isFalse);
    expect(result.id, '1');
  });

  test('an address without codes is sent in manual mode with names only', () async {
    final (repo, server) = await live([
      _json({'success': true, 'data': wire()}, 201),
    ]);

    await repo.createAddress(const AddressEntry(
      id: '', recipient: '', phone: '', region: '', city: 'San Fernando', barangay: 'First', street: 'Ayala Ave',
    ));

    final body = jsonDecode(server.bodies.single) as Map;
    expect(body['address_mode'], 'manual');
    expect(body.containsKey('municipality'), isFalse);
  });

  test('updateAddress puts /buyer/addresses/{id}', () async {
    final (repo, server) = await live([
      _json({'success': true, 'data': wire(street: 'Updated Street')}),
    ]);

    final result = await repo.updateAddress(sampleEntry.copyWith(street: 'Updated Street'));

    expect(server.methods.single, 'PUT');
    expect(server.paths.single, '/buyer/addresses/1');
    expect(result.street, 'Updated Street');
  });

  test('deleteAddress calls DELETE /buyer/addresses/{id}', () async {
    final (repo, server) = await live([
      _json({'success': true, 'data': null}),
    ]);

    await repo.deleteAddress('1');

    expect(server.methods.single, 'DELETE');
    expect(server.paths.single, '/buyer/addresses/1');
  });

  test('setDefault posts /buyer/addresses/{id}/default', () async {
    final (repo, server) = await live([
      _json({'success': true, 'data': wire()}),
    ]);

    final result = await repo.setDefault('1');

    expect(server.methods.single, 'POST');
    expect(server.paths.single, '/buyer/addresses/1/default');
    expect(result.isDefault, isTrue);
  });
}
