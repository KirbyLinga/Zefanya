// lib/state/recently_viewed_state.dart
//
// Tracks which products this buyer has actually opened — the app's one
// signal that it remembers you across visits (no "welcome back", no
// recently-viewed rail existed before this). Deliberately narrow scope:
// this file only tracks view history, not a recommendation engine.
//
// Same persistence pattern as CartState: only product ids + a viewedAt
// timestamp are stored, rehydrated by looking each id up in mockProducts
// on load() — a product removed from the catalog is silently dropped
// rather than crashing.

import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../data/mock_data.dart';

class RecentlyViewedState extends ChangeNotifier {
  // Most-recently-viewed first. Capped so the stored list (and the rail
  // built from it) can't grow without bound over a long install.
  static const int _maxEntries = 20;
  static const _storageKey = 'recently_viewed';

  final List<String> _productIds = [];

  List<Product> get items => _productIds
      .map((id) => mockProducts.where((p) => p.id == id))
      .where((matches) => matches.isNotEmpty)
      .map((matches) => matches.first)
      .toList(growable: false);

  bool get isEmpty => items.isEmpty;

  /// Call once before runApp (see main.dart) to restore history from disk.
  Future<void> load() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_storageKey);
      if (raw == null) return;
      final decoded = (jsonDecode(raw) as List<dynamic>).cast<String>();
      _productIds
        ..clear()
        ..addAll(decoded);
    } catch (_) {
      // Corrupt/old data shape — start with empty history rather than crash.
      _productIds.clear();
    }
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_storageKey, jsonEncode(_productIds));
  }

  /// Call from Product Details on open. Moves the product to the front
  /// if it's already in the list rather than duplicating it, so viewing
  /// something again just refreshes its position.
  void recordView(String productId) {
    _productIds.remove(productId);
    _productIds.insert(0, productId);
    if (_productIds.length > _maxEntries) {
      _productIds.removeRange(_maxEntries, _productIds.length);
    }
    notifyListeners();
    _save();
  }

  /// Items for the Home rail, excluding whatever product is currently
  /// front-and-center there (the cover story) so the rail never repeats
  /// the tile right above it.
  List<Product> excluding(String? productId) =>
      productId == null ? items : items.where((p) => p.id != productId).toList(growable: false);
}
