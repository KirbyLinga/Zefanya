// lib/data/repositories/order_repository.dart
//
// The buyer's order history, and the act of placing an order.
//
// ## Why this one moved more than the others
//
// `OrderState` did three jobs: it held the buyer's orders, it *created* them
// from a cart, and it persisted them to `shared_preferences` under its own key.
// Only the first of those is presentation. Creating and persisting an order is
// a data operation, and in live mode it is emphatically the server's:
// `POST /orders` mints the id, prices the lines and decides the status. A state
// class that mints `ORD-10246` locally cannot be switched to a backend without
// rewriting that logic anyway.
//
// So the numbering, the seller grouping and the storage format live in the mock
// implementation, where they belong — unchanged, byte-for-byte the same
// behaviour, but now behind an interface that a live implementation can replace
// wholesale.
//
// ## What stayed in the state
//
// `resolvedOrders(CourierState)` — the join that makes a placed order's status
// follow its delivery job. That is a *cross-state* composition, not a data
// source: it reads two providers and produces a view. Moving it into a
// repository would mean a repository taking another state object as an
// argument, which is the dependency direction this whole pass is trying to
// straighten out.

import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_exception.dart';
import '../mock_data.dart';
import 'live_stub.dart';

/// One line of an order being placed.
///
/// A DTO, not a `CartItem`: the data layer must not depend on `state/`, and
/// the server does not want a `Product` object — it wants an id and a quantity
/// and does its own pricing. `unitPrice` is carried because the app already
/// shows a total before placing, and a mismatch between what was shown and what
/// the server charges is exactly the kind of thing that must be reconcilable
/// later. (Who is authoritative on price is OPEN QUESTIONS §0.1 territory; both
/// are sent so the difference is visible rather than hidden.)
class OrderLineRequest {
  const OrderLineRequest({
    required this.productId,
    required this.productName,
    required this.sellerName,
    required this.quantity,
    required this.unitPrice,
  });

  final String productId;
  final String productName;
  final String sellerName;
  final int quantity;
  final double unitPrice;

  Map<String, Object?> toJson() => {
        'product_id': productId,
        'quantity': quantity,
        // Sent, not trusted: see the class doc.
        'unit_price': unitPrice,
      };
}

abstract class OrderRepository {
  /// Restores local state before the app's first frame.
  ///
  /// On the interface rather than only on the mock because it is the same
  /// *obligation* in both modes: `main()` awaits it before `runApp` so no
  /// screen flashes empty and then jumps. A live implementation simply has
  /// nothing to restore (the server holds it) and returns.
  Future<void> load();

  /// The buyer's orders, newest first — history and anything placed in this
  /// install, already merged.
  Future<List<Order>> orders();

  /// Places [lines], returning the orders created (empty if [lines] is empty).
  ///
  /// One order per seller, because a marketplace order belongs to one seller —
  /// that is who ships it and who the buyer contacts. The split is the
  /// *server's* rule in live mode and the mock mirrors it, so switching modes
  /// cannot change how many orders a cart produces.
  Future<List<Order>> place(List<OrderLineRequest> lines);
}

// ---------------------------------------------------------------------------
// Mock — the same behaviour and the same storage format as before
// ---------------------------------------------------------------------------

/// Holds the buyer's orders, persisting them exactly as `OrderState` did.
///
/// The storage key and the JSON shape are unchanged from `OrderState`'s own
/// implementation on purpose: an existing install must be able to upgrade
/// without losing order history, and a silent format change here would do
/// exactly that. Note the `productIds` read is null-safe for the same reason —
/// orders written before that field existed mean "contents unknown", not "bought
/// nothing".
class MockOrderRepository implements OrderRepository {
  static const _storageKey = 'placed_orders';

  /// The seeded sample orders use ORD-10xxx numbers up to 10245; new ones
  /// continue from there so a generated id never collides with sample history.
  static const int _firstPlacedNumber = 10246;

  final List<Order> _placed = [];

  @override
  Future<void> load() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_storageKey);
      if (raw == null) return;

      final decoded = jsonDecode(raw) as List<dynamic>;
      _placed.clear();
      for (final entry in decoded) {
        final map = entry as Map<String, dynamic>;
        _placed.add(Order(
          id: map['id'] as String,
          shopName: map['shopName'] as String,
          status: OrderStatus.values.byName(map['status'] as String),
          eta: map['eta'] as String,
          itemSummary: map['itemSummary'] as String,
          productIds:
              (map['productIds'] as List<dynamic>?)?.cast<String>() ?? const [],
        ));
      }
    } catch (_) {
      // Corrupt/old data shape — start clean rather than crash.
      _placed.clear();
    }
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    final encoded = jsonEncode([
      for (final o in _placed)
        {
          'id': o.id,
          'shopName': o.shopName,
          'status': o.status.name,
          'eta': o.eta,
          'itemSummary': o.itemSummary,
          'productIds': o.productIds,
        },
    ]);
    await prefs.setString(_storageKey, encoded);
  }

  @override
  Future<List<Order>> orders() async =>
      List.unmodifiable([..._placed, ...mockOrders]);

  @override
  Future<List<Order>> place(List<OrderLineRequest> lines) async {
    if (lines.isEmpty) return const [];

    // Group by seller, keeping the order sellers first appear in the cart.
    final bySeller = <String, List<OrderLineRequest>>{};
    for (final line in lines) {
      bySeller.putIfAbsent(line.sellerName, () => []).add(line);
    }

    final created = <Order>[];
    for (final entry in bySeller.entries) {
      final summary = entry.value
          .map((l) => l.quantity > 1 ? '${l.productName} ×${l.quantity}' : l.productName)
          .join(', ');

      // Newest first. Each new order goes to the front, so a checkout with two
      // sellers ends up with the later seller's order on top; fine, they were
      // placed in the same instant.
      final order = Order(
        id: 'ORD-${_firstPlacedNumber + _placed.length}',
        shopName: entry.key,
        status: OrderStatus.toShip,
        eta: 'Seller ships within 1-2 days',
        itemSummary: summary,
        // The ids the summary above was built from. Checkout used to drop them
        // here, which is what left order history unable to reference the
        // catalogue at all.
        productIds: [for (final l in entry.value) l.productId],
      );
      _placed.insert(0, order);
      created.add(order);
    }

    await _save();
    return created;
  }
}

// ---------------------------------------------------------------------------
// Live
// ---------------------------------------------------------------------------

class LiveOrderRepository extends LiveRepository implements OrderRepository {
  LiveOrderRepository(super.client);

  @override
  Future<void> load() async {
    // Nothing to restore: in live mode the server holds order history. Present
    // (rather than throwing) because `main()` awaits `load()` on every
    // repository uniformly, and a live build that exploded at startup because
    // the API was unreachable would be a worse failure than an empty Orders
    // tab that reports the error when it is actually opened.
  }

  @override
  Future<List<Order>> orders() {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('GET /orders', section: '§7.1');
  }

  @override
  Future<List<Order>> place(List<OrderLineRequest> lines) {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('POST /orders', section: '§7.2');
  }
}