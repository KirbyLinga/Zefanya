// lib/state/cart_state.dart
//
// Global cart, exposed via Provider (see main.dart) — same pattern as
// AuthState. Product Details adds to it, Cart reads/mutates it,
// Checkout reads the selected subset and clears it on order placement.
//
// ## Where the cart's data comes from now
//
// From a [CatalogRepository], not from `mockProducts`. That distinction is the
// whole point of this pass: the cart stores product *ids*, and resolving an id
// to a product is a data-layer question. Reaching into `mock_data` directly
// worked exactly once — it could not survive the catalogue becoming a network
// call without rewriting this file's rehydration, and every other state
// class's alongside it.
//
// The voucher *catalogue* comes from a [CartRepository] for the same reason:
// which vouchers exist is the server's answer, and the two screens that list
// them (Cart's code box, the vouchers wallet) must not be reading a hardcoded
// list while a live build believes they are. What a voucher is *worth* is a
// different question, and stays in `config/pricing.dart`.
//
// The rehydration path is therefore: read saved ids from disk, ask the
// repository for the catalogue, keep only the ids it still knows about. That
// also means **an id the catalogue no longer lists is silently dropped**, which
// is the same behaviour the cart already had (and is better than crashing on a
// product that was delisted while it sat in someone's cart).
//
// ## A limitation worth stating, because live mode will hit it
//
// Rehydration asks for the *whole* catalogue and indexes it. That is correct
// and cheap against the mock catalogue (8 products) and against a paginated
// live catalogue it is a real weakness: a saved item on page 4 would vanish
// from the cart because the first page didn't include it. The fix is a
// server-side cart (`GET /cart`) rather than a client-side id list, which is
// OPEN QUESTIONS §0.4 in `docs/api-contract.md` — recorded there rather than
// guessed at here.

import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../data/repositories/cart_repository.dart';
import '../data/repositories/catalog_repository.dart';
import '../data/mock_data.dart' show Product, Voucher;

class CartItem {
  CartItem({
    required this.product,
    this.variant = '',
    this.quantity = 1,
    this.selected = true,
  });

  final Product product;
  final String variant;
  int quantity;
  bool selected;

  double get lineTotal => product.price * quantity;
}

class CartState extends ChangeNotifier {
  /// [catalog] defaults to the mock repository so existing call sites (and
  /// tests) keep working unchanged. `main.dart` passes the resolved bundle's
  /// repository explicitly, which is what makes the live switch possible.
  ///
  /// [vouchers] is named for the one thing that repository owns today — the
  /// voucher catalogue — rather than for its interface name, because a
  /// parameter called `cart` inside `CartState` would say nothing about what
  /// was being handed over. See cart_repository.dart for why the maths stays in
  /// `config/pricing.dart` and the line items stay here.
  CartState({CatalogRepository? catalog, CartRepository? vouchers})
      : _catalog = catalog ?? const MockCatalogRepository(),
        _vouchers = vouchers ?? const MockCartRepository();

  final CatalogRepository _catalog;
  final CartRepository _vouchers;

  final List<CartItem> _items = [];

  /// The vouchers on offer, as of the last [load].
  ///
  /// Cached because Cart and the vouchers wallet both read this from `build()`,
  /// and because the *contents* are the server's answer — a screen must not be
  /// able to hold a list that a later load replaces without it noticing.
  List<Voucher> _offer = const [];

  /// Vouchers the buyer can choose from. Empty means none are running.
  List<Voucher> get vouchers => List.unmodifiable(_offer);

  static const _storageKey = 'cart_items';

  List<CartItem> get items => List.unmodifiable(_items);
  bool get isEmpty => _items.isEmpty;
  int get itemCount => _items.fold(0, (sum, i) => sum + i.quantity);
  double get subtotal => _items.where((i) => i.selected).fold(0, (sum, i) => sum + i.lineTotal);

  /// Call once before runApp (see main.dart) to restore the cart from disk.
  Future<void> load() async {
    // The voucher catalogue first, and *outside* the try below: a failure to
    // ask for it is a real "couldn't reach the server" in live mode, whereas
    // everything inside the try is local restore where a corrupt value should
    // cost one line rather than the whole account.
    _offer = List.of(await _vouchers.vouchers());
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_storageKey);
      if (raw == null) return;

      final decoded = jsonDecode(raw) as List<dynamic>;
      // One catalogue read for the whole restore, indexed by id — rather than
      // one lookup per line, which would be N requests in live mode to resolve
      // N ids.
      final byId = <String, Product>{
        for (final p in await _catalog.products()) p.id: p,
      };

      _items.clear();
      for (final entry in decoded) {
        final map = entry as Map<String, dynamic>;
        final product = byId[map['productId']];
        if (product == null) continue; // no longer in the catalogue — drop it
        _items.add(CartItem(
          product: product,
          variant: map['variant'] as String? ?? '',
          quantity: map['quantity'] as int? ?? 1,
          selected: map['selected'] as bool? ?? true,
        ));
      }
    } catch (_) {
      // Corrupt/old data shape, or the catalogue is unreachable — start with an
      // empty cart rather than crash. In live mode this means a cart that
      // cannot be restored renders as empty rather than as an error, which is a
      // deliberate trade: a cart is trivially re-fillable, and blocking the
      // whole app's startup on a network call would be worse.
      _items.clear();
    }
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    final encoded = jsonEncode(_items
        .map((i) => {
              'productId': i.product.id,
              'variant': i.variant,
              'quantity': i.quantity,
              'selected': i.selected,
            })
        .toList());
    await prefs.setString(_storageKey, encoded);
  }

  void addItem(Product product, {String variant = '', int quantity = 1}) {
    final index = _items.indexWhere((i) => i.product.id == product.id && i.variant == variant);
    if (index >= 0) {
      _items[index].quantity += quantity;
    } else {
      _items.add(CartItem(product: product, variant: variant, quantity: quantity));
    }
    notifyListeners();
    _save();
  }

  void updateQuantity(CartItem item, int delta) {
    final next = item.quantity + delta;
    if (next <= 0) {
      _items.remove(item);
    } else {
      item.quantity = next;
    }
    notifyListeners();
    _save();
  }

  void toggleSelected(CartItem item, bool selected) {
    item.selected = selected;
    notifyListeners();
    _save();
  }

  void setAllSelected(bool selected) {
    for (final item in _items) {
      item.selected = selected;
    }
    notifyListeners();
    _save();
  }

  /// Removes and returns the item so the caller can offer an Undo
  /// action (see cart_screen.dart) without CartState knowing about
  /// SnackBars.
  CartItem removeItem(CartItem item) {
    _items.remove(item);
    notifyListeners();
    _save();
    return item;
  }

  /// Undo for [removeItem]. Pass the item's original [index] to put it back
  /// where it was; without it (or if the list has shrunk since) it lands at
  /// the end.
  void restoreItem(CartItem item, {int? index}) {
    if (index != null && index >= 0 && index <= _items.length) {
      _items.insert(index, item);
    } else {
      _items.add(item);
    }
    notifyListeners();
    _save();
  }

  /// Called once an order is successfully placed for the selected items.
  void clearSelected() {
    _items.removeWhere((i) => i.selected);
    notifyListeners();
    _save();
  }
}
