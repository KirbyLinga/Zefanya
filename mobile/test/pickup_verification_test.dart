// test/pickup_verification_test.dart
//
// Pickup is where the parcel changes hands, so the courier's tap alone is not
// proof enough: the workbook requires the tracking number to match before the
// status becomes Picked Up. These tests pin all three outcomes through the real
// tracker screen — blank, wrong, correct — and the one-way milestone step after
// a match.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/courier/delivery_tracker_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/theme/app_theme.dart';

const _order = Order(
  id: 'ORD-PICK-1',
  shopName: 'Willow & Bloom Atelier',
  status: OrderStatus.toShip,
  eta: 'soon',
  itemSummary: 'Linen Blend Wrap Dress',
);

Future<(CourierState, String)> _atSeller() async {
  final courier = CourierState();
  await courier.load();
  courier.dispatchForOrder(
    order: _order,
    buyerName: 'Marielle Santos',
    deliveryArea: 'Sta. Cruz, Laguna',
    fee: 85,
    trackingNumber: 'ZEF-PICK-01',
  );
  final job = courier.jobForOrder(_order.id)!;
  courier.advanceMilestone(job.id, DeliveryMilestone.arrivedAtSeller);
  return (courier, job.id);
}

Future<void> _pump(
  WidgetTester tester,
  CourierState courier,
  String jobId,
) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => AuthState()),
        ChangeNotifierProvider.value(value: courier),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        home: MediaQuery(
          data: const MediaQueryData(
            size: Size(390, 844),
            disableAnimations: true,
          ),
          child: DeliveryTrackerScreen(jobId: jobId),
        ),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 100));
}

Future<void> _openPickupDialog(WidgetTester tester) async {
  final button = find.widgetWithText(
    ElevatedButton,
    'Confirm Pickup from Seller',
  );
  await tester.scrollUntilVisible(button, 300);
  await tester.tap(button);
  await tester.pumpAndSettle();
  expect(find.text('Verify the parcel'), findsOneWidget);
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('RID-PICK-004', () {
    testWidgets('blank and wrong tracking numbers leave the job at seller',
        (tester) async {
      final (courier, jobId) = await _atSeller();
      await _pump(tester, courier, jobId);
      await _openPickupDialog(tester);

      // Blank: the confirm button explains what is missing rather than closing.
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm Pickup'));
      await tester.pump();
      expect(find.text('Enter the tracking number first.'), findsOneWidget);
      expect(courier.milestoneFor(jobId), DeliveryMilestone.arrivedAtSeller);

      // Wrong: a specific mismatch message, still at the seller.
      await tester.enterText(
        find.byKey(const ValueKey('pickup-tracking')),
        'ZEF-WRONG-99',
      );
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm Pickup'));
      await tester.pump();
      expect(
        find.text('That tracking number does not match this parcel.'),
        findsOneWidget,
      );
      expect(courier.milestoneFor(jobId), DeliveryMilestone.arrivedAtSeller);
    });
  });

  group('RID-PICK-003', () {
    testWidgets('the matching number advances pickup exactly once',
        (tester) async {
      final (courier, jobId) = await _atSeller();
      await _pump(tester, courier, jobId);
      await _openPickupDialog(tester);

      await tester.enterText(
        find.byKey(const ValueKey('pickup-tracking')),
        ' zef-pick-01 ',
      );
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm Pickup'));
      await tester.pumpAndSettle();

      expect(courier.milestoneFor(jobId), DeliveryMilestone.pickedUp);
      expect(find.text('Verify the parcel'), findsNothing);
      expect(
        find.widgetWithText(ElevatedButton, 'Drop at Sorting Center'),
        findsOneWidget,
      );

      // The pickup action is gone, so it cannot be confirmed twice.
      expect(
        find.widgetWithText(ElevatedButton, 'Confirm Pickup from Seller'),
        findsNothing,
      );
    });
  });
}
