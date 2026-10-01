// lib/data/repositories/catalog_repository.dart
//
// Where products, categories and the Home page's content come from.
//
// ## What this interface is for
//
// Before it, five state classes and nine screens reached into
// `data/mock_data.dart` directly — `mockProducts.where(...)` in a state class,
// `mockCategories` in a screen. That works exactly once: it cannot become a
// network call without editing every one of those places, and each edit is a
// chance to introduce a different loading behaviour.
//
// With this interface, "products come from somewhere" is one question asked in
// one place, and the answer is chosen by `config/dev_flags.dart`.
//
// ## Why the mock implementation is async
//
// Every method returns a `Future`, including the mock's. That is not ceremony —
// it is the point. A synchronous mock would let every screen be written as if
// the data were always there, and the day the live repository arrives each of
// those screens needs a loading state it was never built with. Making the mock
// async from the start means the loading/error/empty paths are exercised in
// mock mode, which is the only mode that exists.
//
// The mock adds no artificial delay: an `async` function that returns
// immediately still yields, so the async *shape* is real without a fake
// spinner flashing for one frame.

import '../api/api_client.dart';
import '../api/api_exception.dart';
import '../dto/remote_catalog.dart';
import '../mock_data.dart';
import 'live_stub.dart';

/// How the catalogue can be narrowed.
///
/// An object rather than a bare category string because the *request shape* is
/// the live repository's decision, not a screen's: `GET /products?category=`
/// and `GET /categories/{id}/products` are different contracts, and a screen
/// holding a URL-shaped string would force one of them. Passing the whole query
/// as a value keeps that decision here.
class CatalogQuery {
  const CatalogQuery({this.category, this.search, this.seller});

  /// A category *name* (`'Home & Living'`), matching what the app renders. The
  /// contract passes it as a query parameter so the server keeps owning the
  /// id<->name mapping — see OPEN QUESTIONS §0.3 about the 14-vs-4 category
  /// mismatch.
  final String? category;

  /// Free-text search.
  final String? search;

  /// Restrict to one shop — the seller screen.
  final String? seller;

  bool get isEmpty => category == null && search == null && seller == null;
}

abstract class CatalogRepository {
  /// The catalogue, optionally narrowed. Empty means "nothing listed"; a
  /// failure throws.
  ///
  /// That distinction is deliberate and load-bearing: an empty list renders an
  /// empty state, an exception renders an error-with-retry, and collapsing the
  /// two would show "no products yet" to someone whose connection dropped.
  Future<List<Product>> products({CatalogQuery? query});

  /// One product, or null when it no longer exists.
  ///
  /// Null rather than an exception: a product page for a delisted item should
  /// say "no longer available", not fail. The live implementation maps a 404
  /// to null precisely so a caller cannot get this wrong.
  Future<Product?> productById(String id);

  /// Category names in display order — including the leading `'All'` the chips
  /// render.
  Future<List<String>> categories();

  /// Every product in [category].
  Future<List<Product>> productsInCategory(String category);

  /// The seller's own words for Home's "FROM THE MAKER" section, or null when
  /// that shop hasn't written anything.
  ///
  /// Null is a real answer: the section hides rather than substituting generic
  /// copy, because a shop with no note should look like a shop with no note.
  /// See `mockMakerNotes` for why that rule exists.
  Future<String?> makerNote(String sellerName);

  /// Home's promo campaign.
  Future<List<PromoSlide>> promoSlides();
}

// ---------------------------------------------------------------------------
// Mock — the current behaviour, unchanged, behind the interface
// ---------------------------------------------------------------------------

class MockCatalogRepository implements CatalogRepository {
  const MockCatalogRepository();

  @override
  Future<List<Product>> products({CatalogQuery? query}) async {
    final q = query;
    if (q == null || q.isEmpty) return mockProducts;
    return mockProducts.where((p) {
      if (q.category != null && q.category != 'All' && p.category != q.category) {
        return false;
      }
      if (q.seller != null && p.sellerName != q.seller) return false;
      if (q.search != null) {
        final needle = q.search!.toLowerCase();
        // Name and category only. A search matching the description too would
        // return nearly everything for a two-word query, which reads as broken
        // rather than thorough.
        if (!p.name.toLowerCase().contains(needle) &&
            !p.category.toLowerCase().contains(needle)) {
          return false;
        }
      }
      return true;
    }).toList(growable: false);
  }

