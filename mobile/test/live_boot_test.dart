// test/live_boot_test.dart
//
// Live mode's first frame, and where a failure is allowed to be seen.
//
// `main()` awaits nine `load()`s before `runApp`, so a rejection in any of them
// is not an error state — it is an app that never starts. Two of those loads ask
// the network for data the server does not serve yet (orders, courier jobs). The
// rule this file pins is what came out of that: **a remote failure is never fatal
// at boot, and it is never silent either** — the state keeps it, and the screen
// that asked for the data is where it is shown.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/repositories/courier_repository.dart';
import 'package:zefanya/data/repositories/order_repository.dart';
import 'package:zefanya/screens/buyer/orders_status_screen.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/theme/app_theme.dart';

/// A live build whose server has no `GET /orders` yet: what exists today.
class _UnwiredOrders extends MockOrderRepository {
  @override
  Future<List<Order>> orders() async =>
      throw const ApiNotImplementedException('GET /orders');
}

/// A request that never reached the server — retryable, unlike the above, and
/// able to succeed once [recovered] is set.
class _OfflineOrders extends MockOrderRepository {
  bool recovered = false;

  @override
  Future<List<Order>> orders() async {
    if (recovered) {
      return const [
        Order(
          id: 'ORD-1',
          shopName: 'Loom & Larder',
          status: OrderStatus.toShip,
          eta: 'Seller ships within 1-2 days',
          itemSummary: 'Handwoven Rattan Tote',
        ),
      ];
    }

    throw const NetworkException();
  }
}

/// The same two shapes for the courier half.
class _UnwiredJobs extends MockCourierRepository {
  const _UnwiredJobs();

  @override
  Future<List<DeliveryJob>> assignedJobs() async =>
      throw const ApiNotImplementedException('GET /courier/jobs');
}

class _OfflineJobs extends MockCourierRepository {
  bool recovered = false;

  @override
  Future<List<DeliveryJob>> assignedJobs() async {
    if (recovered) return mockDeliveryJobs;

    throw const NetworkException();
  }
}

Future<void> _pumpOrders(WidgetTester tester, OrderState orders) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 780);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: orders),
        ChangeNotifierProvider(create: (_) => CourierState()),
      ],
      child: MaterialApp(theme: AppTheme.light, home: const OrdersStatusScreen()),
    ),
  );
  await tester.pump();
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('the first frame does not depend on the network', () {
    test('an endpoint the server has not built leaves orders empty, not fatal', () async {
      final state = OrderState(repository: _UnwiredOrders());

      // Before this pass this threw, and because `main()` awaits it before
      // `runApp`, the whole app failed to start over one missing endpoint.
      await state.load();

      expect(state.orders, isEmpty);
      expect(state.loadFailure, isA<ApiNotImplementedException>());
    });

    test('an unreachable server is treated the same way', () async {
      final state = OrderState(repository: _OfflineOrders());

      await state.load();

      expect(state.orders, isEmpty);
      expect(state.loadFailure, isA<NetworkException>());
    });

    test('the courier cache survives an unwired jobs endpoint too', () async {
      final state = CourierState(repository: const _UnwiredJobs());

      await state.load();

      expect(state.allJobs, isEmpty);
      expect(state.loadFailure, isA<ApiNotImplementedException>());
    });
  });

  group('the failure is shown where it is asked for, not swallowed', () {
    test('a refresh that works clears it and fills the cache', () async {
      final repository = _OfflineOrders();
      final state = OrderState(repository: repository);
      await state.load();
      expect(state.loadFailure, isNotNull);

      repository.recovered = true;
      await state.refresh();

      expect(state.loadFailure, isNull);
      expect(state.orders, hasLength(1));
    });

    test('refresh still throws, so a caller that wants the error still gets it', () async {
      final state = OrderState(repository: _UnwiredOrders());
      await state.load();

      await expectLater(state.refresh(), throwsA(isA<ApiNotImplementedException>()));
      expect(state.loadFailure, isA<ApiNotImplementedException>());
    });

    test('the courier half records and clears it the same way', () async {
      final repository = _OfflineJobs();
      final state = CourierState(repository: repository);
      await state.load();
      expect(state.loadFailure, isA<NetworkException>());

      repository.recovered = true;
      await state.refreshJobs();

      expect(state.loadFailure, isNull);
      expect(state.allJobs, isNotEmpty);
    });
  });

  group('the Orders screen', () {
    testWidgets('says why it is empty, and offers Retry only when retrying could work',
        (tester) async {
      // An endpoint that has not been built is not retryable, so no Retry button
      // appears: a button that cannot work teaches a person to mash it.
      final unwired = OrderState(repository: _UnwiredOrders());
      await unwired.load();
      await _pumpOrders(tester, unwired);

      expect(find.text('Your orders could not be loaded'), findsOneWidget);
      expect(find.text('Retry'), findsNothing);

      // A dropped connection is retryable, and Retry then actually re-reads.
      final offline = _OfflineOrders();
      final state = OrderState(repository: offline);
      await state.load();
      await _pumpOrders(tester, state);

      // The tabs *are* the success shape: their absence is what says the failure
      // copy replaced the list rather than sitting above an empty one. Asserted by
      // widget type rather than by label copy, so this keeps working if the
      // stepper's wording changes.
      expect(find.text('Retry'), findsOneWidget);
      expect(find.byType(TabBarView), findsNothing);

      offline.recovered = true;
      await tester.tap(find.text('Retry'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 800));

      expect(find.text('Your orders could not be loaded'), findsNothing);
      expect(find.byType(TabBarView), findsOneWidget);
    });
  });
}

