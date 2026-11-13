// lib/data/dto/remote_courier.dart
//
// Server-shape → DeliveryJob mapper for `GET /api/courier/jobs`.
//
// ## The wire shape (from DeliveryJobResource)
//
//   {
//     "id":              "12",
//     "order_id":        "7",
//     "shop_name":       "Willow & Bloom Atelier",
//     "pickup_address":  "12 Mabini Street, Lahug, Cebu City, Cebu",
//     "buyer_name":      "Marites Santos",
//     "delivery_area":   "Quezon City (8.4 km)",
//     "package_size":    "1 item · ₱1,299.00",
//     "fee":             85.0
//   }
//
// ## Field mapping to DeliveryJob
//
// | Wire field      | DeliveryJob field    | Notes                      |
// |-----------------|----------------------|----------------------------|
// | id              | id                   | opaque string              |
// | order_id        | orderId              | links job to buyer order   |
// | shop_name       | shopName             |                            |
// | pickup_address  | pickupAddress        |                            |
// | buyer_name      | buyerName            |                            |
// | delivery_area   | deliveryArea         | pre-formatted string       |
// | package_size    | packageSize          | pre-formatted string       |
// | fee             | fee                  | pesos, already a double    |
//
// `courierName` and `courierPhotoPath` are NOT on the wire — they come from
// the dispatcher's assignment step, which is a separate milestone update
// endpoint not yet built. The app sets them locally via `CourierState`
// when the rider identifies themselves at pickup.

import '../mock_data.dart';

/// Maps one entry from `GET /api/courier/jobs` to a [DeliveryJob].
///
/// Returns null for an entry that is missing its id or shop — both are
/// required to display a job card and neither has a useful default.
DeliveryJob? deliveryJobFromJson(Map<String, Object?> json) {
  final id = json['id']?.toString().trim();
  final shopName = json['shop_name']?.toString().trim();

  if (id == null || id.isEmpty || shopName == null || shopName.isEmpty) {
    return null;
  }

  return DeliveryJob(
    id: id,
    shopName: shopName,
    pickupAddress: json['pickup_address']?.toString() ?? '',
    buyerName: json['buyer_name']?.toString() ?? '',
    deliveryArea: json['delivery_area']?.toString() ?? '',
    packageSize: json['package_size']?.toString() ?? '',
    fee: _double(json['fee']),
    // orderId links this job to the buyer's order history in CourierState.
    orderId: json['order_id']?.toString().trim(),
    // Identity fields — only set by the dispatcher step, not this endpoint.
    courierName: null,
    courierPhotoPath: null,
    trackingNumber: json['tracking_number']?.toString().trim(),
    codAmount: _double(json['cod_amount']),
    prepaid:
        json['payment_method']?.toString() != 'cod' ||
        json['payment_status']?.toString() == 'paid',
    // The server is the authority on the code; it only appears here when a
    // build explicitly includes it (the mock fixtures/test server). An absent
    // code is empty, not the mock value — guessing a live OTP would be worse
    // than the handover failing closed.
  );
}

double _double(Object? value) {
  if (value is double) return value;
  if (value is int) return value.toDouble();
  if (value is String) return double.tryParse(value) ?? 0.0;
  return 0.0;
}

