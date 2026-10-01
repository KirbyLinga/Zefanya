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
import 'package:zefanya/data/api/api_exception.dart';
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

/// A `{"data": [...]}` envelope, which is what both list endpoints return.
http.Response _list(List<Object?> rows) => _json({'data': rows});

/// One product as the server sends it, with the fields the app actually reads.
Map<String, Object?> _productJson({
  Object? id = '7',
  Object? name = 'Handwoven Rattan Tote',
  Object? category = 'Home and Garden',
  Object? priceMinor = 125075,
  Object? sellerName = 'Loom & Larder',
  Object? description = 'Woven from rattan offcuts.',
  List<Object?>? images = const ['https://zefanya.test/storage/products/7/a.jpg'],
  Object? inStock = true,
}) =>
    {
      'id': id,
      'name': name,
      'category': category,
      'price_minor': priceMinor,
      'description': description,
      'in_stock': inStock,
      'seller': {'name': sellerName},
      'images': images,
    };

/// The live repositories, their token store, and the transport behind them.
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

  test('the catalogue is fetched without a token, even when one is held',
      () async {
    final live = _Live([_list(const [])]);
    await live.tokens.write('1|plaintext');

    await live.catalog.products();

    // The route is public, and the client is told so rather than being left to
    // attach whatever happens to be in storage: a catalogue is the same for
    // everybody, signed in or not.
    expect(live.server.paths.single, '/products');
    expect(live.server.headers.single.containsKey('authorization'), isFalse);
  });

  test('the category chip is sent as the label it shows', () async {
    final live = _Live([_list(const [])]);

    await live.catalog
        .products(query: const CatalogQuery(category: 'Home and Garden'));

    expect(live.server.queries.single['category'], 'Home and Garden');
  });

  test('the app\'s "All" chip is not sent as a category', () async {
    final live = _Live([_list(const [])]);

    await live.catalog.products(query: const CatalogQuery(category: 'All'));

    // 'All' is the app's own "no filter" affordance, not a category row. Sent as
    // one, it would ask the server for a category by that name — an empty result
    // that renders as an empty shop.
    expect(live.server.queries.single, isEmpty);
  });

  test('search and shop filters become q and seller', () async {
    final live = _Live([_list(const [])]);

    await live.catalog.products(
      query: const CatalogQuery(search: 'rattan', seller: 'Loom & Larder'),
    );

    expect(live.server.queries.single['q'], 'rattan');
    expect(live.server.queries.single['seller'], 'Loom & Larder');
  });

  test('a blank filter is left off rather than sent as empty text', () async {
    final live = _Live([_list(const [])]);

    await live.catalog.products(query: const CatalogQuery(search: '   '));

    // Whitespace is not a search. It would be harmless today (`LIKE '%%'`), and
    // it is exactly the kind of thing that becomes `LIKE '% %'` later.
    expect(live.server.queries.single, isEmpty);
  });

  test('one product is fetched by the id it holds', () async {
    final live = _Live([
      _json({'data': _productJson()}),
    ]);

    await live.catalog.productById('7');

    expect(live.server.paths.single, '/products/7');
  });

  // ── Reading a product ────────────────────────────────────────────────────

  test('a price in centavos becomes the pesos the app renders', () async {
    final live = _Live([
      _list([_productJson(priceMinor: 125075)]),
    ]);

    // 125075 centavos. A `/ 100` typed anywhere but `money.dart` is the bug that
    // file exists to prevent, so the seam is where it is asserted.
    expect((await live.catalog.products()).single.price, 1250.75);
  });

  test('a price that arrives as a string is read the same way', () async {
    final live = _Live([
      _list([_productJson(priceMinor: '6000')]),
    ]);

    // PHP's encoder emits both shapes for the same column depending on how the
    // value left the database; rejecting one would fail a whole list over a
    // difference with no meaning.
    expect((await live.catalog.products()).single.price, 60);
  });

  test('the cover photo is the first one, as the server ordered it', () async {
    final live = _Live([
      _list([
        _productJson(images: [
          'https://zefanya.test/storage/products/7/b.jpg',
          'https://zefanya.test/storage/products/7/a.jpg',
        ]),
      ]),
    ]);

    final product = (await live.catalog.products()).single;

    expect(product.images.first, endsWith('b.jpg'));
    expect(product.hasGallery, isTrue);
  });

  test('a product with no photos keeps an empty list and still has a cover',
      () async {
    final live = _Live([
      _list([_productJson(images: const [])]),
    ]);

    final product = (await live.catalog.products()).single;

    // Empty stays empty — the server sent no photos, and a URL invented here
    // would be nobody's photo — while `photos` falls back to the app's own
    // labelled placeholder so the layout still has a cover to draw.
    expect(product.images, isEmpty);
    expect(product.photos, hasLength(1));
  });

  test('a live product states no rating and no sale price', () async {
    final live = _Live([
      _list([_productJson()]),
    ]);

    final product = (await live.catalog.products()).single;

    // The two fields the server cannot fill. The constructor's own defaults are
    // the *mock* catalogue's numbers (4.5 stars), so the interesting half of this
    // test is that a live product does not inherit them: an average over no
    // ratings is not a fact about anything.
    expect(product.rating, 0);
    expect(product.ratingCount, 0);
    expect(product.soldCount, 0);
    expect(product.compareAtPrice, isNull);
  });

  test('an empty catalogue is an empty list, not a failure', () async {
    final live = _Live([_list(const [])]);

    // The app renders an empty state for an empty list and an error-with-retry
    // for a throw; collapsing the two would tell someone whose shop has nothing
    // listed that their connection dropped.
    expect(await live.catalog.products(), isEmpty);
  });

  test('a product that is gone reads as null rather than as an error',
      () async {
    final live = _Live([
      _json({'message': 'Not found.'}, 404),
    ]);

    // The interface promises null so a delisted product page can say "no longer
    // available" instead of failing — a 404 is a fact about a product, not a
    // broken request.
    expect(await live.catalog.productById('7'), isNull);
  });

  test('a malformed list is an error, not an empty shop', () async {
    final live = _Live([
      _json({'data': 'products'}),
    ]);

    // `unwrapList` throws for a non-list, because that shape means the endpoint
    // is wrong rather than empty. An empty list here would hide it behind an
    // empty-state screen.
    await expectLater(
      live.catalog.products(),
      throwsA(isA<MalformedResponseException>()),
    );
  });

  // ── Categories ───────────────────────────────────────────────────────────

  test('the categories chip row leads with the app\'s own "All"', () async {
    final live = _Live([
      _list([
        {'id': '1', 'label': 'Books and Media'},
        {'id': '2', 'label': 'Home and Garden'},
      ]),
    ]);

    final categories = await live.catalog.categories();

    // The leading 'All' is the app's affordance, not a server row: the interface
    // promises it, and everything after it is the server's own curated list in
    // the server's own order.
    expect(categories, ['All', 'Books and Media', 'Home and Garden']);
  });

  test('a category row with no label does not become a blank chip', () async {
    final live = _Live([
      _list([
        {'id': '1', 'label': 'Pet Supplies'},
        {'id': '2'},
      ]),
    ]);

    expect(await live.catalog.categories(), ['All', 'Pet Supplies']);
  });

  // ── Vouchers ─────────────────────────────────────────────────────────────

  test('a percentage voucher maps exactly onto the app\'s pricing engine',
      () async {
    final live = _Live([
      _list([
        {
          'code': 'WELCOME10',
          'type': 'percent',
          'value': 10,
          'min_spend_minor': 50000,
          'expires_at': '2026-12-31T00:00:00+00:00',
        },
      ]),
    ]);

    final voucher = (await live.cart.vouchers()).single;

    expect(live.server.paths.single, '/vouchers');
    expect(voucher.code, 'WELCOME10');
    expect(voucher.discountRate, closeTo(0.10, 1e-9));
    // Centavos on the wire, pesos in the model — one conversion, in money.dart.
    expect(voucher.minSpend, 500);
    expect(voucher.expiryLabel, 'Valid until Dec 31');
    // The server caps nothing, so the app does not invent a ceiling: the engine
    // clamps to infinity, which is not clamping.
    expect(voucher.maxDiscount, double.infinity);
  });

  test('a voucher with no minimum spend or expiry still reads sensibly',
      () async {
    final live = _Live([
      _list([
        {
          'code': 'ZEFANYANEW',
          'type': 'percent',
          'value': 5,
          'min_spend_minor': null,
          'expires_at': null,
        },
      ]),
    ]);

    final voucher = (await live.cart.vouchers()).single;

    expect(voucher.minSpend, 0);
    expect(voucher.expiryLabel, 'No expiry');
  });

  test('a flat voucher is not offered, because it cannot be applied correctly',
      () async {
    final live = _Live([
      _list([
        {
          'code': 'LESS200',
          'type': 'fixed',
          'value': 20000,
          'min_spend_minor': null,
          'expires_at': null,
        },
      ]),
    ]);

    // ₱200 off the *goods* has no expression in this app: its pricing engine
    // reads `discountRate <= 0` as a shipping rebate, so offering this voucher
    // would silently become ₱200 off the delivery fee. Refusing it is visible and
    // arguable; applying the wrong one is neither.
    expect(await live.cart.vouchers(), isEmpty);
  });

  test('one flat voucher does not take the percentage vouchers with it',
      () async {
    final live = _Live([
      _list([
        {'code': 'LESS200', 'type': 'fixed', 'value': 20000},
        {'code': 'WELCOME10', 'type': 'percent', 'value': 10},
      ]),
    ]);

    // The refusal is per voucher, so a catalogue that mixes the two still offers
    // the half this app understands.
    final vouchers = await live.cart.vouchers();

    expect(vouchers.map((v) => v.code), ['WELCOME10']);
  });

  test('a voucher whose value cannot be read is refused rather than guessed',
      () async {
    final live = _Live([
      _list([
        {'code': 'BROKEN', 'type': 'percent', 'value': 'not a number'},
        {'code': '', 'type': 'percent', 'value': 10},
      ]),
    ]);

    // No discount at all is better than an invented one, and a code-less voucher
    // is not something a person could type.
    expect(await live.cart.vouchers(), isEmpty);
  });

  // ── What is still not wired, and what a misconfigured build says ─────────

  test('the maker note names itself as not wired instead of inventing prose',
      () async {
    final live = _Live([_list(const [])]);

    // There is no maker-note column, so Home's "FROM THE MAKER" section stays
    // absent. Naming the endpoint is what makes the failure actionable.
    await expectLater(
      () async => live.catalog.makerNote('Loom & Larder'),
      throwsA(
        isA<ApiNotImplementedException>()
            .having((e) => e.endpoint, 'endpoint', contains('maker_note')),
      ),
    );
  });

  test('the promo carousel is not wired to anything either', () async {
    final live = _Live([_list(const [])]);

    // There is no promotions table at all, so the carousel keeps saying so.
    await expectLater(
      () async => live.catalog.promoSlides(),
      throwsA(isA<ApiNotImplementedException>()),
    );
  });

  test('a live repository with no base URL is a configuration failure',
      () async {
    final client = ApiClient(
      baseUrl: '',
      tokenStore: InMemoryTokenStore(),
    );

    // Reported *before* any endpoint is named: this one is fixed by setting a
    // flag, and a live build must never quietly fall back to mock data.
    await expectLater(
      () async => LiveCatalogRepository(client).products(),
      throwsA(isA<ApiNotConfiguredException>()),
    );
    await expectLater(
      () async => LiveCartRepository(client).vouchers(),
      throwsA(isA<ApiNotConfiguredException>()),
    );
  });
}
