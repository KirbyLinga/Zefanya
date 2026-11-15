// lib/services/pod/pod_models.dart
//
// The data a Proof of Delivery (POD) submission carries, per the approved
// policy (owner decision D3 + C-POD plan):
//
//   * delivery photograph **OR** recipient signature (one is enough),
//   * the authenticated courier identity,
//   * a server-recorded timestamp,
//   * GPS coordinates of the delivery event,
//   * the parcel / order reference.
//
// Identity and the official timestamp are *authoritative on the server*; the
// values here are for display and for the server to cross-check. Nothing in
// this file talks to a network.

/// How the delivery was evidenced. Alternatives: exactly one is required.
enum PodEvidenceMethod {
  photo,
  signature;

  String get label => switch (this) {
    PodEvidenceMethod.photo => 'Photo',
    PodEvidenceMethod.signature => 'Signature',
  };
}

/// The evidence file captured on the device.
class PodEvidence {
  const PodEvidence({required this.method, required this.path});

  final PodEvidenceMethod method;

  /// Path of the saved image (a JPEG photo or a PNG signature).
  final String path;

  @override
  bool operator ==(Object other) =>
      other is PodEvidence && other.method == method && other.path == path;

  @override
  int get hashCode => Object.hash(method, path);
}

/// Why a GPS fix could not be obtained.
enum GpsProblem {
  permissionDenied,
  servicesDisabled,
  timeout,
  unavailable;

  String get message => switch (this) {
    GpsProblem.permissionDenied =>
      'Location permission is off. Allow location for Zefanya in Settings.',
    GpsProblem.servicesDisabled => 'Location services are turned off.',
    GpsProblem.timeout => 'No GPS signal yet. Move to an open area and retry.',
    GpsProblem.unavailable => 'The device could not provide a location.',
  };
}

/// The outcome of asking the device for the delivery location.
sealed class PodGpsResult {
  const PodGpsResult();
}

/// A usable GPS fix. [accuracyMeters] is the device's own estimate; the app
/// invents no pass/fail threshold for it.
final class PodGpsFix extends PodGpsResult {
  const PodGpsFix({
    required this.latitude,
    required this.longitude,
    required this.accuracyMeters,
    required this.capturedAt,
    this.isMocked = false,
  });

  final double latitude;
  final double longitude;
  final double accuracyMeters;
  final DateTime capturedAt;

  /// True when the OS says the position came from a mock-location provider.
  /// Shown and sent for review; it does not by itself approve or reject.
  final bool isMocked;

  /// Structurally possible: finite values, latitude and longitude in range,
  /// non-negative accuracy. This is *not* an accuracy or freshness policy; the
  /// owner has set none, and the app invents none.
  bool get isStructurallyValid =>
      latitude.isFinite &&
      longitude.isFinite &&
      accuracyMeters.isFinite &&
      latitude >= -90 &&
      latitude <= 90 &&
      longitude >= -180 &&
      longitude <= 180 &&
      accuracyMeters >= 0;

  Map<String, Object?> toJson() => {
    'latitude': latitude,
    'longitude': longitude,
    'accuracy_m': accuracyMeters,
    'captured_at': capturedAt.toUtc().toIso8601String(),
    'mocked': isMocked,
  };
}

/// No fix. This is an **unresolved exception state**, not a pass: the owner has
/// not decided whether a delivery may complete without GPS (audit U-4).
final class PodGpsUnavailable extends PodGpsResult {
  const PodGpsUnavailable(this.problem);

  final GpsProblem problem;
}

/// One submission attempt. The same object is re-sent on a retry so the
/// idempotency key, payload and timestamps are identical.
class PodSubmission {
  const PodSubmission({
    required this.submissionId,
    required this.jobId,
    required this.trackingNumber,
    required this.evidence,
    required this.gps,
    required this.courierLabel,
    required this.deviceCapturedAt,
  });

  /// Idempotency key (client-generated, unique per intended submission).
  final String submissionId;

  /// The parcel (seller order) id.
  final String jobId;
  final String trackingNumber;
  final PodEvidence evidence;
  final PodGpsFix gps;

  /// Display-only courier identity. The server derives the real identity from
  /// the authenticated session and ignores this.
  final String courierLabel;

  /// Device clock when the proof was submitted. Informational: the server
  /// stamps the official time.
  final DateTime deviceCapturedAt;
}

/// What the service answers for an accepted submission.
class PodReceipt {
  const PodReceipt({
    required this.submissionId,
    required this.reference,
    required this.recordedAt,
    required this.isPreview,
  });

  final String submissionId;
  final String reference;

  /// The service's own timestamp (the server's, for a real implementation).
  final DateTime recordedAt;

  /// True when produced by the mock; nothing was recorded anywhere real.
  final bool isPreview;
}
