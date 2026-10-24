// lib/state/wishlist_state.dart
//
// The buyer's saved products — what the heart on a product card and on
// Product Details toggles, and what Home's "Saved for later" rail shows.
// Before this, the Product Details heart was an empty button.
//
// Only product ids are stored (newest save first) and they resolve through the
// [CatalogRepository], so an id the catalogue no longer lists is dropped rather
// than crashing. Same "async source, sync view" shape as RecentlyViewedState:
// the catalogue is indexed once and `items` resolves against that index, because
// `items` is read inside `build()` and a widget cannot await.

import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../data/mock_data.dart' show Product;
import '../data/repositories/catalog_repository.dart';

class WishlistState extends ChangeNotifier {
  /// [catalog] defaults to the mock repository so existing call sites and tests
  /// are unaffected; `main.dart` passes the resolved bundle's repository.
  WishlistState({CatalogRepository? catalog})
      : _catalog = catalog ?? const MockCatalogRepository();

  final CatalogRepository _catalog;

  static const _storageKey = 'wishlist';
  static const _followedSellersKey = 'followed_sellers';

  /// Most recently saved first.
  final List<String> _productIds = [];

  /// Shops the buyer follows, most recently followed first.
  ///
  /// A separate list rather than a flag on Product: following a store is not
  /// the same act as hearting one of its pieces, and a store with no products
  /// left must still remain followed. Device-local for now, exactly like the
  /// wishlist itself; the server has no follows table.
  final List<String> _sellerNames = [];

  /// The catalogue as of the last [load] / [refreshCatalog]. See the header.
  final Map<String, Product> _products = {};

  /// Saved products, newest first. Ids no longer in the catalogue are skipped.
  List<Product> get items => [
        for (final id in _productIds)
          if (_products[id] case final p?) p,
      ];

  bool isSaved(String productId) => _productIds.contains(productId);

  /// Shops this buyer follows, newest first.
  List<String> get followedSellers => List.unmodifiable(_sellerNames);

  bool isFollowing(String sellerName) => _sellerNames.contains(sellerName);

  /// Call once before runApp (see main.dart) to restore saved items.
  Future<void> load() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_storageKey);
      final followedRaw = prefs.getString(_followedSellersKey);
      if (followedRaw != null) {
        _sellerNames
          ..clear()
          ..addAll((jsonDecode(followedRaw) as List<dynamic>).cast<String>());
      }
      if (raw == null) return;
      final decoded = (jsonDecode(raw) as List<dynamic>).cast<String>();
      _productIds
        ..clear()
        ..addAll(decoded);
      await _indexCatalog();
    } catch (_) {
      // Corrupt/old data shape — start empty rather than crash.
      _productIds.clear();
      _sellerNames.clear();
    }
  }

  /// Re-reads the catalogue so [items] can resolve ids after a refresh.
  Future<void> refreshCatalog() async {
    try {
      await _indexCatalog();
      notifyListeners();
    } catch (_) {
      // Keep what we have: an empty wishlist rail reads as "your saves are
      // gone", which is a worse lie than a slightly stale price.
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
    await prefs.setString(_followedSellersKey, jsonEncode(_sellerNames));
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

  /// Follows [sellerName] if not already followed, unfollows it otherwise.
  /// Returns true when the shop is now followed.
  bool toggleFollow(String sellerName) {
    final nowFollowing = !_sellerNames.contains(sellerName);
    if (nowFollowing) {
      _sellerNames.insert(0, sellerName);
    } else {
      _sellerNames.remove(sellerName);
    }
    notifyListeners();
    _save();
    return nowFollowing;
  }
}
