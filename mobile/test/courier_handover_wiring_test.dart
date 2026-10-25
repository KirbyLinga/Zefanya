// test/courier_handover_wiring_test.dart
//
// What the rider's app sends to the server when a parcel changes hands.
//
// The local state machine already decides what the rider sees; these tests
// pin the second half of the contract — that the values the rider typed
// (waybill, failure reason, delivery code, cash) reach the repository, and
// that a live job with no on-device code does not lock the rider out of a
// code the server never gave this phone.

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/repositories/courier_repository.dart';
import 'package:zefanya/state/courier_state.dart';

const _order = Order(
  id: 'ORD-HANDOVER-1',
  shopName: 'Willow & Bloom Atelier',
  status: OrderStatus.toShip,
  eta: 'soon',
  itemSummary: 'Linen Blend Wrap Dress',
);

typedef _Call = ({
  String jobId,
  String milestone,
  String? trackingNumber,
  String? failureReason,
  String? deliveryOtp,
  double? codAmount,
});

class _RecordingCourierRepository implements CourierRepository {
  final List<_Call> calls = [];

  @override
  Future<List<DeliveryJob>> assignedJobs() async => const [];

  @override
  Future<void> advanceMilestone(
    String jobId,
    String milestoneValue, {
    String? trackingNumber,
    String? failureReason,
    String? deliveryOtp,
    double? codAmount,
  }) async {
    calls.add((
      jobId: jobId,
      milestone: milestoneValue,
      trackingNumber: trackingNumber,
      failureReason: failureReason,
      deliveryOtp: deliveryOtp,
      codAmount: codAmount,
    ));
  }
}

Future<(CourierState, _RecordingCourierRepository, String)> _dispatched({
  double codAmount = 700,
  bool prepaid = false,
  String? deliveryOtp,
}) async {
  SharedPreferences.setMockInitialValues({});
  final repository = _RecordingCourierRepository();
  final courier = CourierState(repository: repository);
  await courier.load();

  courier.dispatchForOrder(
    order: _order,
    buyerName: 'Marielle Santos',
    deliveryArea: 'Sta. Cruz, Laguna',
    fee: 85,
    trackingNumber: 'ZEF-RID-777',
    codAmount: codAmount,
    prepaid: prepaid,
    deliveryOtp: deliveryOtp,
  );

  return (courier, repository, courier.jobForOrder(_order.id)!.id);
}

void main() {
  group('RID-DLV-004', () {
    test('a live job accepts any 6-digit code and leaves the check to server',
        () async {
      final (courier, _, jobId) = await _dispatched(deliveryOtp: '');

      expect(courier.validateDeliveryOtp(jobId, '482913'), isNull);
      expect(courier.otpFailuresFor(jobId), 0);
      expect(courier.isOtpLocked(jobId), isFalse);

      // A five-digit entry is still a shape error, not a server question.
      expect(courier.validateDeliveryOtp(jobId, '48291'), isNotNull);
      expect(courier.otpFailuresFor(jobId), 0);
    });
  });

  group('RID-DLV-005', () {
    test('the exact cash and code are sent with DELIVERED', () async {
      final (courier, repository, jobId) = await _dispatched();

      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.delivered,
        deliveryOtp: '482913',
        codAmount: 700,
      );

      final call = repository.calls.single;
      expect(call.jobId, jobId);
      expect(call.milestone, 'DELIVERED');
      expect(call.deliveryOtp, '482913');
      expect(call.codAmount, 700);
    });
  });

  group('RID-DLV-006', () {
    test('a prepaid delivery sends no cash amount', () async {
      final (courier, repository, jobId) = await _dispatched(
        codAmount: 0,
        prepaid: true,
      );

      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.delivered,
        deliveryOtp: '482913',
      );

      expect(repository.calls.single.codAmount, isNull);
    });
  });

  group('RID-PICK-003', () {
    test('the typed waybill is sent with PICKED_UP', () async {
      final (courier, repository, jobId) = await _dispatched();

      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.pickedUp,
        trackingNumber: 'ZEF-RID-777',
      );

      final call = repository.calls.single;
      expect(call.milestone, 'PICKED_UP');
      expect(call.trackingNumber, 'ZEF-RID-777');
    });
  });

  group('RID-FAIL-001', () {
    test('a failed attempt sends its reason', () async {
      final (courier, repository, jobId) = await _dispatched();

      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.failedDelivery,
        failureReason: 'Buyer not available',
      );

      final call = repository.calls.single;
      expect(call.milestone, 'DELIVERY_FAILED');
      expect(call.failureReason, 'Buyer not available');
    });
  });

  group('RID-FAIL-003', () {
    test('Buyer refused is sent as RETURNED with the reason', () async {
      final (courier, repository, jobId) = await _dispatched();

      courier.advanceMilestone(
        jobId,
        DeliveryMilestone.returnToSender,
        failureReason: 'Buyer refused',
      );

      final call = repository.calls.single;
      expect(call.milestone, 'RETURNED');
      expect(call.failureReason, 'Buyer refused');
    });
  });

  group('RID-FAIL-005', () {
    test('the third failure is sent as RETURNED, not a fourth attempt',
        () async {
      final (courier, repository, jobId) = await _dispatched();

      for (var attempt = 1; attempt <= 3; attempt++) {
        courier.advanceMilestone(
          jobId,
          DeliveryMilestone.failedDelivery,
          failureReason: 'Buyer not available',
        );
      }

      expect(repository.calls.last.milestone, 'RETURNED');
      expect(repository.calls.last.failureReason, 'Buyer not available');
      expect(courier.milestoneFor(jobId), DeliveryMilestone.returnToSender);
    });
  });
}
