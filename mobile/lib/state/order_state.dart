// lib/state/order_state.dart
//
// The buyer's order history, exposed via Provider (see main.dart) —
// same pattern as CartState.
//
// Until this existed, Checkout cleared the cart and showed a confirmation,
// but the order went nowhere: Orders and Profile read the constant
// `mockOrders` list, so nothing you "bought" ever appeared. This is the
// missing link. Checkout calls [placeOrders]; Orders and Profile read
// [orders].
//
// The seeded `mockOrders` stay underneath as sample history so the
// tabs aren't empty on a fresh install. Delete that fallback once a real
// orders API exists.
//
// A cart can hold pieces from several sellers, and a marketplace order
// belongs to ONE seller (that's who ships it, and who the buyer contacts),
// so a checkout is split into one order per seller.
//
// Persisted via shared_preferences, like the other state classes.

import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../data/mock_data.dart';
import 'cart_state.dart';
import 'courier_state.dart';

class OrderState extends ChangeNotifier {
  /// Orders placed in this app, newest first.
  final List<Order> _placed = [];

  static const _storageKey = 'placed_orders';

  /// The seeded sample orders use ORD-10xxx numbers up to 10245; new ones
  /// continue from there so an id never collides with sample history.
  static const int _firstPlacedNumber = 10246;

  /// Placed orders first (newest on top), then the seeded sample history.
  /// Statuses here are as-placed; use [resolvedOrders] for what to show.
  List<Order> get orders => List.unmodifiable([..._placed, ...mockOrders]);

  /// [orders], with each placed order's status and ETA following its
  /// delivery job in [courier]. This is what the Orders screen shows: when
  /// a courier marks an order picked up, it moves to In Transit here.
  ///
  ///   courier: assigned / at the seller  ->  To Ship
  ///   courier: picked up                 ->  In Transit
  ///   courier: in transit                ->  Out for Delivery
  ///   courier: delivered                 ->  Delivered
  ///
  /// Seeded sample orders have no job, so they keep their fixed status.
  List<Order> resolvedOrders(CourierState courier) => List.unmodifiable([
        for (final o in _placed) _resolve(o, courier),
        ...mockOrders,
      ]);

  Order _resolve(Order order, CourierState courier) {
    final job = courier.jobForOrder(order.id);
    if (job == null) return order;

    if (courier.isCompleted(job.id)) {
      return order.copyWith(status: OrderStatus.toRate, eta: 'Delivered');
    }
    return switch (courier.milestoneFor(job.id)) {
      DeliveryMilestone.accepted =>
        order.copyWith(status: OrderStatus.toShip, eta: 'A courier has been assigned'),
      DeliveryMilestone.arrivedAtSeller =>
        order.copyWith(status: OrderStatus.toShip, eta: 'Courier is at the seller collecting it'),
      DeliveryMilestone.pickedUp =>
        order.copyWith(status: OrderStatus.inTransit, eta: 'Picked up, heading to sorting center'),
      DeliveryMilestone.atSortingCenter =>
        order.copyWith(status: OrderStatus.inTransit, eta: 'Package at sorting center'),
      DeliveryMilestone.sorted =>
        order.copyWith(status: OrderStatus.inTransit, eta: 'Sorted and ready for dispatch'),
      DeliveryMilestone.assignedToRider =>
        order.copyWith(status: OrderStatus.outForDelivery, eta: 'Assigned to a delivery rider'),
      DeliveryMilestone.inTransit =>
        order.copyWith(status: OrderStatus.outForDelivery, eta: 'Courier is heading to you'),
      DeliveryMilestone.delivered =>
        order.copyWith(status: OrderStatus.toRate, eta: 'Delivered'),
      DeliveryMilestone.failedDelivery =>
        order.copyWith(status: OrderStatus.outForDelivery, eta: 'Delivery attempted — will retry'),
      DeliveryMilestone.returnToSender =>
        order.copyWith(status: OrderStatus.inTransit, eta: 'Being returned to seller'),
    };
  }

  /// Call once before runApp (see main.dart) to restore placed orders.
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
        },
    ]);
    await prefs.setString(_storageKey, encoded);
  }

  /// Turns the checked-out cart lines into orders, one per seller, all
  /// starting at "To Ship". Returns the orders it created (empty if
  /// [items] is empty) so the caller can dispatch a courier for each.
  ///
  /// Call this BEFORE `CartState.clearSelected()` — it reads the items you
  /// pass in, it does not touch the cart itself.
  List<Order> placeOrders(List<CartItem> items) {
    if (items.isEmpty) return const [];

    // Group by seller, keeping the order sellers first appear in the cart.
    final bySeller = <String, List<CartItem>>{};
    for (final item in items) {
      bySeller.putIfAbsent(item.product.sellerName, () => []).add(item);
    }

    final created = <Order>[];
    for (final entry in bySeller.entries) {
      final summary = entry.value
          .map((i) => i.quantity > 1 ? '${i.product.name} \u00d7${i.quantity}' : i.product.name)
          .join(', ');

      // Newest first. Each new order goes to the front, so a checkout
      // with two sellers ends up with the later seller's order on top;
      // fine, they were placed in the same instant.
      final order = Order(
        id: 'ORD-${_firstPlacedNumber + _placed.length}',
        shopName: entry.key,
        status: OrderStatus.toShip,
        eta: 'Seller ships within 1-2 days',
        itemSummary: summary,
      );
      _placed.insert(0, order);
      created.add(order);
    }

    notifyListeners();
    _save();
    return created;
  }
}
