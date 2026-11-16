// lib/utils/product_sort.dart
//
// Client-side ordering for the category grid and search results.
//
// No endpoint: the catalogue arrives whole (CatalogState.products), so
// sorting is a view concern — a pure copy-in/copy-out helper both
// screens share rather than logic living twice in widget state. Pure
// so it can be unit-tested without pumping widgets.

import '../data/mock_data.dart' show Product;

/// The three orders the chip row offers. No "Newest": Product carries
/// no created/updated timestamp (see OPEN QUESTIONS), and inventing one
/// would be sorting by fiction.
enum ProductSort { featured, priceAsc, priceDesc }

/// Returns [products] in [sort] order, never mutating the input.
///
/// Featured preserves the catalogue order (the server's own sequence);
/// price ties keep their relative order so equal-priced items don't
/// shuffle between rebuilds.
List<Product> sortProducts(List<Product> products, ProductSort sort) {
  switch (sort) {
    case ProductSort.featured:
      return List<Product>.of(products);
    case ProductSort.priceAsc:
      return _byPrice(products, ascending: true);
    case ProductSort.priceDesc:
      return _byPrice(products, ascending: false);
  }
}

List<Product> _byPrice(List<Product> products, {required bool ascending}) {
  final indexed = [
    for (var i = 0; i < products.length; i++) (i, products[i]),
  ];
  indexed.sort((a, b) {
    final cmp = ascending
        ? a.$2.price.compareTo(b.$2.price)
        : b.$2.price.compareTo(a.$2.price);
    if (cmp != 0) return cmp;
    return a.$1.compareTo(b.$1);
  });
  return [for (final e in indexed) e.$2];
}
