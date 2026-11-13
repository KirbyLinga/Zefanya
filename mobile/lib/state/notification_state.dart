// lib/state/notification_state.dart
//
// Buyer notification centre.
//
// Live mode reads WebZefanya's /api/buyer/notifications. Mock mode keeps a
// deliberately local inbox rather than a fake network. The list itself is
// seeded fixture data; the only thing persisted is which fixture ids the buyer
// has read. That is enough to make the bell badge survive a restart without
// claiming the server sent anything.

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../data/api/api_client.dart';
import '../data/api/api_exception.dart';

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
  /// [client] set (live mode): the inbox is the buyer's own WebZefanya
  /// notifications (`/api/buyer/notifications`) and read state is the
  /// server's. Null (mock mode): the seeded local inbox below.
  NotificationState({ApiClient? client}) : _client = client {
    if (client == null) _items.addAll(kSeedNotifications);
  }

  final ApiClient? _client;

  /// Whose WebZefanya inbox is read in live mode: `/buyer` or `/rider`.
  String _prefix = '/buyer';
  bool _hasSession = true;
  Object? _account;
  int _generation = 0;
  int _page = 0;
  int _serverUnread = 0;
  bool hasMore = false;
  bool loading = false;
  bool updating = false;
  String? error;

  bool get isLive => _client != null;

  Future<void> setSession({
    required Object? account,
    required bool rider,
  }) async {
    if (!isLive) return;
    final prefix = rider ? '/rider' : '/buyer';
    if (_account == account &&
        _prefix == prefix &&
        _hasSession == (account != null)) {
      return;
    }
    _account = account;
    _hasSession = account != null;
    _prefix = prefix;
    _resetLiveInbox();
    if (_hasSession) await load();
  }

  void _resetLiveInbox() {
    _generation++;
    _items.clear();
    _readIds.clear();
    _page = 0;
    _serverUnread = 0;
    hasMore = false;
    loading = false;
    updating = false;
    error = null;
    notifyListeners();
  }

  /// Points the live inbox at the signed-in role's notifications and reloads
  /// when that changes. The rider shell calls this; mock mode ignores it.
  Future<void> useInbox({required bool rider}) async {
    final next = rider ? '/rider' : '/buyer';
    if (!isLive || next == _prefix || !_hasSession) return;
    _prefix = next;
    _resetLiveInbox();
    await load();
  }

  static const _storageKey = 'notification_read_ids';

  final List<AppNotification> _items = [];
  final Set<String> _readIds = {};

  List<AppNotification> get notifications => List.unmodifiable(_items);

  int get unreadCount =>
      isLive ? _serverUnread : _items.where((item) => !isRead(item.id)).length;

  bool isRead(String id) => _readIds.contains(id);

  Future<void> load() async {
    final client = _client;
    if (client != null) return _loadLive(client, 1);

    final prefs = await SharedPreferences.getInstance();
    _readIds
      ..clear()
      ..addAll(prefs.getStringList(_storageKey) ?? const []);
    notifyListeners();
  }

  Future<void> markRead(String id) async {
    if (!_items.any((item) => item.id == id)) return;
    if (isRead(id)) return;
    if (isLive) {
      await _markLive('${Uri.encodeComponent(id)}/read', id: id);
      return;
    }
    if (!_readIds.add(id)) return;
    notifyListeners();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setStringList(_storageKey, _readIds.toList()..sort());
  }

  Future<void> markAllRead() async {
    if (isLive) {
      if (unreadCount > 0) await _markLive('read-all');
      return;
    }
    final before = _readIds.length;
    _readIds.addAll(_items.map((item) => item.id));
    if (_readIds.length == before) return;
    notifyListeners();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setStringList(_storageKey, _readIds.toList()..sort());
  }

  Future<void> loadMore() async {
    final client = _client;
    if (client != null && hasMore) await _loadLive(client, _page + 1);
  }

  Future<void> _markLive(String path, {String? id}) async {
    if (!_hasSession || updating || loading) return;
    final generation = _generation;
    updating = true;
    error = null;
    notifyListeners();
    try {
      final data = unwrapMap(
        await _client!.postJson('$_prefix/notifications/$path'),
      );
      if (generation != _generation) return;
      _serverUnread = _unread(data);
      if (id == null) {
        _readIds.addAll(_items.map((item) => item.id));
      } else {
        _readIds.add(id);
      }
    } on ApiException catch (problem) {
      if (generation == _generation) error = problem.message;
    } finally {
      if (generation == _generation) {
        updating = false;
        notifyListeners();
      }
    }
  }

  static int _unread(Map data) {
    final count = data['unread_count'];
    if (count is! int || count < 0) throw const MalformedResponseException();
    return count;
  }

  Future<void> _loadLive(ApiClient client, int page) async {
    if (!_hasSession || loading || updating) return;
    final generation = _generation;
    loading = true;
    error = null;
    notifyListeners();
    try {
      final data = unwrapMap(
        await client.getJson(
          '$_prefix/notifications',
          query: {'page': '$page'},
        ),
      );
      if (generation != _generation) return;
      final rows = data['data'];
      final meta = data['meta'];
      if (rows is! List || meta is! Map || meta['has_more'] is! bool) {
        throw const MalformedResponseException();
      }
      final unread = _unread(meta);
      if (page == 1) {
        _items.clear();
        _readIds.clear();
      }
      for (final row in rows) {
        if (row is! Map) continue;
        final id = row['id']?.toString() ?? '';
        if (id.isEmpty || _items.any((item) => item.id == id)) continue;
        _items.add(
          AppNotification(
            id: id,
            title: row['title']?.toString() ?? 'Notification',
            body: row['message']?.toString() ?? '',
            timeLabel: _timeLabel(
              DateTime.tryParse(row['created_at']?.toString() ?? ''),
            ),
            orderId: row['order_id']?.toString(),
          ),
        );
        if (row['read'] == true) _readIds.add(id);
      }
      _page = page;
      hasMore = meta['has_more'] as bool;
      _serverUnread = unread;
    } on ApiException catch (problem) {
      if (generation == _generation) error = problem.message;
    } finally {
      if (generation == _generation) {
        loading = false;
        notifyListeners();
      }
    }
  }

  static String _timeLabel(DateTime? at) {
    if (at == null) return '';
    final age = DateTime.now().difference(at);
    if (age.inMinutes < 60) return '${age.inMinutes.clamp(1, 59)}m ago';
    if (age.inHours < 24) return '${age.inHours}h ago';
    if (age.inDays == 1) return 'Yesterday';
    return '${age.inDays}d ago';
  }
}