  @override
  Future<Product?> productById(String id) async {
    for (final product in mockProducts) {
      if (product.id == id) return product;
    }
    return null;
  }

  @override
  Future<List<String>> categories() async => mockCategories;

  @override
  Future<List<Product>> productsInCategory(String category) =>
      products(query: CatalogQuery(category: category));

  @override
  Future<String?> makerNote(String sellerName) async => makerNoteFor(sellerName);

  @override
  Future<List<PromoSlide>> promoSlides() async => mockPromoSlides;
}

// ---------------------------------------------------------------------------
// Live — the catalogue, read-only
// ---------------------------------------------------------------------------

/// The app's real catalogue, over `GET /products`, `/products/{id}` and
/// `/categories`.
///
/// ## Two methods still throw, and that is the answer rather than an oversight
///
/// [makerNote] and [promoSlides] have no server-side source: `products` has no
/// maker-note column, and there is no promotions table at all. They keep naming
/// what is missing instead of returning invented content, so Home's maker section
/// and its promo carousel stay absent — which is the honest shape of "the server
/// does not have this yet". (`remote_catalog.dart` does the same for the three
/// product fields the catalogue cannot fill.)
///
/// ## Requests are sent unauthenticated, deliberately
///
/// These routes are public, and `authenticated: false` is how the client is told
/// so: the request does not depend on who the person is, and a bearer token has
/// no business being attached to an endpoint that cannot use it. The catalogue is
/// the same for everybody, signed in or not.
///
/// The `isConfigured` guard still comes first in each method: a live repository
/// with no base URL is a *misconfiguration*, and reporting that is more useful
/// than reporting a missing endpoint — the first is fixed by setting a flag, the
/// second by building an API.
class LiveCatalogRepository extends LiveRepository implements CatalogRepository {
  LiveCatalogRepository(super.client);

  @override
  Future<List<Product>> products({CatalogQuery? query}) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    final body = await client.getJson(
      '/products',
      query: _parameters(query),
      authenticated: false,
    );

    return unwrapList(body).map(productFromJson).toList(growable: false);
  }

  @override
  Future<Product?> productById(String id) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    try {
      final body = await client.getJson('/products/$id', authenticated: false);
      return productFromJson(unwrapMap(body));
    } on NotFoundException {
      // The interface promises null for a product that no longer exists, so the
      // 404 is answered here rather than at every call site. A draft or delisted
      // product answers 404 as well, which is the same fact to a buyer: the page
      // says "no longer available" instead of failing.
      return null;
    }
  }

  @override
  Future<List<String>> categories() async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    final body = await client.getJson('/categories', authenticated: false);

    // The leading 'All' is this app's own chip, not a row the server has: the
    // interface promises it, and the server's list is the curated fourteen. A
    // row with no label would be a chip with no text, so it is skipped.
    final labels = <String>['All'];
    for (final row in unwrapList(body)) {
      final label = categoryLabelFromJson(row);
      if (label.isNotEmpty) labels.add(label);
    }
    return List.unmodifiable(labels);
  }

  @override
  Future<List<Product>> productsInCategory(String category) =>
      products(query: CatalogQuery(category: category));

  @override
  Future<String?> makerNote(String sellerName) {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('maker_note on GET /products', section: '§5.3');
  }

  @override
  Future<List<PromoSlide>> promoSlides() {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('GET /promotions', section: '§5.5');
  }

  /// The query string a [CatalogQuery] becomes.
  ///
  /// Only the parameters that are actually set are sent, so a request stays
  /// legible in a log, and `'All'` is dropped: it is the app's own "no filter"
  /// chip, and sending it would ask the server for a category by that name —
  /// which is an empty catalogue rather than every product.
  ///
  /// The category is sent as its *label* (`'Home and Garden'`), not an id,
  /// because the chip the person tapped shows a label and the server owns the
  /// id<->label mapping for its own rows.
  Map<String, String> _parameters(CatalogQuery? query) {
    if (query == null) return const {};

    final parameters = <String, String>{};

    final category = query.category?.trim();
    if (category != null && category.isNotEmpty && category != 'All') {
      parameters['category'] = category;
    }

    final search = query.search?.trim();
    if (search != null && search.isNotEmpty) parameters['q'] = search;

    final seller = query.seller?.trim();
    if (seller != null && seller.isNotEmpty) parameters['seller'] = seller;

    return parameters;
  }
}
