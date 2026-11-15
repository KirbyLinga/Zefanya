// lib/data/repositories/chat_repository.dart
//
// Conversation history.
//
// ## The server owns text; the device owns the thread cache
//
// Live mode reads `GET /chat/{contact}` and writes `POST /chat/{contact}`
// (`docs/api-contract.md` §9). Mock mode keeps the same threads in
// `SharedPreferences`, so the switch changes the data source, not the screen.
//
// ## The key is still a display name
//
// `{contact}` is a seller's business name or a courier's full name. Two riders
// called Rico share a thread and a renamed shop loses its history. A stable
// conversation id is the fix (§9.3), and the interface already takes a
// `contactKey` rather than a `contactName` so the call sites do not change when
// the server starts sending ids.
//
// ## Attachments and simulated replies are capabilities, not mode checks
//
// The live API stores text only, and a live thread receives the other side's
// messages from the server rather than inventing a reply. Both facts belong to
// the data source and are exposed as [supportsAttachments] and
// [simulatesReplies]; the screen asks the repository instead of reading the
// global build flag.

import 'dart:convert';
import 'dart:typed_data';

import 'package:shared_preferences/shared_preferences.dart';

import '../../state/chat_state.dart';
import '../api/api_client.dart';
import '../api/api_exception.dart';
import '../dto/remote_chat.dart';
import 'live_stub.dart';

abstract class ChatRepository {
  /// Whether this data source can store a file attachment on a message.
  ///
  /// The live API's message body is text only, so the live repository answers
  /// false and the screen explains that before opening a picker. The mock keeps
  /// a local path and answers true.
  bool get supportsAttachments;

  /// Whether the screen should simulate a reply after sending.
  ///
  /// True only for the mock, where there is no other side. A live thread gets
  /// the real reply on the next fetch, and inventing one locally would put
  /// words in someone else's mouth.
  bool get simulatesReplies;

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

  @override
  bool get supportsAttachments => true;

  @override
  bool get simulatesReplies => true;

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
        ..addAll(
          decoded.map(
            (contact, messages) => MapEntry(contact, [
              for (final m in messages as List<dynamic>)
                ChatMessage.fromJson(m as Map<String, dynamic>),
            ]),
          ),
        );
    } catch (_) {
      // Corrupt/old data shape — start with no history rather than crash.
      _threads.clear();
    }
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
      _storageKey,
      jsonEncode(
        _threads.map(
          (contact, messages) =>
              MapEntry(contact, messages.map((m) => m.toJson()).toList()),
        ),
      ),
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

  static const _buyerMessages = '/buyer/messages';

  Future<List<ChatConversation>> buyerConversations() async {
    final body = await client.getJson(_buyerMessages, authenticated: true);
    return [
      for (final row in unwrapList(body))
        ChatConversation(
          id: row['id'] as int,
          contactName: (row['seller'] as Map?)?['name']?.toString() ?? 'Seller',
          contactRole: 'Seller',
          messages: const [],
          unreadCount: row['unread_count'] as int? ?? 0,
        ),
    ];
  }

  Future<int> openBuyerConversation(int sellerId, int productId) async {
    final body = await client.postJson(
      '$_buyerMessages/open',
      body: {'seller_id': sellerId, 'product_id': productId},
      authenticated: true,
    );
    return (body['data'] as Map)['conversation_id'] as int;
  }

  Future<List<ChatMessage>> buyerMessages(int id) async {
    final body = await client.getJson(
      '$_buyerMessages/$id',
      authenticated: true,
    );
    final rows = (body['data'] as Map)['messages'] as List;
    return [
      for (final row in rows)
        _buyerMessage(Map<String, Object?>.from(row as Map)),
    ];
  }

  Future<ChatMessage> sendBuyerMessage(
    int id,
    String text, {
    ({List<int> bytes, String filename})? image,
  }) async {
    final body = image == null
        ? await client.postJson(
            _buyerMessages,
            body: {'conversation_id': id, 'body': text},
            authenticated: true,
          )
        : await client.postMultipart(
            _buyerMessages,
            fields: {'conversation_id': '$id', 'body': text},
            byteFiles: {'image': image},
          );
    return _buyerMessage(Map<String, Object?>.from(body['data'] as Map));
  }

  Future<Uint8List> buyerMessageImage(int conversationId, int messageId) =>
      client.getBytes('$_buyerMessages/$conversationId/images/$messageId');

  ChatMessage _buyerMessage(Map<String, Object?> row) => ChatMessage(
    text: row['body']?.toString() ?? '',
    isMe: row['sender_role'] == 'buyer',
    time: chatTimeLabel(row['created_at']?.toString()),
    imageId: row['image_url'] == null ? null : row['id'] as int?,
  );

  @override
  bool get supportsAttachments => false;

  @override
  bool get simulatesReplies => false;

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
    return unwrapList(body).map(chatMessageFromJson).toList(growable: false);
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
}
