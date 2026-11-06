// test/live_catalog_test.dart
//
// The live catalogue and voucher repositories, against a fake HTTP transport.
//
// ## What this covers that the server tests cannot
//
// `ProductCatalogApiTest` proves the endpoints answer correctly. These prove the
// *app* asks the right question and reads the answer the way the rest of the app
// expects — the same contract from the other side. The failures this guards are
// the quiet ones: a price read as pesos instead of centavos renders ₱125,075.00
// for a ₱1,250.75 tote; an `'All'` chip sent as a category name returns an empty
// catalogue that looks like an empty shop; a flat voucher mapped into this app's
// pricing engine becomes a shipping rebate.
//
// The two assertions worth reading first are the *absences*: a live product must
// carry no sale price and no rating, because the server has neither — and a live
// voucher list must not include a voucher this app cannot apply correctly.

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/cart_repository.dart';
import 'package:zefanya/data/repositories/catalog_repository.dart';

/// A fake transport: records what was sent, answers from a script.
///
/// The same shape as `live_auth_test.dart`'s, plus `queries` — the catalogue is
/// the first repository whose *query string* is part of the contract.
class _FakeServer {
  _FakeServer(this._script);

  final List<http.Response> _script;
  int _served = 0;

  final List<String> methods = [];
  final List<String> paths = [];
  final List<Map<String, String>> queries = [];
  final List<Map<String, String>> headers = [];

  late final http.Client client =
      MockClient.streaming((request, bodyStream) async {
    methods.add(request.method);
    paths.add(request.url.path);
    queries.add(request.url.queryParameters);
    headers.add(request.headers);

    final response = _next();
    return http.StreamedResponse(
      Stream.value(utf8.encode(response.body)),
      response.statusCode,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  });

  /// The last scripted answer repeats, so a test that makes one request more than
  /// it planned fails on its own assertions rather than on an index error.
  http.Response _next() =>
      _script[_served < _script.length ? _served++ : _script.length - 1];
}

http.Response _json(Map<String, Object?> body, [int status = 200]) =>
    http.Response(jsonEncode(body), status);

/// WebZefanya's envelope: `{success, data}`; product lists nest `{data, meta}`.
http.Response _ok(Object? data) => _json({'success': true, 'data': data});

http.Response _page(List<Object?> rows) =>
    _ok({'data': rows, 'meta': {'page': 1, 'per_page': 20, 'total': rows.length, 'has_more': false}});

Map<String, Object?> _productJson({
  Object? id = 7,
  Object? name = 'Handwoven Rattan Tote',
  Object? category = 'Home and Garden',
  int priceMinor = 125075,
  int? regularMinor,
  Object? sellerName = 'Loom & Larder',
  Object? imageUrl = 'https://zefanya.test/storage/products/7/a.jpg',
  int reviewCount = 0,
  double? rating,
  int stock = 12,
}) =>
    {
      'id': id,
      'name': name,
      'category': {'id': 2, 'label': category},
      'seller': {'id': 3, 'name': sellerName},
      'image_url': imageUrl,
      'price': {
        'min_minor': priceMinor,
        'max_minor': priceMinor,
        'regular_min_minor': regularMinor ?? priceMinor,
        'regular_max_minor': regularMinor ?? priceMinor,
      },
      'rating': rating,
      'review_count': reviewCount,
      'sold': 0,
      'in_stock': stock > 0,
      'stock': stock,
    };

class _Live {
  _Live(List<http.Response> script)
      : server = _FakeServer(script),
        tokens = InMemoryTokenStore() {
    client = ApiClient(
      baseUrl: 'https://api.zefanya.test',
      tokenStore: tokens,
      httpClient: server.client,
    );
    catalog = LiveCatalogRepository(client);
    cart = LiveCartRepository(client);
  }

