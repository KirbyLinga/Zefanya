import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/screens/buyer/widgets/editorial_hero.dart';
import 'package:zefanya/theme/app_theme.dart';

void main() {
  test('selection prioritizes variety within the supplied categories', () {
    expect(
      EditorialHero.selectCategories([
        'All',
        'Beauty',
        'Fashion',
        'Fashion',
        'Home & Living',
        'Jewelry and Watches',
      ]),
      ['Fashion', 'Home & Living', 'Beauty'],
    );
    expect(EditorialHero.selectCategories(['All', ' ']), isEmpty);
  });

  testWidgets(
    'empty catalogue links to Browse and a single category stays static',
    (tester) async {
      String? opened;
      var browsed = false;
      Future<void> show(List<String> categories) => tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: Scaffold(
            body: EditorialHero(
              categories: categories,
              onCategory: (category) => opened = category,
              onBrowse: () => browsed = true,
            ),
          ),
        ),
      );
      await show([]);
      await tester.tap(find.text('Browse categories'));
      expect(browsed, isTrue);
      await show(['Independent makers']);
      expect(find.byType(PageView), findsNothing);
      expect(find.byIcon(Icons.category_outlined), findsOneWidget);
      await tester.tap(find.text('Explore category'));
      expect(opened, 'Independent makers');
    },
  );

  for (final size in const [Size(360, 640), Size(360, 740), Size(411, 890)]) {
    for (final brightness in Brightness.values) {
      testWidgets(
        'hero swipe and CTA at $size in ${brightness.name}, 2× text',
        (tester) async {
          tester.view.physicalSize = size;
          tester.view.devicePixelRatio = 1;
          addTearDown(tester.view.reset);
          AppColors.apply(brightness);
          addTearDown(() => AppColors.apply(Brightness.light));
          String? opened;
          await tester.pumpWidget(
            MaterialApp(
              theme: brightness == Brightness.dark
                  ? AppTheme.dark
                  : AppTheme.light,
              builder: (context, child) => MediaQuery(
                data: MediaQuery.of(context).copyWith(
                  textScaler: const TextScaler.linear(2),
                  disableAnimations: true,
                  padding: const EdgeInsets.fromLTRB(6, 28, 8, 20),
                ),
                child: child!,
              ),
              home: Scaffold(
                body: SafeArea(
                  child: EditorialHero(
                    categories: const [
                      'Fashion',
                      'Home & Living',
                      'Furniture and Office Equipment',
                    ],
                    onCategory: (category) => opened = category,
                    onBrowse: () {},
                  ),
                ),
              ),
            ),
          );
          await tester.drag(find.byType(PageView), const Offset(-290, 0));
          await tester.pumpAndSettle();
          await tester.tap(find.text('Explore category').hitTestable());
          expect(opened, 'Home & Living');
          await tester.tap(
            find.byTooltip('Feature 3 of 3: Furniture and Office Equipment'),
          );
          await tester.pumpAndSettle();
          expect(
            find.text('Furniture and Office Equipment').hitTestable(),
            findsOneWidget,
          );
          expect(tester.takeException(), isNull);
        },
      );
    }
  }
}
