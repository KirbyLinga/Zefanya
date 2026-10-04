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
  final List<String> methods = [];
  final List<String> bodies = [];
  final List<Map<String, String>> headers = [];

  late final http.Client client =
      MockClient.streaming((request, bodyStream) async {
    paths.add(request.url.path);
    methods.add(request.method);
    // The body is recorded too, not just the path: what a write *sends* is half of
    // what it can get wrong, and a test that only checks the URL would pass for a
    // request that posted nothing.
    bodies.add(await bodyStream.bytesToString());
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

  group('placing an order', () {
    const lines = [
      OrderLineRequest(
        productId: '2',
        productName: 'Handwoven Rattan Tote',
        sellerName: 'Loom & Larder',
        quantity: 2,
        unitPrice: 1250.75,
      ),
    ];

    test('it posts the lines with the token and reads the new parcels back', () async {
      final live = _Live([
        http.Response(
          jsonEncode({
            'data': [_orderJson(id: '31', shop: 'Loom & Larder')],
            'meta': {
              'order_id': '9',
              'reference': 'ORD-20261004-4F8K2Q',
              'subtotal_minor': 250150,
              'discount_minor': 0,
              'total_minor': 250150,
            },
          }),
          201,
        ),
      ]);
      await live.tokens.write('a-token');

      final placed = await live.orders.place(lines);

      expect(live.server.methods.single, 'POST');
      expect(live.server.paths.single, endsWith('/orders'));
      expect(live.server.header('authorization'), 'Bearer a-token');

      // Exactly this, and therefore nothing else: no `buyer_id`, because the buyer
      // is whoever the token belongs to. The quoted unit price rides along and is
      // the server's to ignore (it charges the catalogue's price) — it is here so
      // that what the app showed can be reconciled with what was charged.
      expect(jsonDecode(live.server.bodies.single), {
        'items': [
          {'product_id': '2', 'quantity': 2, 'unit_price': 1250.75},
        ],
      });

      // Parsed by the same mapper the history uses, so the newly placed parcels and
      // the listed ones cannot be read two different ways.
      expect(placed.single.id, '31');
      expect(placed.single.shopName, 'Loom & Larder');
      expect(placed.single.status, OrderStatus.toShip);
      expect(placed.single.itemSummary, 'Handwoven Rattan Tote ×2, Kamagong Stool');
      expect(placed.single.productIds, ['2', '4']);
    });

    test('a two-shop checkout comes back as both parcels', () async {
      final live = _Live([
        http.Response(
          jsonEncode({
            'data': [
              _orderJson(id: '31', shop: 'Loom & Larder'),
              _orderJson(id: '32', shop: 'Tahanan Ceramics'),
            ],
            'meta': {
              'order_id': '9',
              'reference': 'ORD-20261004-4F8K2Q',
              'subtotal_minor': 630150,
              'discount_minor': 0,
              'total_minor': 630150,
            },
          }),
          201,
        ),
      ]);
      await live.tokens.write('a-token');

      final placed = await live.orders.place(lines);

      // The caller dispatches one courier job per returned parcel, so both must be
      // there — and in the server's order, which is the order they were placed.
      expect([for (final order in placed) order.shopName], ['Loom & Larder', 'Tahanan Ceramics']);
    });

    test('nothing to place is answered locally, without a request', () async {
      final live = _Live([_list(const [])]);
      await live.tokens.write('a-token');

      expect(await live.orders.place(const []), isEmpty);
      // A `POST` with no items is a 422 the app would have to explain; the caller
      // short-circuits on an empty cart, so this must too.
      expect(live.server.paths, isEmpty, reason: 'an empty basket must not mint an order');
    });

    test('a refused basket surfaces the server sentence, not an empty list', () async {
      final live = _Live([
        http.Response(
          '{"message":"Some items just went out of stock while placing your order. '
          'Please review your cart and try again."}',
          422,
        ),
      ]);
      await live.tokens.write('a-token');

      // `[]` here would read as "your order was placed but has no parcels", which
      // is why a refusal has to travel as an error with something to show.
      await expectLater(
        live.orders.place(lines),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.message,
            'message',
            contains('out of stock'),
          ),
        ),
      );
    });

    test('a live build with no base URL says so instead of placing anyway', () async {
      final repository = LiveOrderRepository(
        ApiClient(baseUrl: '', tokenStore: InMemoryTokenStore()),
      );

      await expectLater(
        repository.place(lines),
        throwsA(isA<ApiNotConfiguredException>()),
      );
    });
  });
}
