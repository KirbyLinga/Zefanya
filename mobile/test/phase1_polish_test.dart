// test/phase1_polish_test.dart
//
// Phase 1 regression cover for the accessibility/trust pass:
//
//  * a product with no reviews must not claim a 0.0 rating;
//  * a product with no photo must paint the neutral placeholder, not a
//    stranger's stock photo and not a broken image;
//  * the smallest label roles stay at or above the 12sp floor;
//  * small ink clears AA on the blush/off-white fills it actually sits on.

import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/widgets/product_card.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

double _luminance(Color c) {
  double channel(double v) =>
      v <= 0.03928 ? v / 12.92 : math.pow((v + 0.055) / 1.055, 2.4).toDouble();
  return 0.2126 * channel(c.r) + 0.7152 * channel(c.g) + 0.0722 * channel(c.b);
}

double _contrast(Color a, Color b) {
  final la = _luminance(a), lb = _luminance(b);
  return (math.max(la, lb) + 0.05) / (math.min(la, lb) + 0.05);
}

const Product _unrated = Product(
  id: 'phase1-unrated',
  name: 'Unrated linen dress',
  category: 'Fashion',
  price: 1200,
  sellerName: 'Test atelier',
  rating: 4.5,
  ratingCount: 0,
  soldCount: 0,
);

const Product _soldOnly = Product(
  id: 'phase1-sold-only',
  name: 'Sold but unreviewed',
  category: 'Fashion',
  price: 900,
  sellerName: 'Test atelier',
  rating: 4.5,
  ratingCount: 0,
  soldCount: 12,
);

const Product _rated = Product(
  id: 'phase1-rated',
  name: 'Reviewed linen dress',
  category: 'Fashion',
  price: 1200,
  sellerName: 'Test atelier',
  rating: 4.7,
  ratingCount: 18,
  soldCount: 24,
);

Future<void> _pumpCard(WidgetTester tester, Product product) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    ChangeNotifierProvider(
      create: (_) => WishlistState(),
      child: MaterialApp(
        theme: AppTheme.light,
        home: Scaffold(
          body: Center(
            child: SizedBox(
              width: 180,
              height: 320,
              child: ProductCard(
                product: product,
                heroTag: 'phase1-${product.id}',
                onTap: () {},
              ),
            ),
          ),
        ),
      ),
    ),
  );
  await tester.pump();
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('rating gating', () {
    testWidgets('a product with no reviews hides the star and the average', (
      tester,
    ) async {
      await _pumpCard(tester, _unrated);

      expect(find.byIcon(Icons.star_rounded), findsNothing);
      expect(find.text('0.0'), findsNothing);
      expect(find.textContaining('sold'), findsNothing);
    });

    testWidgets('a sold count can stand alone without inventing a rating', (
      tester,
    ) async {
      await _pumpCard(tester, _soldOnly);

      expect(find.byIcon(Icons.star_rounded), findsNothing);
      expect(find.text('0.0'), findsNothing);
      expect(find.textContaining('12 sold'), findsOneWidget);
    });

    testWidgets('a reviewed product still shows its average and count', (
      tester,
    ) async {
      await _pumpCard(tester, _rated);

      expect(find.byIcon(Icons.star_rounded), findsOneWidget);
      expect(find.text('4.7'), findsOneWidget);
      expect(find.textContaining('24 sold'), findsOneWidget);
    });
  });

  group('neutral placeholder', () {
    testWidgets('a photo-less product card paints the on-brand placeholder', (
      tester,
    ) async {
      await _pumpCard(tester, _unrated);

      expect(_unrated.photos, isEmpty);
      expect(find.byKey(const ValueKey('product-placeholder')), findsOneWidget);
      expect(find.byType(Image), findsNothing);
    });
  });

  group('label floor', () {
    test('the smallest Material label roles are at least 12sp', () {
      final theme = AppTheme.light;
      expect(theme.textTheme.labelSmall?.fontSize, greaterThanOrEqualTo(12.0));
      expect(theme.textTheme.labelMedium?.fontSize, greaterThanOrEqualTo(12.0));
      expect(AppType.eyebrow.fontSize, greaterThanOrEqualTo(12.0));
    });
  });

  group('contrast on blush and off-white fills', () {
    for (final mode in [Brightness.light, Brightness.dark]) {
      final label = mode == Brightness.dark ? 'dark' : 'light';

      test('$label: the search hint ink clears AA on its wash', () {
        AppColors.apply(mode);
        addTearDown(() => AppColors.apply(Brightness.light));

        for (final base in [AppColors.background, AppColors.surface]) {
          final wash = Color.alphaBlend(
            AppColors.primary.withValues(alpha: 0.18),
            base,
          );
          expect(
            _contrast(AppColors.textPrimary, wash),
            greaterThanOrEqualTo(4.5),
            reason: 'search hint on a blush wash over $base ($label)',
          );
        }
      });
    }

    test('light: textSecondary clears AA on blush and off-white fills', () {
      AppColors.apply(Brightness.light);
      addTearDown(() => AppColors.apply(Brightness.light));

      final grounds = <String, Color>{
        'secondary': AppColors.secondary,
        'nav selected pill': AppColors.navSelectedFill,
        'surfaceMuted': AppColors.surfaceMuted,
        '18% blush on background': Color.alphaBlend(
          AppColors.primary.withValues(alpha: 0.18),
          AppColors.background,
        ),
        '50% blush on surface': Color.alphaBlend(
          AppColors.primary.withValues(alpha: 0.50),
          AppColors.surface,
        ),
      };

      grounds.forEach((name, ground) {
        expect(
          _contrast(AppColors.textSecondary, ground),
          greaterThanOrEqualTo(4.5),
          reason: 'textSecondary on $name',
        );
      });
    });
  });
}
