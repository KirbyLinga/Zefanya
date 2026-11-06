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

  group('WebZefanya shipments', () {
    Map<String, Object?> shipment({String id = '12', String status = 'PLACED', String orderId = '5'}) => {
          'id': int.parse(id),
          'order_id': int.parse(orderId),
          'status': status,
          'seller': {'id': 3, 'name': 'Loom & Larder'},
          'items': [
            {'product_id': 2, 'product_name': 'Handwoven Rattan Tote', 'quantity': 2, 'price_minor': 125075},
            {'product_id': 4, 'product_name': 'Kamagong Stool', 'quantity': 1, 'price_minor': 380000},
          ],
          'updated_at': '2026-11-06T10:00:00+08:00',
        };

    test('a shipment becomes one order card with its parent order id', () {
      final order = orderFromShipmentJson(shipment());

      expect(order.id, '12');
      expect(order.serverOrderId, '5');
      expect(order.shopName, 'Loom & Larder');
      expect(order.itemSummary, 'Handwoven Rattan Tote ×2, Kamagong Stool');
      expect(order.productIds, ['2', '4']);
      expect(order.productQuantities, {'2': 2, '4': 1});
      expect(order.status, OrderStatus.toShip);
      expect(order.cancelled, isFalse);
    });

    test('cancellation and receipt are read from the server status', () {
      expect(orderFromShipmentJson(shipment(status: 'CANCELLED')).cancelled, isTrue);
      final completed = orderFromShipmentJson(shipment(status: 'COMPLETED'));
      expect(completed.buyerConfirmed, isTrue);
      expect(completed.deliveredAt, isNotNull);
      expect(orderFromShipmentJson(shipment(status: 'PLACED')).deliveredAt, isNull);
    });
  });

  group('the request', () {
    http.Response ok(Object? data, [int status = 200]) =>
        http.Response(jsonEncode({'success': true, 'data': data}), status);

    test('the history is GET /buyer/shipments with the token', () async {
      final live = _Live([
        ok({
          'data': [
            {'id': 12, 'order_id': 5, 'status': 'PLACED', 'seller': {'name': 'Loom & Larder'}, 'items': []},
          ],
        }),
      ]);
      await live.tokens.write('1|buyer');

      final orders = await live.orders.orders();

      expect(live.server.paths.single, '/buyer/shipments');
      expect(live.server.header('authorization'), 'Bearer 1|buyer');
      expect(orders.single.id, '12');
    });

    test('a 401 says the token is no good, rather than looking like an empty history', () async {
      final live = _Live([http.Response(jsonEncode({'message': 'Unauthenticated.'}), 401)]);
      await expectLater(live.orders.orders(), throwsA(isA<UnauthorizedException>()));
    });

    test('cancel and confirm go to the shipment routes under the parent order', () async {
      final live = _Live([ok(null)]);
      const base = Order(id: '12', shopName: 'Loom & Larder', status: OrderStatus.toShip, eta: '', itemSummary: '', serverOrderId: '5');

      await live.orders.saveBuyerState(base.copyWith(cancelled: true, cancellationReason: 'Changed my mind'));
      await live.orders.saveBuyerState(base.copyWith(buyerConfirmed: true));

      expect(live.server.paths, ['/buyer/orders/5/shipments/12/cancel', '/buyer/orders/5/shipments/12/confirm-received']);
      expect(jsonDecode(live.server.bodies.first), {'reason': 'Changed my mind'});
    });

    test('a stale cancel surfaces the 409 conflict', () async {
      final live = _Live([http.Response(jsonEncode({'message': 'This order can no longer be cancelled because the seller has already accepted it.'}), 409)]);
      const base = Order(id: '12', shopName: '', status: OrderStatus.toShip, eta: '', itemSummary: '', serverOrderId: '5');

      await expectLater(
        live.orders.saveBuyerState(base.copyWith(cancelled: true, cancellationReason: 'x')),
        throwsA(isA<RequestRejectedException>().having((e) => e.isConflict, 'conflict', isTrue)),
      );
    });

    final cartLine = {'id': 31, 'product_id': 2, 'variant_id': 9, 'quantity': 2};
    final quote = {
      'checkout_token': 'tok',
      'item_ids': [31],
      'shipping': {
        'all_routes_available': true,
        'options': [
          {'seller_id': 3, 'carriers': [{'id': 8, 'fee_minor': 4900}]},
        ],
      },
      'totals': {'payable_total_minor': 255050},
      'vouchers_state': <String, Object?>{},
      'fingerprints': {'price': 'p', 'address': 'a', 'shipping': 's', 'voucher': 'v'},
    };
    const line = OrderLineRequest(productId: '2', productName: 'Handwoven Rattan Tote', sellerName: 'Loom & Larder', quantity: 2, unitPrice: 1250.75, variantId: '9');

    test('placing syncs the server cart, quotes, and submits only ids and fingerprints', () async {
      final live = _Live([
        ok({'items': <Object?>[]}),          // GET cart
        ok({'items': [cartLine]}, 201),      // POST cart item
        ok(quote),                           // quote (default carriers)
        ok(quote),                           // quote (explicit carriers)
        ok({'id': 5, 'shipments': [{'id': 12, 'status': 'PLACED', 'seller': {'name': 'Loom & Larder'}, 'items': []}]}, 201),
      ]);

      final placed = await live.orders.place(const [line], expectedTotal: 2550.50);

      expect(live.server.paths, ['/buyer/cart', '/buyer/cart/items', '/buyer/checkout/quote', '/buyer/checkout/quote', '/buyer/checkout']);
      expect(jsonDecode(live.server.bodies[1]), {'product_id': 2, 'variant_id': 9, 'quantity': 2});
      final submitted = jsonDecode(live.server.bodies.last) as Map;
      expect(submitted['checkout_token'], 'tok');
      expect(submitted['carriers'], {'3': 8});
      // Nothing priced is sent: the server owns every amount.
      expect(submitted.keys, isNot(contains('unit_price')));
      expect(submitted.keys, isNot(contains('total_minor')));
      expect(placed.single.id, '12');
      expect(placed.single.serverOrderId, '5');
    });

    test('a server total that differs from the one the buyer saw stops the order', () async {
      final live = _Live([ok({'items': [cartLine]}), ok({'items': [cartLine]}), ok(quote), ok(quote)]);

      await expectLater(
        live.orders.place(const [line], expectedTotal: 2000),
        throwsA(isA<RequestRejectedException>().having((e) => e.isConflict, 'conflict', isTrue)),
      );
      expect(live.server.paths, isNot(contains('/buyer/checkout')));
    });

    test('quoteTotal reads the payable total in pesos', () async {
      final live = _Live([ok({'items': [cartLine]}), ok({'items': [cartLine]}), ok(quote), ok(quote)]);

      expect(await live.orders.quoteTotal(const [line]), 2550.50);
      // An existing server line is updated to the quantity, not added to.
      expect(live.server.methods[1], 'PATCH');
      expect(live.server.paths[1], '/buyer/cart/items/31');
    });

    test('nothing to place is answered locally, without a request', () async {
      final live = _Live([ok(null)]);
      expect(await live.orders.place(const []), isEmpty);
      expect(live.server.paths, isEmpty);
    });

    test('a live build with no base URL says so instead of placing anyway', () async {
      final orders = LiveOrderRepository(ApiClient(baseUrl: '', tokenStore: InMemoryTokenStore()));
      await expectLater(orders.place(const [line]), throwsA(isA<ApiNotConfiguredException>()));
    });
  });
}
