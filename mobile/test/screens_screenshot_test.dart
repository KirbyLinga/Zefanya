// test/screens_screenshot_test.dart
//
// Baseline and verification screenshot generator for Zefanya.
// Uses Flutter golden file mechanism to render pixel-accurate screenshots
// at 360x740 and 411x890, in Light and Dark themes.
//
// Never uses pumpAndSettle (bounded pumps only).

import 'dart:io';
import 'dart:ui' show ImageByteFormat;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/config/dev_flags.dart' show kUseLiveApi;

import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/dto/remote_courier.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/main.dart';
import 'package:zefanya/screens/auth/login_screen.dart';
import 'package:zefanya/screens/buyer/buyer_shell.dart';
import 'package:zefanya/screens/buyer/cart_screen.dart';
import 'package:zefanya/screens/buyer/categories_screen.dart';
import 'package:zefanya/screens/buyer/courier_tracker_screen.dart';
import 'package:zefanya/screens/buyer/orders_status_screen.dart';
import 'package:zefanya/screens/buyer/product_details_screen.dart';
import 'package:zefanya/screens/buyer/search_screen.dart';
import 'package:zefanya/screens/buyer/widgets/product_card.dart';
import 'package:zefanya/screens/courier/courier_shell.dart';
import 'package:zefanya/screens/courier/courier_profit_screen.dart';
import 'package:zefanya/screens/courier/courier_qr_scanner.dart';
import 'package:zefanya/screens/courier/delivery_tracker_screen.dart';
import 'package:zefanya/screens/courier/rider_history_screen.dart';
import 'package:zefanya/screens/landing_screen.dart';
import 'package:zefanya/screens/onboarding_screen.dart';
import 'package:zefanya/screens/shared/profile_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/chat_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/notification_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

const _order = Order(
  id: 'ORD-55555',
  shopName: 'Zefanya Studio',
  status: OrderStatus.inTransit,
  eta: 'Today, 4–6 PM',
  itemSummary: 'Linen Blend Wrap Dress',
);

// Optional review exports use the real icon font without changing the font
// assumptions of the legacy golden comparisons.
const _buyerPreviewDirectory = String.fromEnvironment('BUYER_PREVIEW_DIR');

// These screens expose different controls in Live API builds. Other screens
// share the same visual contract across both build modes.
const _modeSpecificGoldens = {
  'buyer_hero_second',
  'buyer_hero_third',
  'buyer_home',
  'cart',
  'login',
  'product_detail',
  'rider_phase3_details',
};

CourierState _dispatchedCourier() {
  final courier = CourierState();
  courier.dispatchForOrder(
    order: _order,
    buyerName: 'Ana',
    deliveryArea: 'Makati City',
    fee: 120,
  );
  final jobId = courier.jobForOrder(_order.id)!.id;
  courier.advanceMilestone(
    jobId,
    DeliveryMilestone.assignedToRider,
    courierName: 'Rico Delos Santos',
  );
  return courier;
}

class _RiderVisualState extends CourierState {
  _RiderVisualState(this.stage, {required bool large, this.finance = false})
    : sample = DeliveryJob(
        id: 'REQ-0427',
        shopName: large
            ? 'Willow and Bloom Independent Local Design Studio'
            : 'Willow & Bloom Atelier',
        pickupAddress: '42 Mabini Street, Barangay Poblacion, Pila, Laguna',
        buyerName: large
            ? 'Maria Teresa de los Santos Villanueva'
            : 'Maria Santos',
        deliveryArea: '27 Santan Street, Barangay San Antonio, Pila, Laguna',
        packageSize: '2 sealed boxes',
        parcelCount: 2,
        trackingNumber: 'ZEF-0427-01',
        fee: 85,
        codAmount: large ? 123456.78 : 1280,
      ),
      super(clock: () => DateTime(2026, 1, 2, 14, 30)) {
    if (finance) {
      dispatchForOrder(
        order: _order,
        buyerName: sample.buyerName,
        deliveryArea: sample.deliveryArea,
        fee: sample.fee,
        trackingNumber: sample.trackingNumber,
        codAmount: sample.codAmount,
      );
      completeJob(jobForOrder(_order.id)!.id);
    }
  }

  final DeliveryJob sample;
  final DeliveryMilestone stage;
  final bool finance;

