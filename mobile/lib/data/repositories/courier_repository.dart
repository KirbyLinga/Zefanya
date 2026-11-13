// lib/data/repositories/courier_repository.dart
//
// Where a courier's assigned jobs come from, and how a handoff reaches the
// server.
//
// ## Who owns what (WebZefanya integration)
//
//   * **Jobs and their status** are server data. WebZefanya's Logistics
//     dispatcher assigns them; `GET /api/rider/jobs` lists them with the
//     server's `OrderStatus`, and `CourierState` treats that status as
//     authoritative over any milestone this device recorded.
//   * **Handoffs** follow WebZefanya's scan-enforced lifecycle: the rider
//     verifies the parcel's tracking number (`POST /api/rider/scan/verify`),
//     which returns a short-lived token per allowed action, then performs that
//     action (pickup confirm, start delivery, proof of delivery, failed
//     delivery). Row locks, status transitions, the COD amount check, the
//     delivery evidence, earnings and the attempt limit all live on the server.
//   * The app's own milestone machine and payout ledger remain the mock-mode
//     simulation; in live mode they are display state that a refresh replaces.

import 'dart:typed_data';

import '../api/api_exception.dart';
import '../api/api_client.dart';
import '../dto/remote_courier.dart';
import '../mock_data.dart';
import 'live_stub.dart';

abstract class CourierRepository {
  /// True when handoffs are decided by a server (live mode). The tracker then
  /// waits for the server before showing a delivery as complete.
  bool get isServerAuthoritative;

  /// Jobs the dispatcher has assigned to the signed-in courier and which are
  /// not finished.
  Future<List<DeliveryJob>> assignedJobs();

  /// Records a milestone update for a job on the server.
  ///
  /// [jobId] is the seller_order id (what the app calls its job id).
  /// [milestoneValue] is the `DeliveryMilestone` the job is moving to,
  /// translated to the server's `OrderStatus` string (e.g. `'PICKED_UP'`).
  /// Milestones a rider cannot set (station and Logistics steps) and
  /// `DELIVERED` (see [completeDelivery]) are not sent.
  ///
  /// A failure propagates; `CourierState` reacts by re-reading the jobs so the
  /// screen shows the server's state rather than the optimistic one.
  Future<void> advanceMilestone(
    String jobId,
    String milestoneValue, {
    String? trackingNumber,
    String? failureReason,
    double? codAmount,
  });

  Future<void> completeDelivery(
    String jobId, {
    required String proofPhotoPath,
    required String recipientCategory,
    String? receivedBy,
    String? recipientRelationship,
    double? codAmount,
  });
}

/// Rider data that only a server has (live mode).
///
/// A separate interface, like `QuotesCheckout`, so the mock repository and
/// test doubles of [CourierRepository] need not implement calls they have no
/// server for. Callers check `is RiderServerData` and fall back to the mock
/// behaviour otherwise.
abstract class RiderServerData {
  /// `GET /api/rider/earnings`: server totals and the credited ledger rows.
  Future<RiderEarningsSnapshot> earnings({int limit = 50});

  /// `POST /api/rider/jobs/{id}/incidents`: report a lost or damaged parcel.
  /// [type] is `lost` or `damaged`; the server requires [photoPath] for
  /// `damaged` and blocks the parcel's operations until Admin resolves it.
  Future<void> reportIncident(
    String jobId, {
    required String type,
    required String reason,
    required String description,
    String? photoPath,
  });

  /// `GET /api/rider/providers`: companies a new rider may apply to. With
  /// [barangayCode], only those whose validated coverage serves that barangay
  /// (the server's own coverage rule).
  Future<List<RiderProviderOption>> registrationProviders({
    String? barangayCode,
  });

  /// What the last [CourierRepository.assignedJobs] read said beside the job
  /// lists: the rider's server-side availability, the seller stops, and the
  /// failed-pickup reasons and limit. Null before the first read.
  RiderJobsMeta? get lastJobsMeta;

  /// `POST /api/rider/availability`. Returns the availability the server
  /// recorded. Going offline never unassigns a job on the device; the server
  /// decides what happens to open assignments.
  Future<bool> setAvailability({required bool online});

  /// `POST /api/rider/pickups/{id}/failed`. [expectedAttempt] is the attempt
  /// the rider is reporting (the job's recorded attempts + 1); the server
  /// refuses a mismatch with 409, so a retried request cannot count twice.
  /// Whether the order is cancelled is the server's decision.
  Future<PickupFailureResult> reportPickupFailure(
    String jobId, {
    required String reason,
    required int expectedAttempt,
    String? notes,
  });

