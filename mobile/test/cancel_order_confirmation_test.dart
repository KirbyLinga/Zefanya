// test/cancel_order_confirmation_test.dart
//
// S5.6: cancelling an order is deliberate. The sheet names the order, explains
// that the seller is notified and the stock returns, and keeps the confirm
// button disabled until a reason is chosen. Dismissing the sheet leaves the
// order untouched; confirming cancels it with the chosen reason.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/order_detail_screen.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/theme/app_theme.dart';

Future<void> _pumpDetail(
  WidgetTester tester,
  Order order,
  OrderState orders,
) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 1200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final catalog = CatalogState();
  await catalog.load();

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => CartState()),
        ChangeNotifierProvider.value(value: catalog),
        ChangeNotifierProvider(create: (_) => CourierState()),
        ChangeNotifierProvider.value(value: orders),
        ChangeNotifierProvider(create: (_) => ReviewState()),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        home: MediaQuery(
          data: const MediaQueryData(
            size: Size(390, 1200),
            disableAnimations: true,
          ),
          child: OrderDetailScreen(order: order),
        ),
      ),
    ),
  );
  await tester.pump();
}

Future<void> _openSheet(WidgetTester tester) async {
  final cancel = find.widgetWithText(OutlinedButton, 'Cancel order');
  await tester.ensureVisible(cancel);
  await tester.pump();
  await tester.tap(cancel);
  await tester.pumpAndSettle();
}

ElevatedButton _confirmButton(WidgetTester tester) =>
    tester.widget<ElevatedButton>(
      find.widgetWithText(ElevatedButton, 'Cancel order'),
    );

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('the sheet names the order and explains what cancelling does', (
    tester,
  ) async {
    final orders = OrderState();
    await orders.load();
    final order = orders.orders.firstWhere((o) => o.id == 'ORD-10245');

    await _pumpDetail(tester, order, orders);
    await _openSheet(tester);

    expect(find.text('Cancel ORD-10245?'), findsOneWidget);
    expect(
      find.text(
        'The seller is notified and the item returns to stock. '
        'This cannot be undone.',
      ),
      findsOneWidget,
    );
    for (final reason in OrderState.cancellationReasons) {
      expect(find.text(reason), findsOneWidget);
    }
    expect(_confirmButton(tester).onPressed, isNull);

    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('a reason must be chosen before Cancel order is enabled', (
    tester,
  ) async {
    final orders = OrderState();
    await orders.load();
    final order = orders.orders.firstWhere((o) => o.id == 'ORD-10245');

    await _pumpDetail(tester, order, orders);
    await _openSheet(tester);

    expect(_confirmButton(tester).onPressed, isNull);

    await tester.tap(find.text('Changed my mind'));
    await tester.pump();

    expect(_confirmButton(tester).onPressed, isNotNull);

    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('dismissing the sheet leaves the order uncancelled', (
    tester,
  ) async {
    final orders = OrderState();
    await orders.load();
    final order = orders.orders.firstWhere((o) => o.id == 'ORD-10245');

    await _pumpDetail(tester, order, orders);
    await _openSheet(tester);

    // Tap the scrim above the sheet to dismiss it without choosing anything.
    await tester.tapAt(const Offset(10, 10));
    await tester.pumpAndSettle();

    expect(find.text('Cancel ORD-10245?'), findsNothing);
    expect(orders.orders.firstWhere((o) => o.id == order.id).cancelled, isFalse);

    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('choosing a reason cancels the order with that reason', (
    tester,
  ) async {
    final orders = OrderState();
    await orders.load();
    final order = orders.orders.firstWhere((o) => o.id == 'ORD-10245');

    await _pumpDetail(tester, order, orders);
    await _openSheet(tester);

    await tester.tap(find.text('Ordered the wrong item'));
    await tester.pump();
    await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel order'));
    await tester.pumpAndSettle();

    final cancelled = orders.orders.firstWhere((o) => o.id == order.id);
    expect(cancelled.cancelled, isTrue);
    expect(cancelled.cancellationReason, 'Ordered the wrong item');

    await tester.pumpWidget(const SizedBox.shrink());
  });
}
