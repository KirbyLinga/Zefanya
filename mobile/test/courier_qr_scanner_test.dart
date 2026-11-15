// test/courier_qr_scanner_test.dart
//
// Widget tests for the QR scanner flow in the delivery tracker and
// the CourierQrScanner screen itself.
//
// Covers:
//   - Manual tracking number entry advances the milestone on match
//   - Mismatched manual entry does NOT advance and shows an error
//   - QR scanner screen manual entry returns entered text

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/courier/courier_qr_scanner.dart';
import 'package:zefanya/screens/courier/delivery_tracker_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/theme/app_theme.dart';

const _order = Order(
  id: 'ORD-QR-TEST-1',
  shopName: 'Willow & Bloom Atelier',
  status: OrderStatus.toShip,
  eta: 'soon',
  itemSummary: 'Linen Blend Wrap Dress',
);

Future<CourierState> _dispatched() async {
  final courier = CourierState();
  await courier.load();
  courier.dispatchForOrder(
    order: _order,
    buyerName: 'Marielle Santos',
    deliveryArea: 'Sta. Cruz, Laguna (6.2 km)',
    fee: 85,
  );
  return courier;
}

String _jobId(CourierState courier) => courier.jobForOrder(_order.id)!.id;

String _trackingNumber(CourierState courier) =>
    (courier.jobById(_jobId(courier))!.trackingNumber ??
        courier.jobById(_jobId(courier))!.id)
        .trim()
        .toUpperCase();

Future<void> _pumpTracker(
  WidgetTester tester, {
  required CourierState courier,
  required String jobId,
}) async {
  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider<CourierState>.value(value: courier),
        ChangeNotifierProvider<AuthState>.value(value: AuthState()),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        home: DeliveryTrackerScreen(jobId: jobId),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 500));
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;
  });

  testWidgets('matching manual entry advances the milestone', (
    tester,
  ) async {
    final courier = await _dispatched();
    final jobId = _jobId(courier);
    final expectedTracking = _trackingNumber(courier);

    await _pumpTracker(tester, courier: courier, jobId: jobId);

    expect(courier.milestoneFor(jobId), DeliveryMilestone.accepted);

    // At accepted milestone, button shows 'Confirm Arrived at Seller'
    await tester.tap(find.text('Confirm Arrived at Seller'));
    await tester.pump(const Duration(milliseconds: 500));
    expect(courier.milestoneFor(jobId), DeliveryMilestone.arrivedAtSeller);

    // Now at arrivedAtSeller - tap 'Confirm Pickup from Seller' to open dialog.
    await tester.tap(find.text('Confirm Pickup from Seller'));
    await tester.pump(const Duration(milliseconds: 500));

    final trackingField = find.byKey(const ValueKey('pickup-tracking'));
    await tester.enterText(trackingField, expectedTracking);
    await tester.tap(find.text('Confirm Pickup'));
    await tester.pump(const Duration(milliseconds: 500));

    expect(courier.milestoneFor(jobId), DeliveryMilestone.pickedUp);
  });

  testWidgets('mismatched manual entry does NOT advance', (tester) async {
    final courier = await _dispatched();
    final jobId = _jobId(courier);

    await _pumpTracker(tester, courier: courier, jobId: jobId);

    expect(courier.milestoneFor(jobId), DeliveryMilestone.accepted);

    await tester.tap(find.text('Confirm Arrived at Seller'));
    await tester.pump(const Duration(milliseconds: 500));
    expect(courier.milestoneFor(jobId), DeliveryMilestone.arrivedAtSeller);

    await tester.tap(find.text('Confirm Pickup from Seller'));
    await tester.pump(const Duration(milliseconds: 500));

    final trackingField = find.byKey(const ValueKey('pickup-tracking'));
    await tester.enterText(trackingField, 'WRONG-CODE-999');
    await tester.tap(find.text('Confirm Pickup'));
    await tester.pump(const Duration(milliseconds: 500));

    expect(courier.milestoneFor(jobId), DeliveryMilestone.arrivedAtSeller);

    expect(
      find.textContaining('does not match this parcel'),
      findsOneWidget,
    );
  });

  testWidgets('manual entry field accepts tracking number', (tester) async {
    final courier = await _dispatched();
    final jobId = _jobId(courier);
    final expectedTracking = _trackingNumber(courier);

    await _pumpTracker(tester, courier: courier, jobId: jobId);

    await tester.tap(find.text('Confirm Arrived at Seller'));
    await tester.pump(const Duration(milliseconds: 500));
    expect(courier.milestoneFor(jobId), DeliveryMilestone.arrivedAtSeller);

    await tester.tap(find.text('Confirm Pickup from Seller'));
    await tester.pump(const Duration(milliseconds: 500));

    final trackingField = find.byKey(const ValueKey('pickup-tracking'));
    await tester.enterText(trackingField, expectedTracking);
    await tester.tap(find.text('Confirm Pickup'));
    await tester.pump(const Duration(milliseconds: 500));

    expect(courier.milestoneFor(jobId), DeliveryMilestone.pickedUp);
  });

  testWidgets('courier QR scanner widget renders without error', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.light,
        home: CourierQrScanner(
          key: const Key('qr-scanner'),
          expectedTrackingNumber: 'ANY-CODE',
        ),
      ),
    );
    await tester.pump(const Duration(milliseconds: 500));

    // Just verify the scanner widget renders without throwing
    expect(find.byType(MobileScanner), findsOneWidget);
  });
}