/// A parcel from WebZefanya's `GET /api/rider/jobs` (RiderParcelResource).
///
/// Every amount is the server's: COD arrives in minor units and is shown as
/// pesos only. The per-job
/// fee is not part of this payload (earnings are read from `/api/rider/earnings`),
/// so it is 0 rather than an invented figure.
DeliveryJob? deliveryJobFromRiderParcel(Map<String, Object?> json) {
  final id = json['id']?.toString().trim();
  if (id == null || id.isEmpty) return null;

  final requiresCod = json['requires_cod_collection'] == true;
  final codMinor = json['cod_amount_minor'];

  return DeliveryJob(
    id: id,
    shopName: json['seller_name']?.toString() ?? 'Seller',
    pickupAddress: json['pickup_area']?.toString() ?? '',
    buyerName: json['buyer_name']?.toString() ?? '',
    deliveryArea:
        json['delivery_address']?.toString() ??
        json['delivery_area']?.toString() ??
        '',
    packageSize: json['weight']?.toString() ?? '',
    fee: 0,
    orderId: json['order_id']?.toString(),
    buyerPhone: json['buyer_phone']?.toString(),
    trackingNumber: json['tracking_number']?.toString().trim(),
    parcelCount: (json['item_count'] as num?)?.toInt() ?? 1,
    codAmount: requiresCod && codMinor is num ? codMinor / 100 : 0,
    prepaid: !requiresCod,
    serverStatus: json['status']?.toString(),
    pickupSessionId: json['pickup_session_id']?.toString(),
    pickupAttempts: (json['pickup_attempts'] as num?)?.toInt() ?? 0,
  );
}

// ---------------------------------------------------------------------------
// GET /api/rider/jobs: stops, failed-pickup rules and availability
// ---------------------------------------------------------------------------

/// One seller stop (WebZefanya `PickupSession`). Every count and amount is
/// the server's (`PickupSessionService::summary`); the app only displays it.
class PickupStop {
  const PickupStop({
    required this.id,
    required this.status,
    required this.sellerName,
    required this.collected,
    required this.total,
    required this.canFinish,
    this.pickupAddress,
    this.windowEndsAt,
    this.earned = 0,
    this.potential = 0,
  });

  final String id;

  /// `OPEN`, `IN_PROGRESS`, `COMPLETED`, `MISSED` or `CANCELLED`.
  final String status;
  final String sellerName;
  final String? pickupAddress;
  final DateTime? windowEndsAt;
  final int collected;
  final int total;
  final double earned;
  final double potential;

  /// The server's answer to "can this stop be finished now" (at least one
  /// parcel collected and the stop still in progress).
  final bool canFinish;

  int get remaining => total - collected < 0 ? 0 : total - collected;
}

PickupStop? pickupStopFromJson(Map<String, Object?> json) {
  final id = json['id']?.toString().trim();
  if (id == null || id.isEmpty) return null;
  double pesos(Object? minor) => (_int(minor) ?? 0) / 100;

  return PickupStop(
    id: id,
    status: json['status']?.toString() ?? '',
    sellerName: json['seller_name']?.toString() ?? 'Seller',
    pickupAddress: json['pickup_address']?.toString(),
    windowEndsAt: DateTime.tryParse('${json['window_ends_at']}')?.toLocal(),
    collected: _int(json['collected']) ?? 0,
    total: _int(json['total']) ?? 0,
    earned: pesos(json['earned_minor']),
    potential: pesos(json['potential_minor']),
    canFinish: json['can_finish'] == true,
  );
}

/// A failed-pickup reason the server accepts (`DeliveryFailureReason`).
class PickupFailureReason {
  const PickupFailureReason({required this.value, required this.label});

  final String value;
  final String label;
}

/// What `GET /api/rider/jobs` says beside the job lists.
class RiderJobsMeta {
  const RiderJobsMeta({
    required this.online,
    required this.stops,
    required this.failureReasons,
    required this.maxPickupAttempts,
    this.windingDown = false,
    this.windingDownMessage,
  });

  /// The rider's company is suspended inside an Admin-granted wind-down: only
  /// parcels already out for delivery may be finished (server policy).
  final bool windingDown;

  /// The server's own wording for that notice.
  final String? windingDownMessage;

  /// The rider's availability as the server has it, or null when absent.
  final bool? online;
  final List<PickupStop> stops;
  final List<PickupFailureReason> failureReasons;
  final int maxPickupAttempts;
}