  /// `POST /api/rider/pickups/stops/{id}/finish`: ends the seller stop. Parcels
  /// not collected stay assigned for the next visit (server rule).
  Future<PickupStop?> finishPickupStop(String stopId);

  /// `GET /api/rider/cod`: COD cash held and handed over, read-only. Logistics
  /// confirms handovers; the app never creates or confirms one.
  Future<RiderCashSnapshot> cash({int limit = 20});

  /// `GET /api/rider/return-pickups`: approved buyer returns assigned to me.
  Future<List<RiderReturnPickup>> returnPickups();

  /// `POST /api/rider/return-pickups/{id}/collect`: the server verifies
  /// [trackingNumber] against the parcel, refuses a second collection (409)
  /// and moves custody to the rider's station.
  Future<void> collectReturn(String returnId, {required String trackingNumber});

  /// `GET /api/rider/history`: my finished deliveries, newest first.
  Future<List<RiderHistoryEntry>> history();

  /// `GET /api/rider/payouts`: what Logistics paid me, read-only.
  Future<RiderPayoutsSnapshot> payouts({int limit = 20});

  /// `GET /api/rider/waybills/{id}`: the private waybill for my own job.
  Future<RiderWaybill> waybill(String jobId);

  /// `GET /api/rider/proofs/{id}`: my proof-of-delivery photo bytes.
  Future<Uint8List> proofPhoto(String proofId);

  /// `GET /api/rider/profile`: my station and coverage area.
  Future<RiderCoverage> coverage();
}

// ---------------------------------------------------------------------------
// Mock
// ---------------------------------------------------------------------------

class MockCourierRepository implements CourierRepository {
  const MockCourierRepository();

  @override
  bool get isServerAuthoritative => false;

  @override
  Future<List<DeliveryJob>> assignedJobs() async => mockDeliveryJobs;

  @override
  Future<void> advanceMilestone(
    String jobId,
    String milestoneValue, {
    String? trackingNumber,
    String? failureReason,
    double? codAmount,
  }) async {
    // Mock: no-op — the local state machine is the source of truth in mock mode.
  }

  @override
  Future<void> completeDelivery(
    String jobId, {
    required String proofPhotoPath,
    required String recipientCategory,
    String? receivedBy,
    String? recipientRelationship,
    double? codAmount,
  }) async {}
}

// ---------------------------------------------------------------------------
// Live (WebZefanya /api/rider/...)
// ---------------------------------------------------------------------------

