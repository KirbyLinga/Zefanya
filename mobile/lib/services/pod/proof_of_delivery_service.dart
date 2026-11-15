// lib/services/pod/proof_of_delivery_service.dart
//
// Where a Proof of Delivery submission goes.
//
// **Phase 2 ships only the mock.** [MockProofOfDeliveryService] keeps
// submissions in memory and never touches a network or any WebZefanya parcel:
// it has no HTTP client, no `ApiClient`, and no repository, so it *cannot*
// mark a real parcel delivered (a test also scans this file's imports).
//
// The real implementation is the separately approved C-POD change on the
// WebZefanya backend (replace the Buyer OTP with photo-or-signature). Until
// that is approved and built, the live profile keeps the server OTP.

import 'pod_models.dart';

abstract class ProofOfDeliveryService {
  /// True for the preview implementation, which never reaches any server.
  bool get isPreview;

  /// Records [submission]. Re-sending the same `submissionId` returns the
  /// original receipt without recording anything twice (idempotent).
  ///
  /// Throws on a failed upload so the caller can keep the draft and retry.
  Future<PodReceipt> submit(PodSubmission submission);
}

/// The same submission id was sent again with a different payload (preview
/// only: a real backend must define its own conflict semantics).
class PodConflict implements Exception {
  const PodConflict();

  @override
  String toString() =>
      'This submission id was already used for different evidence.';
}

/// A submission that failed in transit (preview/test only).
class PodUploadFailure implements Exception {
  const PodUploadFailure([this.message = 'The upload failed. Try again.']);

  final String message;

  @override
  String toString() => message;
}

/// In-memory preview service. See the file comment: no network, ever.
class MockProofOfDeliveryService implements ProofOfDeliveryService {
  MockProofOfDeliveryService({
    this.latency = const Duration(milliseconds: 700),
    DateTime Function()? clock,
    int failNextSubmissions = 0,
  }) : _clock = clock ?? DateTime.now,
       _failuresRemaining = failNextSubmissions;

  /// Simulated upload time (zero in tests).
  final Duration latency;

  final DateTime Function() _clock;
  int _failuresRemaining;
  final Map<String, PodReceipt> _receipts = {};
  final List<PodSubmission> _accepted = [];
  int _attempts = 0;

  @override
  bool get isPreview => true;

  /// How many times [submit] was called (including failed attempts).
  int get attempts => _attempts;

  /// Distinct submissions that were accepted, oldest first.
  List<PodSubmission> get accepted => List.unmodifiable(_accepted);

  /// Makes the next [count] submissions fail, to rehearse the retry path.
  void failNext(int count) => _failuresRemaining = count;

  @override
  Future<PodReceipt> submit(PodSubmission submission) async {
    _attempts++;
    if (latency > Duration.zero) await Future<void>.delayed(latency);

    final existing = _receipts[submission.submissionId];
    if (existing != null) {
      final first = _accepted.firstWhere(
        (s) => s.submissionId == submission.submissionId,
      );
      final same =
          first.jobId == submission.jobId &&
          first.evidence == submission.evidence &&
          first.gps.latitude == submission.gps.latitude &&
          first.gps.longitude == submission.gps.longitude;
      if (!same) throw const PodConflict();
      return existing;
    }

    if (_failuresRemaining > 0) {
      _failuresRemaining--;
      throw const PodUploadFailure();
    }

    final receipt = PodReceipt(
      submissionId: submission.submissionId,
      reference: 'PREVIEW-${(_accepted.length + 1).toString().padLeft(4, '0')}',
      recordedAt: _clock(),
      isPreview: true,
    );
    _receipts[submission.submissionId] = receipt;
    _accepted.add(submission);
    return receipt;
  }
}