RiderJobsMeta riderJobsMetaFromJson(Map<String, Object?> data) {
  final failure =
      (data['pickup_failure'] as Map?)?.cast<String, Object?>() ?? const {};
  final online = data['online'];

  return RiderJobsMeta(
    online: online is bool ? online : null,
    stops: [
      for (final row in (data['stops'] as List?) ?? const [])
        if (row is Map) ?pickupStopFromJson(row.cast<String, Object?>()),
    ],
    failureReasons: [
      for (final row in (failure['reasons'] as List?) ?? const [])
        if (row is Map && '${row['value'] ?? ''}'.isNotEmpty)
          PickupFailureReason(
            value: '${row['value']}',
            label: '${row['label'] ?? row['value']}',
          ),
    ],
    maxPickupAttempts: _int(failure['max_attempts']) ?? 2,
    windingDown: data['winding_down'] == true,
    windingDownMessage: data['winding_down_message']?.toString(),
  );
}

// ---------------------------------------------------------------------------
// GET /api/rider/cod
// ---------------------------------------------------------------------------

/// One COD record (WebZefanya `CodRemittance`): created by the server when a
/// COD parcel is delivered, confirmed only by Logistics.
class RiderCodRecord {
  const RiderCodRecord({
    required this.id,
    required this.status,
    required this.amount,
    this.trackingNumber,
    this.receivedAmount,
    this.collectedAt,
    this.confirmedAt,
    this.overdue = false,
  });

  final String id;

  /// `PENDING_REMITTANCE` (cash still with the rider) or
  /// `REMITTED_TO_LOGISTICS` (Logistics confirmed receiving it).
  final String status;
  final double amount;
  final String? trackingNumber;

  /// What Logistics confirmed receiving; may be less than [amount].
  final double? receivedAmount;
  final DateTime? collectedAt;
  final DateTime? confirmedAt;
  final bool overdue;

  bool get isHeld => status == 'PENDING_REMITTANCE';
}

/// The server's COD answer. Nothing here is computed on the device.
class RiderCashSnapshot {
  const RiderCashSnapshot({
    required this.collected,
    required this.holding,
    required this.holdingCount,
    required this.overdueCount,
    required this.overdueAfterDays,
    required this.remitted,
    required this.remittedThisMonth,
    required this.held,
    required this.remittedRecords,
  });

  final double collected;
  final double holding;
  final int holdingCount;
  final int overdueCount;
  final int overdueAfterDays;
  final double remitted;
  final double remittedThisMonth;
  final List<RiderCodRecord> held;
  final List<RiderCodRecord> remittedRecords;
}

RiderCashSnapshot riderCashFromJson(Map<String, Object?> data) {
  final summary =
      (data['summary'] as Map?)?.cast<String, Object?>() ?? const {};
  double pesos(Object? minor) => (_int(minor) ?? 0) / 100;
  List<RiderCodRecord> rows(Object? list) => [
    for (final row in (list as List?) ?? const [])
      if (row is Map && row['id'] != null)
        RiderCodRecord(
          id: '${row['id']}',
          status: '${row['status'] ?? ''}',
          amount: pesos(row['amount_minor']),
          trackingNumber: row['tracking_number']?.toString(),
          receivedAmount: row['received_amount_minor'] == null
              ? null
              : pesos(row['received_amount_minor']),
          collectedAt: DateTime.tryParse('${row['collected_at']}')?.toLocal(),
          confirmedAt: DateTime.tryParse('${row['confirmed_at']}')?.toLocal(),
          overdue: row['overdue'] == true,
        ),
  ];

  return RiderCashSnapshot(
    collected: pesos(summary['collected_minor']),
    holding: pesos(summary['holding_minor']),
    holdingCount: _int(summary['holding_count']) ?? 0,
    overdueCount: _int(summary['overdue_count']) ?? 0,
    overdueAfterDays: _int(summary['overdue_after_days']) ?? 0,
    remitted: pesos(summary['remitted_minor']),
    remittedThisMonth: pesos(summary['remitted_month_minor']),
    held: rows(data['holding']),
    remittedRecords: rows(data['remitted']),
  );
}

// ---------------------------------------------------------------------------
// GET /api/rider/return-pickups
// ---------------------------------------------------------------------------

