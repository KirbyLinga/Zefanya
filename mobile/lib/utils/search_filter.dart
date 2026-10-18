// lib/utils/search_filter.dart
//
// Client-side search filtering (Claude polish follow-up).
//
// Pure list-in/list-out like sortProducts: the screen's query match
// (from CatalogState.search) goes in, the price/rating-filtered list
// comes out. Pure so it can be unit-tested without pumping widgets.
//
// Split from sorting on purpose: the screen needs the *unfiltered*
// match count separately (to tell "no results" apart from "filters
// hide everything"), so filter-then-count has to be callable apart
// from sort-then-display.

import '../data/mock_data.dart' show Product;

/// Price/rating bounds. Null max = no bound; 0 rating = any.
class SearchFilter {
  const SearchFilter({this.maxPrice, this.minRating = 0});

  final double? maxPrice;
  final double minRating;

  bool get isActive => maxPrice != null || minRating > 0;

  static const none = SearchFilter();
}

/// Returns [products] matching [filter], preserving order.
List<Product> filterProducts(List<Product> products, SearchFilter filter) {
  if (!filter.isActive) return List<Product>.of(products);
  return [
    for (final p in products)
      if ((filter.maxPrice == null || p.price <= filter.maxPrice!) &&
          p.rating >= filter.minRating)
        p,
  ];
}
