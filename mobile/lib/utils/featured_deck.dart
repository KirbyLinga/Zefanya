// lib/utils/featured_deck.dart
//
// Survivors of the Home featured rail rewrite: the ordering helpers
// the rail's tests still pin ([cycleDeck], [deckWindow]) and the
// rolling-total tween for cart/checkout totals.
//
// The finite-advance mechanic (advanceDeck: swipe-top-away, end card,
// Replay) died with the swipe-to-dismiss stack — see the widget. The
// rail pages both ways, so nothing is ever dismissed.

/// Rotates the deck order after a swipe: the top card goes to the back.
List<int> cycleDeck(List<int> order) {
  if (order.length < 2) return List<int>.of(order);
  return [...order.sublist(1), order.first];
}

/// The visible window: the first [count] entries of [order].
/// Empty input yields an empty window, never a crash.
List<int> deckWindow(List<int> order, {int count = 4}) {
  if (order.isEmpty) return const [];
  return order.take(count).toList(growable: false);
}

/// Linear interpolation between two displayed numbers, snapped to
/// whole pesos — the rolling-total tween for cart/checkout totals.
/// t is 0..1 (clamped); begin and end are the old and new totals.
double rollingTotal(double begin, double end, double t) {
  final clamped = t.clamp(0.0, 1.0);
  return (begin + (end - begin) * clamped).roundToDouble();
}
