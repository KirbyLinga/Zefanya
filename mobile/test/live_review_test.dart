// test/live_review_test.dart
//
// The live half of the review repository, against a fake HTTP transport.
//
// `ReviewApiTest` proves the endpoints answer correctly; this proves the app
// asks the right question and reads the answer the way the rest of the app
// expects. The things worth checking from this side:
//
//  - the URL is `PATCH /orders/{orderId}/items/{productId}/review` — exactly
//    the seller_order_id and product_id the app holds, not the other ids it
//    does not;
//  - the body carries `stars`, `comment`, and `photo_path` and nothing else;
//  - `all()` asks `GET /reviews/mine` with the token and maps the list;
//  - a review whose product_id is null is filtered out silently;
//  - a re-submit is the same request regardless of whether the server answers
//    201 or 200 — the app treats the upsert as idempotent.

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/review_repository.dart';
import 'package:zefanya/state/review_state.dart';

// ── Helpers ──────────────────────────────────────────────────────────────────

/// A fake transport: records what was sent, answers from a script.
class _FakeServer {
  _FakeServer(this._script);

  final List<http.Response> _script;
  int _served = 0;

  final List<String> methods = [];
  final List<String> paths = [];
  final List<String> bodies = [];
  final List<Map<String, String>> headers = [];

  int get calls => paths.length;

  late final http.Client client =
      MockClient.streaming((request, bodyStream) async {
    methods.add(request.method);
    paths.add(request.url.path);
    headers.add(request.headers);
    bodies.add(await bodyStream.bytesToString());

    final response =
        _script[_served < _script.length ? _served++ : _script.length - 1];
    return http.StreamedResponse(
      Stream.value(utf8.encode(response.body)),
      response.statusCode,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  });

  String header(String name) => headers.isEmpty
      ? ''
      : headers.first.entries
          .firstWhere(
            (e) => e.key.toLowerCase() == name.toLowerCase(),
            orElse: () => const MapEntry('', ''),
          )
          .value;
}

http.Response _json(Map<String, Object?> body, [int status = 200]) =>
    http.Response(jsonEncode(body), status);

http.Response _list(List<Object?> rows) =>
    http.Response(jsonEncode({'data': rows}), 200);

/// One review as the server sends it.
Map<String, Object?> _reviewJson({
  String id = '1',
  String orderId = '7',
  String? productId = '3',
  int stars = 4,
  String comment = 'Lovely bowls.',
  String? photoUrl,
  String createdAt = '2026-10-05T14:23:00.000000Z',
}) =>
    {
      'id': id,
      'order_id': orderId,
      'product_id': productId,
      'stars': stars,
      'comment': comment,
      'photo_url': photoUrl,
      'created_at': createdAt,
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
    repository = LiveReviewRepository(client);
  }

