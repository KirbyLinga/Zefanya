// test/courier_tracker_golden_test.dart
//
// The buyer's "where is my parcel" screen, rendered — because it is the screen
// three courier-identity decisions landed on and it was never actually looked
// at.
//
// What changed here: the courier card gained a name, a portrait slot and a
// contact action, the rider's identity is recorded at `assignedToRider` (so
// "waiting for a rider" is a real state, not an empty string), and the old
// `_CourierInfo` block was renamed `_DeliveryDetails` because it had never
// contained a courier — it was a parcel manifest wearing a person's name.
//
// Three things this file pins that reading the code cannot:
//
//  1. Layout of the new card — avatar + name + rating line + contact button in
//     one row is exactly the shape that overflows silently at 1.3x text scale.
//  2. The two states, side by side: a job with a rider, and a job without one.
//     The second is where most of an order's life is spent, and it is the one
//     nobody ever renders.
//  3. That the ground the screen sits on is opaque. The Scaffold here is
//     deliberately transparent (main.dart's app-wide gradient paints behind
//     it), so a golden pumped without a stand-in ground comes out with
//     transparent pixels and proves nothing about contrast.
//
//     flutter test test/courier_tracker_golden_test.dart --update-goldens
//
// then open courier_tracker_light.png / courier_tracker_dark.png.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/screens/buyer/courier_tracker_screen.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/theme/app_theme.dart';

const _order = Order(
  id: 'ORD-55555',
  shopName: 'Zefanya Studio',
  status: OrderStatus.inTransit,
  eta: 'Today, 4–6 PM',
  itemSummary: 'Linen Blend Wrap Dress',
);

/// A courier state holding one job, advanced to the point where a rider is
/// named — the only milestone at which identity is recorded.
CourierState _dispatched({String? courierName = 'Rico Delos Santos'}) {
  final courier = CourierState();
  courier.dispatchForOrder(
    order: _order,
    buyerName: 'Ana',
    deliveryArea: 'Makati City',
    fee: 120,
  );
  final jobId = courier.jobForOrder(_order.id)!.id;
  if (courierName == null) {
    // Past the pickup leg but not yet handed to a rider: the honest
    // "no courier yet" case.
    courier.advanceMilestone(jobId, DeliveryMilestone.sorted);
  } else {
    courier.advanceMilestone(jobId, DeliveryMilestone.assignedToRider,
        courierName: courierName);
  }
  return courier;
}

Future<void> _pump(
  WidgetTester tester, {
  required CourierState courier,
  required Brightness brightness,
  Size size = const Size(390, 844),
  double textScale = 1.0,
}) async {
  AppColors.apply(brightness);
  GoogleFonts.config.allowRuntimeFetching = false;

  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        // Mock Repositories: client is null so the screen skips polling.
        Provider<Repositories>.value(value: Repositories.mock()),
        ChangeNotifierProvider<CourierState>.value(value: courier),
      ],
      child: MaterialApp(
        theme: brightness == Brightness.dark ? AppTheme.dark : AppTheme.light,
        home: MediaQuery(
          data: MediaQueryData(
            size: size,
            textScaler: TextScaler.linear(textScale),
          ),
          // Stands in for the app-wide gradient in main.dart, which this
          // screen's transparent Scaffold normally shows through. Built here
          // rather than passed in, so it is constructed *after* the palette is
          // applied above — the ordering that produced a "dark" golden with a
          // light background when it was got wrong elsewhere.
          child: ColoredBox(
            color: AppColors.background,
            child: const CourierTrackerScreen(order: _order),
          ),
        ),
      ),
    ),
  );
  // The screen staggers its sections in; a golden taken mid-reveal would
  // capture an animation frame rather than the layout.
  await tester.pumpAndSettle();
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('courier tracker with a named rider — light', (tester) async {
    await _pump(tester, courier: _dispatched(), brightness: Brightness.light);
    await expectLater(find.byType(CourierTrackerScreen),
        matchesGoldenFile('courier_tracker_light.png'));
  });

  testWidgets('courier tracker with a named rider — dark', (tester) async {
    await _pump(tester, courier: _dispatched(), brightness: Brightness.dark);
    await expectLater(find.byType(CourierTrackerScreen),
        matchesGoldenFile('courier_tracker_dark.png'));
  });

  testWidgets('the no-rider state is a state, not an empty card',
      (tester) async {
    await _pump(tester,
        courier: _dispatched(courierName: null),
        brightness: Brightness.light);

    // The card is present and says what is true. If someone later "tidies"
    // this by hiding the card until a rider exists, this fails — and that
    // would be a regression, because "hasn't been assigned yet" is
    // information the buyer wants.
    expect(find.text('NO COURIER YET'), findsOneWidget);
    expect(find.text('Waiting for a rider to be assigned'), findsOneWidget);
    // No contact action targeting nobody.
    expect(find.byTooltip('Message null'), findsNothing);
  });

  testWidgets('the named-rider card offers contact with that rider',
      (tester) async {
    await _pump(tester, courier: _dispatched(), brightness: Brightness.light);

    expect(find.text('YOUR COURIER'), findsOneWidget);
    expect(find.text('Rico Delos Santos'), findsOneWidget);
    expect(find.byTooltip('Message Rico Delos Santos'), findsOneWidget);
    // No rating appears: this device holds none for that rider, and a rider
    // with no ratings must not be shown "0.0".
    expect(find.textContaining('deliveries rated'), findsNothing);
    expect(find.textContaining('delivery rated'), findsNothing);
  });

  testWidgets('the card survives 1.3x text on a narrow phone', (tester) async {
    // The app clamps system text scale at 1.3x (see main.dart). A row of
    // avatar + two text lines + an icon button is exactly where that bites,
    // and a RenderFlex overflow is silent in code review.
    await _pump(tester,
        courier: _dispatched(),
        brightness: Brightness.light,
        size: const Size(320, 640),
        textScale: 1.3);

    expect(tester.takeException(), isNull);
  });
}