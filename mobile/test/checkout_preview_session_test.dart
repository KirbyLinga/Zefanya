// test/checkout_preview_session_test.dart
//
// F3 (final): the WebZefanya checkout preview is a cart-write workflow, so it
// must obey the same checkout-generation guard as placement. A cart read that
// completes after the account switched must not lead to cart or quote writes
// under the new account's session.
//
// WebZefanya is never contacted: the transport is an in-memory fake.

import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/api/webzefanya_contract.dart';
import 'package:zefanya/data/repositories/order_repository.dart';
import 'package:zefanya/data/repositories/repositories.dart';

const _lines = [
  OrderLineRequest(
    productId: '11',
    productName: 'Tote',
    sellerName: 'Shop',
    quantity: 2,
    unitPrice: 500,
  ),
];

Map<String, Object?> _quote() => {
  'item_ids': [7],
  'deliver_to': {'full': '1 Main St, Bay, Laguna'},
  'shipping': {
    'all_routes_available': true,
    'options': [
      {
        'seller_id': 3,
        'carriers': [
          {'id': 18},
        ],
      },
    ],
  },
  'totals': {'payable_total_minor': 123450},
  'vouchers_state': <String, Object?>{},
  'voucher_notice': null,
  'checkout_token': 'ck-1',
  'fingerprints': {
    'price': 'p1',
    'address': 'addr-fp',
    'shipping': 's1',
    'voucher': 'v1',
  },
};

