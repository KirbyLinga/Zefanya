// lib/state/wishlist_state.dart
//
// The buyer's saved products — what the heart on a product card and on
// Product Details toggles, and what Home's "Saved for later" rail shows.
// Before this, the Product Details heart was an empty button.
//
// Same persistence pattern as RecentlyViewedState: only product ids are
// stored (newest save first), rehydrated against mockProducts on read, so
// a product removed from the catalog is silently dropped rather than
// crashing.

import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../data/mock_data.dart';

class WishlistState extends ChangeNotifier {
  static const _storageKey = 'wishlist';

  /// Most recently saved first.
  final List<String> _productIds = [];

  /// Saved products, newest first. Ids no longer in the catalog are skipped.
  List<Product> get items => _productIds
      .map((id) => mockProducts.where((p) => p.id == id))
      .where((matches) => matches.isNotEmpty)
      .map((matches) => matches.first)
      .toList(growable: false);

  bool isSaved(String productId) => _productIds.contains(productId);

  /// Call once before runApp (see main.dart) to restore saved items.
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
      // Corrupt/old data shape — start empty rather than crash.
      _productIds.clear();
    }
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_storageKey, jsonEncode(_productIds));
  }

  /// Saves the product if it isn't saved, removes it if it is. Returns
  /// true when the product is now saved.
  bool toggle(String productId) {
    final nowSaved = !_productIds.contains(productId);
    if (nowSaved) {
      _productIds.insert(0, productId);
    } else {
      _productIds.remove(productId);
    }
    notifyListeners();
    _save();
    return nowSaved;
  }
}
