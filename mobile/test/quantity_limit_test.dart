// test/quantity_limit_test.dart
//
// BUY-CART-005 / BUY-PDP-004: a cart line stops at 20, and the refusal is
// reportable so the screen can say "only 20 available".

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/state/cart_state.dart';

Product _product({int stock = 50}) => Product(
  id: 'p1',
  name: 'Test Product',
  category: 'Home',
  price: 100,
  sellerName: 'Shop',
  stock: stock,
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('20 is accepted and 21 is refused', () {
    final cart = CartState();
    cart.addItem(_product(), quantity: 20);

    final item = cart.items.single;
    expect(item.quantity, 20);
    expect(cart.updateQuantity(item, 1), isFalse);
    expect(item.quantity, 20, reason: 'a refused change must not mutate');
    expect(cart.updateQuantity(item, -1), isTrue);
    expect(item.quantity, 19);
  });

  test('the cap is the lower of 20 and stock', () {
    final cart = CartState();
    cart.addItem(_product(stock: 3), quantity: 10);

    expect(cart.items.single.quantity, 3);
    expect(cart.updateQuantity(cart.items.single, 1), isFalse);
  });

  test('adding the same line twice still stops at the cap', () {
    final cart = CartState();
    final product = _product();
    cart.addItem(product, quantity: 15);
    cart.addItem(product, quantity: 15);

    expect(cart.items.single.quantity, 20);
  });
}
