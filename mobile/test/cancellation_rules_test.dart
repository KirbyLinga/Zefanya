import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/dto/remote_orders.dart';
import 'package:zefanya/data/repositories/order_repository.dart';
import 'package:zefanya/state/order_state.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test(
    'BUY-CAN-001 only known stages before Ready for Pickup allow cancellation',
    () {
      for (final status in ['PLACED', 'CONFIRMED', 'PREPARING']) {
        expect(
          orderFromShipmentJson({'id': '1', 'status': status}).canCancel,
          isTrue,
        );
      }
      for (final status in [
        'READY_FOR_PICKUP',
        'PICKED_UP',
        'DELIVERED',
        'COMPLETED',
        'CANCELLED',
        'UNKNOWN',
      ]) {
        expect(
          orderFromShipmentJson({'id': '1', 'status': status}).canCancel,
          isFalse,
        );
      }
      expect(
        orderFromShipmentJson({
          'id': '1',
          'status': 'PREPARING',
          'can_cancel': false,
        }).canCancel,
        isFalse,
      );
    },
  );

  test('BUY-CAN-002 an arbitrary reason never changes a mock order', () async {
    final state = OrderState(repository: MockOrderRepository());
    await state.load();
    final order = state.orders.firstWhere((order) => order.canCancel);
    expect(
      await state.cancelOrder(order, 'Invented reason'),
      'Choose a reason from the list.',
    );
    expect(
      state.orders.firstWhere((value) => value.id == order.id).cancelled,
      isFalse,
    );
  });

  test(
    'server Ready for Pickup refusal survives copying and mock persistence',
    () async {
      final repository = MockOrderRepository();
      await repository.load();
      final order = orderFromShipmentJson({
      'id': 'ORD-10245',
        'status': 'READY_FOR_PICKUP',
      });
      await repository.saveBuyerState(order.copyWith(eta: 'Ready for pickup'));
      final reloaded = MockOrderRepository();
      await reloaded.load();
      expect(
        (await reloaded.orders())
            .firstWhere((value) => value.id == order.id)
            .canCancel,
        isFalse,
      );
    },
  );
}
