// lib/data/repositories/chat_repository.dart
//
// Conversation history.
//
// ## What is mock-only here, and why the *key* is the interesting part
//
// There is no chat/messages table on the Laravel side (one of the four the
// brief lists as missing). So history stays device-local.
//
// The important thing about this repository is therefore its **thread key**,
// not its storage. Threads are currently keyed by the contact's *display name*
// — `ChatState` says so in its own header, and it is a real collision: two
// different riders called Rico share a thread, and a shop that renames itself
// loses its history.
//
// A stable key is needed before the server can own this, and the server's key
// would be a conversation id, not a name. Rather than refactor `ChatState` (and
// the two screens that call `messagesFor(name)`) in a pass that must not change
// behaviour, the interface *takes* a contact key and documents that today that
// key is a name. The day conversations come from the API, the key becomes an id
// and the call sites change — which is exactly the change this seam exists to
// localise.
//
// ## Why there is no `sendMessage` here yet
//
// Sending is currently synchronous and local; a live send is a request that can
// fail, retry and arrive out of order. Folding that in now would change the
// chat screen's behaviour (today a sent message *cannot* fail), which the pass
// forbids. `docs/api-contract.md` §9 specifies `POST /conversations/{id}/messages`
// and the ordering/optimistic-send semantics it will need.

import '../../state/chat_state.dart';
import '../api/api_exception.dart';
import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import 'live_stub.dart';

abstract class ChatRepository {
  /// Restores local threads before the first frame (see `main()`).
  Future<void> load();

  /// Messages for one contact, oldest first.
  ///
  /// [contactKey] is a display name today — see the header. A live
  /// implementation will treat it as a conversation id, which is why the name
  /// of the parameter is deliberately not `contactName`.
  Future<List<ChatMessage>> messagesFor(String contactKey);

  /// Every thread, keyed by contact.
  ///
  /// Exists so a caller can warm its cache once at startup rather than lazily
  /// on first open. Without it, a thread that has never been opened reads as
  /// empty — which is indistinguishable from "no history", the exact confusion
  /// this whole persistence layer was built to avoid.
  Future<Map<String, List<ChatMessage>>> threads();

  /// Appends a message to a thread, creating the thread if needed.
  Future<void> append(String contactKey, ChatMessage message);

  /// Seeds a thread with an opening message, only if it has no history.
  ///
  /// Part of the interface rather than a UI detail: in live mode the opener is
  /// something the *server* has already stored, so a live implementation would
  /// do nothing here — but a caller must still be able to ask for it
  /// unconditionally, which is what the current screens do.
  Future<void> seedIfEmpty(String contactKey, ChatMessage greeting);
}

// ---------------------------------------------------------------------------
// Mock
// ---------------------------------------------------------------------------

class MockChatRepository implements ChatRepository {
  static const _storageKey = 'chat_threads';

  final Map<String, List<ChatMessage>> _threads = {};

  @override
  Future<void> load() async {
    // The stored shape is unchanged from what `ChatState` wrote: contact name
    // as key, a list of `ChatMessage.toJson` as value. An existing install's
    // threads therefore keep parsing after the move.
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_storageKey);
      if (raw == null) return;
      final decoded = jsonDecode(raw) as Map<String, dynamic>;
      _threads
        ..clear()
        ..addAll(decoded.map((contact, messages) => MapEntry(
              contact,
              [
                for (final m in messages as List<dynamic>)
                  ChatMessage.fromJson(m as Map<String, dynamic>),
              ],
            )));
    } catch (_) {
      // Corrupt/old data shape — start with no history rather than crash.
      _threads.clear();
    }
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
      _storageKey,
      jsonEncode(_threads.map(
        (contact, messages) =>
            MapEntry(contact, messages.map((m) => m.toJson()).toList()),
      )),
    );
  }

  @override
  Future<List<ChatMessage>> messagesFor(String contactKey) async =>
      List.unmodifiable(_threads[contactKey] ?? const []);

  @override
  Future<Map<String, List<ChatMessage>>> threads() async => {
        for (final entry in _threads.entries)
          entry.key: List.unmodifiable(entry.value),
      };

  @override
  Future<void> append(String contactKey, ChatMessage message) async {
    _threads.putIfAbsent(contactKey, () => []).add(message);
    await _save();
  }

  @override
  Future<void> seedIfEmpty(String contactKey, ChatMessage greeting) async {
    if (_threads.containsKey(contactKey)) return;
    _threads[contactKey] = [greeting];
    await _save();
  }
}

// ---------------------------------------------------------------------------
// Live
// ---------------------------------------------------------------------------

class LiveChatRepository extends LiveRepository implements ChatRepository {
  LiveChatRepository(super.client);

  @override
  Future<void> load() async {
    // Nothing local to restore in live mode; threads live on the server.
  }

  @override
  Future<List<ChatMessage>> messagesFor(String contactKey) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final body = await client.getJson(
      '/chat/${Uri.encodeComponent(contactKey)}',
      authenticated: true,
    );
    final list = body['data'] as List<dynamic>? ?? const [];
    return list
        .whereType<Map<String, dynamic>>()
        .map(_messageFromJson)
        .toList(growable: false);
  }

  @override
  Future<Map<String, List<ChatMessage>>> threads() async {
    // The server has no "list all threads" endpoint yet — it requires a
    // contact name, not a thread id. Returning an empty map means the cache
    // starts empty and is filled lazily when each thread is opened, which is
    // the correct behaviour: the app never showed a thread list in live mode
    // before this landed, so there is no regression.
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return const {};
  }

  @override
  Future<void> append(String contactKey, ChatMessage message) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    await client.postJson(
      '/chat/${Uri.encodeComponent(contactKey)}',
      body: {'body': message.text},
      authenticated: true,
    );
  }

  @override
  Future<void> seedIfEmpty(String contactKey, ChatMessage greeting) async {
    // In live mode the opening message is already stored server-side,
    // so "seed if empty" is a no-op. The server is the authority.
  }

  static ChatMessage _messageFromJson(Map<String, dynamic> json) {
    final sentAt = json['sent_at'] as String?;
    // Format the ISO timestamp to a short local time for display (e.g. "2:05 PM").
    // If parsing fails, fall back to 'Now' to match the mock's behaviour.
    final time = _formatSentAt(sentAt);
    return ChatMessage(
      text: (json['body'] as String?) ?? '',
      isMe: (json['is_me'] as bool?) ?? false,
      time: time,
    );
  }

  static String _formatSentAt(String? iso) {
    if (iso == null || iso.isEmpty) return 'Now';
    try {
      final dt = DateTime.parse(iso).toLocal();
      final hour = dt.hour == 0
          ? 12
          : dt.hour > 12
              ? dt.hour - 12
              : dt.hour;
      final minute = dt.minute.toString().padLeft(2, '0');
      final period = dt.hour < 12 ? 'AM' : 'PM';
      return '$hour:$minute $period';
    } catch (_) {
      return 'Now';
    }
  }
}