// lib/utils/cart_groups.dart
//
// Seller grouping for the cart (Claude buyer-list follow-up).
//
// Pure data-in/data-out: cart lines in, ordered (seller, lines) groups
// out. The screen renders one section per group with a per-shop
// select-all checkbox; CartState keeps owning selection. Pure so it
// can be unit-tested without pumping widgets.
//
// Order: first-seen seller first (stable, no re-sorting between
// rebuilds). Items carry their own identity — the tuple holds the
// same CartItem objects, never copies.

import '../state/cart_state.dart' show CartItem;

/// One seller's lines, in cart order.
class CartSellerGroup {
  const CartSellerGroup({required this.sellerName, required this.items});

  final String sellerName;
  final List<CartItem> items;

  /// True when every line in this group is selected.
  bool get allSelected => items.every((i) => i.selected);

  /// True when some (but not all) lines are selected — the checkbox's
  /// tri-state middle.
  bool get someSelected =>
      items.any((i) => i.selected) && !allSelected;
}

/// Groups [items] by seller, first-seen order.
List<CartSellerGroup> groupCartBySeller(List<CartItem> items) {
  final order = <String>[];
  final bySeller = <String, List<CartItem>>{};
  for (final item in items) {
    final seller = item.product.sellerName;
    if (!bySeller.containsKey(seller)) {
      order.add(seller);
      bySeller[seller] = [];
    }
    bySeller[seller]!.add(item);
  }
  return [
    for (final seller in order)
      CartSellerGroup(sellerName: seller, items: bySeller[seller]!),
  ];
}