/// An approved buyer return this rider is assigned to collect.
class RiderReturnPickup {
  const RiderReturnPickup({
    required this.id,
    required this.trackingNumber,
    this.buyerName,
    this.buyerPhone,
    this.pickupAddress,
    this.reason,
    this.itemCount = 0,
    this.assignedAt,
  });

  final String id;
  final String trackingNumber;
  final String? buyerName;
  final String? buyerPhone;
  final String? pickupAddress;
  final String? reason;
  final int itemCount;
  final DateTime? assignedAt;
}

List<RiderReturnPickup> riderReturnPickupsFromJson(Map<String, Object?> data) =>
    [
      for (final row in (data['returns'] as List?) ?? const [])
        if (row is Map &&
            row['id'] != null &&
            '${row['tracking_number'] ?? ''}'.isNotEmpty)
          RiderReturnPickup(
            id: '${row['id']}',
            trackingNumber: '${row['tracking_number']}',
            buyerName: row['buyer_name']?.toString(),
            buyerPhone: row['buyer_phone']?.toString(),
            pickupAddress: row['pickup_address']?.toString(),
            reason: row['reason']?.toString(),
            itemCount: _int(row['item_count']) ?? 0,
            assignedAt: DateTime.tryParse('${row['assigned_at']}')?.toLocal(),
          ),
    ];

/// The server's answer to a failed-pickup report.
class PickupFailureResult {
  const PickupFailureResult({
    required this.attempt,
    required this.attemptsRemaining,
    required this.cancelled,
  });

  final int attempt;
  final int attemptsRemaining;

  /// True when the server cancelled the order (the final allowed attempt).
  /// Decided by the server, never by the app.
  final bool cancelled;
}

PickupFailureResult pickupFailureResultFromJson(Map<String, Object?> data) =>
    PickupFailureResult(
      attempt: _int(data['attempt']) ?? 0,
      attemptsRemaining: _int(data['attempts_remaining']) ?? 0,
      cancelled: data['cancelled'] == true,
    );

// ---------------------------------------------------------------------------
// GET /api/rider/earnings
// ---------------------------------------------------------------------------

/// One credited job from WebZefanya's `RiderEarning` ledger. The amount is
/// what the server credited (pickup rates per seller visit, delivery rate);
/// the app never computes it.
class RiderEarningEntry {
  const RiderEarningEntry({
    required this.id,
    required this.job,
    required this.amount,
    required this.earnedAt,
    this.sellerOrderId,
    this.trackingNumber,
    this.sellerName,
    this.paid = false,
  });

  final String id;

  /// `pickup` or `delivery`.
  final String job;
  final double amount;
  final DateTime earnedAt;
  final String? sellerOrderId;
  final String? trackingNumber;
  final String? sellerName;
  final bool paid;
}

/// The server's earnings answer: totals and the recent ledger rows.
class RiderEarningsSnapshot {
  const RiderEarningsSnapshot({
    required this.today,
    required this.week,
    required this.allTime,
    required this.recent,
    this.codHolding = 0,
  });

  final double today;
  final double week;
  final double allTime;
  final double codHolding;
  final List<RiderEarningEntry> recent;
}

RiderEarningsSnapshot riderEarningsFromJson(Map<String, Object?> data) {
  final totals = (data['totals'] as Map?)?.cast<String, Object?>() ?? const {};
  final cod = (data['cod'] as Map?)?.cast<String, Object?>() ?? const {};
  double pesos(Object? minor) => (_int(minor) ?? 0) / 100;

  return RiderEarningsSnapshot(
    today: pesos(totals['today_minor']),
    week: pesos(totals['week_minor']),
    allTime: pesos(totals['all_time_minor']),
    codHolding: pesos(cod['holding_minor']),
    recent: [
      for (final row in (data['recent'] as List?) ?? const [])
        if (row is Map)
          if (DateTime.tryParse('${row['earned_at']}') case final at?)
            RiderEarningEntry(
              id: '${row['id']}',
              job: '${row['job'] ?? 'delivery'}',
              amount: pesos(row['amount_minor']),
              earnedAt: at.toLocal(),
              sellerOrderId: row['seller_order_id']?.toString(),
              trackingNumber: row['tracking_number']?.toString(),
              sellerName: row['seller_name']?.toString(),
              paid: row['paid'] == true,
            ),
    ],
  );
}

