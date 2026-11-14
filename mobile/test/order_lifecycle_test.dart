// test/order_lifecycle_test.dart
//
// The two buyer actions that change an order after it was placed:
// cancelling before shipment, and confirming receipt after delivery.
//
// Both are mostly *refusals* — no cancellation after pickup, no reason-less
// cancellation, no second receipt confirmation — so those are tested at the
// state seam where the rules live. The repository half matters too: a flag the
// next app launch forgets is not a state, it is a toast.

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/repositories/order_repository.dart';
import 'package:zefanya/state/order_state.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  Future<OrderState> loadedState() async {
    final state = OrderState(repository: MockOrderRepository());
    await state.load();
    return state;
  }

  group('cancel an order', () {
    test('before shipping it is cancelled, with the chosen reason', () async {
      final state = await loadedState();
      final order = state.orders.firstWhere((o) => o.id == 'ORD-10245');

      final error = await state.cancelOrder(order, 'Changed my mind');

      expect(error, isNull);
      final cancelled = state.orders.firstWhere((o) => o.id == order.id);
      expect(cancelled.cancelled, isTrue);
      expect(cancelled.cancellationReason, 'Changed my mind');
      expect(cancelled.canCancel, isFalse);
    });

    test('a reason is required and leaves the order untouched', () async {
      final state = await loadedState();
      final order = state.orders.firstWhere((o) => o.id == 'ORD-10245');

      final error = await state.cancelOrder(order, '   ');

      expect(error, 'Choose a reason before cancelling.');
      final unchanged = state.orders.firstWhere((o) => o.id == order.id);
      expect(unchanged.cancelled, isFalse);
      expect(unchanged.cancellationReason, isNull);
    });

    test('there is no cancellation after the parcel has shipped', () async {
      final state = await loadedState();
      final shipped = state.orders.firstWhere((o) => o.id == 'ORD-10198');

      final error = await state.cancelOrder(shipped, 'Changed my mind');

      expect(error, contains('can no longer be cancelled'));
      final unchanged = state.orders.firstWhere((o) => o.id == shipped.id);
      expect(unchanged.cancelled, isFalse);
    });

    test('a double tap produces one cancellation, not two', () async {
      final state = await loadedState();
      final order = state.orders.firstWhere((o) => o.id == 'ORD-10245');

      final results = await Future.wait([
        state.cancelOrder(order, 'Changed my mind'),
        state.cancelOrder(order, 'Changed my mind'),
      ]);

      expect(results.where((r) => r == null), hasLength(1));
      expect(results.where((r) => r != null), hasLength(1));
      expect(
        state.orders.firstWhere((o) => o.id == order.id).cancelled,
        isTrue,
      );
    });

    test('the cancellation survives a fresh repository load', () async {
      final state = await loadedState();
      final order = state.orders.firstWhere((o) => o.id == 'ORD-10245');
      await state.cancelOrder(order, 'Ordered the wrong item');

      final restarted = OrderState(repository: MockOrderRepository());
      await restarted.load();

      final restored = restarted.orders.firstWhere((o) => o.id == order.id);
      expect(restored.cancelled, isTrue);
      expect(restored.cancellationReason, 'Ordered the wrong item');
    });
  });

  group('confirm receipt', () {
    test('a delivered order moves to Completed exactly once', () async {
      final state = await loadedState();
      final delivered = state.orders.firstWhere((o) => o.id == 'ORD-09876');

      final error = await state.confirmOrderReceived(delivered);
      expect(error, isNull);

      final completed = state.orders.firstWhere((o) => o.id == delivered.id);
      expect(completed.buyerConfirmed, isTrue);
      expect(completed.isCompleted, isTrue);
      expect(completed.canConfirmReceived, isFalse);

      final second = await state.confirmOrderReceived(completed);
      expect(second, 'You already confirmed this order.');
    });

    test('Order Received is rejected before delivery', () async {
      final state = await loadedState();
      final inTransit = state.orders.firstWhere((o) => o.id == 'ORD-10198');

      final error = await state.confirmOrderReceived(inTransit);

      expect(error, contains('only after the order is delivered'));
      expect(
        state.orders.firstWhere((o) => o.id == inTransit.id).buyerConfirmed,
        isFalse,
      );
    });

    test('receipt confirmation survives a fresh repository load', () async {
      final state = await loadedState();
      final delivered = state.orders.firstWhere((o) => o.id == 'ORD-09876');
      await state.confirmOrderReceived(delivered);

      final restarted = OrderState(repository: MockOrderRepository());
      await restarted.load();

      expect(
        restarted.orders.firstWhere((o) => o.id == delivered.id).buyerConfirmed,
        isTrue,
      );
    });
  });

  group('order flags', () {
    test('copyWith keeps the buyer flags while the courier moves the status',
        () {
      const order = Order(
        id: 'ORD-TEST',
        shopName: 'Test Studio',
        status: OrderStatus.toShip,
        eta: 'Soon',
        itemSummary: 'One thing',
      );

      final confirmed = order.copyWith(buyerConfirmed: true);
      final delivered = confirmed.copyWith(status: OrderStatus.toRate);

      expect(delivered.buyerConfirmed, isTrue);
      expect(delivered.isCompleted, isTrue);
      expect(delivered.status, OrderStatus.toRate);
    });

    test('a cancelled order is never completed', () {
      const order = Order(
        id: 'ORD-TEST',
        shopName: 'Test Studio',
        status: OrderStatus.toShip,
        eta: 'Soon',
        itemSummary: 'One thing',
        cancelled: true,
        cancellationReason: 'Changed my mind',
      );

      expect(order.isDelivered, isFalse);
      expect(order.canCancel, isFalse);
      expect(order.canConfirmReceived, isFalse);
    });
  });
}
