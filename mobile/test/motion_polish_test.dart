// test/motion_polish_test.dart
//
// Phase 3 motion cover: the component-level flourishes that make the app
// feel alive without overriding the OS reduced-motion setting.
//
// The hero transition, skeleton shimmer, staggered entrances and success
// flourish existed before this pass. These tests pin the pieces that were
// added or hardened (heart burst, badge overshoot, empty-state entrance,
// nav selection) and prove the two paths that are easy to regress: the
// cart-flight overlay and the shared hero tag.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/product_details_screen.dart';
import 'package:zefanya/screens/buyer/widgets/product_card.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/widgets/cart_button.dart';
import 'package:zefanya/widgets/cart_flight.dart';
import 'package:zefanya/widgets/empty_state.dart';
import 'package:zefanya/widgets/floating_nav_bar.dart';
import 'package:zefanya/widgets/heart_button.dart';
import 'package:zefanya/widgets/motion/ease_in_frame.dart';
import 'package:zefanya/widgets/shimmer_box.dart';

const Product _product = Product(
  id: 'motion-p1',
  name: 'Linen Wrap Dress',
  category: 'Fashion',
  price: 1250,
  sellerName: 'Willow & Bloom Atelier',
  stock: 5,
  rating: 4.8,
  ratingCount: 12,
  soldCount: 20,
);

Widget _app(Widget home) => MaterialApp(theme: AppTheme.light, home: home);

Widget _reducedMotionApp(Widget home) => _app(
  MediaQuery(data: const MediaQueryData(disableAnimations: true), child: home),
);

Widget _detailsProviders(Widget child) => MultiProvider(
  providers: [
    ChangeNotifierProvider(create: (_) => CartState()),
    ChangeNotifierProvider(create: (_) => WishlistState()),
    ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
    ChangeNotifierProvider(create: (_) => ReviewState()),
    ChangeNotifierProvider(create: (_) => OrderState()),
    ChangeNotifierProvider(create: (_) => CourierState()),
    ChangeNotifierProvider(create: (_) => CatalogState()),
  ],
  child: child,
);

class _FlightHarness extends StatelessWidget {
  const _FlightHarness({required this.originKey});

