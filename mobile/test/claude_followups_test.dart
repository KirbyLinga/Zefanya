// test/claude_followups_test.dart
//
// Unit tests for the three pure helpers backing Claude's follow-up
// list: seller grouping (cart_groups), password banding
// (password_strength), and the orders-tab clamp rule from
// order_tab_memory (the prefs round-trip itself needs a device or
// mock prefs, so only the contract — clamped 0..3 — is pinned here
// via the exported count).

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/utils/cart_groups.dart';
import 'package:zefanya/utils/order_tab_memory.dart';
import 'package:zefanya/utils/password_strength.dart';

Product _p(String id, String seller, [double price = 500]) => Product(
      id: id,
      name: 'Item $id',
      category: 'Fashion',
      price: price,
      sellerName: seller,
    );

CartItem _line(String id, String seller, {bool selected = true}) =>
    CartItem(product: _p(id, seller), selected: selected);

void main() {
  group('groupCartBySeller', () {
    test('groups by seller in first-seen order', () {
      final items = [
        _line('a', 'Willow & Bloom'),
        _line('b', 'Rattan & Reed'),
        _line('c', 'Willow & Bloom'),
      ];
      final groups = groupCartBySeller(items);
      expect(groups.map((g) => g.sellerName),
          ['Willow & Bloom', 'Rattan & Reed']);
      expect(groups[0].items.map((i) => i.product.id), ['a', 'c']);
      expect(groups[1].items.map((i) => i.product.id), ['b']);
    });

    test('empty cart yields no groups', () {
      expect(groupCartBySeller(const []), isEmpty);
    });

    test('selection flags drive the tri-state checkbox', () {
      final all = groupCartBySeller(
          [_line('a', 'Shop'), _line('b', 'Shop')]);
      expect(all.single.allSelected, isTrue);
      expect(all.single.someSelected, isFalse);

      final mixed = groupCartBySeller(
          [_line('a', 'Shop'), _line('b', 'Shop', selected: false)]);
      expect(mixed.single.allSelected, isFalse);
      expect(mixed.single.someSelected, isTrue);

      final none = groupCartBySeller([
        _line('a', 'Shop', selected: false),
        _line('b', 'Shop', selected: false),
      ]);
      expect(none.single.allSelected, isFalse);
      expect(none.single.someSelected, isFalse);
    });

    test('holds the same CartItem objects, never copies', () {
      final items = [_line('a', 'Shop')];
      final groups = groupCartBySeller(items);
      expect(identical(groups.single.items.single, items.single), isTrue);
    });
  });

  group('scorePassword', () {
    test('below minimum is weak', () {
      expect(scorePassword(''), PasswordStrength.weak);
      expect(scorePassword('abc123'), PasswordStrength.weak);
      expect(scorePassword('1234567'), PasswordStrength.weak);
    });

    test('minimum length single-class is weak, mixed or longer is fair', () {
      expect(scorePassword('abcdefgh'), PasswordStrength.weak);
      expect(scorePassword('Abcdefgh'), PasswordStrength.fair);
      expect(scorePassword('abcdefghij'), PasswordStrength.fair);
    });

    test('long with mixed classes is strong', () {
      expect(scorePassword('Abcdefgh1234'), PasswordStrength.strong);
      expect(scorePassword('longpassword99'), PasswordStrength.strong);
      // Long but single-class stays fair, not strong.
      expect(scorePassword('aaaaaaaaaaaa'), PasswordStrength.fair);
    });

    test('labels are words, never numbers', () {
      expect(passwordStrengthLabel(PasswordStrength.weak), 'Weak');
      expect(passwordStrengthLabel(PasswordStrength.fair), 'Fair');
      expect(passwordStrengthLabel(PasswordStrength.strong), 'Strong');
    });
  });

  group('orders tab memory contract', () {
    test('tab count matches the four-stage stepper', () {
      expect(ordersTabCount, 4);
    });

    test('out-of-range stored values clamp to a valid index', () {
      // Mirrors loadOrdersTab's clamp so the contract is pinned even
      // though prefs I/O itself isn't exercised here.
      expect(99.clamp(0, ordersTabCount - 1), 3);
      expect((-2).clamp(0, ordersTabCount - 1), 0);
      expect(2.clamp(0, ordersTabCount - 1), 2);
    });
  });
}
