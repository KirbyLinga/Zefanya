// lib/state/recently_viewed_state.dart
//
// Tracks which products this buyer has actually opened — the app's one
// signal that it remembers you across visits (no "welcome back", no
// recently-viewed rail existed before this). Deliberately narrow scope:
// this file only tracks view history, not a recommendation engine.
//
// Persisted like CartState: only product ids are stored, and rehydration goes
// through the [CatalogRepository] so the ids resolve against whatever the
// catalogue actually is — mock today, the API tomorrow. An id the catalogue no
// longer lists is dropped rather than crashing, which is the behaviour this
// file already had and is the right one when a product is delisted.

import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../data/mock_data.dart' show Product;
import '../data/repositories/catalog_repository.dart';

class RecentlyViewedState extends ChangeNotifier {
  /// [catalog] defaults to the mock repository, so screens and tests that
  /// construct this without arguments behave exactly as before; `main.dart`
  /// passes the resolved bundle's repository.
  RecentlyViewedState({CatalogRepository? catalog})
      : _catalog = catalog ?? const MockCatalogRepository();

  final CatalogRepository _catalog;

  // Most-recently-viewed first. Capped so the stored list (and the rail
  // built from it) can't grow without bound over a long install.
  static const int _maxEntries = 20;
  static const _storageKey = 'recently_viewed';

  final List<String> _productIds = [];

  /// The catalogue, indexed by id, as of the last [load] or [refreshCatalog].
  ///
  /// Held rather than re-fetched on every read because `items` is a *synchronous*
  /// getter used inside `build()`; a widget cannot await. This is the standard
  /// shape for "async source, sync view": fetch once, then resolve locally.
  final Map<String, Product> _products = {};

  List<Product> get items => [
        for (final id in _productIds)
          if (_products[id] case final p?) p,
      ];

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
      await _indexCatalog();
    } catch (_) {
      // Corrupt/old data shape — start with empty history rather than crash.
      _productIds.clear();
    }
  }

  /// Re-reads the catalogue so [items] can resolve ids. Safe to call more than
  /// once; a failure leaves the previous index in place rather than emptying
  /// the rail, because a rail that briefly has no products reads as "your
  /// history was deleted".
  Future<void> refreshCatalog() async {
    try {
      await _indexCatalog();
      notifyListeners();
    } catch (_) {
      // Keep what we have.
    }
  }

  Future<void> _indexCatalog() async {
    final products = await _catalog.products();
    _products
      ..clear()
      ..addEntries(products.map((p) => MapEntry(p.id, p)));
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
