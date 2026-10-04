// test/live_orders_test.dart
//
// The live order history, against a fake HTTP transport.
//
// `OrderApiTest` proves the endpoint answers correctly; this proves the app asks the
// right question and reads the answer the way the rest of the app expects. The
// failures guarded here are the quiet ones: thirteen server statuses squeezed into
// the app's four stages (and the wrong stage showing a parcel as delivered), a
// history asked for *without* the token (which answers 401 and looks like a broken
// server), and an item summary built from the wrong fields.

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/dto/remote_orders.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/repositories/order_repository.dart';

/// A fake transport: records what was sent, answers from a script.
class _FakeServer {
  _FakeServer(this._script);

  final List<http.Response> _script;
  int _served = 0;

  final List<String> paths = [];
  final List<Map<String, String>> headers = [];

  late final http.Client client =
      MockClient.streaming((request, bodyStream) async {
    paths.add(request.url.path);
    headers.add(request.headers);

    final response = _script[_served < _script.length ? _served++ : _script.length - 1];

    return http.StreamedResponse(
      Stream.value(utf8.encode(response.body)),
      response.statusCode,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  });

  /// The value of [name], case-insensitively: the transport lower-cases keys.
  String header(String name) => headers.isEmpty
      ? ''
      : headers.first.entries
          .firstWhere(
            (entry) => entry.key.toLowerCase() == name.toLowerCase(),
            orElse: () => const MapEntry('', ''),
          )
          .value;
}

http.Response _list(List<Object?> rows) => http.Response(jsonEncode({'data': rows}), 200);

/// One seller order as the server sends it, with the fields the app reads.
Map<String, Object?> _orderJson({
  Object? id = '12',
  Object? status = 'PLACED',
  Object? shop = 'Loom & Larder',
  List<Object?>? items = const [
    {'name': 'Handwoven Rattan Tote', 'quantity': 2, 'price_minor': 125075, 'product_id': '2'},
    {'name': 'Kamagong Stool', 'quantity': 1, 'price_minor': 380000, 'product_id': '4'},
  ],
  List<Object?>? productIds = const ['2', '4'],
}) =>
    {
      'id': id,
      'reference': 'ORD-20261004-4F8K2Q',
      'status': status,
      'shop': shop,
      'subtotal_minor': 630150,
      'items': items,
      'product_ids': productIds,
    };

/// The live repository, its token store, and the transport behind them.
class _Live {
  _Live(List<http.Response> script)
      : server = _FakeServer(script),
        tokens = InMemoryTokenStore() {
    client = ApiClient(
      baseUrl: 'https://api.zefanya.test',
      tokenStore: tokens,
      httpClient: server.client,
    );
    orders = LiveOrderRepository(client);
  }

  final _FakeServer server;
  final InMemoryTokenStore tokens;
  late final ApiClient client;
  late final LiveOrderRepository orders;
}

void main() {
  group('the mapping', () {
    test("the server's pipeline becomes the app's four stages", () {
      const expected = {
        'PLACED': OrderStatus.toShip,
        'CONFIRMED': OrderStatus.toShip,
        'PREPARING': OrderStatus.toShip,
        'READY_FOR_PICKUP': OrderStatus.toShip,
        'PICKED_UP': OrderStatus.inTransit,
        'AT_SORTING_CENTER': OrderStatus.inTransit,
        'SORTED': OrderStatus.inTransit,
        // Coming back to the seller is still movement, and the app has no stage for
        // it — the same choice `OrderState.resolvedOrders` makes for returnToSender.
        'RETURNED': OrderStatus.inTransit,
        'ASSIGNED_TO_RIDER': OrderStatus.outForDelivery,
        'OUT_FOR_DELIVERY': OrderStatus.outForDelivery,
        'DELIVERY_FAILED': OrderStatus.outForDelivery,
        'DELIVERED': OrderStatus.toRate,
        'COMPLETED': OrderStatus.toRate,
      };

      for (final entry in expected.entries) {
        expect(orderStatusFromServer(entry.key), entry.value, reason: entry.key);
      }
    });

    test('a status this build has never seen is not claimed to have moved', () {
      // The earliest stage claims the least. "In transit" for an unknown status
      // would be a claim about a real parcel made out of ignorance.
      expect(orderStatusFromServer('SOMETHING_NEW'), OrderStatus.toShip);
    });

    test("the summary is the app's own format, built from the item rows", () {
      final order = orderFromJson(_orderJson());

      expect(order.id, '12');
      expect(order.shopName, 'Loom & Larder');
      expect(order.itemSummary, 'Handwoven Rattan Tote ×2, Kamagong Stool');
      expect(order.productIds, ['2', '4']);
      expect(order.eta, isNotEmpty);
    });

    test('a line whose product is gone keeps its name and contributes no id', () {
      final order = orderFromJson(_orderJson(
        items: const [
          {'name': 'Capiz Shell Wind Chime', 'quantity': 1, 'price_minor': 68000, 'product_id': null},
        ],
        productIds: const [],
      ));

      expect(order.itemSummary, 'Capiz Shell Wind Chime');
      expect(order.productIds, isEmpty);
    });

    test('an unreadable body answers empty rather than throwing', () {
      // The tolerant-reader rule `remote_catalog.dart` states: one field that left
      // the database differently must not fail a whole response.
      final order = orderFromJson(const <String, Object?>{});

      expect(order.id, isEmpty);
      expect(order.shopName, isEmpty);
      expect(order.status, OrderStatus.toShip);
      expect(order.productIds, isEmpty);
    });
  });

  group('the request', () {
    test('the history is asked for with the token, and read in the order it arrives',
        () async {
      final live = _Live([
        _list([_orderJson(), _orderJson(id: '11', shop: 'Tahanan Ceramics')]),
      ]);
      await live.tokens.write('a-token');

      final orders = await live.orders.orders();

      expect(live.server.paths.single, endsWith('/orders'));
      expect(live.server.header('authorization'), 'Bearer a-token');
      expect([for (final order in orders) order.shopName], ['Loom & Larder', 'Tahanan Ceramics']);
    });

    test('a 401 says the token is no good, rather than looking like an empty history',
        () async {
      final live = _Live([http.Response('{"message":"Unauthenticated."}', 401)]);
      await live.tokens.write('stale');

      // The boot guard records this and the Orders screen shows it. What must not
      // happen is `[]`, which reads as "you have never ordered anything".
      await expectLater(live.orders.orders(), throwsA(isA<UnauthorizedException>()));
    });
  });
}
