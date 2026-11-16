// test/cart_clear_all_test.dart
//
// CartState.clearAll()/restoreAll() (third-pass item: cart clear-all).
// Pure state — no widgets pumped.

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/state/cart_state.dart';

Product _p(String id) => Product(
      id: id,
      name: 'Item $id',
      category: 'Fashion',
      price: 100,
      sellerName: 'Shop',
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  SharedPreferences.setMockInitialValues({});

  test('clearAll empties the cart and returns the snapshot', () {
    final cart = CartState();
    cart.addItem(_p('a'));
    cart.addItem(_p('b'));
    expect(cart.items, hasLength(2));

    final snapshot = cart.clearAll();
    expect(cart.items, isEmpty);
    expect(snapshot.map((i) => i.product.id), ['a', 'b']);
  });

  test('restoreAll puts the snapshot back', () {
    final cart = CartState();
    cart.addItem(_p('a'));
    final snapshot = cart.clearAll();
    expect(cart.items, isEmpty);

    cart.restoreAll(snapshot);
    expect(cart.items.map((i) => i.product.id), ['a']);
  });

  test('clearAll on an empty cart returns an empty snapshot', () {
    final cart = CartState();
    expect(cart.clearAll(), isEmpty);
  });
}