http.Response _ok(Object? data) => http.Response.bytes(
  utf8.encode(jsonEncode({'success': true, 'data': data})),
  200,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

const _serverLine = {
  'id': 7,
  'product_id': 11,
  'variant_id': null,
  'quantity': 2,
};

class _Rig {
  _Rig({this.holdCartRead = false, this.cartReadFails = false}) {
    client = guardedWebZefanyaClient(
      baseUrl: 'https://wz.test/api',
      tokenStore: _tokens,
      httpClient: MockClient.streaming((request, stream) async {
        await stream.toBytes();
        final token = request.headers['Authorization'];
        calls.add('${request.method} ${request.url.path}');
        auth.add(token);
        final path = request.url.path;
        if (path.endsWith('/buyer/cart') && request.method == 'GET') {
          if (cartReadFails) {
            throw http.ClientException('connection reset');
          }
          await cartGate?.future;
          return _stream(_ok({'items': _cartItems}));
        }
        if (path.endsWith('/buyer/cart/items') && request.method == 'POST') {
          _cartItems.add(_serverLine);
          return _stream(_ok({'items': _cartItems}));
        }
        if (path.endsWith('/buyer/checkout/quote')) {
          return _stream(_ok(_quote()));
        }
        if (path.endsWith('/buyer/checkout') && request.method == 'POST') {
          return _stream(
            _ok({
              'id': 5,
              'shipments': [
                {'id': 12, 'status': 'PLACED', 'items': []},
              ],
            }),
          );
        }
        return _stream(
          http.Response(jsonEncode({'message': 'unexpected $path'}), 404),
        );
      }),
      allowVerifiedWrites: true,
    );
    orders = Repositories.webzefanya(client).order as LiveOrderRepository;
    if (holdCartRead) cartGate = Completer<void>();
  }

  final bool holdCartRead;
  final bool cartReadFails;
  final _tokens = InMemoryTokenStore();
  final _cartItems = <Map<String, Object?>>[];
  late final ApiClient client;
  late final LiveOrderRepository orders;
  Completer<void>? cartGate;
  final List<String> calls = [];
  final List<String?> auth = [];

  /// Requests that change server state (everything that is not a GET).
  List<String> get writes => [
    for (final c in calls)
      if (!c.startsWith('GET ')) c,
  ];

  Future<void> signIn(String owner) async {
    await client.adoptToken('token-$owner');
    orders.setCheckoutOwner(owner);
  }

  http.StreamedResponse _stream(http.Response r) => http.StreamedResponse(
    Stream.value(r.bodyBytes),
    r.statusCode,
    headers: {'content-type': 'application/json; charset=utf-8'},
  );
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('normal preview for one signed-in account works end to end', () async {
    final r = _Rig();
    await r.signIn('buyer-a');

    expect(await r.orders.quoteTotal(_lines), 1234.50);
    expect(r.writes, [
      'POST /api/buyer/cart/items',
      'POST /api/buyer/checkout/quote',
      'POST /api/buyer/checkout/quote',
    ]);
    // Every request used Buyer A's token.
    expect(r.auth.toSet(), {'Bearer token-buyer-a'});
  });

  test('review and placement work with only verified writes enabled', () async {
    final r = _Rig();
    await r.signIn('buyer-a');

    final review = await r.orders.reviewCheckout(_lines, const []);
    final placed = await r.orders.place(
      _lines,
      expectedTotal: review.total,
      expectedAddressFingerprint: review.addressFingerprint,
    );

    expect(placed.single.id, '12');
    expect(r.writes, [
      'POST /api/buyer/cart/items',
      'POST /api/buyer/checkout/quote',
      'POST /api/buyer/checkout/quote',
      'POST /api/buyer/checkout/quote',
      'POST /api/buyer/checkout/quote',
      'POST /api/buyer/checkout',
    ]);
    expect(r.auth.toSet(), {'Bearer token-buyer-a'});
  });

  test(
    'a cart read that returns after the account switched is rejected and sends no write',
    () async {
      final r = _Rig(holdCartRead: true);
      await r.signIn('buyer-a');

      final preview = r.orders.quoteTotal(_lines); // Buyer A starts
      await Future<void>.delayed(const Duration(milliseconds: 20));
      expect(r.calls, ['GET /api/buyer/cart']); // cart read is in flight

      await r.signIn('buyer-b'); // authentication switches to Buyer B
      r.cartGate!.complete(); // Buyer A's cart response arrives afterwards

      await expectLater(preview, throwsA(isA<UnauthorizedException>()));
      // Nothing was written, and in particular nothing under Buyer B's token.
      expect(r.writes, isEmpty);
      expect(r.auth.whereType<String>().toSet(), {'Bearer token-buyer-a'});
      expect(r.calls, ['GET /api/buyer/cart']);
    },
  );

  test('the address review is rejected the same way', () async {
    final r = _Rig(holdCartRead: true);
    await r.signIn('buyer-a');
    final review = r.orders.reviewCheckout(_lines, const []);
    await Future<void>.delayed(const Duration(milliseconds: 20));
    await r.signIn('buyer-b');
    r.cartGate!.complete();

    await expectLater(review, throwsA(isA<UnauthorizedException>()));
    expect(r.writes, isEmpty);
  });

  test(
    'signing out (no owner) during the cart read also aborts the preview',
    () async {
      final r = _Rig(holdCartRead: true);
      await r.signIn('buyer-a');
      final preview = r.orders.quoteTotal(_lines);
      await Future<void>.delayed(const Duration(milliseconds: 20));
      r.orders.setCheckoutOwner(null);
      r.cartGate!.complete();

      await expectLater(preview, throwsA(isA<UnauthorizedException>()));
      expect(r.writes, isEmpty);
    },
  );

  test(
    're-asserting the same account mid-flight keeps the operation valid',
    () async {
      final r = _Rig(holdCartRead: true);
      await r.signIn('buyer-a');
      final preview = r.orders.quoteTotal(_lines);
      await Future<void>.delayed(const Duration(milliseconds: 20));
      r.orders.setCheckoutOwner('buyer-a'); // same owner: no generation change
      r.cartGate!.complete();

      expect(await preview, 1234.50);
      expect(r.writes, hasLength(3));
      expect(r.auth.toSet(), {'Bearer token-buyer-a'});
    },
  );

  test(
    'the new account can preview immediately and is not affected by the stale one',
    () async {
      final r = _Rig(holdCartRead: true);
      await r.signIn('buyer-a');
      final stale = r.orders.quoteTotal(_lines);
      await Future<void>.delayed(const Duration(milliseconds: 20));
      await r.signIn('buyer-b');
      r.cartGate!.complete();
      await expectLater(stale, throwsA(isA<UnauthorizedException>()));

      r.calls.clear();
      r.auth.clear();
      expect(await r.orders.quoteTotal(_lines), 1234.50); // gate already open
      expect(r.writes, hasLength(3));
      expect(r.auth.toSet(), {'Bearer token-buyer-b'});
    },
  );

  test('placement still enforces the generation check', () async {
    final r = _Rig(holdCartRead: true);
    await r.signIn('buyer-a');
    final placement = r.orders.place(_lines);
    await Future<void>.delayed(const Duration(milliseconds: 20));
    await r.signIn('buyer-b');
    r.cartGate!.complete();

    await expectLater(placement, throwsA(isA<UnauthorizedException>()));
    expect(r.writes, isEmpty);
    expect(r.calls.where((c) => c.contains('/buyer/checkout')), isEmpty);
  });

  test(
    'a network failure in a stale preview does not disturb a newer checkout session',
    () async {
      // Buyer B has an unconfirmed checkout record under B's own key.
      final bKey = 'live_checkout:https://wz.test/api:buyer-b';
      SharedPreferences.setMockInitialValues({bKey: '{"token":"keep-me"}'});

      final r = _Rig(cartReadFails: true);
      await r.signIn('buyer-a');
      final stale = r.orders.quoteTotal(_lines);
      await r.signIn('buyer-b');

      await expectLater(stale, throwsA(isA<NetworkException>()));

      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString(bKey), '{"token":"keep-me"}'); // untouched
      expect(r.writes, isEmpty);
      // The checkout owner is still Buyer B: B still has its pending record.
      expect(await r.orders.hasPendingCheckout(), isTrue);
    },
  );

  test(
    'demo mode keeps its single raw-lines preview, with no cart workflow',
    () async {
      final calls = <String>[];
      final demo = LiveOrderRepository(
        ApiClient(
          baseUrl: 'https://demo.test/api',
          tokenStore: InMemoryTokenStore(),
          httpClient: MockClient.streaming((request, stream) async {
            await stream.toBytes();
            calls.add('${request.method} ${request.url.path}');
            return http.StreamedResponse(
              Stream.value(
                _ok({
                  'totals': {'payable_total_minor': 5000},
                }).bodyBytes,
              ),
              200,
              headers: {'content-type': 'application/json; charset=utf-8'},
            );
          }),
        ),
      )..setCheckoutOwner('buyer-a');

      final pending = demo.quoteTotal(_lines);
      demo.setCheckoutOwner('buyer-b'); // irrelevant: nothing to guard
      expect(await pending, 50.0);
      expect(calls, ['POST /api/v1/buyer/checkout/quote']);
    },
  );
}