  final _FakeServer server;
  final InMemoryTokenStore tokens;
  late final ApiClient client;
  late final LiveReviewRepository repository;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

void main() {
  group('all()', () {
    test('asks GET /reviews/mine with the token', () async {
      final live = _Live([_list([_reviewJson()])]);
      await live.tokens.write('a-token');

      await live.repository.all();

      expect(live.server.methods.single, 'GET');
      expect(live.server.paths.single, endsWith('/reviews/mine'));
      expect(live.server.header('authorization'), 'Bearer a-token');
    });

    test('maps the list into ProductReview objects', () async {
      final live = _Live([
        _list([
          _reviewJson(orderId: '7', productId: '3', stars: 4, comment: 'Lovely.'),
          _reviewJson(id: '2', orderId: '8', productId: '5', stars: 5, comment: ''),
        ]),
      ]);
      await live.tokens.write('a-token');

      final reviews = await live.repository.all();

      expect(reviews.length, 2);
      expect(reviews[0].orderId, '7');
      expect(reviews[0].productId, '3');
      expect(reviews[0].stars, 4);
      expect(reviews[0].comment, 'Lovely.');
      expect(reviews[1].orderId, '8');
      expect(reviews[1].stars, 5);
      expect(reviews[1].comment, '');
    });

    test('silently drops entries whose product_id is null', () async {
      // A review for a product that was removed after submission. The app has
      // nowhere to show it (no product page to link to), so it is filtered out
      // rather than stored as a review with an empty productId.
      final live = _Live([
        _list([
          _reviewJson(orderId: '7', productId: '3', stars: 4),
          _reviewJson(id: '2', orderId: '8', productId: null, stars: 5),
        ]),
      ]);
      await live.tokens.write('a-token');

      final reviews = await live.repository.all();

      expect(reviews.length, 1);
      expect(reviews.single.orderId, '7');
    });

    test('returns an empty list when there are no reviews', () async {
      final live = _Live([_list(const [])]);
      await live.tokens.write('a-token');

      final reviews = await live.repository.all();

      expect(reviews, isEmpty);
    });

    test('throws when the server returns 401', () async {
      final live = _Live([
        http.Response('{"message":"Unauthenticated."}', 401),
      ]);
      await live.tokens.write('stale');

      await expectLater(
        live.repository.all(),
        throwsA(isA<UnauthorizedException>()),
      );
    });

    test('throws ApiNotConfiguredException when no base URL is set', () async {
      final repository = LiveReviewRepository(
        ApiClient(baseUrl: '', tokenStore: InMemoryTokenStore()),
      );

      await expectLater(
        repository.all(),
        throwsA(isA<ApiNotConfiguredException>()),
      );
    });
  });

  group('submit()', () {
    final review = ProductReview(
      orderId: '7',
      productId: '3',
      stars: 4,
      comment: 'Lovely bowls.',
      createdAt: DateTime(2026, 10, 5),
    );

    test('sends PATCH to the right URL with stars and comment', () async {
      final live = _Live([
        _json({'data': _reviewJson()}, 201),
      ]);
      await live.tokens.write('a-token');

      await live.repository.submit(review);

      expect(live.server.methods.single, 'PATCH');
      // The route key is orderId (seller_order_id) and productId — what the
      // app has. Never the server's own review row id, which the app never
      // stores.
      expect(
        live.server.paths.single,
        endsWith('/orders/7/items/3/review'),
      );
      expect(live.server.header('authorization'), 'Bearer a-token');

      final body = jsonDecode(live.server.bodies.single) as Map<String, Object?>;
      expect(body['stars'], 4);
      expect(body['comment'], 'Lovely bowls.');
      expect(body.containsKey('photo_path'), isFalse);
    });

    test('includes photo_path when the review has one', () async {
      final live = _Live([_json({'data': _reviewJson()}, 201)]);
      await live.tokens.write('a-token');

      await live.repository.submit(ProductReview(
        orderId: '7',
        productId: '3',
        stars: 5,
        comment: '',
        photoPath: '/storage/emulated/0/review.jpg',
        createdAt: DateTime(2026, 10, 5),
      ));

      final body = jsonDecode(live.server.bodies.single) as Map<String, Object?>;
      expect(body['photo_path'], '/storage/emulated/0/review.jpg');
    });

    test('omits comment from the body when it is empty', () async {
      final live = _Live([_json({'data': _reviewJson(comment: '')}, 201)]);
      await live.tokens.write('a-token');

      await live.repository.submit(ProductReview(
        orderId: '7',
        productId: '3',
        stars: 3,
        comment: '',
        createdAt: DateTime(2026, 10, 5),
      ));

      final body = jsonDecode(live.server.bodies.single) as Map<String, Object?>;
      // An explicit empty-string `comment` would be valid too, but omitting it
      // keeps the body clean and matches the convention in OrderLineRequest.
      expect(body.containsKey('comment'), isFalse);
    });

    test('a 200 response (edit) is accepted the same as 201 (create)', () async {
      // The upsert contract: the server answers 201 for a new review and 200
      // for an edit. The app does not distinguish — it just needs to know the
      // write succeeded.
      final live = _Live([
        _json({'data': _reviewJson(stars: 5, comment: 'Grew on me.')}, 200),
      ]);
      await live.tokens.write('a-token');

      // Must not throw.
      await live.repository.submit(review);

      expect(live.server.calls, 1);
    });

    test('a server 422 surfaces the message as a ValidationException', () async {
      final live = _Live([
        _json({
          'message': 'That product was not part of this order.',
        }, 422),
      ]);
      await live.tokens.write('a-token');

      await expectLater(
        live.repository.submit(review),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.message,
            'message',
            contains('not part of this order'),
          ),
        ),
      );
    });

    test('throws ApiNotConfiguredException when no base URL is set', () async {
      final repository = LiveReviewRepository(
        ApiClient(baseUrl: '', tokenStore: InMemoryTokenStore()),
      );

      await expectLater(
        () async => repository.submit(review),
        throwsA(isA<ApiNotConfiguredException>()),
      );
    });
  });

  group('load()', () {
    test('is a no-op and does not touch the network', () async {
      // The server owns the reviews. load() on a live repository has nothing
      // local to restore and must not make a request — that is all().
      final live = _Live([_list(const [])]);
      await live.tokens.write('a-token');

      await live.repository.load();

      expect(live.server.calls, 0);
    });
  });
}
