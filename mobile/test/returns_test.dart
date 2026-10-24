// test/returns_test.dart
//
// The return/refund rules the workbook checks separately from "can I tap the
// button": delivery-only access, the day-7 boundary, evidence limits, ordered
// photos, persistence, escalation, and the block on Order Received while a
// request is open.
//
// The buyer-side status card is tested with a repository that supplies a
// rejected request. That is deliberate: this app has no seller dashboard, so
// the seller's decision cannot be produced here. What can be proved is that a
// rejection carrying the seller's exact words renders them, and that Escalate
// moves the request to the next state.

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/repositories/order_repository.dart';
import 'package:zefanya/data/repositories/return_repository.dart';
import 'package:zefanya/screens/buyer/order_detail_screen.dart';
import 'package:zefanya/screens/buyer/return_screen.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/return_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/theme/app_theme.dart';

final _now = DateTime(2026, 10, 23, 12);

Order _order({
  String id = 'ORD-RET-1',
  OrderStatus status = OrderStatus.toRate,
  bool confirmed = false,
  DateTime? deliveredAt,
}) =>
    Order(
      id: id,
      shopName: 'Willow & Bloom Atelier',
      status: status,
      eta: status == OrderStatus.toRate ? 'Delivered' : 'On the way',
      itemSummary: 'Cotton Gauze Scarf',
      buyerConfirmed: confirmed,
      deliveredAt: deliveredAt ?? _now.subtract(const Duration(days: 2)),
    );

Future<ReturnState> _loadedState([ReturnRepository? repository]) async {
  final state = ReturnState(repository: repository);
  await state.load();
  return state;
}

class _RejectedReturnRepository implements ReturnRepository {
  _RejectedReturnRepository(this.request);

  ReturnRequest request;

  @override
  Future<void> load() async {}

  @override
  Future<List<ReturnRequest>> all() async => [request];

  @override
  Future<ReturnRequest> submit({
    required String orderId,
    required String description,
    required List<String> photoPaths,
    required DateTime submittedAt,
  }) async {
    throw StateError('Not part of this test.');
  }

  @override
  Future<ReturnRequest> escalate(String requestId, DateTime at) async {
    request = request.copyWith(
      status: ReturnStatus.escalated,
      escalatedAt: at,
    );
    return request;
  }
}

class _UnwiredReturns implements ReturnRepository {
  @override
  Future<void> load() async {}

  @override
  Future<List<ReturnRequest>> all() async =>
      throw const ApiNotImplementedException('GET /returns');

  @override
  Future<ReturnRequest> submit({
    required String orderId,
    required String description,
    required List<String> photoPaths,
    required DateTime submittedAt,
  }) async =>
      throw const ApiNotImplementedException(
        'POST /orders/{sellerOrder}/returns',
      );

  @override
  Future<ReturnRequest> escalate(String requestId, DateTime at) async =>
      throw const ApiNotImplementedException(
        'POST /returns/{return}/escalate',
      );
}

Future<void> _pumpDetail(
  WidgetTester tester,
  Order order,
  OrderState orders,
) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 900);
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
            size: Size(390, 900),
            disableAnimations: true,
          ),
          child: OrderDetailScreen(order: order),
        ),
      ),
    ),
  );
  await tester.pump();
}

