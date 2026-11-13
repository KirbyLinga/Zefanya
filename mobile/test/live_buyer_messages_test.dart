import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/chat_repository.dart';

http.Response _json(Object data, [int status = 200]) =>
    http.Response(jsonEncode({'data': data}), status);

void main() {
  test(
    'Buyer messaging uses conversation IDs and authenticates photos',
    () async {
      final token = InMemoryTokenStore();
      await token.write('buyer-test-token');
      final requests = <String>[];
      final repository = LiveChatRepository(
        ApiClient(
          baseUrl: 'https://example.test/api',
          tokenStore: token,
          httpClient: MockClient((request) async {
            expect(request.headers['authorization'], 'Bearer buyer-test-token');
            final path = request.url.path;
            requests.add(path);
            expect(request.url.host, 'example.test');
            switch (path) {
              case '/api/buyer/messages/open':
                expect(jsonDecode(request.body), {
                  'seller_id': 9,
                  'product_id': 14,
                });
                return _json({'conversation_id': 42});
              case '/api/buyer/messages/42':
                return _json({
                  'messages': [
                    {
                      'id': 6,
                      'sender_role': 'seller',
                      'body': 'In stock',
                      'image_url': 'https://another-host.test/photo',
                    },
                  ],
                });
              case '/api/buyer/messages/42/images/6':
                return http.Response.bytes([1, 2, 3], 200);
              case '/api/buyer/messages':
                if (request.method == 'GET') {
                  return _json([
                    {
                      'id': 42,
                      'seller': {'name': 'Shop'},
                      'unread_count': 1,
                    },
                  ]);
                }
                final body = jsonDecode(request.body) as Map;
                expect(body['conversation_id'], 42);
                if (body['body'] == 'Try again') return _json({}, 403);
                return _json({
                  'sender_role': 'buyer',
                  'body': 'Thank you',
                }, 201);
              default:
                fail('Unexpected request: ${request.method} $path');
            }
          }),
        ),
      );
      final inbox = await repository.buyerConversations();
      expect(inbox.single.id, 42);
      expect(inbox.single.unreadCount, 1);
      expect(await repository.openBuyerConversation(9, 14), 42);
      final messages = await repository.buyerMessages(42);
      expect(messages.single.text, 'In stock');
      expect(messages.single.isMe, isFalse);
      expect(await repository.buyerMessageImage(42, messages.single.imageId!), [
        1,
        2,
        3,
      ]);
      expect((await repository.sendBuyerMessage(42, 'Thank you')).isMe, isTrue);
      await expectLater(
        repository.sendBuyerMessage(42, 'Try again'),
        throwsA(isA<ForbiddenException>()),
      );
      expect(requests, hasLength(6));
    },
  );
}
