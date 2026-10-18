// test/search_filter_test.dart
//
// Unit tests for the client-side filter helper (Claude polish
// follow-up) and the earnings week-bucketing.
//
// Both pure list-in/list-out — no widgets pumped, same as the sort
// helper tests.

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/utils/search_filter.dart';
import 'package:zefanya/utils/weekly_buckets.dart';

Product _p(String id, double price, [double rating = 4.0]) => Product(
      id: id,
      name: 'Item $id',
      category: 'Fashion',
      price: price,
      sellerName: 'Shop',
      rating: rating,
    );

void main() {
  group('filterProducts', () {
    final products = [
      _p('a', 199, 4.8),
      _p('b', 500, 4.2),
      _p('c', 899, 4.9),
    ];

    test('inactive filter preserves order and copies the list', () {
      final out = filterProducts(products, SearchFilter.none);
      expect(out.map((p) => p.id), ['a', 'b', 'c']);
      expect(identical(out, products), isFalse);
    });

    test('price bound keeps at-or-under items only', () {
      final out = filterProducts(
          products, const SearchFilter(maxPrice: 500));
      expect(out.map((p) => p.id), ['a', 'b']);
    });

    test('rating bound keeps at-or-above items only', () {
      final out = filterProducts(
          products, const SearchFilter(minRating: 4.5));
      expect(out.map((p) => p.id), ['a', 'c']);
    });

    test('combined bounds intersect', () {
      final out = filterProducts(
          products,
          const SearchFilter(maxPrice: 500, minRating: 4.5));
      expect(out.map((p) => p.id), ['a']);
    });

    test('a bound that hides everything returns empty, input untouched', () {
      final out = filterProducts(
          products, const SearchFilter(maxPrice: 50));
      expect(out, isEmpty);
      expect(products.map((p) => p.id), ['a', 'b', 'c']);
    });
  });

  group('last7DayTotals', () {
    // Pinned week: Wed 2026-10-07. Index 6 is today, 0 is six days ago.
    final now = DateTime(2026, 10, 7, 15, 0);

    test('payouts land on their local calendar day', () {
      final payouts = [
        DatedPayout(amount: 85, when: DateTime(2026, 10, 7, 9)),
        DatedPayout(amount: 120, when: DateTime(2026, 10, 5, 18)),
        DatedPayout(amount: 40, when: DateTime(2026, 10, 5, 8)),
      ];
      final totals = last7DayTotals(payouts, now: now);
      expect(totals.length, 7);
      expect(totals[6], 85); // today
      expect(totals[4], 160); // two days ago, summed
      expect(totals.where((t) => t > 0).length, 2);
    });

    test('payouts older than 6 days are excluded', () {
      final payouts = [
        DatedPayout(amount: 999, when: DateTime(2026, 9, 30, 12)),
      ];
      expect(last7DayTotals(payouts, now: now), everyElement(0));
    });

    test('calendar-day construction, not Duration arithmetic', () {
      // A midnight payout 6 days back must land on index 0 even
      // though Duration math across a DST boundary could shift it.
      final payouts = [
        DatedPayout(amount: 10, when: DateTime(2026, 10, 1, 0, 30)),
      ];
      final totals = last7DayTotals(payouts, now: now);
      expect(totals[0], 10);
    });

    test('empty input is a flat zero week', () {
      expect(last7DayTotals(const [], now: now), everyElement(0));
    });

    test('labels align: index 6 is today, all in range', () {
      final labels = last7DayLabels(now: now);
      expect(labels.length, 7);
      expect(labels[6], 'W'); // Oct 7 2026 is a Wednesday
      expect(labels[0], 'T'); // six days back is Thursday
      for (final l in labels) {
        expect('MTWTFSS', contains(l));
      }
    });
  });
}
