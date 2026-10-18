// test/live_chat_test.dart
//
// LiveChatRepository against a fake HTTP transport.
//
// Proves: correct endpoints, correct body shape, correct DTO mapping,
// correct error forwarding — without a running server.

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/chat_repository.dart';
import 'package:zefanya/state/chat_state.dart';

// ── Fake transport ────────────────────────────────────────────────────────────

class _FakeServer {
  _FakeServer(this._script);
  final List<http.Response> _script;
  int _served = 0;

  final List<String> methods = [];
  final List<String> paths = [];
  final List<Map<String, String>> headers = [];
  final List<String> bodies = [];

  late final http.Client client =
      MockClient.streaming((request, bodyStream) async {
    methods.add(request.method);
    paths.add(request.url.path);
    headers.add(request.headers);
    final bytes = await bodyStream.toBytes();
    bodies.add(utf8.decode(bytes));

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

http.Response _ok(List<Object?> data) => http.Response.bytes(
      utf8.encode(jsonEncode({'data': data})),
      200,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );

http.Response _created(Map<String, Object?> data) => http.Response.bytes(
      utf8.encode(jsonEncode({'data': data})),
      201,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );

Map<String, Object?> _msgJson({
  String id = '1',
  String body = 'Hello!',
  bool isMe = false,
  String sentAt = '2026-10-07T10:05:00+00:00',
}) =>
    {
      'id': id,
      'body': body,
      'is_me': isMe,
      'sent_at': sentAt,
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
    repo = LiveChatRepository(client);
  }

  final _FakeServer server;
  final InMemoryTokenStore tokens;
  late final ApiClient client;
  late final LiveChatRepository repo;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

void main() {
  // ── messagesFor ──────────────────────────────────────────────────────────

  test('messagesFor sends GET /chat/{contact} with auth', () async {
    final live = _Live([_ok([])]);
    await live.tokens.write('buyer-token');

    await live.repo.messagesFor('Willow & Bloom Atelier');

    expect(live.server.methods.single, 'GET');
    expect(live.server.paths.single,
        endsWith('/chat/Willow%20%26%20Bloom%20Atelier'));
    expect(live.server.header('authorization'), 'Bearer buyer-token');
  });

  test('messagesFor maps messages to ChatMessage objects', () async {
    final live = _Live([
      _ok([
        _msgJson(id: '1', body: 'Hello!', isMe: true,  sentAt: '2026-10-07T10:05:00Z'),
        _msgJson(id: '2', body: 'Hi back', isMe: false, sentAt: '2026-10-07T10:06:00Z'),
      ]),
    ]);
    await live.tokens.write('t');

    final msgs = await live.repo.messagesFor('Willow & Bloom Atelier');

    expect(msgs.length, 2);
    expect(msgs[0].text, 'Hello!');
    expect(msgs[0].isMe, isTrue);
    expect(msgs[1].text, 'Hi back');
    expect(msgs[1].isMe, isFalse);
  });

  test('messagesFor returns empty list for an empty thread', () async {
    final live = _Live([_ok([])]);
    await live.tokens.write('t');

    final msgs = await live.repo.messagesFor('Willow & Bloom Atelier');
    expect(msgs, isEmpty);
  });

  test('messagesFor formats sent_at as a short time string', () async {
    final live = _Live([
      _ok([
        // UTC 02:05 → local may vary, but the test just confirms something is set
        _msgJson(sentAt: '2026-10-07T14:05:00Z'),
      ]),
    ]);
    await live.tokens.write('t');

    final msgs = await live.repo.messagesFor('Willow & Bloom Atelier');
    // Should be a non-empty short time string, not 'Now'
    expect(msgs.single.time, isNotEmpty);
    expect(msgs.single.time, isNot('Now'));
  });

  test('messagesFor throws UnauthorizedException on 401', () async {
    final live = _Live([
      http.Response('{"message":"Unauthenticated."}', 401),
    ]);
    await live.tokens.write('stale');

    await expectLater(
      live.repo.messagesFor('Someone'),
      throwsA(isA<UnauthorizedException>()),
    );
  });

  test('messagesFor throws ApiNotConfiguredException when no base URL', () async {
    final repo = LiveChatRepository(
      ApiClient(baseUrl: '', tokenStore: InMemoryTokenStore()),
    );
    await expectLater(
      () async => repo.messagesFor('Someone'),
      throwsA(isA<ApiNotConfiguredException>()),
    );
  });

  // ── append ───────────────────────────────────────────────────────────────

  test('append sends POST /chat/{contact} with the message body', () async {
    final live = _Live([
      _created(_msgJson(id: '5', body: 'My message', isMe: true)),
    ]);
    await live.tokens.write('buyer-token');

    await live.repo.append(
      'Willow & Bloom Atelier',
      const ChatMessage(text: 'My message', isMe: true, time: 'Now'),
    );

    expect(live.server.methods.single, 'POST');
    expect(live.server.paths.single,
        endsWith('/chat/Willow%20%26%20Bloom%20Atelier'));
    expect(live.server.bodies.single, contains('"body"'));
    expect(live.server.bodies.single, contains('My message'));
    expect(live.server.header('authorization'), 'Bearer buyer-token');
  });

  test('append throws ApiNotConfiguredException when no base URL', () async {
    final repo = LiveChatRepository(
      ApiClient(baseUrl: '', tokenStore: InMemoryTokenStore()),
    );
    await expectLater(
      () async => repo.append(
        'Someone',
        const ChatMessage(text: 'hi', isMe: true, time: 'Now'),
      ),
      throwsA(isA<ApiNotConfiguredException>()),
    );
  });

  // ── threads ──────────────────────────────────────────────────────────────

  test('threads returns empty map (no list-all endpoint yet)', () async {
    final live = _Live([]);
    await live.tokens.write('t');

    final result = await live.repo.threads();
    expect(result, isEmpty);
  });

  // ── seedIfEmpty ──────────────────────────────────────────────────────────

  test('seedIfEmpty is a no-op in live mode', () async {
    // No server calls should be made.
    final live = _Live([]);
    await live.tokens.write('t');

    await live.repo.seedIfEmpty(
      'Willow & Bloom Atelier',
      const ChatMessage(text: 'Hi!', isMe: false, time: '10:02 AM'),
    );

    expect(live.server.methods, isEmpty);
  });
}
