// test/courier_flow_test.dart
//
// The courier's delivery loop, asserted as logic rather than pixels: a job
// dispatched by a checkout, walked through the milestone machine, completed.
//
// This exists because the completion path was rewritten (it used to be a
// SnackBar plus a pop) and because the buyer's tracker now depends on data
// this side writes — the courier's name is recorded *here*, at dispatch, and
// read by a completely different screen. That crossing is exactly the kind
// of thing that breaks silently, so it's pinned: the name must be absent
// before assignment, present after it, and still present after a restart.

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/state/courier_state.dart';

const _order = Order(
  id: 'ORD-TEST-1',
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

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('a dispatched job lands in the courier queue, unassigned', () async {
    final courier = await _dispatched();
    final job = courier.jobForOrder(_order.id);

    expect(job, isNotNull);
    // The buyer's tracker reads this. A job with no courier yet must say so
    // rather than showing someone.
    expect(job!.courierName, isNull);
    // A freshly dispatched job is on the *pickup* leg: the two dashboards
    // split on milestone, and an unset milestone reads as `accepted`.
    expect(courier.milestoneFor(job.id), DeliveryMilestone.accepted);
    expect(courier.jobsForPickup.map((j) => j.job.id), contains(job.id));
  });

  test('the courier name is recorded at dispatch, not before', () async {
    final courier = await _dispatched();
    final jobId = courier.jobForOrder(_order.id)!.id;

    courier.advanceMilestone(jobId, DeliveryMilestone.inTransit,
        courierName: 'Arnel P.');
    expect(courier.jobForOrder(_order.id)!.courierName, isNull,
        reason: 'the rider carrying the pickup leg is not the delivery rider');

    courier.advanceMilestone(jobId, DeliveryMilestone.assignedToRider,
        courierName: 'Arnel P.');
    expect(courier.jobForOrder(_order.id)!.courierName, 'Arnel P.');
  });

  test('completing a job records the fee and clears it from the queue',
      () async {
    final courier = await _dispatched();
    final jobId = courier.jobForOrder(_order.id)!.id;

    courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
    courier.completeJob(jobId);

    expect(courier.isCompleted(jobId), isTrue);
    expect(courier.todayEarnings, 85);
    expect(courier.completedJobs.single.job.id, jobId);
    expect(courier.jobsForDelivery.map((j) => j.job.id), isNot(contains(jobId)),
        reason: 'a completed job must not reappear as assigned');
    // What the confirmation screen reads for its "₱X added" line.
    expect(courier.completedJobs.single.job.fee, 85);
  });

  test('the name the buyer sees survives a restart', () async {
    final first = await _dispatched();
    final jobId = first.jobForOrder(_order.id)!.id;
    first.advanceMilestone(jobId, DeliveryMilestone.assignedToRider,
        courierName: 'Arnel P.');

    // _save() is fire-and-forget (as in every other state class in this
    // app), so let the pending prefs writes drain before "restarting".
    await pumpEventQueue();

    // Same prefs, fresh state — the app-restart path.
    final restarted = CourierState();
    await restarted.load();

    expect(restarted.jobForOrder(_order.id)!.courierName, 'Arnel P.');
  });
}
