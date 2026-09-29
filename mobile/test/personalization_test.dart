// test/personalization_test.dart
//
// The three personalization features talking to each other, asserted as
// logic rather than pixels.
//
// The gap these cover: Wishlist, Recently Viewed and real order history all
// existed as separate rails that never referenced each other, and the reason
// was concrete — order lines threw away their catalogue ids when they were
// placed (`itemSummary` is prose), so "you saved this", "you viewed this"
// and "you bought this" had no shared key to join on. These tests pin the
// key, the join, and the one rule that uses it.

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/widgets/recently_viewed_rail.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/order_state.dart';

/// A catalogue item that is NOT in the seeded sample history (those are
/// p1–p4), so tests can watch a *new* purchase become joinable.
Product get _unbought => mockProducts[4];

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('seeded history can be joined to the catalogue', () async {
    // load() first: orders now resolve through the repository, so the cache
    // that the synchronous getters read is filled by load() rather than being
    // computed from a const list on every access.
    final orders = OrderState();
    await orders.load();

    expect(mockOrders.every((o) => o.productIds.isNotEmpty), isTrue,
        reason: 'sample history has to be joinable too, not just new orders');

    // Every id in history is a real catalogue id — the join is only useful
    // if it points at things the rest of the app knows about.
    final catalogue = {for (final p in mockProducts) p.id};
    expect(orders.purchasedProductIds.difference(catalogue), isEmpty);
  });

  test('a placed order keeps the ids its summary was built from', () async {
    final orders = OrderState();
    await orders.load();
    expect(orders.purchasedProductIds, isNot(contains(_unbought.id)));

    final created = await orders.placeOrders([CartItem(product: _unbought, quantity: 2)]);

    expect(created.single.productIds, [_unbought.id]);
    // The summary is still prose — that's fine, it's for the order card.
    expect(created.single.itemSummary, contains(_unbought.name));
    expect(orders.purchasedProductIds, contains(_unbought.id));
  });

  test('the ids survive a restart', () async {
    final first = OrderState();
    await first.load();
    await first.placeOrders([CartItem(product: _unbought)]);
    // The mock repository persists with a fire-and-forget write, as every
    // state class here did before it.
    await pumpEventQueue();

    final restarted = OrderState();
    await restarted.load();

    expect(restarted.purchasedProductIds, contains(_unbought.id));
  });

  test('"pick up where you left off" drops what you already bought', () {
    final viewed = [mockProducts[0], _unbought, mockProducts[5]];

    final remaining = unpurchased(viewed, {_unbought.id});

    expect(remaining.map((p) => p.id), [mockProducts[0].id, mockProducts[5].id]);
  });

  test('an order with unknown contents excludes nothing', () {
    // Orders placed before the ids existed carry an empty list. That has to
    // mean "we do not know what was in it" rather than "this person has
    // bought nothing", or an old history would be read as a fresh buyer.
    final viewed = [mockProducts[0]];

    expect(unpurchased(viewed, const {}), viewed);
  });
}
