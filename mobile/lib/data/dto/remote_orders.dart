// lib/data/dto/remote_orders.dart
//
// `GET /orders` -> the app's `Order`.
//
// ## Three things the server sends that the app does not have
//
//  1. **Thirteen statuses, four stages.** The server's pipeline runs
//     `PLACED` -> `CONFIRMED` -> ... -> `DELIVERED`, and the app shows four stages
//     because that is what a buyer needs. The mapping lives here, on the side that
//     chose to simplify, so the server can keep reporting `SORTED` for the day a
//     tracker wants real progress.
//  2. **No ETA sentence.** The app's `Order.eta` is display prose ('Seller ships
//     within 1-2 days') and the server has no such column — it has a status. The
//     sentences below state the *stage*; they are not predictions about a parcel,
//     which is why none of them promises a date.
//  3. **No item summary.** `itemSummary` is the card's line (`'Handwoven Rattan
//     Tote ×2, Kamagong Stool'`), built here from the item rows the server does
//     send, in the app's own format. Sending display wording from the API would put
//     the app's typography in the server.
//
// Money: nothing on this wire is an amount today (the card shows items, and a
// total arrives as `subtotal_minor` the day the card shows one) — and if it did, it
// would arrive in centavos like every other amount, through `money.dart`.

import '../mock_data.dart';

/// One seller order from `GET /api/orders`.
Order orderFromJson(Map<String, Object?> json) {
  final status = orderStatusFromServer(_text(json['status']));

  return Order(
    // The server's own row id, as a string like every other id the app reads.
    id: _text(json['id']),
    shopName: _text(json['shop']),
    status: status,
    eta: _etaFor(status),
    itemSummary: _summary(json['items']),
    productIds: _productIds(json['product_ids']),
    productQuantities: _productQuantities(json['items']),
  );
}

/// The app's four display stages, from the server's pipeline status.
///
/// `RETURNED` maps to [OrderStatus.inTransit] because the parcel is still moving —
/// back to the seller — and the app has no stage for that; it is the same choice
/// `OrderState.resolvedOrders` makes for `returnToSender`.
///
/// Anything unrecognised maps to [OrderStatus.toShip], the *earliest* stage: a
/// status this build has never heard of is not evidence that the parcel moved, and
/// claiming "in transit" for it would be a claim about a real delivery made out of
/// ignorance.
OrderStatus orderStatusFromServer(String status) => switch (status) {
      'PLACED' || 'CONFIRMED' || 'PREPARING' || 'READY_FOR_PICKUP' => OrderStatus.toShip,
      'PICKED_UP' || 'AT_SORTING_CENTER' || 'SORTED' => OrderStatus.inTransit,
      'ASSIGNED_TO_RIDER' || 'OUT_FOR_DELIVERY' || 'DELIVERY_FAILED' => OrderStatus.outForDelivery,
      'DELIVERED' || 'COMPLETED' => OrderStatus.toRate,
      'RETURNED' => OrderStatus.inTransit,
      _ => OrderStatus.toShip,
    };

/// The sentence the order card shows for a stage.
String _etaFor(OrderStatus status) => switch (status) {
      OrderStatus.toShip => 'Seller ships within 1-2 days',
      OrderStatus.inTransit => 'In transit to you',
      OrderStatus.outForDelivery => 'Out for delivery',
      OrderStatus.toRate => 'Delivered',
    };

/// The card's line: `'Handwoven Rattan Tote ×2, Kamagong Stool'`.
///
/// `×` and the comma are the format the mock's own checkout wrote, so switching a
/// build from mock to live does not change how an order reads.
String _summary(Object? items) {
  if (items is! List) return '';

  final parts = <String>[];

  for (final entry in items) {
    if (entry is! Map) continue;

    final name = _text(entry['name']);
    if (name.isEmpty) continue;

    final quantity = entry['quantity'];
    final count = quantity is num ? quantity.toInt() : 0;

    parts.add(count > 1 ? '$name ×$count' : name);
  }

  return parts.join(', ');
}

/// The catalogue ids this order's lines still resolve to.
///
/// The server omits a line's id when its product row has been removed, so the list
/// is ids only — the app's `productIds` is a join key, and a null in it would be an
/// id that matches nothing.
List<String> _productIds(Object? value) {
  if (value is! List) return const [];

  return [
    for (final id in value)
      if (id is String && id.isNotEmpty) id,
  ];
}

/// Units per product id, read from the same `items` array the summary uses.
///
/// `OrderResource` already sends `quantity` and `product_id` on each line, so
/// purchase limits can use the server's own numbers instead of assuming one
/// unit per id. A line whose product was removed has a null id and is skipped.
Map<String, int> _productQuantities(Object? items) {
  if (items is! List) return const {};

  final quantities = <String, int>{};
  for (final entry in items) {
    if (entry is! Map) continue;
    final id = _text(entry['product_id']);
    if (id.isEmpty) continue;
    final quantity = entry['quantity'];
    final count = quantity is num ? quantity.toInt() : 0;
    if (count <= 0) continue;
    quantities.update(id, (value) => value + count, ifAbsent: () => count);
  }
  return quantities;
}

/// The same tolerant reader `remote_catalog.dart` carries, deliberately local
/// rather than exported: PHP's encoder emits the same column as a string or a
/// number depending on how it left the database, and rejecting either would fail a
/// whole response over a difference with no meaning. A DTO file that cannot be read
/// on its own is the thing this layer exists to avoid.
String _text(Object? value) => value?.toString().trim() ?? '';
