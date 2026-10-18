import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/cart_screen.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/theme/app_theme.dart';

Future<void> _pumpCart(WidgetTester tester, CartState cart) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  SharedPreferences.setMockInitialValues({});
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: CatalogState()),
        ChangeNotifierProvider.value(value: cart),
        ChangeNotifierProvider(create: (_) => CourierState()),
        ChangeNotifierProvider(create: (_) => OrderState()),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        home: const CartScreen(),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 500));
  await tester.pump(const Duration(milliseconds: 500));
}

void main() {
  testWidgets('swiping a real tile removes it with an Undo offer',
      (tester) async {
    // Mock prefs BEFORE cart.load(): without it, getInstance() awaits a
    // real platform channel that never answers on the test host — the
    // test deadlocks at load() with zero CPU (the original "hang",
    // misdiagnosed as a pumpAndSettle issue).
    SharedPreferences.setMockInitialValues({});
    final cart = CartState();
    await cart.load();
    cart.addItem(mockProducts.first);
    expect(cart.items, isNotEmpty);

    await _pumpCart(tester, cart);
    expect(find.text(mockProducts.first.name), findsWidgets);

    await tester.drag(
      find.text(mockProducts.first.name).first,
      const Offset(-400, 0),
    );
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pump(const Duration(milliseconds: 500));

    expect(cart.items, isEmpty);
    expect(find.textContaining('Removed'), findsOneWidget);
    expect(find.text('Undo'), findsOneWidget);
    await tester.pump(const Duration(seconds: 5));
    expect(tester.takeException(), isNull);
  });
}
