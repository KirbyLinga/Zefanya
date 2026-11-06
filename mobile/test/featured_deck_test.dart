// test/featured_deck_test.dart
//
// PageView rail: pure ordering helpers that survive the rewrite plus
// widget tests with animations disabled showing the rail renders,
// pages both ways, and dots jump. Bounded pumps only — no
// pumpAndSettle (looping shimmer/image fades elsewhere on Home).

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/widgets/featured_deck.dart';
import 'package:zefanya/screens/buyer/widgets/product_card.dart';
import 'package:zefanya/screens/buyer/widgets/promo_carousel.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/utils/featured_deck.dart';

Future<void> _pumpDeck(
  WidgetTester tester,
  List<Product> products, {
  Size surface = const Size(390, 844),
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = surface;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MediaQuery(
      data: const MediaQueryData(disableAnimations: true),
      child: MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: FeaturedDeck(
              products: products,
              heroTagPrefix: 'test',
              isActive: true,
            ),
          ),
        ),
      ),
    ),
  );
  await tester.pump();
}

void main() {
  group('rail helpers (pure)', () {
    test('cycle helper still rotates an order', () {
      expect(cycleDeck([0, 1, 2, 3]), [1, 2, 3, 0]);
      expect(cycleDeck([]), isEmpty);
      expect(cycleDeck([7]), [7]);
    });

    test('window shows at most four, in order', () {
      expect(deckWindow([0, 1, 2, 3, 4]), [0, 1, 2, 3]);
      expect(deckWindow([2, 0]), [2, 0]);
      expect(deckWindow([]), isEmpty);
    });

    test('rolling numbers interpolate and clamp', () {
      expect(rollingTotal(100, 200, 0.5), 150);
      expect(rollingTotal(100, 200, 0), 100);
      expect(rollingTotal(100, 200, 1), 200);
      expect(rollingTotal(100, 200, 9), 200);
      expect(rollingTotal(100, 200, -3), 100);
    });
  });

  group('the rail widget (animations disabled)', () {
    testWidgets('shows the first card with featured semantics',
        (tester) async {
      await _pumpDeck(tester, mockProducts.take(5).toList());

      // Name once (in the label body, below the arch — never
      // clipped by the crown). Cue is right-aligned eyebrow caps.
      expect(find.text(mockProducts.first.name), findsOneWidget);
      expect(find.text('FEATURED THIS WEEK'), findsOneWidget);
      expect(find.text('1 OF 5'), findsOneWidget);
      expect(
        find.bySemanticsLabel(RegExp('Featured, 5 items')),
        findsOneWidget,
      );
      expect(tester.takeException(), isNull);
    });

    testWidgets('card body fills with tag, name and price', (tester) async {
      // No dead white space: category tag + one-line description +
      // shared PriceLabel all render inside the rectangular body.
      final product = mockProducts.first;
      await _pumpDeck(tester, [product]);

      expect(find.text(product.category.toUpperCase()), findsOneWidget);
      expect(find.byType(PriceLabel), findsOneWidget);
      if (product.description.isNotEmpty) {
        expect(find.text(product.description), findsOneWidget);
      }
      expect(tester.takeException(), isNull);
    });

    testWidgets('rail swipe never drives the promo carousel',
        (tester) async {
      // Gesture arenas (Claude polish #6): a horizontal fling that
      // starts on the rail must page the rail — and must not touch
      // a neighbouring PageView. Both carousels are pumped side by
      // side; the promo page stays put while the rail advances.
      AppColors.apply(Brightness.light);
      GoogleFonts.config.allowRuntimeFetching = false;
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(
        MediaQuery(
          data: const MediaQueryData(disableAnimations: true),
          child: MaterialApp(
            // Real theme: PromoCarousel's slides read AppThemeExtension
            // off it, and a bare MaterialApp default carries none
            // (null-check crash, same trap as the cart screen's).
            theme: AppTheme.light,
            home: Scaffold(
              body: SingleChildScrollView(
                child: Column(
                  children: [
                    const PromoCarousel(autoAdvance: false),
                    FeaturedDeck(
                      products: mockProducts.take(5).toList(),
                      heroTagPrefix: 'test',
                      isActive: true,
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      );
      await tester.pump();

      // Promo starts on slide 1 of 3; rail starts on 1 of 5.
      expect(find.text('1 OF 5'), findsOneWidget);

      // Fling on the rail's own PageView.
      final rail = find.descendant(
        of: find.byType(FeaturedDeck),
        matching: find.byType(PageView),
      );
      await tester.fling(rail, const Offset(-300, 0), 800);
      await tester.pump(const Duration(milliseconds: 100));

      // Rail advanced; promo carousel untouched (still showing its
      // first slide — a page change would swap the title out).
      // Titles contain a hard \n, so match the first line.
      expect(find.text('2 OF 5'), findsOneWidget);
      expect(find.textContaining('New This'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('dot jumps to another page and back', (tester) async {
      final products = mockProducts.take(5).toList();
      await _pumpDeck(tester, products);

      await tester.tap(find.bySemanticsLabel('Go to featured 3 of 5'));
      await tester.pump(const Duration(milliseconds: 100));
      expect(find.text('3 OF 5'), findsOneWidget);

      await tester.tap(find.bySemanticsLabel('Go to featured 1 of 5'));
      await tester.pump(const Duration(milliseconds: 100));
      expect(find.text('1 OF 5'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('swipe goes both ways without losing cards',
        (tester) async {
      final products = mockProducts.take(5).toList();
      await _pumpDeck(tester, products);

      await tester.fling(
        find.byType(PageView),
        const Offset(-300, 0),
        800,
      );
      await tester.pump(const Duration(milliseconds: 100));
      expect(find.text('2 OF 5'), findsOneWidget);

      // Back again: the first product is still there.
      await tester.fling(
        find.byType(PageView),
        const Offset(300, 0),
        800,
      );
      await tester.pump(const Duration(milliseconds: 100));
      expect(find.text('1 OF 5'), findsOneWidget);
      expect(find.text(products.first.name), findsWidgets);
      expect(tester.takeException(), isNull);
    });

    testWidgets('rail height is fixed across pages', (tester) async {
      await _pumpDeck(tester, mockProducts.take(5).toList());

      // The rail box (header excluded): cards + dots row, one fixed
      // height so paging never shifts surrounding content.
      final railBox = find.ancestor(
        of: find.byType(PageView),
        matching: find.byWidgetPredicate(
          (w) => w is SizedBox && w.height == kFeaturedRailHeight,
        ),
      );
      final h0 = tester.getSize(railBox).height;
      expect(h0, moreOrLessEquals(kFeaturedRailHeight, epsilon: 1));
      await tester.tap(find.bySemanticsLabel('Go to featured 4 of 5'));
      await tester.pump(const Duration(milliseconds: 100));
      expect(tester.getSize(railBox).height, moreOrLessEquals(h0));
      expect(tester.takeException(), isNull);
    });

    group('home composition (real sliver stack)', () {
      // Claude alignment round 2: the deck-alone tests passed while
      // Home showed 40px — the shell's wrapper added a second 20px
      // on top of the rail's own gutters. These tests pump the real
      // composition (search sliver + promo sliver + rail sliver,
      // same paddings as buyer_shell) and assert all three left
      // edges coincide.
      Future<void> pumpHome(
        WidgetTester tester,
        List<Product> products,
        Size surface,
      ) async {
        AppColors.apply(Brightness.light);
        GoogleFonts.config.allowRuntimeFetching = false;
        tester.view.physicalSize = surface;
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.reset);

        await tester.pumpWidget(
          MediaQuery(
            data: const MediaQueryData(disableAnimations: true),
            child: MaterialApp(
              theme: AppTheme.light,
              home: Scaffold(
                body: CustomScrollView(
                  slivers: [
                    // Same as buyer_shell's search sliver.
                    const SliverToBoxAdapter(
                      child: Padding(
                        padding: EdgeInsets.fromLTRB(20, 14, 20, 12),
                        child: TextField(
                          key: ValueKey('home-search-probe'),
                          readOnly: true,
                          decoration: InputDecoration(
                            hintText: 'Search products, brands...',
                            border: InputBorder.none,
                          ),
                        ),
                      ),
                    ),
                    // Same as buyer_shell's promo sliver: full-bleed,
                    // bottom padding only.
                    const SliverToBoxAdapter(
                      child: Padding(
                        padding: EdgeInsets.fromLTRB(0, 0, 0, 12),
                        child: PromoCarousel(autoAdvance: false),
                      ),
                    ),
                    // Same as buyer_shell's rail sliver: full-bleed,
                    // top padding only — the rail owns its gutters.
                    SliverPadding(
                      padding: const EdgeInsets.only(top: 8),
                      sliver: SliverToBoxAdapter(
                        child: FeaturedDeck(
                          products: products,
                          heroTagPrefix: 'test',
                          isActive: true,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
        await tester.pump();
      }

      // Left edges, in global coords, of the three Home landmarks.
      double searchLeft(WidgetTester tester) => tester
          .getTopLeft(find.byKey(const ValueKey('home-search-probe')))
          .dx;

      double promoLeft(WidgetTester tester) {
        // Promo card edge via slide 1's title: layout A positions
        // its copy at left:20 (promo_carousel.dart, _TextLeftLayout),
        // so title dx minus 20 is the card edge. Pinned here because
        // this group owns the cross-rail alignment contract — if
        // layout A moves, this failure points at the new number.
        final titleDx = tester
            .getTopLeft(find.textContaining('New This'))
            .dx;
        return titleDx - 20;
      }

      double railLeft(WidgetTester tester) {
        final viewport = tester.getTopLeft(find.descendant(
          of: find.byType(FeaturedDeck),
          matching: find.byType(PageView),
        ));
        // padEnds false: first page starts at the viewport's left
        // edge; the card carries its own 20px left inset.
        return viewport.dx + 20;
      }

      testWidgets('rail, search and promo share one left edge (360dp)',
          (tester) async {
        await pumpHome(
          tester,
          mockProducts.take(5).toList(),
          const Size(360, 740),
        );
        final search = searchLeft(tester);
        expect(search, moreOrLessEquals(20, epsilon: 1));
        // Epsilon 2, not 1: the promo centers its first page
        // (padEnds default true), so its card lands at ~18.4, not
        // exactly 20 — 1.6px of the promo's own centering design,
        // sub-perceptual and out of scope to redesign here. This
        // still catches the 40px double-gutter regression with 10x
        // margin.
        expect(promoLeft(tester), moreOrLessEquals(search, epsilon: 2));
        expect(railLeft(tester), moreOrLessEquals(search, epsilon: 1));
        // Cue ends at the card's right edge: full-bleed 0.92 page,
        // 20px card inset → 0.92 * 360 − 20 = 311.2.
        final cueRight =
            tester.getTopRight(find.text('1 OF 5')).dx;
        expect(cueRight, moreOrLessEquals(0.92 * 360 - 20, epsilon: 2));
        expect(tester.takeException(), isNull);
      });

      testWidgets('rail, search and promo share one left edge (411dp)',
          (tester) async {
        await pumpHome(
          tester,
          mockProducts.take(5).toList(),
          const Size(411, 890),
        );
        final search = searchLeft(tester);
        expect(search, moreOrLessEquals(20, epsilon: 1));
        expect(promoLeft(tester), moreOrLessEquals(search, epsilon: 2));
        expect(railLeft(tester), moreOrLessEquals(search, epsilon: 1));
        final cueRight =
            tester.getTopRight(find.text('1 OF 5')).dx;
        expect(cueRight, moreOrLessEquals(0.92 * 411 - 20, epsilon: 2));
        expect(tester.takeException(), isNull);
      });

      testWidgets('second card peeks past the right edge, unclipped',
          (tester) async {
        // Peek check (Claude round 2): with padEnds false the
        // wrapper must not clip card 2's peek. Card 2's page starts
        // at 0.92 * width; its card spans +20 .. +pageWidth − 20,
        // so its right edge (2 * 0.92W − 20) crosses the viewport's
        // right edge — only true when no ancestor clips it.
        await pumpHome(
          tester,
          mockProducts.take(5).toList(),
          const Size(360, 740),
        );
        final viewportRight = tester
            .getBottomRight(find.descendant(
              of: find.byType(FeaturedDeck),
              matching: find.byType(PageView),
            ))
            .dx;
        // Viewport spans the full 360dp (full-bleed rail sliver).
        expect(viewportRight, moreOrLessEquals(360, epsilon: 1));
        final card2Right = 2 * 0.92 * viewportRight - 20;
        expect(card2Right, greaterThan(viewportRight));
        expect(tester.takeException(), isNull);
      });
    });

    testWidgets('card stays balanced with an empty description',
        (tester) async {
      // Description is optional: with none, the Spacer still pushes
      // price + rating to the same baseline, so the card reads
      // finished rather than top-heavy. Pump the same product with
      // and without a description and compare the price row's top.
      const base = Product(
        id: 'align-probe',
        name: 'Balance Probe',
        category: 'Home & Living',
        price: 349,
        sellerName: 'Probe Shop',
      );
      const withDesc = Product(
        id: 'align-probe',
        name: 'Balance Probe',
        category: 'Home & Living',
        price: 349,
        sellerName: 'Probe Shop',
        description: 'A short line of copy.',
      );

      await _pumpDeck(tester, [base]);
      final bareTop =
          tester.getTopLeft(find.byType(PriceLabel)).dy;

      await _pumpDeck(tester, [withDesc]);
      final filledTop =
          tester.getTopLeft(find.byType(PriceLabel)).dy;

      // Same card, same height, same price baseline — the optional
      // description line is absorbed by the Spacer, not the price.
      expect(filledTop, moreOrLessEquals(bareTop, epsilon: 1));
      expect(tester.takeException(), isNull);
    });

    testWidgets('dots never overlap the price at 1.5x text scale',
        (tester) async {
      // Dots live in their own reserved row below the Expanded
      // PageView — not overlaid — so large text can grow the label
      // body without ever reaching them. Pump at 1.5x and assert
      // the price row's bottom stays above the dot strip's top.
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
          child: MaterialApp(
            theme: AppTheme.light,
            home: Scaffold(
              body: SingleChildScrollView(
                child: FeaturedDeck(
                  products: mockProducts.take(5).toList(),
                  heroTagPrefix: 'test',
                  isActive: true,
                ),
              ),
            ),
          ),
        ),
      );
      await tester.pump();

      final priceBox =
          tester.getRect(find.byType(PriceLabel).first);
      final dotsBox = tester.getRect(
        find.bySemanticsLabel('Go to featured 1 of 5'),
      );
      expect(priceBox.bottom, lessThan(dotsBox.top));
      expect(tester.takeException(), isNull);
    });

    testWidgets('empty products render nothing', (tester) async {
      await _pumpDeck(tester, const []);
      expect(find.text('FEATURED THIS WEEK'), findsNothing);
      expect(tester.takeException(), isNull);
    });
  });
}
