// test/remote_chat_test.dart
//
// The wire-to-model mapper for `GET /chat/{contact}`.
//
// The repository test proves the request; this one pins the field translation
// so a server rename has exactly one failing place to point at.

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/data/dto/remote_chat.dart';

void main() {
  test('maps body, is_me and sent_at to the app model', () {
    final message = chatMessageFromJson(const {
      'id': '91',
      'body': 'Is the parcel nearby?',
      'is_me': true,
      'sent_at': '2026-10-29T08:12:00+00:00',
    });

    expect(message.text, 'Is the parcel nearby?');
    expect(message.isMe, isTrue);
    expect(message.time, isNotEmpty);
    expect(message.time, isNot('Now'));
  });

  test('missing fields fall back instead of failing the thread', () {
    final message = chatMessageFromJson(const {});

    expect(message.text, isEmpty);
    expect(message.isMe, isFalse);
    expect(message.time, 'Now');
  });

  test('an unparseable sent_at becomes Now', () {
    expect(chatTimeLabel('not-a-date'), 'Now');
    expect(chatTimeLabel(null), 'Now');
    expect(chatTimeLabel(''), 'Now');
  });
}
