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
//     delivery OTP, earnings and the attempt limit all live on the server.
//   * The app's own milestone machine and payout ledger remain the mock-mode
//     simulation; in live mode they are display state that a refresh replaces.

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
    String? deliveryOtp,
    double? codAmount,
  });

  /// Asks the server to send a fresh delivery code to the buyer. The code is
  /// never returned to the rider.
  Future<void> requestDeliveryCode(String jobId);

  /// Submits proof of delivery: the live photo, the code the buyer read out,
  /// and — for COD — the cash collected. The server checks all three and
  /// records the delivery once; a repeat after a lost response is a no-op.
  Future<void> completeDelivery(
    String jobId, {
    required String proofPhotoPath,
    required String deliveryOtp,
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
  Future<List<RiderProviderOption>> registrationProviders({String? barangayCode});
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
    String? deliveryOtp,
    double? codAmount,
  }) async {
    // Mock: no-op — the local state machine is the source of truth in mock mode.
  }

  @override
  Future<void> requestDeliveryCode(String jobId) async {}

  @override
  Future<void> completeDelivery(
    String jobId, {
    required String proofPhotoPath,
    required String deliveryOtp,
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
      fields: {'type': type, 'reason': reason.trim(), 'description': description.trim()},
      files: {if (photo.isNotEmpty) 'photo': photo},
    );
  }

  @override
  Future<List<RiderProviderOption>> registrationProviders({String? barangayCode}) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final code = barangayCode?.trim() ?? '';
    return riderProvidersFromJson(
      unwrapMap(await client.getJson(
        '/rider/providers',
        query: code.isEmpty ? null : {'barangay': code},
        authenticated: false,
      )),
    );
  }

  /// Tracking numbers from the last [assignedJobs], for the scan step.
  final Map<String, String> _tracking = {};

  @override
  bool get isServerAuthoritative => true;

  @override
  Future<List<DeliveryJob>> assignedJobs() async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final data = unwrapMap(await client.getJson('/rider/jobs'));
    final jobs = <DeliveryJob>[
      for (final key in const ['pickups', 'deliveries'])
        for (final row in (data[key] as List?) ?? const [])
          if (row is Map)
            ?deliveryJobFromRiderParcel(row.cast<String, Object?>()),
    ];

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
    String? deliveryOtp,
    double? codAmount,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    switch (milestoneValue) {
      case 'PICKED_UP':
        final token = await _verify(jobId, 'confirm_pickup', trackingNumber);
        await client.postJson(
          '/rider/pickups/$jobId/confirm',
          body: {'verification_token': token},
        );
      case 'OUT_FOR_DELIVERY':
        final token = await _verify(jobId, 'start_delivery', trackingNumber);
        await client.postJson(
          '/rider/deliveries/$jobId/start',
          body: {'verification_token': token},
        );
      case 'DELIVERY_FAILED' || 'RETURNED':
        // The server records the failed attempt; whether the parcel goes back
        // to the seller is Logistics' decision, not the app's.
        final token = await _verify(jobId, 'mark_failed', trackingNumber);
        final reason = failureReasonWire(failureReason);
        await client.postJson(
          '/rider/deliveries/$jobId/failed',
          body: {
            'verification_token': token,
            'failure_reason': reason,
            if (reason == 'other') 'failure_notes': failureReason ?? 'Other',
          },
        );
      default:
        // Station/Logistics-owned steps, and DELIVERED (see completeDelivery).
        return;
    }
  }

  @override
  Future<void> requestDeliveryCode(String jobId) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final token = await _verify(jobId, 'proof_of_delivery', null);
    await client.postJson(
      '/rider/deliveries/$jobId/otp',
      body: {'verification_token': token},
    );
  }

  @override
  Future<void> completeDelivery(
    String jobId, {
    required String proofPhotoPath,
    required String deliveryOtp,
    double? codAmount,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final token = await _verify(jobId, 'proof_of_delivery', null);
    await client.postMultipart(
      '/rider/deliveries/$jobId/deliver',
      fields: {
        'verification_token': token,
        'delivery_otp': deliveryOtp.trim(),
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
          'tracking_number': 'That tracking number belongs to a different parcel.',
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
      message: 'This parcel changed on the server. Your jobs have been refreshed.',
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