  @override
  List<AssignedJob> get jobsForPickup => stage == DeliveryMilestone.accepted
      ? [AssignedJob(sample, stage)]
      : const [];

  @override
  List<AssignedJob> get jobsForDelivery => stage == DeliveryMilestone.accepted
      ? const []
      : [AssignedJob(sample, stage)];

  @override
  DeliveryJob? jobById(String id) =>
      id == sample.id ? sample : super.jobById(id);

  @override
  DeliveryMilestone milestoneFor(String id) =>
      id == sample.id ? stage : super.milestoneFor(id);

  @override
  bool get hasServerRecords => true;

  @override
  RiderCoverage get coverage => const RiderCoverage(
    stationName: 'Pila Station Hub',
    stationMunicipality: 'Pila',
  );

  @override
  List<RiderHistoryEntry> get history => [
    RiderHistoryEntry(
      sellerOrderId: '427',
      status: 'DELIVERED',
      statusLabel: 'Delivered',
      trackingNumber: sample.trackingNumber,
      sellerName: sample.shopName,
      earning: sample.fee,
      deliveredAt: DateTime(2020, 1, 2),
      proofId: '427',
    ),
  ];

  @override
  Future<void> refreshHistory() async {}
}

Future<void> _renderAndCapture(
  WidgetTester tester, {
  required String name,
  required Widget Function() builder,
  required Size size,
  required Brightness brightness,
  Duration advanceTime = const Duration(milliseconds: 600),
  bool disableAnimations = false,
  Future<void> Function(WidgetTester)? interact,
  double textScale = 1,
  bool verifyOnly = false,
  CourierState? riderFixture,
}) async {
  AppColors.apply(brightness);
  GoogleFonts.config.allowRuntimeFetching = false;

  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final repositories = Repositories.mock();
  final authState = AuthState(repository: repositories.auth);
  if (riderFixture != null) {
    authState
      ..role = UserRole.courier
      ..status = AccountStatus.active
      ..name = 'Rico Delos Santos'
      ..riderCompany = 'Bilis Hatid Express'
      ..riderVehicleType = 'Motorcycle'
      ..riderPlate = '123ABC';
  }
  final cartState = CartState(
    catalog: repositories.catalog,
    vouchers: repositories.cart,
  );
  cartState.addItem(mockProducts.first);
  if (mockProducts.length > 1) {
    cartState.addItem(mockProducts[1]);
  }
  final catalogState = CatalogState(repository: repositories.catalog);
  if (name.startsWith('buyer_') || name.startsWith('search')) {
    await catalogState.load();
  }
  final courierState = riderFixture ?? _dispatchedCourier();
  final notificationState = NotificationState();
  final orderState = OrderState(repository: repositories.order);
  final chatState = ChatState(repository: repositories.chat);
  final reviewState = ReviewState(repository: repositories.review);
  final wishlistState = WishlistState(catalog: repositories.catalog);
  final themeState = ThemeState();
  themeState.setMode(
    brightness == Brightness.dark ? ThemeMode.dark : ThemeMode.light,
  );
  final recentlyViewedState = RecentlyViewedState(
    catalog: repositories.catalog,
  );

  final testKey = GlobalKey();

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        Provider<Repositories>.value(value: repositories),
        ChangeNotifierProvider<AuthState>.value(value: authState),
        ChangeNotifierProvider<CartState>.value(value: cartState),
        ChangeNotifierProvider<CatalogState>.value(value: catalogState),
        ChangeNotifierProvider<CourierState>.value(value: courierState),
        ChangeNotifierProvider<NotificationState>.value(
          value: notificationState,
        ),
        ChangeNotifierProvider<OrderState>.value(value: orderState),
        ChangeNotifierProvider<ChatState>.value(value: chatState),
        ChangeNotifierProvider<ReviewState>.value(value: reviewState),
        ChangeNotifierProvider<WishlistState>.value(value: wishlistState),
        ChangeNotifierProvider<ThemeState>.value(value: themeState),
        ChangeNotifierProvider<RecentlyViewedState>.value(
          value: recentlyViewedState,
        ),
      ],
      child: MaterialApp(
        theme: brightness == Brightness.dark ? AppTheme.dark : AppTheme.light,
        home: MediaQuery(
          data: MediaQueryData(
            size: size,
            disableAnimations: disableAnimations,
            textScaler: TextScaler.linear(textScale),
            padding: verifyOnly
                ? const EdgeInsets.fromLTRB(8, 28, 10, 22)
                : name.startsWith('landing_') || name.startsWith('onboarding_')
                ? const EdgeInsets.only(top: 24, bottom: 16)
                : EdgeInsets.zero,
          ),
          child: RepaintBoundary(
            key: testKey,
            child: ColoredBox(
              color: AppColors.background,
              child: SizedBox(
                width: size.width,
                height: size.height,
                child: builder(),
              ),
            ),
          ),
        ),
      ),
    ),
  );

  await tester.pump();
  if (kUseLiveApi && name == 'cart') {
    // Start the staggered cart-row tickers before advancing through them.
    await tester.pump(const Duration(milliseconds: 150));
    await tester.pump();
  }
  if (advanceTime > Duration.zero) {
    await tester.pump(advanceTime);
  }
  if (name == 'courier_home') {
    await tester.pump(const Duration(milliseconds: 900));
  }

  if (interact != null) {
    await interact(tester);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));
  }

  if (name.startsWith('landing_') ||
      name.startsWith('buyer_') ||
      name.startsWith('onboarding_')) {
    await tester.runAsync(() async {
      for (final element in find.byType(Image).evaluate()) {
        final image = element.widget as Image;
        if (image.image is AssetBundleImageProvider) {
          await precacheImage(image.image, element);
        }
      }
    });
    await tester.pump();
  }

  final widthInt = size.width.round();
  final heightInt = size.height.round();
  final modeStr = brightness == Brightness.dark ? 'dark' : 'light';
  final folder = kUseLiveApi && _modeSpecificGoldens.contains(name)
      ? 'goldens/live'
      : 'goldens';
  final filename = '$folder/${name}_${widthInt}x${heightInt}_$modeStr.png';

  if (verifyOnly) {
    expect(
      tester.takeException(),
      isNull,
      reason: '$name at ${size.width}×${size.height}, ${brightness.name}',
    );
  } else if (_buyerPreviewDirectory.isNotEmpty) {
    final boundary = tester.renderObject<RenderRepaintBoundary>(
      find.byKey(testKey),
    );
    await tester.runAsync(() async {
      final screenshot = await boundary.toImage();
      try {
        final data = await screenshot.toByteData(format: ImageByteFormat.png);
        final file = File(
          '$_buyerPreviewDirectory/${filename.split('/').last}',
        );
        await file.writeAsBytes(data!.buffer.asUint8List());
      } finally {
        screenshot.dispose();
      }
    });
  } else {
    await expectLater(find.byKey(testKey), matchesGoldenFile(filename));
  }

  // Unmount tree so stateful widgets' dispose() runs and cancels any timers.
  await tester.pumpWidget(const SizedBox.shrink());
  await tester.pump(const Duration(seconds: 5));
}

