// test/search_chips_test.dart
//
// Pure unit tests for the chip helpers (Claude Round A): ranking and
// dimming with no widgets pumped.

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/utils/search_chips.dart';

void main() {
  const terms = ['Linen', 'Ceramic', 'Tote Bag', 'Scarf', 'Home & Living'];

  group('rankSearchChips (idle row: reorder + dim)', () {
    test('empty query preserves authored order, nothing dimmed', () {
      final ranked = rankSearchChips(terms, '');
      expect(ranked.map((c) => c.term), terms);
      expect(ranked.every((c) => !c.dimmed), isTrue);
    });

    test('whitespace-only query behaves as empty', () {
      final ranked = rankSearchChips(terms, '   ');
      expect(ranked.map((c) => c.term), terms);
      expect(ranked.every((c) => !c.dimmed), isTrue);
    });

    test('matching terms rise to the front, stably', () {
      // 'o' hits 'Tote Bag' and 'Home & Living', keeping their
      // relative order; the rest follow, dimmed.
      final ranked = rankSearchChips(terms, 'o');
      expect(
        ranked.map((c) => c.term),
        ['Tote Bag', 'Home & Living', 'Linen', 'Ceramic', 'Scarf'],
      );
      expect(ranked.take(2).every((c) => !c.dimmed), isTrue);
      expect(ranked.skip(2).every((c) => c.dimmed), isTrue);
    });

    test('matching is case-insensitive', () {
      final ranked = rankSearchChips(terms, 'LINEN');
      expect(ranked.first.term, 'Linen');
      expect(ranked.first.dimmed, isFalse);
    });

    test('input list is never mutated', () {
      final input = List<String>.of(terms);
      rankSearchChips(input, 'o');
      expect(input, terms);
    });
  });

  group('dimSearchChips (retry row: dim only, order kept)', () {
    test('empty query preserves authored order, nothing dimmed', () {
      final ranked = dimSearchChips(terms, '');
      expect(ranked.map((c) => c.term), terms);
      expect(ranked.every((c) => !c.dimmed), isTrue);
    });

    test('non-empty query dims non-matches in place', () {
      // Same 'o' query as above — but here 'Tote Bag' and
      // 'Home & Living' stay where they are, undimmed, while the
      // rest dim around them. No reorder, no layout jump.
      final ranked = dimSearchChips(terms, 'o');
      expect(ranked.map((c) => c.term), terms);
      expect(
        ranked.map((c) => c.dimmed),
        [true, true, false, true, false],
      );
    });

    test('no matches: order preserved, everything dimmed', () {
      final ranked = dimSearchChips(terms, 'zzz');
      expect(ranked.map((c) => c.term), terms);
      expect(ranked.every((c) => c.dimmed), isTrue);
    });

    test('input list is never mutated', () {
      final input = List<String>.of(terms);
      dimSearchChips(input, 'o');
      expect(input, terms);
    });
  });
}

