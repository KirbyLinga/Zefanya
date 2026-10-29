// lib/data/repositories/courier_repository.dart
//
// Where a courier's assigned jobs come from.
//
// ## Scope, stated precisely, because the boundary is unusual
//
// This is the *narrowest* of the seven repositories, and deliberately so. It
// supplies job data and nothing else; the milestone state machine, the
// completion timestamps and the payout ledger all stay in `CourierState`.
//
// That is not laziness — it is a claim about which side owns what, and it
// matters that the claim is explicit:
//
//   * **Jobs** are server data. A dispatcher at the Logistics/Sorting Center
//     assigns them (per the ERP spec), so the app cannot invent one. This is
//     what the interface below covers.
//   * **Milestones and payouts** are *currently simulated on the device*, and
//     that is a mock-mode behaviour, not a design. The real system records
//     milestones server-side (the buyer's tracker reads them too, through
//     different credentials) and pays out server-side. `docs/api-contract.md`
//     §6 specifies the endpoints that will replace this.
//
// Moving the milestone machine into this repository today would mean rewriting
// a state class whose behaviour is pinned by tests — the partial return fee,
// the legacy-compatible persisted format, the "identity only at dispatch"
// guard — for no gain, because the server side of it does not exist to talk to.
// The seam that matters right now is the one that stops `CourierState`
// importing `mock_data`.

import '../api/api_exception.dart';
import '../api/api_client.dart';
import '../dto/remote_courier.dart';
import '../mock_data.dart';
import 'live_stub.dart';

abstract class CourierRepository {
  /// Jobs the dispatcher has assigned to the signed-in courier and which are
  /// not finished.
  Future<List<DeliveryJob>> assignedJobs();

  /// Records a milestone update for a job on the server.
  ///
  /// [jobId] is the seller_order id (what the app calls its job id).
  /// [milestone] is the `DeliveryMilestone` the job is moving to, translated
  /// to the server's `OrderStatus` string (e.g. `'PICKED_UP'`).
  ///
  /// The optional fields are the handover checks the server re-runs:
  /// [trackingNumber] at pickup, [failureReason] on a failed attempt,
  /// [deliveryOtp] and [codAmount] at the door. The app validates them first
  /// so the rider hears about a mistake immediately; the server validates
  /// them again because none of these values can be trusted from a client.
  ///
  /// A failure propagates — a milestone that failed to record server-side
  /// is still applied locally by `CourierState`, because the local state
  /// machine must not block on the network. The caller logs or shows the
  /// error if needed.
  Future<void> advanceMilestone(
    String jobId,
    String milestoneValue, {
    String? trackingNumber,
    String? failureReason,
    String? deliveryOtp,
    double? codAmount,
  });
}

// ---------------------------------------------------------------------------
// Mock
// ---------------------------------------------------------------------------

class MockCourierRepository implements CourierRepository {
  const MockCourierRepository();

  @override
  Future<List<DeliveryJob>> assignedJobs() async => mockDeliveryJobs;

  @override
  Future<void> advanceMilestone(
    String jobId,
    String milestoneValue, {
    String? trackingNumber,
    String? failureReason,
    String? deliveryOtp,
    double? codAmount,
  }) async {
    // Mock: no-op — the local state machine is the source of truth in mock mode.
  }
}

// ---------------------------------------------------------------------------
// Live
// ---------------------------------------------------------------------------

class LiveCourierRepository extends LiveRepository implements CourierRepository {
  LiveCourierRepository(super.client);

  @override
  Future<List<DeliveryJob>> assignedJobs() async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final body = await client.getJson('/courier/jobs', authenticated: true);
    return unwrapList(body)
        .map(deliveryJobFromJson)
        .whereType<DeliveryJob>()
        .toList(growable: false);
  }

  @override
  Future<void> advanceMilestone(
    String jobId,
    String milestoneValue, {
    String? trackingNumber,
    String? failureReason,
    String? deliveryOtp,
    double? codAmount,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    // PATCH /api/courier/jobs/{sellerOrder}/milestone
    // jobId is the seller_order id that the server uses as the route key.
    // Omitted fields stay omitted rather than being sent as null: the
    // server distinguishes "nothing to check" from "a blank value that
    // failed a check".
    await client.patchJson(
      '/courier/jobs/$jobId/milestone',
      body: {
        'milestone': milestoneValue,
        if (trackingNumber != null && trackingNumber.trim().isNotEmpty)
          'tracking_number': trackingNumber.trim(),
        if (failureReason != null && failureReason.trim().isNotEmpty)
          'failure_reason': failureReason.trim(),
        if (deliveryOtp != null && deliveryOtp.trim().isNotEmpty)
          'delivery_otp': deliveryOtp.trim(),
        if (codAmount != null) 'cod_amount': codAmount,
      },
      authenticated: true,
    );
  }
}