Future<void> _pumpReturnForm(
  WidgetTester tester,
  Order order,
  OrderState orders, {
  required ReturnPhotoPicker picker,
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    ChangeNotifierProvider.value(
      value: orders,
      child: MaterialApp(
        theme: AppTheme.light,
        home: MediaQuery(
          data: const MediaQueryData(
            size: Size(390, 900),
            disableAnimations: true,
          ),
          child: ReturnScreen(order: order, photoPicker: picker),
        ),
      ),
    ),
  );
  await tester.pump();
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('when a return can be requested', () {
    test('before delivery it is refused', () async {
      final state = await _loadedState();
      for (final status in [
        OrderStatus.toShip,
        OrderStatus.inTransit,
        OrderStatus.outForDelivery,
      ]) {
        final error = await state.submit(
          order: _order(status: status),
          description: 'The seam is torn',
          photoPaths: const ['/tmp/evidence.jpg'],
          now: _now,
        );
        expect(error, 'Returns are available only after delivery.');
      }
      expect(state.all, isEmpty);
    });

    test('a completed order is refused', () async {
      final state = await _loadedState();
      final error = await state.submit(
        order: _order(confirmed: true),
        description: 'The seam is torn',
        photoPaths: const ['/tmp/evidence.jpg'],
        now: _now,
      );
      expect(error, 'This order has already been completed.');
    });

    test('day 7 is allowed and day 8 is not', () async {
      final state = await _loadedState();
      final deliveredAt = DateTime(2026, 10, 16, 12);

      final allowed = await state.submit(
        order: _order(id: 'ORD-RET-DAY7', deliveredAt: deliveredAt),
        description: 'The seam is torn',
        photoPaths: const ['/tmp/evidence.jpg'],
        now: DateTime(2026, 10, 23, 12),
      );
      expect(allowed, isNull);

      final refused = await state.submit(
        order: _order(
          id: 'ORD-RET-DAY8',
          deliveredAt: DateTime(2026, 10, 15, 12),
        ),
        description: 'The seam is torn',
        photoPaths: const ['/tmp/evidence.jpg'],
        now: DateTime(2026, 10, 23, 12),
      );
      expect(refused, 'The 7-day return window has closed.');
    });

    test('one request per order; an open request blocks Order Received',
        () async {
      final returns = ReturnState();
      final orders = OrderState(
        repository: MockOrderRepository(),
        returns: returns,
      );
      await orders.load();
      final delivered =
          orders.orders.firstWhere((order) => order.id == 'ORD-09876');

      final error = await returns.submit(
        order: delivered,
        description: 'The seam is torn',
        photoPaths: const ['/tmp/evidence.jpg'],
      );
      expect(error, isNull);

      final duplicate = await returns.submit(
        order: delivered,
        description: 'The seam is torn',
        photoPaths: const ['/tmp/evidence.jpg'],
      );
      expect(duplicate, 'A return request already exists for this order.');

      final refusal = await orders.confirmOrderReceived(delivered);
      expect(refusal, contains('open return request'));
      expect(
        orders.orders.firstWhere((o) => o.id == delivered.id).buyerConfirmed,
        isFalse,
      );
    });
  });

  group('evidence rules', () {
    test('the description must be at least 10 characters', () async {
      final state = await _loadedState();
      final short = await state.submit(
        order: _order(id: 'ORD-RET-SHORT'),
        description: '123456789',
        photoPaths: const ['/tmp/evidence.jpg'],
        now: _now,
      );
      expect(short, 'Describe the problem in at least 10 characters.');

      final exact = await state.submit(
        order: _order(id: 'ORD-RET-EXACT'),
        description: '1234567890',
        photoPaths: const ['/tmp/evidence.jpg'],
        now: _now,
      );
      expect(exact, isNull);
    });

    test('one to five photos are required', () async {
      final state = await _loadedState();
      final none = await state.submit(
        order: _order(id: 'ORD-RET-NONE'),
        description: 'The seam is torn',
        photoPaths: const [],
        now: _now,
      );
      expect(none, 'Attach at least 1 photo of the item.');

      final tooMany = await state.submit(
        order: _order(id: 'ORD-RET-MANY'),
        description: 'The seam is torn',
        photoPaths: const ['1.jpg', '2.jpg', '3.jpg', '4.jpg', '5.jpg', '6.jpg'],
        now: _now,
      );
      expect(tooMany, 'Attach no more than 5 photos.');

      final five = await state.submit(
        order: _order(id: 'ORD-RET-FIVE'),
        description: 'The seam is torn',
        photoPaths: const ['1.jpg', '2.jpg', '3.jpg', '4.jpg', '5.jpg'],
        now: _now,
      );
      expect(five, isNull);
    });

    test('photos keep the order they were chosen in and persist', () async {
      final state = await _loadedState();
      final paths = ['/tmp/first.jpg', '/tmp/second.jpg', '/tmp/third.jpg'];

      final error = await state.submit(
        order: _order(id: 'ORD-RET-PHOTOS'),
        description: 'The seam is torn',
        photoPaths: paths,
        now: _now,
      );
      expect(error, isNull);
      expect(
        state.latestForOrder('ORD-RET-PHOTOS')!.photoPaths,
        paths,
      );

      final restarted = await _loadedState();
      final restored = restarted.latestForOrder('ORD-RET-PHOTOS')!;
      expect(restored.photoPaths, paths);
      expect(restored.status, ReturnStatus.submitted);
      expect(restored.description, 'The seam is torn');
    });
  });

  group('tracking and escalation', () {
    test('a rejected request carries the exact seller reason', () async {
      final request = ReturnRequest(
        id: 'RET-2001',
        orderId: 'ORD-RET-REJECTED',
        description: 'The seam is torn',
        photoPaths: const ['/tmp/evidence.jpg'],
        submittedAt: _now,
        status: ReturnStatus.rejected,
        sellerReason: 'Item shows signs of use.',
      );
      final state = ReturnState(
        repository: _RejectedReturnRepository(request),
      );
      await state.load();

      final stored = state.latestForOrder('ORD-RET-REJECTED')!;
      expect(stored.orderLabel, 'Rejected');
      expect(stored.sellerReason, 'Item shows signs of use.');
      expect(state.canEscalate(stored), isTrue);

      final error = await state.escalate(stored, now: _now);
      expect(error, isNull);
      final escalated = state.latestForOrder('ORD-RET-REJECTED')!;
      expect(escalated.status, ReturnStatus.escalated);
      expect(escalated.isOpen, isTrue);
      expect(escalated.sellerReason, 'Item shows signs of use.');

      expect(
        await state.escalate(escalated, now: _now),
        'Only a rejected return can be escalated.',
      );
    });

    test('an unwired live endpoint is recorded, not fatal at boot', () async {
      final state = ReturnState(repository: _UnwiredReturns());
      await state.load();

      expect(state.all, isEmpty);
      expect(state.loadFailure, isA<ApiNotImplementedException>());
    });
  });

  group('the buyer screen', () {
    testWidgets('rejection shows the exact reason and Escalate', (tester) async {
      final request = ReturnRequest(
        id: 'RET-2001',
        orderId: 'ORD-RET-REJECTED',
        description: 'The seam is torn',
        photoPaths: const ['/tmp/evidence.jpg'],
        submittedAt: _now,
        status: ReturnStatus.rejected,
        sellerReason: 'Item shows signs of use.',
      );
      final returns = ReturnState(
        repository: _RejectedReturnRepository(request),
      );
      final orders = OrderState(
        repository: MockOrderRepository(),
        returns: returns,
      );
      await orders.load();

      await _pumpDetail(
        tester,
        _order(
          id: 'ORD-RET-REJECTED',
          deliveredAt: _now.subtract(const Duration(days: 2)),
        ),
        orders,
      );

      expect(find.text('Rejected'), findsWidgets);
      expect(find.text('Item shows signs of use.'), findsOneWidget);
      expect(find.text('Escalate'), findsOneWidget);

      await tester.tap(find.text('Escalate'));
      await tester.pumpAndSettle();

      expect(find.text('Escalated'), findsWidgets);
      expect(find.text('Escalate'), findsNothing);
    });

    testWidgets('an open request hides Order Received and names the status',
        (tester) async {
      final returns = ReturnState();
      final orders = OrderState(
        repository: MockOrderRepository(),
        returns: returns,
      );
      await orders.load();
      final order = _order(id: 'ORD-RET-OPEN');
      await returns.submit(
        order: order,
        description: 'The seam is torn',
        photoPaths: const ['/tmp/evidence.jpg'],
      );

      await _pumpDetail(tester, order, orders);

      expect(find.text('Return/Refund'), findsWidgets);
      expect(find.text('Order Received'), findsNothing);
      expect(
        find.textContaining('Order Received is unavailable'),
        findsWidgets,
      );
    });

    testWidgets('photos add in order, preview, remove, and submit',
        (tester) async {
      final first = File('/returns/first.png');
      final second = File('/returns/second.png');
      final third = File('/returns/third.png');
      final queue = [first, second, third];

      final returns = ReturnState();
      final orders = OrderState(
        repository: MockOrderRepository(),
        returns: returns,
      );
      await orders.load();
      final order = _order(id: 'ORD-RET-FORM');

      await _pumpReturnForm(
        tester,
        order,
        orders,
        picker: (_) async => queue.removeAt(0),
      );

      await tester.tap(find.byKey(const ValueKey('return-submit')));
      await tester.pump();
      expect(
        find.text('Describe the problem in at least 10 characters.'),
        findsOneWidget,
      );

      await tester.enterText(
        find.byKey(const ValueKey('return-description')),
        'The seam is torn',
      );
      await tester.tap(find.byKey(const ValueKey('return-submit')));
      await tester.pump();
      expect(find.text('Attach at least 1 photo of the item.'), findsOneWidget);

      for (var i = 0; i < 3; i++) {
        await tester.tap(find.byKey(const ValueKey('return-photo-add')));
        // Bounded pumps rather than pumpAndSettle: the image stream and the
        // text cursor are not things this test needs to wait for.
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 20));
      }
      expect(find.byKey(const ValueKey('return-photo-0')), findsOneWidget);
      expect(find.byKey(const ValueKey('return-photo-1')), findsOneWidget);
      expect(find.byKey(const ValueKey('return-photo-2')), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('return-photo-remove-1')));
      await tester.pump();
      expect(find.byKey(const ValueKey('return-photo-0')), findsOneWidget);
      expect(find.byKey(const ValueKey('return-photo-1')), findsOneWidget);
      expect(find.byKey(const ValueKey('return-photo-2')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('return-submit')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));

      expect(find.text('Return request submitted'), findsOneWidget);
      final submitted = returns.latestForOrder(order.id)!;
      expect(submitted.photoPaths, [first.path, third.path]);
    });
  });
}
