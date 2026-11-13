import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/state/notification_state.dart';

http.Response _json(Object data, {int status = 200}) => http.Response(
  jsonEncode({'data': data, 'message': 'Inbox request failed'}),
  status,
  headers: {'content-type': 'application/json'},
);

Map<String, Object?> _page(String id, int unread, {bool more = false}) => {
  'data': [
    {'id': id, 'title': 'Order update', 'order_id': 12, 'read': false},
  ],
  'meta': {'has_more': more, 'unread_count': unread},
};

void main() {
  test(
    'Buyer inbox keeps global unread count, paginates and confirms reads',
    () async {
      final requests = <String>[];
      var failRead = true;
      final state = NotificationState(
        client: ApiClient(
          baseUrl: 'https://example.test/api',
          tokenStore: InMemoryTokenStore(),
          httpClient: MockClient((request) async {
            requests.add(request.url.path);
            if (request.method == 'GET') {
              return request.url.queryParameters['page'] == '1'
                  ? _json(_page('first', 31, more: true))
                  : _json(_page('second', 31));
            }
            if (failRead) return _json({}, status: 500);
            return _json({
              'unread_count': request.url.path.endsWith('read-all') ? 0 : 30,
            });
          }),
        ),
      );
      addTearDown(state.dispose);
      await state.setSession(account: 'buyer-1', rider: false);
      expect(state.unreadCount, 31);
      expect(state.notifications.single.orderId, '12');
      await state.loadMore();
      expect(state.notifications.map((item) => item.id), ['first', 'second']);
      expect(state.hasMore, isFalse);

      await state.markRead('first');
      expect(state.isRead('first'), isFalse);
      expect(state.unreadCount, 31);
      expect(state.error, isNotNull);
      failRead = false;
      await state.markRead('first');
      expect(state.isRead('first'), isTrue);
      expect(state.unreadCount, 30);
      await state.markAllRead();
      expect(state.isRead('second'), isTrue);
      expect(state.unreadCount, 0);
      expect(
        requests.every((path) => path.startsWith('/api/buyer/notifications')),
        isTrue,
      );
    },
  );

  test('an old Buyer response cannot replace another account inbox', () async {
    final old = Completer<http.Response>();
    final state = NotificationState(
      client: ApiClient(
        baseUrl: 'https://example.test/api',
        tokenStore: InMemoryTokenStore(),
        httpClient: MockClient((request) async {
          return request.url.path.contains('/buyer/')
              ? old.future
              : _json(_page('rider', 1));
        }),
      ),
    );
    addTearDown(state.dispose);
    final pending = state.setSession(account: 'buyer', rider: false);
    await state.setSession(account: 'rider', rider: true);
    old.complete(_json(_page('other', 10)));
    await pending;
    expect(state.notifications.single.id, 'rider');
    expect(state.unreadCount, 1);
    await state.setSession(account: null, rider: false);
    expect(state.notifications, isEmpty);
    expect(state.unreadCount, 0);
  });
}