  final _FakeServer server;
  final InMemoryTokenStore tokens;
  late final ApiClient client;
  late final LiveCatalogRepository catalog;
  late final LiveCartRepository cart;
}

void main() {
  // ── What goes over the wire ──────────────────────────────────────────────

  test('the catalogue is fetched without a token, even when one is held', () async {
    final live = _Live([_page([])]);
    await live.tokens.write('1|buyer');

    await live.catalog.products();

    expect(live.server.paths.single, '/buyer/products');
    expect(live.server.headers.single.containsKey('authorization'), isFalse);
  });

  test('the category chip is sent as the server id behind its label', () async {
    final live = _Live([
      _ok([
        {'id': 2, 'label': 'Home and Garden'},
      ]),
      _page([]),
    ]);

    await live.catalog.products(query: const CatalogQuery(category: 'Home and Garden'));

    expect(live.server.paths, ['/buyer/categories', '/buyer/products']);
    expect(live.server.queries.last['category'], '2');
  });

  test('an unknown category answers empty without asking for products', () async {
    final live = _Live([_ok(<Object?>[])]);

    expect(await live.catalog.products(query: const CatalogQuery(category: 'Nope')), isEmpty);
    expect(live.server.paths, ['/buyer/categories']);
  });

  test('the app\'s "All" chip is not sent as a category', () async {
    final live = _Live([_page([])]);

    await live.catalog.products(query: const CatalogQuery(category: 'All'));

    expect(live.server.queries.single, isEmpty);
  });

  test('search becomes q; a shop filter uses the seller id the server sent', () async {
    final live = _Live([_page([_productJson()]), _page([])]);

    await live.catalog.products();
    await live.catalog.products(query: const CatalogQuery(search: 'rattan', seller: 'Loom & Larder'));

    expect(live.server.queries.last['q'], 'rattan');
    expect(live.server.queries.last['seller'], '3');
  });

  test('a blank filter is left off rather than sent as empty text', () async {
    final live = _Live([_page([])]);

    await live.catalog.products(query: const CatalogQuery(search: '  '));

    expect(live.server.queries.single, isEmpty);
  });

  test('one product is fetched by the id it holds', () async {
    final live = _Live([_ok(_productJson())]);

    final product = await live.catalog.productById('7');

    expect(live.server.paths.single, '/buyer/products/7');
    expect(product?.name, 'Handwoven Rattan Tote');
  });

  // ── What the answer becomes ──────────────────────────────────────────────

  test('a price in centavos becomes the pesos the app renders', () async {
    final live = _Live([_page([_productJson(priceMinor: 125075)])]);

    expect((await live.catalog.products()).single.price, 1250.75);
  });

  test('the detail cover photo is the first one, as the server ordered it', () async {
    final live = _Live([
      _ok({
        ..._productJson(),
        'images': ['https://zefanya.test/b.jpg', 'https://zefanya.test/a.jpg'],
      }),
    ]);

    final product = (await live.catalog.productById('7'))!;

    expect(product.images.first, endsWith('b.jpg'));
    expect(product.hasGallery, isTrue);
  });

  test('a product with no photo keeps an empty list', () async {
    final live = _Live([_page([_productJson(imageUrl: null)])]);

    final product = (await live.catalog.products()).single;

    expect(product.images, isEmpty);
    expect(product.hasGallery, isFalse);
  });

  test('a sale price is shown only when the server\'s regular price is higher', () async {
    final live = _Live([
      _page([
        _productJson(id: 1),
        _productJson(id: 2, priceMinor: 80000, regularMinor: 100000),
      ]),
    ]);

    final products = await live.catalog.products();

    expect(products.first.compareAtPrice, isNull);
    expect(products.last.compareAtPrice, 1000);
  });

  test('a live product with reviews carries the real aggregate', () async {
    final live = _Live([_page([_productJson(rating: 4.3, reviewCount: 47)])]);

    final product = (await live.catalog.products()).single;

    expect(product.rating, closeTo(4.3, 0.01));
    expect(product.ratingCount, 47);
  });

  test('variants carry the server SKU id, price and stock', () async {
    final live = _Live([
      _ok({
        ..._productJson(),
        'options': [
          {'name': 'Color', 'values': ['Black', 'Tan']},
        ],
        'variants': [
          {'id': 91, 'price': {'min_minor': 130000}, 'stock': 3, 'available': true, 'values': ['Black']},
          {'id': 92, 'price': {'min_minor': 125075}, 'stock': 0, 'available': true, 'values': ['Tan']},
        ],
      }),
    ]);

    final product = (await live.catalog.productById('7'))!;

    expect(product.variationTypes.single.name, 'Color');
    expect(product.variantForLabel('Black')?.id, '91');
    expect(product.variantForLabel('Black')?.price, 1300);
    expect(product.stock, 3);
  });

  test('an empty catalogue is an empty list, not a failure', () async {
    final live = _Live([_page([])]);

    expect(await live.catalog.products(), isEmpty);
  });

  test('a product that is gone reads as null rather than as an error', () async {
    final live = _Live([_json({'message': 'Not found.'}, 404)]);

    expect(await live.catalog.productById('7'), isNull);
  });

  // ── Categories ───────────────────────────────────────────────────────────

  test('the categories chip row leads with the app\'s own "All"', () async {
    final live = _Live([
      _ok([
        {'id': 1, 'label': 'Books and Media'},
        {'id': 2, 'label': 'Home and Garden'},
      ]),
    ]);

    expect(await live.catalog.categories(), ['All', 'Books and Media', 'Home and Garden']);
    expect(live.server.paths.single, '/buyer/categories');
  });

  test('a category row with no label does not become a blank chip', () async {
    final live = _Live([
      _ok([
        {'id': 1, 'label': ''},
        {'id': 2, 'label': 'Pet Supplies'},
      ]),
    ]);

    expect(await live.catalog.categories(), ['All', 'Pet Supplies']);
  });

  // ── Vouchers ─────────────────────────────────────────────────────────────

  test('claimable vouchers come from /buyer/vouchers with the token', () async {
    final live = _Live([
      _ok([
        {'code': 'WELCOME10', 'scope': 'shop', 'type': 'percent', 'value': 10, 'min_spend_minor': 50000, 'expires_at': null, 'summary': '10% off · Min. spend ₱500.00'},
        {'code': 'FLAT50', 'scope': 'shop', 'type': 'fixed', 'value': 5000, 'min_spend_minor': null, 'expires_at': null, 'summary': '₱50.00 off'},
      ]),
    ]);
    await live.tokens.write('1|buyer');

    final vouchers = await live.cart.vouchers();

    expect(live.server.paths.single, '/buyer/vouchers');
    expect(live.server.headers.single['authorization'], 'Bearer 1|buyer');
    expect(vouchers.map((v) => v.code), ['WELCOME10', 'FLAT50']);
    expect(vouchers.first.minSpend, 500);
    expect(vouchers.first.discountLabel, '10% off · Min. spend ₱500.00');
    expect(vouchers.last.fixedDiscount, 50);
    expect(vouchers.first.expiryLabel, 'No expiry');
  });

  test('a voucher whose value cannot be read is refused rather than guessed', () async {
    final live = _Live([
      _ok([
        {'code': 'ODD', 'type': 'percent', 'value': 'lots'},
      ]),
    ]);

    expect(await live.cart.vouchers(), isEmpty);
  });

  // ── What WebZefanya does not have ────────────────────────────────────────

  test('no maker note or promo slide is invented, and nothing is requested', () async {
    final live = _Live([_ok(null)]);

    expect(await live.catalog.makerNote('Loom & Larder'), isNull);
    expect(await live.catalog.promoSlides(), isEmpty);
    expect(live.server.paths, isEmpty);
  });
}
