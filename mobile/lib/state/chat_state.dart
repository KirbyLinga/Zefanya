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
import '../utils/chat_validation.dart';

class ChatMessage {
  const ChatMessage({
    required this.text,
    required this.isMe,
    required this.time,
    this.attachment,
    this.imageId,
  });

  final String text;
  final bool isMe;
  final String time;

  /// A locally chosen file, if this message carries one. Null on every old
  /// saved message, which is why the JSON reader stays null-safe.
  final ChatAttachment? attachment;
  final int? imageId;

  Map<String, dynamic> toJson() => {
    'text': text,
    'isMe': isMe,
    'time': time,
    if (attachment != null) 'attachment': attachment!.toJson(),
  };

  factory ChatMessage.fromJson(Map<String, dynamic> json) {
    final rawAttachment = json['attachment'];
    return ChatMessage(
      text: json['text'] as String? ?? '',
      isMe: json['isMe'] as bool? ?? false,
      time: json['time'] as String? ?? '',
      attachment: rawAttachment is Map<String, dynamic>
          ? ChatAttachment.fromJson(rawAttachment)
          : null,
    );
  }
}

/// One row of the inbox: a contact, their history, and how much of the other
/// side's most recent talk has not been read.
class ChatConversation {
  const ChatConversation({
    required this.contactName,
    required this.contactRole,
    required this.messages,
    required this.unreadCount,
    this.id,
  });

  final String contactName;
  final String contactRole;
  final List<ChatMessage> messages;
  final int unreadCount;
  final int? id;

  ChatMessage? get lastMessage => messages.isEmpty ? null : messages.last;
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

  LiveChatRepository? get liveRepository => switch (_repository) {
    final LiveChatRepository repository => repository,
    _ => null,
  };

  /// What the thread screen may offer, asked of the data source rather than of
  /// the global mock/live flag.
  bool get supportsAttachments => _repository.supportsAttachments;
  bool get simulatesReplies => _repository.simulatesReplies;

  /// Threads as of the last [load] / [seedIfEmpty] / [addMessage].
  ///
  /// A cache because [messagesFor] is synchronous and called from `build()`.
  final Map<String, List<ChatMessage>> _threads = {};

  /// Which role each contact speaks as, so reopening a thread from the inbox
  /// keeps "COURIER" or "SELLER" above the name. Session-only: it is a display
  /// label, and the thread itself survives restarts.
  final Map<String, String> _roles = {};

  /// How many messages had been seen when each thread was last read. A count
  /// rather than a message id because [ChatMessage] has only a display time
  /// ("Now", "10:02 AM"), which cannot be compared safely.
  final Map<String, int> _readCounts = {};

  /// Most recently active first. The repository stores history, not ordering,
  /// and a map alone would list threads by when they were first created.
  final List<String> _recent = [];

  List<ChatMessage> messagesFor(String contactName) =>
      List.unmodifiable(_threads[contactName] ?? const []);

  /// The inbox, newest activity first, with only threads that have something
  /// in them. A thread seeded but never messaged has nothing to preview.
  List<ChatConversation> get conversations {
    final keys = <String>[
      ..._recent.where(_threads.containsKey),
      ..._threads.keys.where((key) => !_recent.contains(key)),
    ];
    return [
      for (final key in keys)
        if ((_threads[key] ?? const []).isNotEmpty)
          ChatConversation(
            contactName: key,
            contactRole: _roles[key] ?? 'Seller',
            messages: messagesFor(key),
            unreadCount: unreadFor(key),
          ),
    ];
  }

  /// Incoming messages added after this thread was last read. Messages the
  /// buyer sent are never unread, even if they are the newest line.
  int unreadFor(String contactName) {
    final messages = _threads[contactName] ?? const <ChatMessage>[];
    final read = _readCounts[contactName] ?? messages.length;
    return messages.skip(read).where((message) => !message.isMe).length;
  }

  int get totalUnread =>
      _threads.keys.fold(0, (sum, contact) => sum + unreadFor(contact));

  /// Remembers how a contact should be labelled when the inbox reopens their
  /// thread. Deliberately does not notify: this is called from ChatScreen's
  /// `initState`, while the route beneath is still building.
  void registerContact(String contactName, String contactRole) {
    _roles[contactName] = contactRole;
  }

  /// Marks everything currently in [contactName]'s thread as seen.
  void markRead(String contactName) {
    final messages = _threads[contactName];
    if (messages == null) return;
    final read = _readCounts[contactName] ?? 0;
    if (read >= messages.length) return;
    _readCounts[contactName] = messages.length;
    notifyListeners();
  }

  /// Call once before runApp (see main.dart).
  Future<void> load() async {
    await _repository.load();
    // Warm the cache from the repository rather than leaving it empty until a
    // thread is opened. An empty cache and "no history" would otherwise look
    // identical, which is the confusion this persistence layer exists to avoid.
    _threads
      ..clear()
      ..addAll(await _repository.threads());
    // History from a previous session is not new mail. Starting every thread
    // at "all read" is the only honest baseline: there is no stored read
    // marker to recover, and re-badging a week-old conversation would nag
    // about messages the person almost certainly saw.
    _readCounts
      ..clear()
      ..addEntries(
        _threads.entries.map(
          (entry) => MapEntry(entry.key, entry.value.length),
        ),
      );
    _recent
      ..clear()
      ..addAll(_threads.keys);
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
    // Capture what had been seen before this refresh. On a brand-new thread
    // the baseline is zero, so an incoming first message is unread; on an
    // existing thread it is the count from the previous render.
    final previousLength = _threads[contactName]?.length ?? 0;
    final messages = await _repository.messagesFor(contactName);
    _threads[contactName] = messages;
    _readCounts.putIfAbsent(contactName, () => previousLength);
    _recent
      ..remove(contactName)
      ..insert(0, contactName);
    notifyListeners();
  }
}