void main() {
  if (_buyerPreviewDirectory.isNotEmpty) {
    setUpAll(() async {
      await (FontLoader(
        'MaterialIcons',
      )..addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf'))).load();
    });
    for (final size in [
      const Size(360, 640),
      const Size(360, 740),
      const Size(411, 890),
    ]) {
      for (final brightness in Brightness.values) {
        testWidgets('Phase 6 editorial motion at $size in ${brightness.name}', (
          tester,
        ) async {
          await _renderAndCapture(
            tester,
            name: 'landing_phase6_motion',
            size: size,
            brightness: brightness,
            advanceTime: const Duration(seconds: 7),
            builder: () => LandingScreen(
              animateEditorial: true,
              onDone: () {},
              onCreateAccount: () {},
            ),
          );
        });
      }
    }
  }
  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  const sizes = [Size(360, 740), Size(411, 890)];
  const buyerSizes = [Size(360, 640), ...sizes];
  Future<void> selectRiderTab(WidgetTester tester, String name) async {
    await tester.tap(find.text(name).hitTestable().last);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 420));
  }

  const riderScreens = [
    ('pickups', DeliveryMilestone.accepted),
    ('deliveries', DeliveryMilestone.assignedToRider),
    ('assigned', DeliveryMilestone.assignedToRider),
    ('in_progress', DeliveryMilestone.inTransit),
    ('details', DeliveryMilestone.inTransit),
    ('scanner', DeliveryMilestone.arrivedAtSeller),
    ('earnings', DeliveryMilestone.delivered),
    ('transactions', DeliveryMilestone.delivered),
    ('history', DeliveryMilestone.delivered),
    ('profile', DeliveryMilestone.delivered),
  ];
  for (final brightness in Brightness.values) {
    for (final (screen, stage) in riderScreens) {
      Future<void> render(
        WidgetTester tester,
        Size size,
        double scale, {
        required bool verifyOnly,
      }) async {
        final fixture = _RiderVisualState(
          stage,
          large: scale > 1,
          finance: screen == 'earnings' || screen == 'transactions',
        );
        await _renderAndCapture(
          tester,
          name: 'rider_phase3_$screen',
          builder: () => switch (screen) {
            'details' => DeliveryTrackerScreen(jobId: fixture.sample.id),
            'scanner' => CourierQrScanner(
              expectedTrackingNumber: fixture.sample.trackingNumber!,
              riderTaskLabel: 'Scan the seller’s parcel label',
            ),
            'earnings' ||
            'transactions' => CourierProfitScreen(now: DateTime(2026, 1, 2)),
            'history' => const RiderHistoryScreen(),
            _ => const CourierShell(),
          },
          size: size,
          brightness: brightness,
          disableAnimations: true,
          verifyOnly: verifyOnly,
          textScale: scale,
          riderFixture: fixture,
          interact: (tester) async {
            if (screen == 'pickups' && verifyOnly && size.height == 640) {
              final availability = find.byKey(
                const ValueKey('availability-switch'),
              );
              final wasOnline = tester.widget<Switch>(availability).value;
              await tester.tap(availability);
              await tester.pump();
              expect(tester.widget<Switch>(availability).value, !wasOnline);
              await tester.tap(availability);
              await tester.pump();
              expect(tester.widget<Switch>(availability).value, wasOnline);
              expect(find.text(fixture.sample.shopName), findsOneWidget);
            } else if (screen == 'deliveries' ||
                screen == 'assigned' ||
                screen == 'in_progress') {
              await selectRiderTab(tester, 'Deliveries');
              if (screen == 'assigned') {
                await tester.ensureVisible(find.text('Ready to start'));
              }
            } else if (screen == 'profile') {
              await selectRiderTab(tester, 'Profile');
            } else if (screen == 'transactions') {
              await tester.scrollUntilVisible(
                find.text('Recent Transactions'),
                200,
                scrollable: find.byType(Scrollable).first,
              );
            } else if (screen == 'history' && verifyOnly) {
              await tester.tap(find.text('Last 7 days'));
              await tester.pump();
              expect(
                find.text('No deliveries in the last 7 days'),
                findsOneWidget,
              );
            } else if (screen == 'earnings' &&
                verifyOnly &&
                size.height == 640) {
              await tester.tap(find.text('Delivery history'));
              await tester.pump();
              await tester.pump(const Duration(milliseconds: 420));
              expect(find.byType(RiderHistoryScreen), findsOneWidget);
            }
          },
        );
      }

      testWidgets('Rider Phase 3 $screen preview — ${brightness.name}', (
        tester,
      ) async {
        await render(tester, const Size(360, 740), 1, verifyOnly: false);
      });
      for (final size in buyerSizes) {
        testWidgets('Rider Phase 3 $screen at $size 2× — ${brightness.name}', (
          tester,
        ) async {
          await render(tester, size, 2, verifyOnly: true);
        });
      }
      testWidgets('Rider Phase 3 $screen compact 1.3× — ${brightness.name}', (
        tester,
      ) async {
        await render(tester, const Size(360, 640), 1.3, verifyOnly: true);
      });
    }
  }
  Widget buyerHome() => BuyerShell(now: DateTime(2026, 1, 1, 18));
  Future<void> nextOnboarding(WidgetTester tester, int count) async {
    for (var step = 0; step < count; step++) {
      await tester.tap(find.text('Next').hitTestable());
      await tester.pump();
    }
  }

  Future<void> searchLinen(WidgetTester tester) async {
    final field = find.byType(TextField);
    await tester.enterText(field, 'linen');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('Linen Blend Wrap Dress'), findsOneWidget);
  }

  for (final brightness in Brightness.values) {
    for (final size in buyerSizes) {
      for (final scale in [1.3, 2.0]) {
        testWidgets('Onboarding layout $scale — $size ${brightness.name}', (
          tester,
        ) async {
          await _renderAndCapture(
            tester,
            name: 'onboarding_accessibility',
            builder: () => OnboardingScreen(onFinish: () {}),
            size: size,
            brightness: brightness,
            disableAnimations: true,
            textScale: scale,
            verifyOnly: true,
            interact: (tester) async {
              expect(find.text('Skip').hitTestable(), findsOneWidget);
              await nextOnboarding(tester, 1);
              expect(find.text('Back').hitTestable(), findsOneWidget);
              expect(find.text('Skip').hitTestable(), findsOneWidget);
              await nextOnboarding(tester, 1);
              expect(find.text('Get Started').hitTestable(), findsOneWidget);
              expect(find.text('Skip').hitTestable(), findsOneWidget);
              expect(
                find.text('From local shops to your doorstep.'),
                findsOneWidget,
              );
            },
          );
        });
      }
    }
    for (final (page, name) in [
      (0, 'discover'),
      (1, 'explore'),
      (2, 'delivered'),
      (3, 'landing'),
    ]) {
      testWidgets('Onboarding preview $name — ${brightness.name}', (
        tester,
      ) async {
        await _renderAndCapture(
          tester,
          name: 'onboarding_$name',
          builder: () => const AuthGate(),
          size: const Size(360, 740),
          brightness: brightness,
          disableAnimations: true,
          interact: (tester) async {
            await nextOnboarding(tester, page.clamp(0, 2));
            if (page == 3) {
              await tester.tap(find.text('Get Started').hitTestable());
              await tester.pump();
              expect(find.byType(LandingScreen), findsOneWidget);
            }
            await tester.pump(const Duration(milliseconds: 550));
          },
        );
      });
    }
    for (final (name, title) in [
      ('second', 'Feature 2 of 3: Home & Living'),
      ('third', 'Feature 3 of 3: Beauty'),
    ]) {
      testWidgets('Buyer editorial buyer_hero_$name — ${brightness.name}', (
        tester,
      ) async {
        await _renderAndCapture(
          tester,
          name: 'buyer_hero_$name',
          builder: buyerHome,
          size: const Size(360, 740),
          brightness: brightness,
          disableAnimations: true,
          interact: (tester) async {
            await tester.tap(find.byTooltip(title));
            await tester.pump();
            await tester.pump(const Duration(milliseconds: 550));
          },
        );
      });
    }
    testWidgets('Buyer editorial long card 2× — ${brightness.name}', (
      tester,
    ) async {
      const item = Product(
        id: 'editorial-long-card',
        name:
            'Hand-finished Philippine hardwood and woven rattan console table',
        category: 'Home & Living',
        price: 9876543.21,
        compareAtPrice: 12345678.90,
        sellerName: 'Independent furniture studio and workshop',
        stock: 0,
      );
      await _renderAndCapture(
        tester,
        name: 'buyer_card_accessibility',
        builder: () => Scaffold(
          body: SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: SizedBox(
                height: 410,
                child: ProductCard(
                  product: item,
                  heroTag: item.id,
                  onTap: () {},
                ),
              ),
            ),
          ),
        ),
        size: const Size(360, 640),
        brightness: brightness,
        disableAnimations: true,
        textScale: 2,
        verifyOnly: true,
        interact: (tester) async {
          expect(find.text('₱9,876,543.21').hitTestable(), findsOneWidget);
          expect(find.text('₱12,345,678.90').hitTestable(), findsOneWidget);
          expect(find.text('Sold out').hitTestable(), findsOneWidget);
        },
      );
    });
    for (final size in buyerSizes) {
      testWidgets('Buyer editorial Detail 2× — $size ${brightness.name}', (
        tester,
      ) async {
        await _renderAndCapture(
          tester,
          name: 'buyer_detail_accessibility',
          builder: () => ProductDetailsScreen(product: mockProducts.first),
          size: size,
          brightness: brightness,
          disableAnimations: true,
          textScale: 2,
          verifyOnly: true,
          interact: (tester) async {
            expect(find.text('Add to Cart').hitTestable(), findsOneWidget);
            expect(find.text('Buy Now').hitTestable(), findsOneWidget);
          },
        );
      });
      testWidgets('Buyer editorial Categories 2× — $size ${brightness.name}', (
        tester,
      ) async {
        await _renderAndCapture(
          tester,
          name: 'buyer_category_accessibility',
          builder: () => const CategoriesScreen(),
          size: size,
          brightness: brightness,
          disableAnimations: true,
          textScale: 2,
          verifyOnly: true,
        );
      });
      testWidgets('Buyer editorial Home 2× — $size ${brightness.name}', (
        tester,
      ) async {
        await _renderAndCapture(
          tester,
          name: 'buyer_accessibility',
          builder: buyerHome,
          size: size,
          brightness: brightness,
          disableAnimations: true,
          textScale: 2,
          verifyOnly: true,
        );
      });
      testWidgets('Buyer editorial Search 2× — $size ${brightness.name}', (
        tester,
      ) async {
        await _renderAndCapture(
          tester,
          name: 'search_accessibility',
          builder: () => const SearchScreen(),
          size: size,
          brightness: brightness,
          disableAnimations: true,
          textScale: 2,
          verifyOnly: true,
          interact: searchLinen,
        );
      });
    }
    final screens =
        <(String, Widget Function(), Future<void> Function(WidgetTester)?)>[
          ('buyer_home', buyerHome, null),
          ('buyer_categories', () => const CategoriesScreen(), null),
          (
            'buyer_sale',
            buyerHome,
            (tester) async {
              final sale = find.text('On sale');
              await tester.scrollUntilVisible(
                sale,
                150,
                scrollable: find.byType(Scrollable).first,
              );
              await tester.pump();
              expect(find.text('Ends soon'), findsNothing);
            },
          ),
          (
            'buyer_cards',
            buyerHome,
            (tester) async {
              await tester.scrollUntilVisible(
                find.text('Just for you'),
                200,
                scrollable: find.byType(Scrollable).first,
              );
              await tester.pump();
            },
          ),
          ('search_results', () => const SearchScreen(), searchLinen),
        ];
    for (final (name, builder, interact) in screens) {
      for (final size in const [Size(360, 640), Size(360, 740)]) {
        if (name == 'buyer_home' && size.height == 740) continue;
        testWidgets('Buyer editorial $name — $size ${brightness.name}', (
          tester,
        ) async {
          await _renderAndCapture(
            tester,
            name: name,
            builder: builder,
            size: size,
            brightness: brightness,
            disableAnimations: true,
            interact: interact,
          );
        });
      }
    }
  }

  for (final size in [const Size(360, 640), ...sizes]) {
    for (final brightness in [Brightness.light, Brightness.dark]) {
      final sName =
          '${size.width.round()}x${size.height.round()}_${brightness.name}';

      testWidgets('Landing first frame — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'landing_beat1',
          builder: () => LandingScreen(onDone: () {}, onCreateAccount: () {}),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 100),
        );
      });

      testWidgets('Landing reduced motion — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'landing_beat2',
          builder: () => LandingScreen(
            onDone: () {},
            onCreateAccount: () {},
            reduceMotion: true,
          ),
          size: size,
          brightness: brightness,
          advanceTime: Duration.zero,
        );
      });

      if (size.height == 640) continue;

      testWidgets('Login — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'login',
          builder: () => const LoginScreen(),
          size: size,
          brightness: brightness,
          disableAnimations: true,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Buyer Home — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'buyer_home',
          builder: () => BuyerShell(now: DateTime(2026, 1, 1, 18)),
          size: size,
          brightness: brightness,
          disableAnimations: true,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Search — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'search',
          builder: () => const SearchScreen(),
          size: size,
          brightness: brightness,
          disableAnimations: true,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Cart — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'cart',
          builder: () => const CartScreen(),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Orders — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'orders',
          builder: () => const OrdersStatusScreen(),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Profile — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'profile',
          builder: () => const ProfileScreen(title: 'Buyer Profile'),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Product Detail — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'product_detail',
          builder: () => ProductDetailsScreen(product: mockProducts.first),
          size: size,
          brightness: brightness,
          disableAnimations: true,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Courier Home — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'courier_home',
          builder: () => const CourierShell(),
          size: size,
          brightness: brightness,
          // The dashboard resolves its (empty) job list after the first
          // frame. Without this, the empty state's entrance starts on the
          // frame being captured and the golden records an invisible state.
          disableAnimations: true,
          advanceTime: const Duration(milliseconds: 900),
        );
      });

      testWidgets('Courier Tracker — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'courier_tracker',
          builder: () => const CourierTrackerScreen(order: _order),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 600),
        );
      });
    }
  }
}
