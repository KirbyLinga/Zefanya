// lib/state/order_state.dart
//
// The buyer's order history, exposed via Provider (see main.dart) — same
// pattern as CartState.
//
// ## Where orders come from now
//
// From an [OrderRepository]. Everything the state class used to do *itself* —
// minting `ORD-10246`, grouping a cart by seller, encoding the orders to
// `shared_preferences` — now lives in the mock repository, because all of it is
// data work and every part of it is the server's in live mode (a real
// `POST /orders` mints the id, prices the lines and decides the status).
//
// What is left here is presentation: [orders] and [purchasedProductIds] are
// synchronous getters read inside `build()`, so they resolve against a cache
// that [load] fills. The app loads before its first frame (see `main.dart`), so
// there is no empty-then-jump.
//
// ## The one place this state class still composes two sources
//
// [resolvedOrders] merges the buyer's orders with live courier progress — when
// a courier marks a parcel picked up, the order moves to "In Transit". That is a
// *view over two states*, not a data source, which is why it stayed here: moving
// it into a repository would mean a repository taking another state object as an
// argument, inverting the dependency this pass is trying to straighten.
//
// ## The seller split
//
// A cart can hold pieces from several sellers, and a marketplace order belongs
// to ONE seller (that is who ships it, and who the buyer contacts), so a
// checkout becomes one order per seller. That rule is the *server's* in live
// mode and the mock mirrors it exactly, so switching modes cannot change how
// many orders a cart produces.

import 'package:flutter/foundation.dart';
import '../data/api/api_exception.dart';
import '../data/mock_data.dart' show Order, OrderStatus;
import '../data/repositories/order_repository.dart';
import 'cart_state.dart';
import 'courier_state.dart';

class OrderState extends ChangeNotifier {
  /// [repository] defaults to the mock implementation so existing call sites
  /// (and tests) are unaffected; `main.dart` passes the resolved bundle's.
  OrderState({OrderRepository? repository})
      : _repository = repository ?? MockOrderRepository();

  final OrderRepository _repository;

  /// Orders placed or restored, newest first — as-placed statuses. Read
  /// [resolvedOrders] for what a screen should show.
  ///
  /// A cache over the repository's answer, refreshed by [load] and [place].
  /// Synchronous on purpose: this getter is called from `build()`.
  List<Order> _orders = const [];

  List<Order> get orders => List.unmodifiable(_orders);

  /// Every catalogue item this buyer has ordered, across all orders.
  ///
  /// The join key the personalization surfaces share. Deliberately derived from
  /// data that already exists rather than tracked separately: there is no new
  /// event stream here, no "engagement" counter, nothing that could drift from
  /// the order history or outlive it. The only question it answers is the one
  /// the rails actually need — has this person already bought this?
  Set<String> get purchasedProductIds => {for (final o in _orders) ...o.productIds};

  /// [orders], with each order's status and ETA following its delivery job in
  /// [courier]. This is what the Orders screen shows.
  ///
  ///   courier: assigned / at the seller  ->  To Ship
  ///   courier: picked up                 ->  In Transit
  ///   courier: in transit                ->  Out for Delivery
  ///   courier: delivered                 ->  Delivered
  ///
  /// Seeded sample orders have no job, so they keep their fixed status.
  List<Order> resolvedOrders(CourierState courier) =>
      List.unmodifiable([for (final o in _orders) _resolve(o, courier)]);

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
  ///
  /// **A failure here is recorded, never thrown.** `main()` awaits this before
  /// `runApp`, so a live build — which has no `GET /orders` yet — would otherwise
  /// abort the entire app over one unimplemented endpoint instead of opening with
  /// an empty history. The exception is kept in [loadFailure] so the screen that
  /// asks for orders can explain it, which is what `LiveOrderRepository.load()`'s
  /// own comment has always said: an empty Orders tab that reports the error when
  /// it is actually opened.
  Future<void> load() async {
    await _repository.load();

    try {
      _orders = await _repository.orders();
      _loadFailure = null;
    } on ApiNotConfiguredException {
      // A live build with no base URL is a *build* mistake rather than a runtime
      // condition: no screen can do anything sensible with it, and the person who
      // has to fix it is the developer, not the person holding the phone. It keeps
      // throwing, and `repository_wiring_test.dart` pins that.
      rethrow;
    } on ApiException catch (error) {
      _orders = const [];
      _loadFailure = error;
    }
  }

  /// Re-reads orders from the repository, recording how the attempt went.
  ///
  /// This is the Retry behind the Orders screen's failure copy, and safe to call
  /// repeatedly. A failure still propagates — the repositories throw rather than
  /// answer empty, and callers may want that — *and* it is kept in [loadFailure],
  /// so a reader that cannot handle a throw (a `notifyListeners`-driven rebuild)
  /// still has something true to render.
  Future<void> refresh() async {
    try {
      _orders = await _repository.orders();
      _loadFailure = null;
    } on ApiException catch (error) {
      _loadFailure = error;
      rethrow;
    } finally {
      notifyListeners();
    }
  }

  /// Why [orders] is empty, when the reason is a failed request rather than an
  /// empty history.
  ///
  /// Set by [load] and [refresh], cleared by a [refresh] that succeeds. It exists
  /// so the Orders screen can say *what* happened: without it, a live build with
  /// no `GET /orders` yet would render its honest "no orders yet" empty state over
  /// a request that never succeeded — an absence presented as a fact.
  ApiException? _loadFailure;

  /// The failure behind an empty [orders], or null when nothing failed.
  ApiException? get loadFailure => _loadFailure;

  /// Turns the checked-out cart lines into orders, one per seller, all
  /// starting at "To Ship". Returns the orders it created (empty if [items] is
  /// empty) so the caller can dispatch a courier for each.
  ///
  /// Call this BEFORE `CartState.clearSelected()` — it reads the items you pass
  /// in; it does not touch the cart itself.
  ///
  /// Async because placing an order is a *write*: the mock writes to disk and a
  /// live implementation posts to `POST /orders` and waits for the server to
  /// mint the ids. A synchronous signature here would be a lie that has to be
  /// broken later, and every caller would need changing at that point instead of
  /// now.
  Future<List<Order>> placeOrders(List<CartItem> items) async {
    if (items.isEmpty) return const [];

    final created = await _repository.place([
      for (final item in items)
        OrderLineRequest(
          productId: item.product.id,
          productName: item.product.name,
          sellerName: item.product.sellerName,
          quantity: item.quantity,
          unitPrice: item.product.price,
        ),
    ]);

    // Re-read rather than merging `created` by hand: in live mode the server's
    // list is authoritative (it may include orders placed on another device),
    // and in mock mode this is the same list plus the new ones. One code path,
    // so the two modes cannot disagree about ordering.
    _orders = await _repository.orders();
    notifyListeners();
    return created;
  }
}
