// lib/state/chat_state.dart
//
// Per-contact message history, shared so leaving and returning to a
// chat thread doesn't wipe what was said. Threads are keyed by contact
// name — fine for this mock-data scope, but note that two different
// contacts sharing a display name would collide; swap for a stable
// conversation id once conversations come from a real backend.

import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

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
  final Map<String, List<ChatMessage>> _threads = {};

  static const _storageKey = 'chat_threads';

  List<ChatMessage> messagesFor(String contactName) => List.unmodifiable(_threads[contactName] ?? const []);

  Future<void> load() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_storageKey);
      if (raw == null) return;

      final decoded = jsonDecode(raw) as Map<String, dynamic>;
      _threads.clear();
      decoded.forEach((contact, messages) {
        _threads[contact] = (messages as List<dynamic>)
            .map((m) => ChatMessage.fromJson(m as Map<String, dynamic>))
            .toList();
      });
    } catch (_) {
      // Corrupt/old data shape — start with no history rather than crash.
      _threads.clear();
    }
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    final encoded = jsonEncode(_threads.map((contact, messages) => MapEntry(contact, messages.map((m) => m.toJson()).toList())));
    await prefs.setString(_storageKey, encoded);
  }

  /// Seeds a thread with an opening message the first time it's
  /// opened — no-op if the contact already has history (including from
  /// a previous session).
  void seedIfEmpty(String contactName, ChatMessage greeting) {
    if (_threads.containsKey(contactName)) return;
    _threads[contactName] = [greeting];
    notifyListeners();
    _save();
  }

  void addMessage(String contactName, ChatMessage message) {
    _threads.putIfAbsent(contactName, () => []).add(message);
    notifyListeners();
    _save();
  }
}
