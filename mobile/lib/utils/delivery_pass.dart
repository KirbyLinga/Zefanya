// The QR payload for the buyer-to-rider handover.
//
// The buyer shows a delivery pass; the rider scans it at the door. The
// payload carries both the job it belongs to and the 6-digit code the
// server already treats as the handover proof, so a pass photographed
// from another order cannot be used to complete this one.
//
// The code is the same one the buyer can read aloud, and the courier's
// [CourierState.validateDeliveryOtp] still verifies it. This is a
// presentation change, not a second source of truth.

const String _deliveryPassPrefix = 'ZEFANYA:DELIVERY';

class DeliveryPassPayload {
  const DeliveryPassPayload({required this.jobId, required this.otp});

  final String jobId;
  final String otp;

  String encode() => '$_deliveryPassPrefix:$jobId:$otp';

  /// Parses a scanned pass, or null when the value is not one.
  ///
  /// The prefix is matched case-insensitively so a QR encoder that
  /// upper-cases alphanumeric payloads still works, but the job id and
  /// code are kept as issued.
  static DeliveryPassPayload? tryParse(String raw) {
    final parts = raw.trim().split(':');
    if (parts.length != 4) return null;
    if (parts[0].toUpperCase() != 'ZEFANYA' ||
        parts[1].toUpperCase() != 'DELIVERY') {
      return null;
    }

    final jobId = parts[2].trim();
    final otp = parts[3].trim();
    if (jobId.isEmpty || !RegExp(r'^\d{6}$').hasMatch(otp)) return null;

    return DeliveryPassPayload(jobId: jobId, otp: otp);
  }
}

/// The result of interpreting text returned by the QR scanner.
///
/// A bare 6-digit value is accepted as manual entry: the scanner's
/// fallback field asks for the same code when the camera is unavailable.
typedef DeliveryPassScan = ({String? code, String? error});

DeliveryPassScan readDeliveryPassScan(String raw, {required String jobId}) {
  final trimmed = raw.trim();
  if (RegExp(r'^\d{6}$').hasMatch(trimmed)) {
    return (code: trimmed, error: null);
  }

  final payload = DeliveryPassPayload.tryParse(trimmed);
  if (payload == null) {
    return (code: null, error: 'That QR is not a Zefanya delivery pass.');
  }
  if (payload.jobId.toUpperCase() != jobId.trim().toUpperCase()) {
    return (
      code: null,
      error: 'That delivery pass belongs to a different order.',
    );
  }

  return (code: payload.otp, error: null);
}