  final GlobalKey originKey;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Stack(
        children: [
          Positioned(
            left: 32,
            top: 48,
            child: SizedBox(
              key: originKey,
              width: 48,
              height: 48,
              child: const ColoredBox(color: Colors.pink),
            ),
          ),
          const Positioned(
            right: 24,
            top: 24,
            child: CartAnchor(child: Icon(Icons.shopping_bag_outlined)),
          ),
          Center(
            child: Builder(
              builder: (context) => ElevatedButton(
                onPressed: () => CartFlight.send(
                  context,
                  originKey: originKey,
                  thumbnail: const SizedBox(
                    width: 48,
                    height: 48,
                    child: ColoredBox(color: Colors.teal),
                  ),
                ),
                child: const Text('Fly'),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

void main() {
  setUp(() {
    AppColors.apply(Brightness.light);
    GoogleFonts.config.allowRuntimeFetching = false;
    SharedPreferences.setMockInitialValues({});
  });

  tearDown(() {
    AppColors.apply(Brightness.light);
  });

  group('wishlist heart', () {
    testWidgets('saving pops the heart and fires a burst', (tester) async {
      final wishlist = WishlistState();

      await tester.pumpWidget(
        ChangeNotifierProvider.value(
          value: wishlist,
          child: _app(
            Scaffold(
              body: Center(child: HeartButton(product: _product)),
            ),
          ),
        ),
      );
      await tester.pump();

      expect(find.byIcon(Icons.favorite_border), findsOneWidget);
      expect(find.byKey(const ValueKey('heart-burst')), findsNothing);

      await tester.tap(find.byType(HeartButton));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 60));

      expect(find.byIcon(Icons.favorite), findsOneWidget);
      expect(wishlist.isSaved(_product.id), isTrue);
      expect(find.byKey(const ValueKey('heart-burst')), findsOneWidget);

      await tester.pump(const Duration(milliseconds: 500));
      expect(find.byKey(const ValueKey('heart-burst')), findsNothing);
      await tester.pumpWidget(const SizedBox.shrink());
    });

    testWidgets('reduced motion still saves, without the burst', (
      tester,
    ) async {
      final wishlist = WishlistState();

      await tester.pumpWidget(
        ChangeNotifierProvider.value(
          value: wishlist,
          child: _reducedMotionApp(
            Scaffold(
              body: Center(child: HeartButton(product: _product)),
            ),
          ),
        ),
      );
      await tester.pump();

      await tester.tap(find.byType(HeartButton));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 60));

      expect(find.byIcon(Icons.favorite), findsOneWidget);
      expect(wishlist.isSaved(_product.id), isTrue);
      expect(find.byKey(const ValueKey('heart-burst')), findsNothing);
      await tester.pumpWidget(const SizedBox.shrink());
    });
  });

  group('cart badge', () {
    testWidgets('a new count overshoots instead of appearing flat', (
      tester,
    ) async {
      final cart = CartState();

      await tester.pumpWidget(
        ChangeNotifierProvider.value(
          value: cart,
          child: _app(const Scaffold(body: Center(child: CartButton()))),
        ),
      );
      await tester.pump();
      expect(find.text('1'), findsNothing);

      cart.addItem(_product);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 120));

      expect(find.text('1'), findsOneWidget);
      final scale = tester
          .widgetList<ScaleTransition>(
            find.ancestor(
              of: find.text('1'),
              matching: find.byType(ScaleTransition),
            ),
          )
          .first;
      expect(scale.scale.value, greaterThan(1.0));

      await tester.pump(const Duration(milliseconds: 400));
      await tester.pumpWidget(const SizedBox.shrink());
    });

    testWidgets('reduced motion shows the badge with no bounce', (
      tester,
    ) async {
      final cart = CartState();

      await tester.pumpWidget(
        ChangeNotifierProvider.value(
          value: cart,
          child: _reducedMotionApp(
            const Scaffold(body: Center(child: CartButton())),
          ),
        ),
      );
      await tester.pump();

      cart.addItem(_product);
      await tester.pump();
      await tester.pump();

      expect(find.text('1'), findsOneWidget);
      expect(tester.binding.transientCallbackCount, 0);
      await tester.pumpWidget(const SizedBox.shrink());
    });
  });

  group('empty state', () {
    testWidgets('uses the canonical entrance and settles in place', (
      tester,
    ) async {
      await tester.pumpWidget(
        _app(
          const Scaffold(
            body: EmptyState(
              icon: Icons.shopping_bag_outlined,
              title: 'Your cart is empty',
            ),
          ),
        ),
      );
      await tester.pump();

      expect(find.byType(EaseInFrame), findsOneWidget);
      expect(find.text('Your cart is empty'), findsOneWidget);

      await tester.pump(const Duration(milliseconds: 400));
      expect(find.text('Your cart is empty'), findsOneWidget);
      await tester.pumpWidget(const SizedBox.shrink());
    });

    testWidgets('reduced motion renders the settled state immediately', (
      tester,
    ) async {
      await tester.pumpWidget(
        _reducedMotionApp(
          const Scaffold(
            body: EmptyState(
              icon: Icons.shopping_bag_outlined,
              title: 'Your cart is empty',
            ),
          ),
        ),
      );
      await tester.pump();

      expect(find.text('Your cart is empty'), findsOneWidget);
      expect(tester.binding.transientCallbackCount, 0);
      await tester.pumpWidget(const SizedBox.shrink());
    });
  });

  group('shimmer skeleton', () {
    testWidgets('loops while loading', (tester) async {
      await tester.pumpWidget(
        _app(
          const Scaffold(
            body: Center(
              child: ShimmerBox(
                child: SizedBox(
                  width: 120,
                  height: 40,
                  child: ColoredBox(color: Colors.grey),
                ),
              ),
            ),
          ),
        ),
      );
      await tester.pump();

      expect(tester.binding.transientCallbackCount, greaterThan(0));
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
      expect(tester.binding.transientCallbackCount, 0);
    });

    testWidgets('stays still under reduced motion', (tester) async {
      await tester.pumpWidget(
        _reducedMotionApp(
          const Scaffold(
            body: Center(
              child: ShimmerBox(
                child: SizedBox(
                  width: 120,
                  height: 40,
                  child: ColoredBox(color: Colors.grey),
                ),
              ),
            ),
          ),
        ),
      );
      await tester.pump();

      expect(tester.binding.transientCallbackCount, 0);
      await tester.pumpWidget(const SizedBox.shrink());
    });
  });

