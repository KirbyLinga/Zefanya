// test/courier_failure_flow_test.dart
//
// Failed-delivery bookkeeping, named by workbook case:
//
//   RID-FAIL-001  mark failed with a reason: attempt 1 of 3, no COD, no pay
//   RID-FAIL-002  a failure with no reason changes nothing
//   RID-FAIL-003  "Buyer refused" goes straight to Return to Sender
//   RID-FAIL-004  attempt 2 of 3 is shown, and success still completes
//   RID-FAIL-005  the third failure returns, and a fourth attempt is refused
//
// The UI reads these values from CourierState, so they are pinned through the
// real tracker screen where the rule is a screen rule, and in the state where
// it is a data rule.

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
  id: 'ORD-FAIL-1',
  shopName: 'Willow & Bloom Atelier',
  status: OrderStatus.toShip,
  eta: 'soon',
  itemSummary: 'Linen Blend Wrap Dress',
);

Future<(CourierState, String)> _dispatched({
  double codAmount = 0,
  String tracking = 'ZEF-FAIL-01',
}) async {
  final courier = CourierState();
  await courier.load();
  courier.dispatchForOrder(
    order: _order,
    buyerName: 'Marielle Santos',
    deliveryArea: 'Sta. Cruz, Laguna',
    fee: 85,
    trackingNumber: tracking,
    codAmount: codAmount,
  );
  return (courier, courier.jobForOrder(_order.id)!.id);
}

Future<void> _pumpTracker(
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

Future<void> _openFailureSheet(WidgetTester tester) async {
  final button = find.widgetWithText(
    OutlinedButton,
    'Mark Delivery Failed',
  );
  await tester.scrollUntilVisible(button, 300);
  await tester.tap(button);
  await tester.pumpAndSettle();
  expect(find.text('Why did delivery fail?'), findsOneWidget);
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('RID-FAIL-001', () {
    test('a failure records its reason and attempt, collects no COD, pays 0',
        () async {
      final (courier, jobId) = await _dispatched(codAmount: 500);
      courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);

      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.failedDelivery,
        failureReason: 'Buyer not available',
      );

      expect(courier.attemptsFor(jobId), 1);
      expect(courier.failureReasonFor(jobId), 'Buyer not available');
      expect(courier.milestoneFor(jobId), DeliveryMilestone.failedDelivery);
      expect(courier.codOutstanding, 0);
      expect(courier.completedJobs, isEmpty);
      expect(courier.todayEarnings, 0);
    });
  });

  group('RID-FAIL-002', () {
    test('a failure with no reason leaves the status and counter unchanged',
        () async {
      final (courier, jobId) = await _dispatched();
      courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);

      courier.advanceMilestone(jobId, DeliveryMilestone.failedDelivery);

      expect(courier.milestoneFor(jobId), DeliveryMilestone.inTransit);
      expect(courier.attemptsFor(jobId), 0);
      expect(courier.failureReasonFor(jobId), isNull);
    });

    testWidgets('dismissing the reason sheet blocks the failure',
        (tester) async {
      final (courier, jobId) = await _dispatched();
      courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
      await _pumpTracker(tester, courier, jobId);
      await _openFailureSheet(tester);

      // Tap the barrier above the sheet: no reason was chosen.
      await tester.tapAt(const Offset(10, 10));
      await tester.pumpAndSettle();

      expect(courier.milestoneFor(jobId), DeliveryMilestone.inTransit);
      expect(courier.attemptsFor(jobId), 0);
    });
  });

  group('RID-FAIL-003', () {
    testWidgets('Buyer refused returns the parcel immediately, with no retry',
        (tester) async {
      final (courier, jobId) = await _dispatched();
      courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
      await _pumpTracker(tester, courier, jobId);
      await _openFailureSheet(tester);

      await tester.tap(find.text('Buyer refused'));
      await tester.pumpAndSettle();

      expect(
        courier.milestoneFor(jobId),
        DeliveryMilestone.returnToSender,
      );
      expect(
        find.textContaining('Returning to seller'),
        findsOneWidget,
      );
      expect(find.text('Retry Delivery'), findsNothing);
      expect(find.text('Mark Delivery Failed'), findsNothing);
      expect(find.text('Return Complete'), findsOneWidget);
    });
  });

  group('RID-FAIL-004', () {
    testWidgets('attempt 2 of 3 is shown and success still completes',
        (tester) async {
      final (courier, jobId) = await _dispatched();

      courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.failedDelivery,
        failureReason: 'Buyer not available',
      );
      courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.failedDelivery,
        failureReason: 'Wrong address',
      );
      expect(courier.attemptsFor(jobId), 2);
      expect(courier.milestoneFor(jobId), DeliveryMilestone.failedDelivery);

      await _pumpTracker(tester, courier, jobId);

      // The failed state names the attempt that just failed. The next
      // attempt is 3 of 3 once the rider retries.
      expect(
        find.text('Attempt 2 of 3 failed — Wrong address'),
        findsOneWidget,
      );

      // A successful third visit completes normally: it is not a failure,
      // so it neither increments the counter nor halves the pay.
      courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
      courier.advanceMilestone(jobId, DeliveryMilestone.delivered);
      courier.completeJob(jobId);

      expect(courier.isCompleted(jobId), isTrue);
      expect(courier.todayEarnings, 85);
      expect(courier.attemptsFor(jobId), 2);
    });
  });

  group('RID-FAIL-005', () {
    test('the third failure returns, and a fourth attempt is impossible',
        () async {
      final (courier, jobId) = await _dispatched();

      courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
      for (var attempt = 1; attempt <= 2; attempt++) {
        courier.advanceMilestone(
          jobId,
          DeliveryMilestone.failedDelivery,
          failureReason: 'Buyer not available',
        );
        expect(courier.attemptsFor(jobId), attempt);
        courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
      }
      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.failedDelivery,
        failureReason: 'Buyer not available',
      );

      expect(courier.attemptsFor(jobId), 3);
      expect(courier.milestoneFor(jobId), DeliveryMilestone.returnToSender);

      // Even a stale caller cannot start a fourth attempt.
      courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.failedDelivery,
        failureReason: 'Buyer not available',
      );
      expect(courier.milestoneFor(jobId), DeliveryMilestone.returnToSender);
      expect(courier.attemptsFor(jobId), 3);
    });
  });
}
