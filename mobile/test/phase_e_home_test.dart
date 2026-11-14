import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/repositories/catalog_repository.dart';
import 'package:zefanya/screens/buyer/buyer_shell.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/notification_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/widgets/empty_state.dart';

Widget _buildHomeHarness({
  required CatalogState catalog,
  Brightness brightness = Brightness.light,
  double textScaleFactor = 1.0,
}) {
  return MediaQuery(
    data: MediaQueryData(
      disableAnimations: true,
      textScaler: TextScaler.linear(textScaleFactor),
    ),
    child: MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: catalog),
        ChangeNotifierProvider(create: (_) => AuthState()),
        ChangeNotifierProvider(create: (_) => CartState()),
        ChangeNotifierProvider(create: (_) => CourierState()),
        ChangeNotifierProvider(create: (_) => NotificationState()),
        ChangeNotifierProvider(create: (_) => OrderState()),
        ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
        ChangeNotifierProvider(create: (_) => ReviewState()),
        ChangeNotifierProvider(create: (_) => WishlistState()),
        ChangeNotifierProvider(create: (_) => ThemeState()),
      ],
      child: MaterialApp(
        theme: brightness == Brightness.light ? AppTheme.light : AppTheme.dark,
        home: const BuyerShell(),
      ),
    ),
  );
}

class _FailingCatalogRepo extends MockCatalogRepository {
  @override
  Future<List<Product>> products({CatalogQuery? query}) async {
    throw const NetworkException(message: 'Connection timeout');
  }

  @override
  Future<List<String>> categories() async {
    throw const NetworkException(message: 'Connection timeout');
  }
}

class _EmptyCatalogRepo extends MockCatalogRepository {
  @override
  Future<List<Product>> products({CatalogQuery? query}) async => [];

  @override
  Future<List<String>> categories() async => [];
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;
  });

  testWidgets('Phase E: Home renders without overflow at 1.3x text scale in Light and Dark themes', (tester) async {
    tester.view.physicalSize = const Size(390, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final catalog = CatalogState();
    await catalog.load();

    // 1. Light theme at 1.3x text scale
    AppColors.apply(Brightness.light);
    await tester.pumpWidget(_buildHomeHarness(
      catalog: catalog,
      brightness: Brightness.light,
      textScaleFactor: 1.3,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));

    final err = tester.takeException();
    if (err is FlutterError) {
      debugPrint(err.toStringDeep());
    }
    expect(err, isNull);
    expect(find.text('Zefanya'), findsWidgets);
    expect(find.text('CATEGORIES'), findsOneWidget);
    expect(find.text('See all categories'), findsOneWidget);

    // 2. Dark theme at 1.3x text scale
    AppColors.apply(Brightness.dark);
    await tester.pumpWidget(_buildHomeHarness(
      catalog: catalog,
      brightness: Brightness.dark,
      textScaleFactor: 1.3,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));

    expect(tester.takeException(), isNull);
  });

  testWidgets('Phase E: Home touch targets meet 48dp minimum floor', (tester) async {
    tester.view.physicalSize = const Size(390, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final catalog = CatalogState();
    await catalog.load();

    await tester.pumpWidget(_buildHomeHarness(catalog: catalog));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));

    // Search bar >= 48dp
    final searchFinder = find.byType(TextField);
    expect(searchFinder, findsOneWidget);
    final searchRenderBox = tester.renderObject<RenderBox>(
      find.ancestor(of: searchFinder, matching: find.byType(Container)).first,
    );
    expect(searchRenderBox.size.height, greaterThanOrEqualTo(48.0));

    // "See all categories" action container >= 48dp
    final seeAllCatFinder = find.ancestor(
      of: find.text('See all categories'),
      matching: find.byType(ConstrainedBox),
    );
    expect(seeAllCatFinder, findsWidgets);
    final seeAllBox = tester.widget<ConstrainedBox>(seeAllCatFinder.first);
    expect(seeAllBox.constraints.minHeight, greaterThanOrEqualTo(48.0));
  });

  testWidgets('Phase E: Home renders error state with retry when catalog fails', (tester) async {
    tester.view.physicalSize = const Size(390, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final failingCatalog = CatalogState(repository: _FailingCatalogRepo());
    await failingCatalog.load();

    await tester.pumpWidget(_buildHomeHarness(catalog: failingCatalog));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));

    expect(find.byType(EmptyState), findsOneWidget);
    expect(find.text('Could not load products'), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);
  });

  testWidgets('Phase E: Home renders empty state when catalog has no products', (tester) async {
    tester.view.physicalSize = const Size(390, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final emptyCatalog = CatalogState(repository: _EmptyCatalogRepo());
    await emptyCatalog.load();

    await tester.pumpWidget(_buildHomeHarness(catalog: emptyCatalog));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));

    expect(find.byType(EmptyState), findsOneWidget);
    expect(find.text('No products yet'), findsOneWidget);
    expect(find.text('Refresh'), findsOneWidget);
  });
}