  group('bottom navigation', () {
    testWidgets('tapping changes selection and emits a selection haptic', (
      tester,
    ) async {
      final haptics = <MethodCall>[];
      final messenger =
          TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
      messenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
        if (call.method == 'HapticFeedback.vibrate') haptics.add(call);
        return null;
      });
      addTearDown(
        () => messenger.setMockMethodCallHandler(SystemChannels.platform, null),
      );

      var index = 0;
      await tester.pumpWidget(
        _app(
          StatefulBuilder(
            builder: (context, setState) => Scaffold(
              body: Center(child: Text('tab $index')),
              bottomNavigationBar: FloatingNavBar(
                items: const [
                  NavBarItem(
                    icon: Icons.home_outlined,
                    activeIcon: Icons.home,
                    label: 'Home',
                  ),
                  NavBarItem(
                    icon: Icons.search,
                    activeIcon: Icons.search,
                    label: 'Search',
                  ),
                ],
                currentIndex: index,
                onTap: (i) => setState(() => index = i),
              ),
            ),
          ),
        ),
      );
      await tester.pump();

      await tester.tap(find.text('Search'));
      await tester.pumpAndSettle();

      expect(index, 1);
      expect(find.text('tab 1'), findsOneWidget);
      expect(haptics, isNotEmpty);
      expect(
        haptics.any(
          (call) => call.arguments == 'HapticFeedbackType.selectionClick',
        ),
        isTrue,
      );
    });
  });

  group('cart flight', () {
    testWidgets('arcs over the route and lands, then removes the overlay', (
      tester,
    ) async {
      final originKey = GlobalKey();

      await tester.pumpWidget(_app(_FlightHarness(originKey: originKey)));
      await tester.pump();

      await tester.tap(find.text('Fly'));
      await tester.pump();

      expect(find.byKey(const ValueKey('cart-flight-layer')), findsOneWidget);

      await tester.pump(const Duration(milliseconds: 700));
      await tester.pump();
      expect(find.byKey(const ValueKey('cart-flight-layer')), findsNothing);

      // Let the anchor's catch pop finish so the test leaves no ticker behind.
      await tester.pump(const Duration(milliseconds: 500));
      await tester.pumpWidget(const SizedBox.shrink());
    });

    testWidgets('reduced motion sends no flight at all', (tester) async {
      final originKey = GlobalKey();

      await tester.pumpWidget(
        _reducedMotionApp(_FlightHarness(originKey: originKey)),
      );
      await tester.pump();

      await tester.tap(find.text('Fly'));
      await tester.pump();

      expect(find.byKey(const ValueKey('cart-flight-layer')), findsNothing);
      await tester.pumpWidget(const SizedBox.shrink());
    });
  });

  testWidgets('product card and details share the same hero tag', (
    tester,
  ) async {
    const tag = 'motion-hero';

    await tester.pumpWidget(
      ChangeNotifierProvider(
        create: (_) => WishlistState(),
        child: _app(
          Scaffold(
            body: Center(
              child: SizedBox(
                width: 180,
                height: 320,
                child: ProductCard(
                  product: _product,
                  heroTag: tag,
                  onTap: () {},
                ),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pump();

    final cardHero = tester.widget<Hero>(find.byType(Hero));
    expect(cardHero.tag, tag);

    await tester.pumpWidget(
      _detailsProviders(
        _app(ProductDetailsScreen(product: _product, heroTag: tag)),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));

    final detailHero = tester.widget<Hero>(find.byType(Hero).first);
    expect(detailHero.tag, tag);
    await tester.pumpWidget(const SizedBox.shrink());
  });
}