class LiveCourierRepository extends LiveRepository
    implements CourierRepository, RiderServerData {
  LiveCourierRepository(super.client);

  @override
  Future<RiderEarningsSnapshot> earnings({int limit = 50}) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final data = unwrapMap(
      await client.getJson('/rider/earnings', query: {'limit': '$limit'}),
    );
    return riderEarningsFromJson(data);
  }

  @override
  Future<void> reportIncident(
    String jobId, {
    required String type,
    required String reason,
    required String description,
    String? photoPath,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final photo = photoPath?.trim() ?? '';
    await client.postMultipart(
      '/rider/jobs/$jobId/incidents',
      fields: {
        'type': type,
        'reason': reason.trim(),
        'description': description.trim(),
      },
      files: {if (photo.isNotEmpty) 'photo': photo},
    );
  }

  @override
  Future<List<RiderProviderOption>> registrationProviders({
    String? barangayCode,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final code = barangayCode?.trim() ?? '';
    return riderProvidersFromJson(
      unwrapMap(
        await client.getJson(
          '/rider/providers',
          query: code.isEmpty ? null : {'barangay': code},
          authenticated: false,
        ),
      ),
    );
  }

  /// Tracking numbers from the last [assignedJobs], for the scan step.
  final Map<String, String> _tracking = {};

  RiderJobsMeta? _lastJobsMeta;

  @override
  RiderJobsMeta? get lastJobsMeta => _lastJobsMeta;

  @override
  Future<bool> setAvailability({required bool online}) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final data = unwrapMap(
      await client.postJson('/rider/availability', body: {'online': online}),
    );
    final recorded = data['online'];
    return recorded is bool ? recorded : online;
  }

  @override
  Future<PickupFailureResult> reportPickupFailure(
    String jobId, {
    required String reason,
    required int expectedAttempt,
    String? notes,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final note = notes?.trim() ?? '';
    final data = unwrapMap(
      await client.postJson(
        '/rider/pickups/$jobId/failed',
        body: {
          'failure_reason': reason,
          'expected_attempt': expectedAttempt,
          if (note.isNotEmpty) 'failure_notes': note,
        },
      ),
    );
    return pickupFailureResultFromJson(data);
  }

  @override
  Future<RiderCashSnapshot> cash({int limit = 20}) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final data = unwrapMap(
      await client.getJson('/rider/cod', query: {'limit': '$limit'}),
    );
    final summary = data['summary'];
    if (summary is! Map || summary['holding_minor'] is! int) {
      throw const MalformedResponseException();
    }
    return riderCashFromJson(data);
  }

  @override
  Future<List<RiderReturnPickup>> returnPickups() async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return riderReturnPickupsFromJson(
      unwrapMap(await client.getJson('/rider/return-pickups')),
    );
  }

  @override
  Future<void> collectReturn(
    String returnId, {
    required String trackingNumber,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    await client.postJson(
      '/rider/return-pickups/$returnId/collect',
      body: {'tracking_number': trackingNumber.trim()},
    );
  }

  @override
  Future<List<RiderHistoryEntry>> history() async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return riderHistoryFromJson(
      unwrapMap(await client.getJson('/rider/history')),
    );
  }

  @override
  Future<RiderPayoutsSnapshot> payouts({int limit = 20}) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return riderPayoutsFromJson(
      unwrapMap(
        await client.getJson('/rider/payouts', query: {'limit': '$limit'}),
      ),
    );
  }

  @override
  Future<RiderWaybill> waybill(String jobId) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return riderWaybillFromJson(
      unwrapMap(await client.getJson('/rider/waybills/$jobId')),
    );
  }

  @override
  Future<Uint8List> proofPhoto(String proofId) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return client.getBytes('/rider/proofs/$proofId');
  }

  @override
  Future<RiderCoverage> coverage() async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return riderCoverageFromJson(
      unwrapMap(await client.getJson('/rider/profile')),
    );
  }

  @override
  Future<PickupStop?> finishPickupStop(String stopId) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final data = unwrapMap(
      await client.postJson('/rider/pickups/stops/$stopId/finish'),
    );
    final stop = data['stop'];
    return stop is Map
        ? pickupStopFromJson(stop.cast<String, Object?>())
        : null;
  }

  @override
  bool get isServerAuthoritative => true;

  int _riderGeneration = 0;

  void resetRiderSession() {
    _riderGeneration++;
    _tracking.clear();
    _lastJobsMeta = null;
  }

  @override
  Future<List<DeliveryJob>> assignedJobs() async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final generation = _riderGeneration;
    final data = unwrapMap(await client.getJson('/rider/jobs'));
    final jobs = <DeliveryJob>[
      for (final key in const ['pickups', 'deliveries'])
        for (final row in (data[key] as List?) ?? const [])
          if (row is Map)
            ?deliveryJobFromRiderParcel(row.cast<String, Object?>()),
    ];

    if (generation != _riderGeneration) return const [];
    _lastJobsMeta = riderJobsMetaFromJson(data);
    _tracking
      ..clear()
      ..addEntries(
        jobs
            .where((j) => (j.trackingNumber ?? '').isNotEmpty)
            .map((j) => MapEntry(j.id, j.trackingNumber!)),
      );
    return List.unmodifiable(jobs);
  }

  @override
  Future<void> advanceMilestone(
    String jobId,
    String milestoneValue, {
    String? trackingNumber,
    String? failureReason,
    double? codAmount,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    switch (milestoneValue) {
      case 'PICKED_UP':
        await _postParcelAction(
          jobId,
          pickup: true,
          trackingNumber: trackingNumber,
        );
      case 'OUT_FOR_DELIVERY':
        final token = await _verify(jobId, 'start_delivery', trackingNumber);
        await client.postJson(
          '/rider/deliveries/$jobId/start',
          body: {'verification_token': token},
        );
      case 'DELIVERY_FAILED' || 'RETURNED':
        await _postParcelAction(
          jobId,
          pickup: false,
          trackingNumber: trackingNumber,
          failureReason: failureReason,
        );
      default:
        // Station/Logistics-owned steps, and DELIVERED (see completeDelivery).
        return;
    }
  }

  Future<bool?> deliveryCompletionStatus(String jobId) async {
    final data = unwrapMap(
      await client.getJson('/rider/deliveries/$jobId/completion-status'),
    );
    return switch (data['state']) {
      'completed' => true,
      'open' => false,
      'unconfirmed' => null,
      _ => throw const MalformedResponseException(),
    };
  }

  Future<DeliveryJob> confirmParcelAction(
    String jobId, {
    required bool pickup,
    String? trackingNumber,
    String? failureReason,
    String? failureNotes,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final job = deliveryJobFromRiderParcel(
      unwrapMap(
        await _postParcelAction(
          jobId,
          pickup: pickup,
          trackingNumber: trackingNumber,
          failureReason: failureReason,
          failureNotes: failureNotes,
        ),
      ),
    );
    if (job == null ||
        job.id != jobId ||
        job.serverStatus != (pickup ? 'PICKED_UP' : 'DELIVERY_FAILED')) {
      throw const MalformedResponseException();
    }
    return job;
  }

  Future<Map<String, Object?>> _postParcelAction(
    String jobId, {
    required bool pickup,
    String? trackingNumber,
    String? failureReason,
    String? failureNotes,
  }) async {
    final generation = _riderGeneration;
    final token = await _verify(
      jobId,
      pickup ? 'confirm_pickup' : 'mark_failed',
      trackingNumber,
    );
    if (generation != _riderGeneration) throw const UnauthorizedException();
    final reason = failureReasonWire(failureReason);
    final notes = failureNotes?.trim();
    return client.postJson(
      pickup
          ? '/rider/pickups/$jobId/confirm'
          : '/rider/deliveries/$jobId/failed',
      body: {
        'verification_token': token,
        if (!pickup) 'failure_reason': reason,
        if (!pickup && (reason == 'other' || (notes?.isNotEmpty ?? false)))
          'failure_notes': notes?.isNotEmpty == true
              ? notes
              : failureReason ?? 'Other',
      },
    );
  }

  @override
  Future<void> completeDelivery(
    String jobId, {
    required String proofPhotoPath,
    required String recipientCategory,
    String? receivedBy,
    String? recipientRelationship,
    double? codAmount,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final generation = _riderGeneration;
    final token = await _verify(jobId, 'proof_of_delivery', null);
    if (generation != _riderGeneration) throw const UnauthorizedException();
    await client.postMultipart(
      '/rider/deliveries/$jobId/deliver',
      fields: {
        'verification_token': token,
        'physical_handover': '1',
        'recipient_accepted': '1',
        'recipient_category': recipientCategory,
        if (receivedBy != null) 'received_by': receivedBy,
        if (recipientRelationship != null)
          'recipient_relationship': recipientRelationship,
        if (codAmount != null) 'cod_amount': codAmount.toStringAsFixed(2),
      },
      files: {'proof_photo': proofPhotoPath},
    );
  }

  /// Verifies the parcel by tracking number and returns the token for [action].
  ///
  /// No token for the action means the parcel is no longer in a state that
  /// allows it — reported the same way as a stale request (409).
  Future<String> _verify(String jobId, String action, String? typed) async {
    final tracking = (typed != null && typed.trim().isNotEmpty)
        ? typed.trim()
        : _tracking[jobId];
    if (tracking == null || tracking.isEmpty) {
      throw const ValidationException(
        fieldErrors: {
          'tracking_number': 'Scan or type the parcel tracking number.',
        },
      );
    }

    final data = unwrapMap(
      await client.postJson(
        '/rider/scan/verify',
        body: {'tracking_number': tracking, 'method': 'manual'},
      ),
    );
    final parcelId = (data['parcel'] as Map?)?['id']?.toString();
    if (parcelId != null && parcelId != jobId) {
      throw const ValidationException(
        fieldErrors: {
          'tracking_number':
              'That tracking number belongs to a different parcel.',
        },
      );
    }
    for (final entry in (data['actions'] as List?) ?? const []) {
      if (entry is Map && entry['key'] == action) {
        final token = entry['verification_token']?.toString();
        if (token != null && token.isNotEmpty) return token;
      }
    }
    throw const RequestRejectedException(
      statusCode: 409,
      message:
          'This parcel changed on the server. Your jobs have been refreshed.',
    );
  }
}

/// The app's failure reasons in WebZefanya's `DeliveryFailureReason` values.
String failureReasonWire(String? label) => switch (label?.trim()) {
  'Buyer not available' => 'recipient_unavailable',
  'Buyer refused' => 'refused_by_recipient',
  'Wrong address' => 'wrong_address',
  _ => 'other',
};
