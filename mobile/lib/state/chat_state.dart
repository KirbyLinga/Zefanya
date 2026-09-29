// lib/state/chat_state.dart
//
// Per-contact message history, shared so leaving and returning to a
// chat thread doesn't wipe what was said. Threads are keyed by contact
// name — fine for this mock-data scope, but note that two different
// contacts sharing a display name would collide; swap for a stable
// conversation id once conversations come from a real backend.
//
// The history itself is the [ChatRepository]'s, not this class's: the threads,
// the storage key and the JSON shape all live there now, and this class holds
// only the loaded copy that the synchronous `messagesFor` reads. That is what
// makes a live thread possible at all — see the note on `load()` for why the
// cache is warmed from the repository rather than filled lazily.

import 'package:flutter/foundation.dart';
import '../data/repositories/chat_repository.dart';

class ChatMessage {
  const ChatMessage({required this.text, required this.isMe, required this.time});

  final String text;
  final bool isMe;
  final String time;

  Map<String, dynamic> toJson() => {'text': text, 'isMe': isMe, 'time': time};

  factory ChatMessage.fromJson(Map<String, dynamic> json) => ChatMessage(
        text: json['text'] as String,
        isMe: json['isMe'] as bool,
        time: json['time'] as String,
      );
}

class ChatState extends ChangeNotifier {
  /// [repository] defaults to the mock implementation so existing call sites
  /// (and tests) are unaffected; `main.dart` passes the resolved bundle's.
  ///
  /// The repository rather than this class owning its own storage: threads are
  /// data, and one store is the only way two screens cannot disagree about what
  /// was said.
  ChatState({ChatRepository? repository})
      : _repository = repository ?? MockChatRepository();

  final ChatRepository _repository;

  /// Threads as of the last [load] / [seedIfEmpty] / [addMessage].
  ///
  /// A cache because [messagesFor] is synchronous and called from `build()`.
  final Map<String, List<ChatMessage>> _threads = {};

  List<ChatMessage> messagesFor(String contactName) =>
      List.unmodifiable(_threads[contactName] ?? const []);

  /// Call once before runApp (see main.dart).
  Future<void> load() async {
    await _repository.load();
    // Warm the cache from the repository rather than leaving it empty until a
    // thread is opened. An empty cache and "no history" would otherwise look
    // identical, which is the confusion this persistence layer exists to avoid.
    _threads
      ..clear()
      ..addAll(await _repository.threads());
  }

  /// Seeds a thread with an opening message the first time it's opened — no-op
  /// if the contact already has history (including from a previous session).
  ///
  /// The cache is refreshed from the repository afterwards rather than being
  /// written here too: in live mode the thread is the *server's*, and a local
  /// append would make the screen show a message the server never stored.
  Future<void> seedIfEmpty(String contactName, ChatMessage greeting) async {
    await _repository.seedIfEmpty(contactName, greeting);
    await _refreshThread(contactName);
  }

  Future<void> addMessage(String contactName, ChatMessage message) async {
    await _repository.append(contactName, message);
    await _refreshThread(contactName);
  }

  Future<void> _refreshThread(String contactName) async {
    _threads[contactName] = await _repository.messagesFor(contactName);
    notifyListeners();
  }
}
