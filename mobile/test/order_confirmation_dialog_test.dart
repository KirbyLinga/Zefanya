// test/order_confirmation_dialog_test.dart
//
// The checkout confirmation, rendered and asserted.
//
// It was extracted from checkout_screen.dart (same reasoning as the courier's
// DeliveryCompletionView) partly so it could be *looked at*: its only action
// used to be a button labelled "Track Order" that called popUntil(route.isFirst)
// — landing the buyer on Home — while the copy beneath it said to go and find
// the order in the Orders tab. Label, copy and behaviour all disagreed, and
// the buyer never saw the thing they had just bought.
//
// So: goldens for both modes (open the PNGs after --update-goldens), plus
// assertions on the two facts that were missing — which order, and what the
// primary action actually claims.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/widgets/order_confirmation_dialog.dart';
import 'package:zefanya/theme/app_theme.dart';

/// One order, as a single-seller checkout creates.
List<Order> get _oneOrder => [mockOrders.first];

/// Two orders, as a cart spanning two sellers creates.
List<Order> get _twoOrders => [mockOrders[0], mockOrders[1]];

Widget _dialog(
  List<Order> orders, {
  VoidCallback? onTrack,
  VoidCallback? onDone,
}) =>
    OrderConfirmationDialog(
      orders: orders,
      total: 1299,
      onTrack: onTrack ?? () {},
      onDone: onDone ?? () {},
    );

Future<void> _pump(
  WidgetTester tester, {
  required Brightness brightness,
  // A builder, not a widget: `child: _sheet()` would be evaluated as an
  // argument *before* AppColors.apply below, so anything reading AppColors
  // while being constructed would capture the previous mode. That exact bug
  // produced a "dark" golden with a light background once already.
  required Widget Function() child,
  Size size = const Size(400, 720),
  double textScale = 1.0,
}) async {
  AppColors.apply(brightness);
  GoogleFonts.config.allowRuntimeFetching = false;

  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: brightness == Brightness.dark ? AppTheme.dark : AppTheme.light,
      home: MediaQuery(
        data: MediaQueryData(size: size, textScaler: TextScaler.linear(textScale)),
        child: child(),
      ),
    ),
  );
  await tester.pump();
}

/// Both cases side by side: the ordinary checkout, and the multi-seller one
/// whose button is worded differently.
Widget _sheet() => RepaintBoundary(
      key: const ValueKey('sheet'),
      child: ColoredBox(
        color: AppColors.background,
        child: Row(
          children: [
            SizedBox(width: 400, height: 720, child: _dialog(_oneOrder)),
            SizedBox(width: 400, height: 720, child: _dialog(_twoOrders)),
          ],
        ),
      ),
    );

void main() {
  testWidgets('confirmation — light', (tester) async {
    await _pump(tester, brightness: Brightness.light, child: _sheet,
        size: const Size(800, 720));
    await expectLater(find.byKey(const ValueKey('sheet')),
        matchesGoldenFile('order_confirmation_light.png'));
  });

  testWidgets('confirmation — dark', (tester) async {
    await _pump(tester, brightness: Brightness.dark, child: _sheet,
        size: const Size(800, 720));
    await expectLater(find.byKey(const ValueKey('sheet')),
        matchesGoldenFile('order_confirmation_dark.png'));
  });

  testWidgets('it names the order the buyer just created', (tester) async {
    await _pump(tester, brightness: Brightness.light,
        child: () => _dialog(_oneOrder));

    // The one piece of information needed to ask anyone about this purchase,
    // and previously invisible until the buyer went looking for it.
    expect(find.text(_oneOrder.first.id), findsOneWidget);
    expect(find.text(_oneOrder.first.shopName), findsOneWidget);
    expect(find.text('ORDER NUMBER'), findsOneWidget);
  });

  testWidgets('one order is singular, several are not', (tester) async {
    await _pump(tester, brightness: Brightness.light,
        child: () => _dialog(_oneOrder));
    expect(find.text('Track Order'), findsOneWidget);
    expect(find.textContaining('also created'), findsNothing);

    await _pump(tester, brightness: Brightness.light,
        child: () => _dialog(_twoOrders));
    // A singular promise about a plural checkout is its own small lie.
    expect(find.text('Track Order'), findsNothing);
    expect(find.text('Track First Order'), findsOneWidget);
    expect(find.textContaining('also created 1 more order'), findsOneWidget);
  });

  testWidgets('both actions do something', (tester) async {
    var tracked = 0;
    var done = 0;

    await _pump(tester, brightness: Brightness.light,
        child: () => _dialog(_oneOrder,
            onTrack: () => tracked++, onDone: () => done++));

    await tester.tap(find.text('Track Order'));
    await tester.pump();
    await tester.tap(find.text('Done'));
    await tester.pump();

    // The whole point of the rewrite: this button used to do nothing but
    // unwind the stack.
    expect(tracked, 1);
    expect(done, 1);
  });

  testWidgets('does not overflow a short screen at 1.3x text', (tester) async {
    await _pump(tester, brightness: Brightness.light,
        child: () => _dialog(_twoOrders),
        size: const Size(360, 300), textScale: 1.3);

    expect(tester.takeException(), isNull);
  });
}