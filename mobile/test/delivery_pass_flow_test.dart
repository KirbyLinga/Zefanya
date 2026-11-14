// test/delivery_pass_flow_test.dart
//
// The buyer's delivery pass, rendered where it is actually used. The card
// only appears while a rider is carrying the parcel; before assignment
// there is no one to show it to, and after delivery it is no longer a
// handover credential.

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
  id: 'ORD-PASS-1',
  shopName: 'Zefanya Studio',
  status: OrderStatus.inTransit,
  eta: 'Today, 4–6 PM',
  itemSummary: 'Linen Blend Wrap Dress',
);

Future<CourierState> _courierAt(
  DeliveryMilestone milestone, {
  String? deliveryOtp,
}) async {
  final courier = CourierState();
  await courier.load();
  courier.dispatchForOrder(
    order: _order,
    buyerName: 'Marielle Santos',
    deliveryArea: 'Sta. Cruz, Laguna',
    fee: 120,
    deliveryOtp: deliveryOtp,
  );
  final jobId = courier.jobForOrder(_order.id)!.id;
  courier.advanceMilestone(jobId, milestone);
  return courier;
}

Future<void> _pump(
  WidgetTester tester, {
  required CourierState courier,
  Brightness brightness = Brightness.light,
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
        // Mock repositories: no ApiClient, so the tracker skips polling.
        Provider<Repositories>.value(value: Repositories.mock()),
        ChangeNotifierProvider<CourierState>.value(value: courier),
      ],
      child: MaterialApp(
        theme: brightness == Brightness.dark ? AppTheme.dark : AppTheme.light,
        home: MediaQuery(
          data: MediaQueryData(
            size: size,
            textScaler: TextScaler.linear(textScale),
            disableAnimations: true,
          ),
          child: ColoredBox(
            color: AppColors.background,
            child: const CourierTrackerScreen(order: _order),
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;
  });

  group('buyer delivery pass', () {
    testWidgets('appears while the rider is carrying the parcel', (
      tester,
    ) async {
      final courier = await _courierAt(DeliveryMilestone.assignedToRider);
      await _pump(tester, courier: courier);

      expect(find.byKey(const ValueKey('delivery-pass-card')), findsOneWidget);
      expect(find.byKey(const ValueKey('delivery-pass-qr')), findsOneWidget);
      expect(find.text('DELIVERY PASS'), findsOneWidget);
      expect(find.text('482 913'), findsOneWidget);
    });

    testWidgets('stays visible in transit', (tester) async {
      final courier = await _courierAt(DeliveryMilestone.inTransit);
      await _pump(tester, courier: courier);

      expect(find.byKey(const ValueKey('delivery-pass-card')), findsOneWidget);
      expect(find.byKey(const ValueKey('delivery-pass-qr')), findsOneWidget);
    });

    testWidgets('is absent before a rider is assigned', (tester) async {
      final courier = await _courierAt(DeliveryMilestone.sorted);
      await _pump(tester, courier: courier);

      expect(find.byKey(const ValueKey('delivery-pass-card')), findsNothing);
    });

    testWidgets('is absent once the parcel is delivered', (tester) async {
      final courier = await _courierAt(DeliveryMilestone.delivered);
      await _pump(tester, courier: courier);

      expect(find.byKey(const ValueKey('delivery-pass-card')), findsNothing);
    });

    testWidgets('is absent when a live job has no on-device code', (
      tester,
    ) async {
      final courier = await _courierAt(
        DeliveryMilestone.assignedToRider,
        deliveryOtp: '',
      );
      await _pump(tester, courier: courier);

      expect(find.byKey(const ValueKey('delivery-pass-card')), findsNothing);
    });

    testWidgets('renders in dark mode without losing the QR ground', (
      tester,
    ) async {
      final courier = await _courierAt(DeliveryMilestone.assignedToRider);
      await _pump(tester, courier: courier, brightness: Brightness.dark);

      expect(find.byKey(const ValueKey('delivery-pass-card')), findsOneWidget);
      expect(find.byKey(const ValueKey('delivery-pass-qr')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('survives 1.3x text on a narrow phone', (tester) async {
      final courier = await _courierAt(DeliveryMilestone.assignedToRider);
      await _pump(
        tester,
        courier: courier,
        size: const Size(320, 640),
        textScale: 1.3,
      );

      expect(find.byKey(const ValueKey('delivery-pass-card')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}
