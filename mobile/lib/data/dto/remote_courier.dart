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
  );
}

double _double(Object? value) {
  if (value is double) return value;
  if (value is int) return value.toDouble();
  if (value is String) return double.tryParse(value) ?? 0.0;
  return 0.0;
}
