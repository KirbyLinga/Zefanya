// lib/state/catalog_state.dart
//
// The catalogue, held once for every screen that browses it.
//
// ## Why this exists
//
// `CatalogRepository` was the seam for "products come from somewhere", and the
// states that *resolve ids* already used it — `CartState` (restoring cart lines),
// `WishlistState` and `RecentlyViewedState` (indexing saved and seen ids). The
// screens that *browse* did not: the home grid, the category grid, search, the
// seller screen and the review button all reached into `data/mock_data.dart` and
// read `mockProducts` directly.
//
// Mock mode hid that completely, because the mock repository returns that same
// list. Live mode showed it the first time anyone looked: the app booted, signed
// in, and rendered the eight mock products while the server held thirteen real
// ones. That is the whole of the bug this file fixes, and it is why the browsing
// screens now read the catalogue the way they read the cart.
//
// `mockProducts` is now reached only *through* the mock repository, which is what
// the interface's own doc asked for.

import 'package:flutter/foundation.dart';

import '../data/api/api_exception.dart';
import '../data/mock_data.dart' show Product;
import '../data/repositories/catalog_repository.dart';
class CatalogState extends ChangeNotifier {
  /// [repository] defaults to the mock so existing call sites and tests are
  /// unaffected; `main()` passes the resolved bundle's.
  CatalogState({CatalogRepository? repository})
      : _repository = repository ?? MockCatalogRepository();

  final CatalogRepository _repository;

  List<Product> _products = const [];
  List<String> _categories = const [];
  ApiException? _loadFailure;

  /// Why the catalogue is empty, when the reason is a failed request rather than
  /// an empty shop.
  ///
  /// Kept here rather than left to the startup notice because the browsing screens
  /// are where a person looks for products: "no products yet" and "we could not
  /// ask" are different sentences, and this is the screen that can say which.
  ApiException? get loadFailure => _loadFailure;

  /// Every product the catalogue lists, in the server's order.
  List<Product> get products => List.unmodifiable(_products);

  /// Resolves a product id for a deep link, or null when the catalogue does
  /// not contain it. Null is the honest answer for a stale link — the screen
  /// shows an unavailable state rather than inventing a product.
  Product? productById(String id) {
    for (final product in _products) {
      if (product.id == id) return product;
    }
    return null;
  }

  /// Category labels in display order, including the leading `'All'` the chips
  /// render (that entry is the app's own no-filter chip, not a server row).
  List<String> get categories => List.unmodifiable(_categories);

  /// The products in [category]. `'All'` is not a category row — it means "no
  /// filter" — so it answers the whole catalogue rather than matching nothing.
  List<Product> inCategory(String category) => category == 'All'
      ? products
      : [
          for (final product in _products)
            if (product.category == category) product,
        ];

  /// Everything one shop sells — the seller screen.
  List<Product> bySeller(String sellerName) => [
        for (final product in _products)
          if (product.sellerName == sellerName) product,
      ];

  /// Free-text search over name and category — the same two fields the server
  /// matches (`ProductController::index`), so a live search and a mock search
  /// return the same products for the same query. Matching the description too is
  /// what the mock's own comment refuses: a two-word query would return nearly
  /// everything, which reads as broken rather than thorough.
  List<Product> search(String query) {
    final needle = query.trim().toLowerCase();
    if (needle.isEmpty) return const [];

    return [
      for (final product in _products)
        if (product.name.toLowerCase().contains(needle) ||
            product.category.toLowerCase().contains(needle))
          product,
    ];
  }

  /// A catalogue word close to a query that matched nothing, or null.
  ///
  /// Search is a single exact substring today, so one transposed letter turns a
  /// real product into a dead end. This deliberately suggests a *word that is
  /// in the catalogue* rather than guessing the whole product name: tapping
  /// "linen" runs a search that returns results, while suggesting "Linen Blend
  /// Wrap Dress" from "linenn" would be a much larger claim than the data can
  /// support. It only fires when the original query found nothing, and only
  /// for words of four characters or more, so "zzqxw" stays an honest empty
  /// state.
  String? suggestSearch(String query) {
    final trimmed = query.trim().toLowerCase();
    if (trimmed.length < 4 || search(trimmed).isNotEmpty) return null;

    final queryWords = trimmed
        .split(RegExp(r'[^a-z0-9]+'))
        .where((word) => word.length >= 4)
        .toList(growable: false);
    if (queryWords.isEmpty) return null;

    const stopWords = {'with', 'from', 'that', 'this', 'your', 'shop'};
    final candidates = <String>{};
    for (final product in _products) {
      for (final word in '${product.name} ${product.category}'
          .toLowerCase()
          .split(RegExp(r'[^a-z0-9]+'))) {
        if (word.length >= 4 && !stopWords.contains(word)) {
          candidates.add(word);
        }
      }
    }

    String? best;
    var bestDistance = 3;
    for (final candidate in candidates) {
      for (final word in queryWords) {
        final distance = _editDistance(word, candidate);
        // One typo is enough for short words; allow two on longer ones, where
        // "someting" vs "something" is still obviously the same word.
        final allowed = word.length >= 8 ? 2 : 1;
        if (distance >= 1 &&
            distance <= allowed &&
            distance < bestDistance &&
            search(candidate).isNotEmpty) {
          best = candidate;
          bestDistance = distance;
        }
      }
    }
    return best;
  }

  /// Levenshtein distance, capped by the tiny words this is used on.
  static int _editDistance(String a, String b) {
    if (a == b) return 0;
    var previous = List<int>.generate(b.length + 1, (i) => i);
    for (var i = 0; i < a.length; i++) {
      final current = <int>[i + 1];
      for (var j = 0; j < b.length; j++) {
        final insertion = current[j] + 1;
        final deletion = previous[j + 1] + 1;
        final substitution = previous[j] + (a[i] == b[j] ? 0 : 1);
        current.add(
          insertion < deletion
              ? (insertion < substitution ? insertion : substitution)
              : (deletion < substitution ? deletion : substitution),
        );
      }
      previous = current;
    }
    return previous.last;
  }

  /// Call once before runApp (see main.dart) to fetch the catalogue.
  ///
  /// A failure is recorded, never thrown: `main()` awaits this before `runApp`, so
  /// throwing would hold the splash on screen forever, and the browsing screens are
  /// where the failure belongs. `ApiNotConfiguredException` still throws — that is
  /// a build mistake, not a runtime condition (see `StartupState`).
  Future<void> load() async {
    try {
      final products = await _repository.products();
      final categories = await _repository.categories();

      _products = List.unmodifiable(products);
      _categories = List.unmodifiable(categories);
      _loadFailure = null;
    } on ApiNotConfiguredException {
      rethrow;
    } on ApiException catch (error) {
      _products = const [];
      _categories = const [];
      _loadFailure = error;
    }

    notifyListeners();
  }

  /// Re-fetches the catalogue. Called by pull-to-refresh on Home and Categories.
  ///
  /// Unlike [load], a failure here propagates — the RefreshIndicator receives it
  /// and the screen can show an error message — but [_loadFailure] is also updated
  /// so a rebuild from state still has something true to render.
  Future<void> refresh() async {
    try {
      final products = await _repository.products();
      final categories = await _repository.categories();
      _products = List.unmodifiable(products);
      _categories = List.unmodifiable(categories);
      _loadFailure = null;
    } on ApiException catch (error) {
      _loadFailure = error;
      rethrow;
    } finally {
      notifyListeners();
    }
  }
}