int? _int(Object? value) => switch (value) {
  final int v => v,
  final num v => v.round(),
  final String v => int.tryParse(v),
  _ => null,
};

// ---------------------------------------------------------------------------
// GET /api/rider/providers
// ---------------------------------------------------------------------------

/// A logistics company a new rider may apply to.
class RiderProviderOption {
  const RiderProviderOption({required this.id, required this.name});

  final int id;
  final String name;
}

List<RiderProviderOption> riderProvidersFromJson(Map<String, Object?> data) => [
  for (final row in (data['providers'] as List?) ?? const [])
    if (row is Map)
      if (_int(row['id']) case final id?)
        RiderProviderOption(id: id, name: '${row['name'] ?? ''}'),
];

// ---------------------------------------------------------------------------
// GET /api/rider/history
// ---------------------------------------------------------------------------

/// A finished delivery (delivered, completed, failed or returned).
class RiderHistoryEntry {
  const RiderHistoryEntry({
    required this.sellerOrderId,
    required this.status,
    required this.statusLabel,
    this.trackingNumber,
    this.sellerName,
    this.deliveryArea,
    this.returned = false,
    this.earning,
    this.deliveredAt,
    this.proofId,
  });

  final String sellerOrderId;
  final String status;
  final String statusLabel;
  final String? trackingNumber;
  final String? sellerName;
  final String? deliveryArea;
  final bool returned;

  /// The server's credited delivery pay for this parcel, when there is one.
  final double? earning;
  final DateTime? deliveredAt;

  /// `GET /api/rider/proofs/{proofId}` streams the photo to this rider only.
  final String? proofId;
}

List<RiderHistoryEntry> riderHistoryFromJson(Map<String, Object?> data) => [
  for (final row in (data['history'] as List?) ?? const [])
    if (row is Map && row['id'] != null)
      RiderHistoryEntry(
        sellerOrderId: '${row['id']}',
        status: '${row['status'] ?? ''}',
        statusLabel: '${row['status_label'] ?? row['status'] ?? ''}',
        trackingNumber: row['tracking_number']?.toString(),
        sellerName: row['seller_name']?.toString(),
        deliveryArea: row['delivery_area']?.toString(),
        returned: row['returned'] == true,
        earning: row['earning_minor'] == null
            ? null
            : (_int(row['earning_minor']) ?? 0) / 100,
        deliveredAt: DateTime.tryParse('${row['delivered_at']}')?.toLocal(),
        proofId: row['proof_id']?.toString(),
      ),
];

// ---------------------------------------------------------------------------
// GET /api/rider/payouts
// ---------------------------------------------------------------------------

/// A payment Logistics recorded for the rider.
class RiderPayoutRecord {
  const RiderPayoutRecord({
    required this.id,
    required this.reference,
    required this.amount,
    required this.jobCount,
    this.paidOn,
    this.reversed = false,
  });

  final String id;
  final String reference;
  final double amount;
  final int jobCount;
  final DateTime? paidOn;

  /// Logistics reversed this payment for correction; its jobs are unpaid again.
  final bool reversed;
}

class RiderPayoutsSnapshot {
  const RiderPayoutsSnapshot({
    required this.earnedThisMonth,
    required this.paid,
    required this.pending,
    required this.payouts,
  });

  final double earnedThisMonth;
  final double paid;
  final double pending;
  final List<RiderPayoutRecord> payouts;
}

