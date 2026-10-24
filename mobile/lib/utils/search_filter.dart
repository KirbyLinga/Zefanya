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

/// Price/rating bounds. Null min/max = no bound; 0 rating = any.
class SearchFilter {
  const SearchFilter({this.minPrice, this.maxPrice, this.minRating = 0});

  final double? minPrice;
  final double? maxPrice;
  final double minRating;

  bool get isActive => minPrice != null || maxPrice != null || minRating > 0;

  static const none = SearchFilter();
}

/// Returns [products] matching [filter], preserving order.
List<Product> filterProducts(List<Product> products, SearchFilter filter) {
  if (!filter.isActive) return List<Product>.of(products);
  return [
    for (final p in products)
      if ((filter.minPrice == null || p.price >= filter.minPrice!) &&
          (filter.maxPrice == null || p.price <= filter.maxPrice!) &&
          p.rating >= filter.minRating)
        p,
  ];
}

/// The amount typed into a price field, or null when it is blank.
///
/// A blank bound means "no bound", which is different from zero — zero is a
/// real amount and must survive as one.
double? parsePriceBound(String raw) {
  final text = raw.trim();
  if (text.isEmpty) return null;
  final value = double.tryParse(text);
  if (value == null || !value.isFinite) return null;
  return value;
}

/// Why a price range cannot be applied, or null when it can.
///
/// Kept out of the dialog so both "Apply" and any future API validation can
/// enforce the same two rules: amounts are not negative, and the minimum is
/// not greater than the maximum. Both ends are inclusive, so equality is a
/// valid one-price range.
String? priceRangeError(String minText, String maxText) {
  final minTextTrimmed = minText.trim();
  final maxTextTrimmed = maxText.trim();
  if (minTextTrimmed.isEmpty && maxTextTrimmed.isEmpty) return null;

  final min = parsePriceBound(minTextTrimmed);
  final max = parsePriceBound(maxTextTrimmed);
  if (minTextTrimmed.isNotEmpty && min == null) {
    return 'Enter a valid minimum price.';
  }
  if (maxTextTrimmed.isNotEmpty && max == null) {
    return 'Enter a valid maximum price.';
  }
  if ((min != null && min < 0) || (max != null && max < 0)) {
    return 'Price cannot be negative.';
  }
  if (min != null && max != null && min > max) {
    return 'Minimum price cannot be greater than maximum.';
  }
  return null;
}
