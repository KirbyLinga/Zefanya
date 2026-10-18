// test/search_screen_chips_test.dart
//
// Widget tests for the "Try looking for" chips (Claude Round A):
// entrance end state, tap fills the query, live query reorders and
// dims. Bounded pumps only — never pumpAndSettle (image fades and
// staggered tiles elsewhere never settle).

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/search_screen.dart';
import 'package:zefanya/screens/buyer/widgets/product_card.dart';
import 'package:zefanya/screens/buyer/widgets/try_looking_for.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

void _noop() {}

Future<void> _pumpSearch(WidgetTester tester) async {
  // Prefs first: the screen reads history in initState, and without
  // the mock getInstance() awaits a channel that never answers
  // (the cart swipe deadlock — same trap).
  SharedPreferences.setMockInitialValues({});
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final catalog = CatalogState();
  await catalog.load();

  await tester.pumpWidget(
    MediaQuery(
      data: const MediaQueryData(disableAnimations: true),
      child: MultiProvider(
        providers: [
          ChangeNotifierProvider.value(value: catalog),
          // ProductCard's HeartButton reads this on the results grid.
          ChangeNotifierProvider(create: (_) => WishlistState()),
        ],
        child: MaterialApp(
          theme: AppTheme.light,
          home: const SearchScreen(),
        ),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 100));
}

void main() {
  testWidgets('idle chips render in authored order, fully opaque',
      (tester) async {
    await _pumpSearch(tester);

    // Entrance end state under disabled animations: visible,
    // undisplaced, undimmed.
    expect(find.text('TRY LOOKING FOR'), findsOneWidget);
    for (final term in ['Linen', 'Ceramic', 'Tote Bag', 'Scarf']) {
      expect(find.text(term), findsWidgets);
    }
    final opacities = tester
        .widgetList<AnimatedOpacity>(find.descendant(
          of: find.byType(TryLookingFor),
          matching: find.byType(AnimatedOpacity),
        ))
        .map((w) => w.opacity);
    expect(opacities, everyElement(1.0));
    expect(tester.takeException(), isNull);
  });

  testWidgets('tap fills the query and swaps to results', (tester) async {
    await _pumpSearch(tester);

    await tester.tap(find.text('Linen').first);
    await tester.pump(const Duration(milliseconds: 100));

    // Chip filled the field (crossfade to results — 'Linen' matches
    // the loaded catalogue, so the dead-end view stays away).
    final field = tester.widget<TextField>(find.byType(TextField));
    expect(field.controller!.text, 'Linen');
    expect(find.text('TRY LOOKING FOR'), findsNothing);
    expect(find.textContaining('PIECES'), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  testWidgets('typing to a dead end crossfades, both branches mounted',
      (tester) async {
    // AnimatedSwitcher keeps the outgoing branch mounted for the
    // 220ms fade: mid-transition both the suggestions list and the
    // dead-end view are in the tree. This needs animations ENABLED
    // (the shared harness disables them, which makes the switch
    // instant) — pumped separately here with bounded pumps only.
    SharedPreferences.setMockInitialValues({});
    AppColors.apply(Brightness.light);
    GoogleFonts.config.allowRuntimeFetching = false;
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final catalog = CatalogState();
    await catalog.load();
    await tester.pumpWidget(
      MediaQuery(
        data: const MediaQueryData(),
        child: MultiProvider(
          providers: [
            ChangeNotifierProvider.value(value: catalog),
            ChangeNotifierProvider(create: (_) => WishlistState()),
          ],
          child: MaterialApp(
            theme: AppTheme.light,
            home: const SearchScreen(),
          ),
        ),
      ),
    );
    await tester.pump(const Duration(milliseconds: 100));
    expect(find.byKey(const ValueKey('search-suggestions')), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'zzz');
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.byKey(const ValueKey('search-suggestions')), findsOneWidget);
    expect(find.byKey(const ValueKey('search-no-results')), findsOneWidget);

    await tester.pump(const Duration(milliseconds: 300));
    expect(find.byKey(const ValueKey('search-suggestions')), findsNothing);
    expect(find.byKey(const ValueKey('search-no-results')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('typing dims non-matches without reordering', (tester) async {
    await _pumpSearch(tester);

    // 'zzz' matches nothing in the catalogue → dead-end view, whose
    // retry chips keep authored order and dim everything.
    await tester.enterText(find.byType(TextField), 'zzz');
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.textContaining('No results for'), findsOneWidget);
    // Authored order preserved (no rise-to-front here).
    final row = find.descendant(
      of: find.byType(TryLookingFor),
      matching: find.byType(ActionChip),
    );
    expect(
      tester.widgetList<ActionChip>(row).map((c) => (c.label as Text).data),
      ['Linen', 'Ceramic', 'Tote Bag', 'Scarf', 'Home & Living'],
    );
    final chips = find.descendant(
      of: find.byType(TryLookingFor),
      matching: find.byType(AnimatedOpacity),
    );
    expect(chips, findsNWidgets(5));
    final opacities = tester
        .widgetList<AnimatedOpacity>(chips)
        .map((w) => w.opacity);
    expect(opacities, everyElement(0.6));
  });

  testWidgets('rating row never overflows a 150dp tile at 1.5x scale',
      (tester) async {
    // Narrow tile + large text: the sold-count text flexes with
    // ellipsis instead of striping (product_card rating row).
    AppColors.apply(Brightness.light);
    GoogleFonts.config.allowRuntimeFetching = false;
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MediaQuery(
        data: const MediaQueryData(
          disableAnimations: true,
          textScaler: TextScaler.linear(1.5),
        ),
        child: MultiProvider(
          providers: [
            ChangeNotifierProvider(create: (_) => WishlistState()),
          ],
          child: MaterialApp(
            theme: AppTheme.light,
            home: Scaffold(
              body: Center(
                child: SizedBox(
                  width: 150,
                  child: ProductCard(
                    product: const Product(
                      id: 'overflow-probe',
                      name: 'Probe Product With A Long Name',
                      category: 'Fashion',
                      price: 1299,
                      sellerName: 'Probe Shop',
                      rating: 4.8,
                      ratingCount: 120,
                      soldCount: 12500,
                    ),
                    index: 0,
                    heroTag: 'probe',
                    onTap: _noop,
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('Probe Product With A Long Name'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