RiderPayoutsSnapshot riderPayoutsFromJson(Map<String, Object?> data) {
  final summary =
      (data['summary'] as Map?)?.cast<String, Object?>() ?? const {};
  double pesos(Object? minor) => (_int(minor) ?? 0) / 100;

  return RiderPayoutsSnapshot(
    earnedThisMonth: pesos(summary['earned_month_minor']),
    paid: pesos(summary['paid_minor']),
    pending: pesos(summary['pending_minor']),
    payouts: [
      for (final row in (data['payouts'] as List?) ?? const [])
        if (row is Map && row['id'] != null)
          RiderPayoutRecord(
            id: '${row['id']}',
            reference: '${row['reference'] ?? ''}',
            amount: pesos(row['amount_minor']),
            jobCount: _int(row['earning_count']) ?? 0,
            paidOn: DateTime.tryParse('${row['paid_on']}'),
            reversed: row['reversed'] == true,
          ),
    ],
  );
}

// ---------------------------------------------------------------------------
// GET /api/rider/waybills/{id}
// ---------------------------------------------------------------------------

class WaybillParty {
  const WaybillParty({required this.name, this.address, this.phone});

  final String name;
  final String? address;
  final String? phone;
}

/// The private digital waybill. The server sends the seller's contact on a
/// pickup and the buyer's on a delivery, never both.
class RiderWaybill {
  const RiderWaybill({
    required this.context,
    required this.sellerOrderId,
    required this.statusLabel,
    this.trackingNumber,
    this.weight,
    this.itemCount = 0,
    this.seller,
    this.buyer,
    this.destinationArea,
    this.paymentLabel,
    this.collectCod = 0,
  });

  /// `pickup` or `delivery`.
  final String context;
  final String sellerOrderId;
  final String statusLabel;
  final String? trackingNumber;
  final String? weight;
  final int itemCount;
  final WaybillParty? seller;
  final WaybillParty? buyer;
  final String? destinationArea;
  final String? paymentLabel;

  /// Cash to collect at the door (the server's amount); 0 when none.
  final double collectCod;
}

WaybillParty? _party(Object? json) {
  if (json is! Map) return null;
  return WaybillParty(
    name: '${json['name'] ?? ''}',
    address: json['address']?.toString(),
    phone: json['phone']?.toString(),
  );
}

RiderWaybill riderWaybillFromJson(Map<String, Object?> data) => RiderWaybill(
  context: '${data['context'] ?? ''}',
  sellerOrderId: '${data['seller_order_id'] ?? ''}',
  statusLabel: '${data['status_label'] ?? ''}',
  trackingNumber: data['tracking_number']?.toString(),
  weight: data['weight']?.toString(),
  itemCount: _int(data['item_count']) ?? 0,
  seller: _party(data['seller']),
  buyer: _party(data['buyer']),
  destinationArea: data['destination_area']?.toString(),
  paymentLabel: data['payment_label']?.toString(),
  collectCod: (_int(data['collect_cod_minor']) ?? 0) / 100,
);

// ---------------------------------------------------------------------------
// GET /api/rider/profile: station and coverage
// ---------------------------------------------------------------------------

class RiderCoverage {
  const RiderCoverage({
    this.stationName,
    this.stationMunicipality,
    this.areaName,
    this.areaMunicipality,
    this.areaBarangay,
  });

  final String? stationName;
  final String? stationMunicipality;
  final String? areaName;
  final String? areaMunicipality;
  final String? areaBarangay;

  String? get stationLabel => _joinNonEmpty([stationName, stationMunicipality]);
  String? get areaLabel =>
      _joinNonEmpty([areaBarangay, areaMunicipality]) ?? areaName;
}

String? _joinNonEmpty(List<String?> parts) {
  final kept = [
    for (final p in parts)
      if (p != null && p.trim().isNotEmpty) p.trim(),
  ];
  return kept.isEmpty ? null : kept.join(', ');
}

RiderCoverage riderCoverageFromJson(Map<String, Object?> data) {
  final station = (data['station'] as Map?)?.cast<String, Object?>();
  final area = (data['coverage_area'] as Map?)?.cast<String, Object?>();
  return RiderCoverage(
    stationName: station?['name']?.toString(),
    stationMunicipality: station?['municipality']?.toString(),
    areaName: area?['name']?.toString(),
    areaMunicipality: area?['municipality']?.toString(),
    areaBarangay: area?['barangay']?.toString(),
  );
}
