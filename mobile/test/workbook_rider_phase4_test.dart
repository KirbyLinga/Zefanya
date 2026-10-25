// test/workbook_rider_phase4_test.dart
//
// Case-named acceptance tests for the Rider (RID) rows closed in Phase 4.
// Each group is the workbook row, so the evidence maps mechanically:
//
//   RID-PROF-001  rider profile shows vehicle, licence, company, status, docs
//   RID-PROF-002  vehicle update replaces the plate and flags re-verification
//   RID-PROF-003  a plate registered to another rider is refused
//   RID-PROF-004  licence expiry boundary: yesterday rejected, today accepted
//   RID-PROF-005  document rules: 5.0 MB JPG accepted, others rejected, old kept
//   RID-PROF-006  a suspended rider cannot go online
//   RID-AVAIL-003 going offline with a picked-up job keeps the job
//   RID-PICK-001  pickup details: seller, address, contact, tracking, parcels
//   RID-DLV-004   delivery OTP: 5 digits, five wrong, 15-minute lock
//   RID-DLV-005   COD amount must match exactly
//   RID-DLV-006   a prepaid order asks for no COD and adds no balance
//   RID-EARN-001  history lists date, tracking number, result and pay
//   RID-EARN-002  today's total is the sum of today's payouts
//   RID-EARN-003  COD balance is the sum of cash collected at the door
//   RID-EARN-004  a pending remittance does not clear the balance; confirmation does
//   RID-EARN-005  a short remittance leaves the shortfall outstanding
//
// RID-PICK-003/004/005 live in pickup_verification_test.dart and
// RID-FAIL-001..005 in courier_failure_flow_test.dart.

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/courier/courier_profit_screen.dart';
import 'package:zefanya/screens/courier/delivery_tracker_screen.dart';
import 'package:zefanya/screens/courier/pickup_dashboard_screen.dart';
import 'package:zefanya/screens/shared/profile_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/utils/money_format.dart';
import 'package:zefanya/utils/rider_validators.dart';

const _order = Order(
  id: 'ORD-RID-1',
  shopName: 'Willow & Bloom Atelier',
  status: OrderStatus.toShip,
  eta: 'soon',
  itemSummary: 'Linen Blend Wrap Dress',
);

const _pixelPngBase64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8'
    'z8BQDwAFgwJ/lK3Q6wAAAABJRU5ErkJggg==';

Future<(CourierState, String)> _courierWithJob({
  double codAmount = 500,
  bool prepaid = false,
  String buyer = 'Marielle Santos',
  String tracking = 'ZEF-RID-01',
  int parcels = 1,
  String otp = kMockDeliveryOtp,
  CourierState? state,
  Order order = _order,
}) async {
  final courier = state ?? CourierState();
  if (state == null) await courier.load();
  courier.dispatchForOrder(
    order: order,
    buyerName: buyer,
    deliveryArea: 'Sta. Cruz, Laguna',
    fee: 85,
    buyerPhone: '0917 555 0201',
    sellerPhone: '0917 555 0101',
    trackingNumber: tracking,
    codAmount: codAmount,
    prepaid: prepaid,
    deliveryOtp: otp,
  );
  return (courier, courier.jobForOrder(order.id)!.id);
}

Order _secondOrder(String id) => Order(
  id: id,
  shopName: 'Rattan & Reed Co.',
  status: OrderStatus.toShip,
  eta: 'soon',
  itemSummary: 'Market Basket',
);

Widget _app({
  required AuthState auth,
  required CourierState courier,
  required Widget home,
}) {
  return MultiProvider(
    providers: [
      ChangeNotifierProvider<AuthState>.value(value: auth),
      ChangeNotifierProvider<CourierState>.value(value: courier),
      ChangeNotifierProvider(create: (_) => OrderState()),
      ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
      ChangeNotifierProvider(create: (_) => WishlistState()),
      ChangeNotifierProvider(create: (_) => ThemeState()),
    ],
    child: MaterialApp(theme: AppTheme.light, home: home),
  );
}

void _phone(WidgetTester tester) {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
}

