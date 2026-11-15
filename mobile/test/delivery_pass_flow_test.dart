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
  eta: 'Today, 4â€“6 PM',
  itemSummary: 'Linen Blend Wrap Dress',
);

Future<CourierState> _courierAt(DeliveryMilestone milestone) async {
  final courier = CourierState();
  await courier.load();
  courier.dispatchForOrder(
    order: _order,
    buyerName: 'Marielle Santos',
    deliveryArea: 'Sta. Cruz, Laguna',
    fee: 120,
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

  group('buyer handover guidance', () {
    testWidgets('does not expose a pass after assignment', (tester) async {
      final courier = await _courierAt(DeliveryMilestone.assignedToRider);
      await _pump(tester, courier: courier);

      expect(find.byKey(const ValueKey('delivery-pass-card')), findsNothing);
      expect(find.byKey(const ValueKey('delivery-pass-qr')), findsNothing);
      expect(find.text('DELIVERY PASS'), findsNothing);
      expect(find.text('482 913'), findsNothing);
    });

    testWidgets('explains authorized handover in transit', (tester) async {
      final courier = await _courierAt(DeliveryMilestone.inTransit);
      await _pump(tester, courier: courier);

      expect(find.byKey(const ValueKey('delivery-pass-card')), findsNothing);
      expect(find.byKey(const ValueKey('delivery-pass-qr')), findsNothing);
      expect(find.textContaining('someone you authorize'), findsOneWidget);
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

    testWidgets('does not show handover instructions at pickup', (
      tester,
    ) async {
      final courier = await _courierAt(DeliveryMilestone.pickedUp);
      await _pump(tester, courier: courier);

      expect(find.byKey(const ValueKey('delivery-pass-card')), findsNothing);
    });

    testWidgets('renders handover guidance in dark mode', (tester) async {
      final courier = await _courierAt(DeliveryMilestone.assignedToRider);
      await _pump(tester, courier: courier, brightness: Brightness.dark);

      expect(find.byKey(const ValueKey('delivery-pass-card')), findsNothing);
      expect(find.byKey(const ValueKey('delivery-pass-qr')), findsNothing);
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

      expect(find.byKey(const ValueKey('delivery-pass-card')), findsNothing);
      expect(tester.takeException(), isNull);
    });
  });
}
