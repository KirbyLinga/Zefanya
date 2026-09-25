// lib/state/cart_state.dart
//
// Global cart, exposed via Provider (see main.dart) — same pattern as
// AuthState. Product Details adds to it, Cart reads/mutates it,
// Checkout reads the selected subset and clears it on order placement.
// This replaces each screen's old private hardcoded item list, which
// is why "Woven Rattan Tote Bag" used to show a different price
// depending which screen you were looking at.
//
// Persisted via shared_preferences: each item is stored as just a
// product id + variant + quantity + selected (not the full Product),
// then rehydrated by looking the id up in mockProducts on load() — so
// if a product is ever removed from the catalog, any saved cart entry
// for it is silently dropped rather than crashing.

import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../data/mock_data.dart';

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
  final List<CartItem> _items = [];

  static const _storageKey = 'cart_items';

  List<CartItem> get items => List.unmodifiable(_items);
  bool get isEmpty => _items.isEmpty;
  int get itemCount => _items.fold(0, (sum, i) => sum + i.quantity);
  double get subtotal => _items.where((i) => i.selected).fold(0, (sum, i) => sum + i.lineTotal);

  /// Call once before runApp (see main.dart) to restore the cart from disk.
  Future<void> load() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_storageKey);
      if (raw == null) return;

      final decoded = jsonDecode(raw) as List<dynamic>;
      _items.clear();
      for (final entry in decoded) {
        final map = entry as Map<String, dynamic>;
        final matches = mockProducts.where((p) => p.id == map['productId']);
        if (matches.isEmpty) continue; // product no longer in the catalog — drop it
        _items.add(CartItem(
          product: matches.first,
          variant: map['variant'] as String? ?? '',
          quantity: map['quantity'] as int? ?? 1,
          selected: map['selected'] as bool? ?? true,
        ));
      }
    } catch (_) {
      // Corrupt/old data shape — start with an empty cart rather than crash.
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