Future<File> _docFile(
  Directory dir,
  String name, {
  int bytes = 1024,
}) async {
  final file = File('${dir.path}/$name');
  // Sync IO on purpose: widget tests run on a fake clock, so an awaited
  // dart:io Future (createTemp/writeAsBytes) never completes there.
  file.writeAsBytesSync(List<int>.filled(bytes, 0));
  return file;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('RID-PROF-001', () {
    testWidgets('profile shows the rider record and opens both documents',
        (tester) async {
      _phone(tester);
      final dir = Directory.systemTemp.createTempSync('rid_prof_001');
      addTearDown(() => dir.deleteSync(recursive: true));
      final png = File('${dir.path}/licence.png');
      png.writeAsBytesSync(base64Decode(_pixelPngBase64));

      final auth = AuthState()
        ..role = UserRole.courier
        ..status = AccountStatus.active
        ..name = 'Pedro Jose Garcia'
        ..email = 'pedro@example.com';
      auth.saveRiderRegistration(
        company: 'Bilis Hatid Express Inc.',
        vehicleType: 'Motorcycle',
        plate: '123ABC',
        licenseNumber: 'N01-23-456789',
        licenseExpiry: DateTime(2030, 3, 22),
        licensePath: png.path,
        orCrPath: png.path,
      );
      final courier = CourierState();
      await courier.load();

      await tester.pumpWidget(
        _app(
          auth: auth,
          courier: courier,
          home: const ProfileScreen(title: 'Courier Profile'),
        ),
      );
      await tester.pump(const Duration(milliseconds: 100));

      await tester.scrollUntilVisible(
        find.byKey(const ValueKey('rider-profile-section')),
        300,
      );
      expect(find.text('Pedro Jose Garcia'), findsWidgets);
      expect(find.text('Bilis Hatid Express Inc.'), findsOneWidget);
      expect(find.text('Motorcycle'), findsOneWidget);
      expect(find.text('123ABC'), findsOneWidget);
      expect(find.text('N01-23-456789'), findsOneWidget);
      expect(find.text('03/22/2030'), findsOneWidget);
      expect(find.text('Active'), findsOneWidget);

      // Fixed pumps, not pumpAndSettle: the profile's ambient motion never
      // settles, and pumpAndSettle waits its full 10-minute timeout for it.
      // The section header is visible after the scroll above, but the two
      // document buttons sit just below the fold, so bring each into view
      // before tapping it.
      await tester.ensureVisible(
        find.byKey(const ValueKey('rider-document-license')),
      );
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('rider-document-license')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.byKey(const ValueKey('document-preview-Licence')),
          findsOneWidget);
      await tester.tap(find.text('Close'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      await tester.ensureVisible(
        find.byKey(const ValueKey('rider-document-orcr')),
      );
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('rider-document-orcr')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.byKey(const ValueKey('document-preview-OR/CR')),
          findsOneWidget);
    });
  });

  group('RID-PROF-002', () {
    test('a changed vehicle replaces the plate and asks for re-verification',
        () async {
      final auth = AuthState();
      auth.saveRiderRegistration(
        company: 'Bilis Hatid Express Inc.',
        vehicleType: 'Motorcycle',
        plate: '123ABC',
        licenseNumber: 'N01-23-456789',
        licenseExpiry: DateTime(2030, 3, 22),
      );

      final problem = auth.updateRiderVehicle(
        company: 'Bilis Hatid Express Inc.',
        vehicleType: 'Motorcycle',
        plate: 'XYZ 9876',
        licenseNumber: 'N01-23-456789',
        licenseExpiry: DateTime(2030, 3, 22),
        today: DateTime(2026, 10, 25),
      );

      expect(problem, isNull);
      expect(auth.riderPlate, 'XYZ 9876');
      expect(auth.riderReverificationRequired, isTrue);
      expect(auth.riderPlate, isNot('123ABC'));
    });
  });

  group('RID-PROF-003', () {
    test('a plate used by another rider is refused and the old plate stays',
        () async {
      final auth = AuthState();
      auth.saveRiderRegistration(plate: '123ABC', vehicleType: 'Motorcycle');

      // Both spellings of the same plate: the registry compares the
      // normalised form, so neither may slip through on punctuation.
      for (final plate in ['ABC 1234', 'ABC-1234']) {
        final problem = auth.updateRiderVehicle(
          vehicleType: 'Motorcycle',
          plate: plate,
          licenseNumber: 'N01-23-456789',
          licenseExpiry: DateTime(2030, 3, 22),
        );

        expect(
          problem,
          'That plate number is already registered to another rider.',
        );
        expect(auth.riderPlate, '123ABC');
      }
    });
  });

  group('RID-PROF-004', () {
    test('expiry yesterday is rejected, today is accepted', () {
      final today = DateTime(2026, 10, 25);
      final yesterday = today.subtract(const Duration(days: 1));

      expect(validateLicenseExpiry(yesterday, today: today),
          contains('expired'));
      expect(validateLicenseExpiry(today, today: today), isNull);

      final auth = AuthState();
      final rejected = auth.updateRiderVehicle(
        vehicleType: 'Motorcycle',
        plate: 'XYZ 9876',
        licenseNumber: 'N01-23-456789',
        licenseExpiry: yesterday,
        today: today,
      );
      expect(rejected, contains('expired'));
      expect(auth.riderPlate, isNull);

      final accepted = auth.updateRiderVehicle(
        vehicleType: 'Motorcycle',
        plate: 'XYZ 9876',
        licenseNumber: 'N01-23-456789',
        licenseExpiry: today,
        today: today,
      );
      expect(accepted, isNull);
      expect(auth.riderLicenseExpiry, today);
    });
  });

  group('RID-PROF-005', () {
    test('only a 5.0 MB JPG is accepted; a rejected file keeps the old one',
        () async {
      final dir = await Directory.systemTemp.createTemp('rid_prof_005');
      addTearDown(() => dir.delete(recursive: true));
      final jpg = await _docFile(
        dir,
        'licence.jpg',
        bytes: kRiderDocumentMaxBytes,
      );
      final tooBig = await _docFile(
        dir,
        'too_big.jpg',
        bytes: kRiderDocumentMaxBytes + 1,
      );
      final text = await _docFile(dir, 'notes.txt');

      expect(validateRiderDocumentPath(jpg.path), isNull);
      expect(validateRiderDocumentPath(tooBig.path), contains('5 MB'));
      expect(validateRiderDocumentPath(text.path), contains('JPG'));

      final auth = AuthState();
      auth.saveRiderRegistration(
        vehicleType: 'Motorcycle',
        plate: 'XYZ 9876',
        licenseNumber: 'N01-23-456789',
        licenseExpiry: DateTime(2030, 3, 22),
        licensePath: jpg.path,
      );

      final rejected = auth.updateRiderVehicle(
        vehicleType: 'Motorcycle',
        plate: 'XYZ 9876',
        licenseNumber: 'N01-23-456789',
        licenseExpiry: DateTime(2030, 3, 22),
        licensePath: tooBig.path,
        today: DateTime(2026, 10, 25),
      );
      expect(rejected, contains('5 MB'));
      expect(auth.riderLicensePath, jpg.path);
    });
  });

  group('RID-PROF-006', () {
    testWidgets('a suspended rider cannot go online and sees the message',
        (tester) async {
      _phone(tester);
      final courier = CourierState();
      await courier.load();
      courier.setSuspended(suspended: true, reason: 'Unsettled remittance');

      await tester.pumpWidget(
        _app(
          auth: AuthState(),
          courier: courier,
          home: const PickupDashboardScreen(),
        ),
      );
      await tester.pump(const Duration(milliseconds: 800));

      final toggle = tester.widget<Switch>(
        find.byKey(const ValueKey('availability-switch')),
      );
      expect(toggle.onChanged, isNull);
      expect(find.text('Unsettled remittance'), findsOneWidget);
      expect(courier.goOnline(), contains('suspended'));
      expect(courier.isOnline, isFalse);
    });
  });

  group('RID-AVAIL-003', () {
    test('going offline with an active job keeps the job deliverable',
        () async {
      final (courier, jobId) = await _courierWithJob();
      expect(courier.goOnline(), isNull);
      courier.advanceMilestone(jobId, DeliveryMilestone.assignedToRider);

      courier.goOffline();

      expect(courier.isOnline, isFalse);
      expect(
        courier.jobsForDelivery.map((j) => j.job.id),
        contains(jobId),
      );
      courier.completeJob(jobId);
      expect(courier.isCompleted(jobId), isTrue);
    });
  });

  group('RID-PICK-001', () {
    testWidgets('pickup details show seller, address, contact, tracking and '
        'parcel count', (tester) async {
      _phone(tester);
      final (courier, jobId) = await _courierWithJob(
        tracking: 'ZEF-RID-777',
        parcels: 1,
      );

      await tester.pumpWidget(
        _app(
          auth: AuthState(),
          courier: courier,
          home: MediaQuery(
            data: const MediaQueryData(
              size: Size(390, 844),
              disableAnimations: true,
            ),
            child: DeliveryTrackerScreen(jobId: jobId),
          ),
        ),
      );
      await tester.pump(const Duration(milliseconds: 100));

      expect(find.text('Willow & Bloom Atelier'), findsWidgets);
      expect(find.text('Sta. Cruz, Laguna'), findsWidgets);
      expect(find.text('0917 555 0101'), findsWidgets);
      expect(find.text('ZEF-RID-777'), findsOneWidget);
      expect(find.text('Parcel'), findsWidgets);
      expect(find.text('1'), findsOneWidget);
    });
  });

  group('RID-DLV-004', () {
    test('5 digits is refused, five wrong codes lock 15 minutes, then the '
        'correct code works', () async {
      var now = DateTime(2026, 10, 25, 12);
      final courier = CourierState(clock: () => now);
      final (state, jobId) = await _courierWithJob(state: courier);
      expect(state, same(courier));

      expect(
        courier.validateDeliveryOtp(jobId, '12345'),
        contains('6-digit'),
      );
      expect(courier.otpFailuresFor(jobId), 0);

      for (var attempt = 1; attempt <= 4; attempt++) {
        final problem = courier.validateDeliveryOtp(jobId, '000000');
        expect(problem, contains('incorrect'));
        expect(courier.otpFailuresFor(jobId), attempt);
      }
      expect(
        courier.validateDeliveryOtp(jobId, '000000'),
        contains('locked for 15 minutes'),
      );
      expect(courier.isOtpLocked(jobId), isTrue);

      // The correct code is still refused while the lock is running.
      expect(
        courier.validateDeliveryOtp(jobId, kMockDeliveryOtp),
        contains('Try again in'),
      );

      now = now.add(const Duration(minutes: 15));
      expect(courier.isOtpLocked(jobId), isFalse);
      expect(courier.validateDeliveryOtp(jobId, kMockDeliveryOtp), isNull);
    });
  });

  group('RID-DLV-005', () {
    test('COD must match exactly; one centavo either side is refused',
        () async {
      final (courier, jobId) = await _courierWithJob(codAmount: 500);

      expect(
        courier.validateCodAmount(jobId, '499.99'),
        contains('does not match'),
      );
      expect(
        courier.validateCodAmount(jobId, '500.01'),
        contains('does not match'),
      );
      expect(courier.validateCodAmount(jobId, '500.00'), isNull);
      expect(courier.validateCodAmount(jobId, '₱500'), isNull);
    });
  });

  group('RID-DLV-006', () {
    test('a prepaid order asks for no COD and adds no COD balance', () async {
      final (courier, jobId) = await _courierWithJob(
        codAmount: 500,
        prepaid: true,
      );
      final job = courier.jobById(jobId)!;

      expect(job.paymentLabel, 'Prepaid · Paid');
      expect(job.hasCod, isFalse);
      expect(courier.validateCodAmount(jobId, ''), isNull);

      courier.completeJob(jobId);
      expect(courier.codOutstanding, 0);
      expect(courier.codCollections, isEmpty);
    });
  });

  group('RID-EARN-001', () {
    testWidgets('history shows result, tracking number and pay', (tester) async {
      _phone(tester);
      final (courier, deliveredId) = await _courierWithJob(
        tracking: 'ZEF-EARN-01',
      );
      courier.completeJob(deliveredId);

      final returnedOrder = Order(
        id: 'ORD-RID-2',
        shopName: 'Rattan & Reed Co.',
        status: OrderStatus.toShip,
        eta: 'soon',
        itemSummary: 'Market Basket',
      );
      courier.dispatchForOrder(
        order: returnedOrder,
        buyerName: 'Jonah Perez',
        deliveryArea: 'Victoria, Laguna',
        fee: 100,
        trackingNumber: 'ZEF-EARN-02',
        codAmount: 700,
      );
      final returnedId = courier.jobForOrder(returnedOrder.id)!.id;
      courier.completeJob(returnedId, returned: true);

      await tester.pumpWidget(
        _app(
          auth: AuthState(),
          courier: courier,
          home: const CourierProfitScreen(),
        ),
      );
      await tester.pump(const Duration(milliseconds: 100));

      await tester.scrollUntilVisible(
        find.byKey(ValueKey('transaction-result-$deliveredId')),
        300,
      );
      expect(find.textContaining('Delivered · ZEF-EARN-01'), findsOneWidget);
      expect(find.textContaining('Returned · ZEF-EARN-02'), findsOneWidget);
      expect(find.text(formatPeso(85)), findsWidgets);
      expect(find.text(formatPeso(50)), findsWidgets);
    });
  });

  group('RID-EARN-002', () {
    test('today total is exactly the sum of today\'s payouts', () async {
      final (courier, first) = await _courierWithJob();
      courier.completeJob(first);
      final (_, second) = await _courierWithJob(
        state: courier,
        tracking: 'ZEF-EARN-03',
        order: _secondOrder('ORD-RID-3'),
      );
      courier.completeJob(second);

      expect(courier.todayEarnings, 170);
      expect(courier.weekEarnings, 170);
    });
  });

  group('RID-EARN-003', () {
    test('COD balance sums cash collected on delivery', () async {
      final (courier, first) = await _courierWithJob(codAmount: 500);
      courier.completeJob(first);
      final (_, second) = await _courierWithJob(
        state: courier,
        codAmount: 1300,
        tracking: 'ZEF-EARN-04',
        order: _secondOrder('ORD-RID-4'),
      );
      courier.completeJob(second);

      expect(courier.codOutstanding, 1800);
      expect(courier.codCollections.length, 2);
      expect(
        courier.codCollections.map((c) => c.amount),
        containsAll(<double>[500, 1300]),
      );
    });
  });

  group('RID-EARN-004', () {
    test('pending remittance leaves the balance; confirmation clears it',
        () async {
      final (courier, first) = await _courierWithJob(codAmount: 500);
      courier.completeJob(first);
      final (_, second) = await _courierWithJob(
        state: courier,
        codAmount: 1300,
        tracking: 'ZEF-EARN-05',
        order: _secondOrder('ORD-RID-5'),
      );
      courier.completeJob(second);
      expect(courier.codOutstanding, 1800);

      expect(courier.requestRemittance(1800), isNull);
      expect(courier.codOutstanding, 1800);
      expect(courier.pendingRemittanceTotal, 1800);

      final pending = courier.remittances.single;
      expect(courier.confirmRemittance(pending.id), isTrue);
      expect(courier.codOutstanding, 0);
      expect(courier.pendingRemittanceTotal, 0);
    });
  });

  group('RID-EARN-005', () {
    test('a short remittance leaves the shortfall outstanding', () async {
      final (courier, first) = await _courierWithJob(codAmount: 500);
      courier.completeJob(first);
      final (_, second) = await _courierWithJob(
        state: courier,
        codAmount: 1300,
        tracking: 'ZEF-EARN-06',
        order: _secondOrder('ORD-RID-6'),
      );
      courier.completeJob(second);

      expect(courier.requestRemittance(1750), isNull);
      courier.confirmRemittance(courier.remittances.single.id);

      expect(courier.codOutstanding, 50);
    });
  });
}
