// lib/state/notification_state.dart
//
// Buyer notification centre.
//
// There is no notifications endpoint in the Laravel API yet, so this is a
// deliberately local inbox rather than a fake network. The list itself is
// seeded fixture data; the only thing persisted is which fixture ids the buyer
// has read. That is enough to make the bell badge survive a restart without
// claiming the server sent anything.

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

class AppNotification {
  const AppNotification({
    required this.id,
    required this.title,
    required this.body,
    required this.timeLabel,
    this.orderId,
    this.productId,
  });

  final String id;
  final String title;
  final String body;
  final String timeLabel;

  /// Optional navigation targets. Null means the notification is informational.
  final String? orderId;
  final String? productId;
}

const List<AppNotification> kSeedNotifications = [
  AppNotification(
    id: 'n1',
    title: 'Your parcel is out for delivery',
    body: 'ORD-10245 is with the rider today. Keep your phone nearby.',
    timeLabel: '2h ago',
    orderId: 'ORD-10245',
  ),
  AppNotification(
    id: 'n2',
    title: 'Order delivered',
    body: 'ORD-10122 was delivered. Confirm receipt to complete the order.',
    timeLabel: 'Yesterday',
    orderId: 'ORD-10122',
  ),
  AppNotification(
    id: 'n3',
    title: 'A voucher landed in your wallet',
    body: 'MCH50 takes ₱50 off at Rattan & Reed Co. until Dec 31.',
    timeLabel: '2d ago',
  ),
];

class NotificationState extends ChangeNotifier {
  static const _storageKey = 'notification_read_ids';

  final List<AppNotification> _items = List.of(kSeedNotifications);
  final Set<String> _readIds = {};

  List<AppNotification> get notifications => List.unmodifiable(_items);

  int get unreadCount => _items.where((item) => !isRead(item.id)).length;

  bool isRead(String id) => _readIds.contains(id);

  Future<void> load() async {
    final prefs = await SharedPreferences.getInstance();
    _readIds
      ..clear()
      ..addAll(prefs.getStringList(_storageKey) ?? const []);
    notifyListeners();
  }

  Future<void> markRead(String id) async {
    if (!_items.any((item) => item.id == id)) return;
    if (!_readIds.add(id)) return;
    notifyListeners();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setStringList(_storageKey, _readIds.toList()..sort());
  }

  Future<void> markAllRead() async {
    final before = _readIds.length;
    _readIds.addAll(_items.map((item) => item.id));
    if (_readIds.length == before) return;
    notifyListeners();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setStringList(_storageKey, _readIds.toList()..sort());
  }
}
