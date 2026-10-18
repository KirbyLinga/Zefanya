// lib/utils/order_tab_memory.dart
//
// Remembering the Orders tab (Claude orders-list follow-up).
//
// The stage stepper is a DefaultTabController of 4; remembering the
// last-viewed stage across visits is a small prefs int, read once for
// initialIndex and written on every stage change. Kept here (not in
// OrderState) because it is navigation memory, not order data — the
// same reason search history lives next to the search screen.
//
// The stored value is clamped to 0..3 on read, so a corrupt or stale
// value can never crash the TabController.
//
// Cleared on logout (see AuthState.logout): on a shared device the
// next account must open on To Ship, not on the previous account's
// stage. Same treatment as recentAddresses.

import 'package:shared_preferences/shared_preferences.dart';

const String _kOrdersTabKey = 'orders_last_tab';
const int ordersTabCount = 4;

/// Reads the last-viewed stage, clamped to a valid tab index.
Future<int> loadOrdersTab() async {
  try {
    final prefs = await SharedPreferences.getInstance();
    final stored = prefs.getInt(_kOrdersTabKey);
    if (stored == null) return 0;
    return stored.clamp(0, ordersTabCount - 1);
  } catch (_) {
    return 0;
  }
}

/// Remembers the currently-viewed stage.
Future<void> saveOrdersTab(int index) async {
  try {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setInt(_kOrdersTabKey, index);
  } catch (_) {
    // Navigation memory must never break navigation.
  }
}

/// Forgets the remembered stage. Called from AuthState.logout so a
/// shared device doesn't carry one account's stage into the next.
Future<void> clearOrdersTab() async {
  try {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kOrdersTabKey);
  } catch (_) {
    // Best-effort, like the save path.
  }
}

