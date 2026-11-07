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
    deliveryOtp: json['delivery_otp']?.toString() ?? '',
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
/// pesos only. The server never sends the delivery OTP, so [DeliveryJob.deliveryOtp]
/// is empty and the tracker leaves the code check to the server. The per-job
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
    deliveryArea: json['delivery_address']?.toString() ??
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
    deliveryOtp: '',
    serverStatus: json['status']?.toString(),
  );
}

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
