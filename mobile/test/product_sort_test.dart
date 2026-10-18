// test/product_sort_test.dart
//
// Unit tests for the client-side sort helper (Tier 1, task 6).
// Pure list-in/list-out — no widgets pumped.

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/utils/product_sort.dart';

Product _p(String id, double price) => Product(
      id: id,
      name: 'Item $id',
      category: 'Fashion',
      price: price,
      sellerName: 'Shop',
    );

void main() {
  final products = [_p('a', 500), _p('b', 199), _p('c', 899)];

  test('featured preserves catalogue order and copies the list', () {
    final sorted = sortProducts(products, ProductSort.featured);
    expect(sorted.map((p) => p.id), ['a', 'b', 'c']);
    expect(identical(sorted, products), isFalse);
  });

  test('price ascending orders low to high', () {
    final sorted = sortProducts(products, ProductSort.priceAsc);
    expect(sorted.map((p) => p.id), ['b', 'a', 'c']);
  });

  test('price descending orders high to low', () {
    final sorted = sortProducts(products, ProductSort.priceDesc);
    expect(sorted.map((p) => p.id), ['c', 'a', 'b']);
  });

  test('price ties keep catalogue order and input is untouched', () {
    final tied = [_p('a', 300), _p('b', 300), _p('c', 100)];
    expect(sortProducts(tied, ProductSort.priceAsc).map((p) => p.id), ['c', 'a', 'b']);
    expect(sortProducts(tied, ProductSort.priceDesc).map((p) => p.id), ['a', 'b', 'c']);
    expect(tied.map((p) => p.id), ['a', 'b', 'c']);
  });
}
