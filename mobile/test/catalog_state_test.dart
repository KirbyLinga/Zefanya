// test/catalog_state_test.dart
//
// Where the browsing screens get their products.
//
// `CatalogRepository` existed and both implementations worked; the screens that
// *browse* still read `mockProducts` directly, so a live build booted, signed in
// and rendered the eight mock products while the server held thirteen real ones.
// Mock mode hid it completely — the mock repository returns that same list — so
// every fixture here is a catalogue that shares nothing with the mock file. That
// is the only kind of fixture that can tell the two apart, and without it these
// tests would pass whether or not the screens were wired up.

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/dto/remote_catalog.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/repositories/catalog_repository.dart';
import 'package:zefanya/state/catalog_state.dart';

/// Built through the wire mapper rather than a `Product(...)` literal, so it is
/// exactly what a live server's row becomes.
final Product _bodyOil = productFromJson({
  'id': 'l14',
  'name': 'Ilang-Ilang Body Oil',
  'category': 'Health and Beauty',
  'price_minor': 76000,
  'in_stock': true,
  'seller': {'name': 'Alon Botanicals'},
  'description': 'Ilang-ilang distilled in Bohol, carried in sweet almond oil.',
  'images': ['https://picsum.photos/seed/zef-ilang-ilang-body-oil-0/1000/1000'],
});

final Product _stool = productFromJson({
  'id': 'l4',
  'name': 'Kamagong Stool',
  'category': 'Furniture and Office Equipment',
  'price_minor': 380000,
  'in_stock': true,
  'seller': {'name': 'Loom & Larder'},
  'description': 'Solid kamagong with mortise-and-tenon joinery.',
  'images': const <String>[],
});

/// A catalogue that shares nothing with `mockProducts`.
class _ForeignCatalog implements CatalogRepository {
  _ForeignCatalog({this.failure});

  final ApiException? failure;

  List<Product> get _all => [_bodyOil, _stool];

  @override
  Future<List<Product>> products({CatalogQuery? query}) async {
    if (failure != null) throw failure!;

    return _all;
  }

  @override
  Future<List<Product>> productsInCategory(String category) async {
    if (failure != null) throw failure!;

    return [for (final product in _all) if (product.category == category) product];
  }

  @override
  Future<Product?> productById(String id) async {
    if (failure != null) throw failure!;

    for (final product in _all) {
      if (product.id == id) return product;
    }

    return null;
  }

  @override
  Future<List<String>> categories() async {
    if (failure != null) throw failure!;

    return const ['All', 'Health and Beauty', 'Furniture and Office Equipment'];
  }

  @override
  Future<String?> makerNote(String sellerName) async => null;

  @override
  Future<List<PromoSlide>> promoSlides() async => const [];
}

void main() {
  test('the fixture really is foreign to the mock catalogue', () {
    // Guard the guard: if this stops holding, every test below stops proving
    // anything at all, and would pass in mock mode by accident.
    expect(mockProducts.any((product) => product.id == _bodyOil.id), isFalse);
    expect(mockProducts.any((product) => product.name == _bodyOil.name), isFalse);
    expect(mockProducts.any((product) => product.name == _stool.name), isFalse);
  });

  test('load() takes the catalogue from the repository', () async {
    final state = CatalogState(repository: _ForeignCatalog());

    await state.load();

    expect(state.products.map((product) => product.name), ['Ilang-Ilang Body Oil', 'Kamagong Stool']);
    expect(state.categories, contains('All'));
    expect(state.loadFailure, isNull);
  });

  test('a failure is recorded rather than thrown, and the catalogue stays empty', () async {
    final state = CatalogState(repository: _ForeignCatalog(failure: const NetworkException()));

    // Never thrown: `main()` awaits this before `runApp` (see `StartupState`), and
    // the browsing screens are where the failure belongs.
    await state.load();

    expect(state.products, isEmpty);
    expect(state.loadFailure, isA<NetworkException>());
  });

  test("'All' is the app's own no-filter chip, not a category row", () async {
    final state = CatalogState(repository: _ForeignCatalog());
    await state.load();

    // Matching it as a label would return nothing, which is an empty catalogue for
    // tapping the chip that means "everything".
    expect(state.inCategory('All'), hasLength(2));
    expect(state.inCategory('Health and Beauty'), hasLength(1));
    expect(state.inCategory('Nothing Here'), isEmpty);
  });

  test('bySeller narrows to one shop', () async {
    final state = CatalogState(repository: _ForeignCatalog());
    await state.load();

    expect(state.bySeller('Loom & Larder').single.name, 'Kamagong Stool');
    expect(state.bySeller('Nobody'), isEmpty);
  });

  test('search matches name and category, and deliberately not the description', () async {
    final state = CatalogState(repository: _ForeignCatalog());
    await state.load();

    expect(state.search('ilang'), hasLength(1));
    expect(state.search('furniture'), hasLength(1));

    // 'Bohol' is in the description only. Matching descriptions is what the mock's
    // own comment refuses: a two-word query would return nearly everything, which
    // reads as broken rather than thorough — and the server matches name and
    // category only, so the two modes have to agree.
    expect(state.search('Bohol'), isEmpty);
    expect(state.search('   '), isEmpty);
  });
}
