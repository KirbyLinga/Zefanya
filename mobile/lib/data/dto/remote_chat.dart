// lib/data/dto/remote_chat.dart
//
// `GET /chat/{contact}` -> the app's `ChatMessage`.
//
// The wire says `body`, `is_me` and `sent_at`; the app says `text`, `isMe` and
// a short display time. That translation belongs here, not in the repository
// and certainly not in a screen: a server-side rename should touch this file
// and nothing else.
//
// `sent_at` is ISO 8601 UTC. The app shows a short local time ("2:05 PM"),
// because a thread is read in the reader's timezone. An unparseable or absent
// timestamp falls back to "Now" rather than failing the whole thread.

import '../../state/chat_state.dart';

/// Maps one chat row from `GET /api/chat/{contact}`.
ChatMessage chatMessageFromJson(Map<String, Object?> json) {
  return ChatMessage(
    text: json['body']?.toString() ?? '',
    isMe: json['is_me'] == true,
    time: chatTimeLabel(json['sent_at']?.toString()),
  );
}

/// Formats an ISO 8601 moment as the thread's short local time.
///
/// Public so the one formatting rule can be tested without a repository.
String chatTimeLabel(String? iso) {
  if (iso == null || iso.isEmpty) return 'Now';
  try {
    final local = DateTime.parse(iso).toLocal();
    final hour = local.hour == 0
        ? 12
        : local.hour > 12
        ? local.hour - 12
        : local.hour;
    final minute = local.minute.toString().padLeft(2, '0');
    final period = local.hour < 12 ? 'AM' : 'PM';
    return '$hour:$minute $period';
  } catch (_) {
    return 'Now';
  }
}
