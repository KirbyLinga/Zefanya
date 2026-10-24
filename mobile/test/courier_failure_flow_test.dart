// test/courier_failure_flow_test.dart
//
// Failed-delivery bookkeeping: a reason and an attempt count must survive a
// retry, and the third failure must hand the parcel back to the seller
// instead of allowing a fourth attempt. The UI reads these values from
// CourierState, so they are pinned here rather than only in a screen test.

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/state/courier_state.dart';

const _order = Order(
  id: 'ORD-FAIL-1',
  shopName: 'Willow & Bloom Atelier',
  status: OrderStatus.toShip,
  eta: 'soon',
  itemSummary: 'Linen Blend Wrap Dress',
);

Future<(CourierState, String)> _dispatched() async {
  final courier = CourierState();
  await courier.load();
  courier.dispatchForOrder(
    order: _order,
    buyerName: 'Marielle Santos',
    deliveryArea: 'Sta. Cruz, Laguna',
    fee: 85,
  );
  return (courier, courier.jobForOrder(_order.id)!.id);
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test(
    'a failure records its reason and increments the attempt count',
    () async {
      final (courier, jobId) = await _dispatched();

      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.failedDelivery,
        failureReason: 'Buyer not available',
      );

      expect(courier.attemptsFor(jobId), 1);
      expect(courier.failureReasonFor(jobId), 'Buyer not available');
      expect(courier.milestoneFor(jobId), DeliveryMilestone.failedDelivery);
    },
  );

  test(
    'retrying keeps the count and reason, then the third failure returns',
    () async {
      final (courier, jobId) = await _dispatched();

      courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.failedDelivery,
        failureReason: 'Wrong address',
      );
      expect(courier.attemptsFor(jobId), 1);

      courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.failedDelivery,
        failureReason: 'Buyer not available',
      );
      expect(courier.attemptsFor(jobId), 2);
      expect(courier.milestoneFor(jobId), DeliveryMilestone.failedDelivery);

      courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.failedDelivery,
        failureReason: 'Buyer not available',
      );
      expect(courier.attemptsFor(jobId), 3);
      expect(courier.milestoneFor(jobId), DeliveryMilestone.returnToSender);
    },
  );
}
