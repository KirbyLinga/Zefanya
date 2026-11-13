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
import '../data/api/api_client.dart';
import '../data/api/api_exception.dart';
import '../data/dto/remote_catalog.dart' show productFromWebZefanyaJson;
import '../data/mock_data.dart' show Product;
import '../data/repositories/catalog_repository.dart';

class WishlistState extends ChangeNotifier {
  /// [catalog] defaults to the mock repository so existing call sites and tests
  /// are unaffected; `main.dart` passes the resolved bundle's repository.
  /// [client] set (live mode): saved products are the buyer's WebZefanya
  /// wishlist (`/api/buyer/wishlist`), so they follow the account across
  /// devices. Followed sellers stay on the device: WebZefanya has no API for
  /// store follows yet.
  WishlistState({CatalogRepository? catalog, this._client})
    : _catalog = catalog ?? const MockCatalogRepository();

  final CatalogRepository _catalog;
  final ApiClient? _client;

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
      final client = _client;
      if (client != null) {
        await _loadLive(client);
        return;
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
    final client = _client;
    if (client != null) _syncLive(client, productId, nowSaved);
    return nowSaved;
  }

  /// Saved products from the server (live mode). On failure the list is left
  /// as it was rather than shown empty.
  Future<void> _loadLive(ApiClient client) async {
    try {
      final data = unwrapMap(await client.getJson('/buyer/wishlist'));
      final ids = <String>[];
      for (final row in (data['data'] as List?) ?? const []) {
        if (row is! Map || row['product'] is! Map) continue;
        final product = productFromWebZefanyaJson(
          (row['product'] as Map).cast<String, Object?>(),
        );
        if (product.id.isEmpty) continue;
        ids.add(product.id);
        _products[product.id] = product;
      }
      _productIds
        ..clear()
        ..addAll(ids);
      notifyListeners();
    } on ApiException {
      // Signed out or offline: keep what is shown.
    }
  }

  /// Idempotent server write; a refusal undoes the optimistic toggle.
  Future<void> _syncLive(ApiClient client, String productId, bool saved) async {
    try {
      if (saved) {
        await client.putJson(
          '/buyer/wishlist/${Uri.encodeComponent(productId)}',
        );
      } else {
        await client.deleteJson(
          '/buyer/wishlist/${Uri.encodeComponent(productId)}',
        );
      }
    } on ApiException {
      if (saved) {
        _productIds.remove(productId);
      } else if (!_productIds.contains(productId)) {
        _productIds.insert(0, productId);
      }
      notifyListeners();
      await _save();
    }
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
