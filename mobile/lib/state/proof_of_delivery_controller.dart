// lib/state/proof_of_delivery_controller.dart
//
// The state machine behind the Proof of Delivery screen.
//
//   editing ──submit──▶ submitting ──ok──▶ succeeded
//                           │
//                           └─error─▶ failed ──retry──▶ submitting …
//
// Rules this class enforces (so no screen can get them wrong):
//
//   * **One evidence method is enough**: a photo *or* a signature. Choosing
//     one replaces the other.
//   * **Evidence must be real.** It is validated when it is set and again when
//     it is submitted: a missing, empty, oversized, unsupported or undecodable
//     file is refused (the draft is kept so it can be replaced) and nothing is
//     sent. The invented-policy line is held: no signature-complexity or
//     GPS-accuracy threshold is applied.
//   * **Duplicate protection**: a second `submit()` while one is in flight, or
//     after success, does nothing. A retry after a failure re-sends the *same*
//     attempt (same idempotency key, evidence, GPS and time). Changing the
//     evidence or the GPS fix starts a new attempt with a new key.
//   * **GPS unavailable (or structurally impossible) is an unresolved
//     exception, not a pass.** No usable fix means `canSubmit` is false and
//     nothing is sent; the owner has not decided a policy (audit U-4).
//   * **Identity and the official time are the server's.** The courier label
//     and device time kept here are for display and cross-checking only.
//   * **Evidence files are released**: replacing or clearing evidence, and
//     closing the screen, hand the file back through [onEvidenceReleased] so
//     the preview-owned file store can delete it. Retries never copy a file.

import 'dart:math';

import 'package:flutter/foundation.dart';

import '../services/pod/pod_evidence.dart';
import '../services/pod/pod_location.dart';
import '../services/pod/pod_models.dart';
import '../services/pod/proof_of_delivery_service.dart';

enum PodPhase { editing, submitting, failed, succeeded }

class ProofOfDeliveryController extends ChangeNotifier {
  ProofOfDeliveryController({
    required this.jobId,
    required this.trackingNumber,
    required this.courierLabel,
    required this._service,
    required this._location,
    PodEvidenceValidator? validator,
    this.onEvidenceReleased,
    DateTime Function()? clock,
    String Function()? idGenerator,
  }) : _validator = validator ?? const FilePodEvidenceValidator(),
       _clock = clock ?? DateTime.now,
       _newId = idGenerator ?? _randomId;

  final String jobId;
  final String trackingNumber;

  /// Display-only courier identity (the server uses the authenticated one).
  final String courierLabel;

  /// Called with evidence this controller no longer holds (replaced, cleared or
  /// the screen closed) so its file can be removed. Never called with a file
  /// that is still the current evidence.
  final Future<void> Function(PodEvidence evidence)? onEvidenceReleased;

  final ProofOfDeliveryService _service;
  final PodLocationProvider _location;
  final PodEvidenceValidator _validator;
  final DateTime Function() _clock;
  final String Function() _newId;

  PodPhase _phase = PodPhase.editing;
  PodEvidence? _evidence;
  EvidenceProblem? _evidenceProblem;
  PodGpsResult? _gps;
  bool _gpsLoading = false;
  String? _error;
  PodReceipt? _receipt;
  PodSubmission? _attempt;
  int _gpsGeneration = 0;
  bool _disposed = false;

  PodPhase get phase => _phase;
  PodEvidence? get evidence => _evidence;

  /// Why the current evidence cannot be used (it is still shown so it can be
  /// replaced), or null when it is usable.
  EvidenceProblem? get evidenceProblem => _evidenceProblem;

  PodGpsResult? get gps => _gps;
  bool get gpsLoading => _gpsLoading;
  String? get errorMessage => _error;
  PodReceipt? get receipt => _receipt;

  /// The device clock now, for display.
  DateTime get now => _clock();

  bool get isSubmitting => _phase == PodPhase.submitting;
  bool get isSucceeded => _phase == PodPhase.succeeded;
  bool get isFailed => _phase == PodPhase.failed;

  /// No usable GPS fix: the unresolved exception state.
  bool get gpsUnavailable => _gps is PodGpsUnavailable;

  /// Evidence and edits are locked while sending and after success.
  bool get isLocked => isSubmitting || isSucceeded;

  /// Why the submit button is disabled, or null when it can be used.
  String? get submitBlocker {
    if (isSucceeded) return 'Already submitted.';
    if (isSubmitting) return 'Submitting…';
    if (_evidence == null) {
      return 'Add a photo or a recipient signature (one is enough).';
    }
    if (_evidenceProblem != null) return _evidenceProblem!.message;
    if (_gpsLoading || _gps == null) return 'Getting the delivery location…';
    if (_gps is PodGpsUnavailable) {
      return 'GPS is unavailable. This is an unresolved exception: the '
          'delivery cannot be completed in the app until the owner decides '
          'how to handle it.';
    }
    return null;
  }

  bool get canSubmit => submitBlocker == null;

  /// Asks the device for the delivery location. Safe to call again (Retry GPS).
  Future<void> refreshGps() async {
    if (isLocked || _gpsLoading) return;
    final generation = ++_gpsGeneration;
    _gpsLoading = true;
    _discardAttempt();
    _notify();
    PodGpsResult result;
    try {
      result = await _location.currentFix();
      if (result is PodGpsFix && !result.isStructurallyValid) {
        result = const PodGpsUnavailable(GpsProblem.unavailable);
      }
    } on Object {
      result = const PodGpsUnavailable(GpsProblem.unavailable);
    }
    if (_disposed || generation != _gpsGeneration) return;
    _gps = result;
    _gpsLoading = false;
    _notify();
  }

  /// Offers [evidence] (replacing any earlier photo or signature). It is
  /// validated first: an unusable file is refused, the earlier evidence is kept
  /// and false is returned. The refused file is handed back for cleanup.
  Future<bool> setEvidence(PodEvidence evidence) async {
    if (isLocked) return false;
    final problem = await _validator.validate(evidence);
    if (_disposed) {
      await _release(evidence);
      return false;
    }
    if (isLocked) {
      await _release(evidence);
      return false;
    }
    if (problem != null) {
      _error = problem.message;
      _notify();
      await _release(evidence);
      return false;
    }
    final previous = _evidence;
    _evidence = evidence;
    _evidenceProblem = null;
    _error = null;
    if (_phase == PodPhase.failed) _phase = PodPhase.editing;
    _discardAttempt();
    _notify();
    if (previous != null && previous != evidence) await _release(previous);
    return true;
  }

  /// Removes the evidence so another can be captured, and releases its file.
  Future<void> clearEvidence() async {
    if (isLocked) return;
    final previous = _evidence;
    _evidence = null;
    _evidenceProblem = null;
    _discardAttempt();
    _notify();
    if (previous != null) await _release(previous);
  }

  /// Sends the proof. Returns true on success. A call while sending, after
  /// success, or without usable evidence and a GPS fix sends nothing.
  Future<bool> submit() async {
    if (isLocked) return false;
    if (_evidence != null && _evidenceProblem == null) {
      // The file may have been deleted since it was accepted.
      final problem = await _validator.validate(_evidence!);
      if (_disposed) return false;
      if (problem != null) {
        _evidenceProblem = problem;
        _discardAttempt();
      }
    }
    if (!canSubmit) {
      _error = submitBlocker;
      _notify();
      return false;
    }

    final attempt = _attempt ??= PodSubmission(
      submissionId: _newId(),
      jobId: jobId,
      trackingNumber: trackingNumber,
      evidence: _evidence!,
      gps: _gps! as PodGpsFix,
      courierLabel: courierLabel,
      deviceCapturedAt: _clock(),
    );

    _phase = PodPhase.submitting;
    _error = null;
    _notify();
    try {
      final receipt = await _service.submit(attempt);
      if (_disposed) return true;
      _receipt = receipt;
      _phase = PodPhase.succeeded;
      _notify();
      return true;
    } on PodUploadFailure catch (failure) {
      return _fail(failure.message);
    } on Object catch (error) {
      return _fail(
        error is Exception ? error.toString() : 'The upload failed. Try again.',
      );
    }
  }

  bool _fail(String message) {
    if (_disposed) return false;
    _phase = PodPhase.failed;
    _error = message;
    _notify();
    return false;
  }

  void _discardAttempt() {
    if (_phase != PodPhase.submitting) _attempt = null;
  }

  Future<void> _release(PodEvidence evidence) async {
    final callback = onEvidenceReleased;
    if (callback == null) return;
    try {
      await callback(evidence);
    } on Object {
      // Cleanup is best effort; it must never break the flow.
    }
  }

  void _notify() {
    if (!_disposed) notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    final held = _evidence;
    _evidence = null;
    if (held != null) _release(held);
    super.dispose();
  }

  static final Random _random = Random.secure();

  static String _randomId() {
    String hex(int n) =>
        List.generate(n, (_) => _random.nextInt(16).toRadixString(16)).join();
    return 'pod-${hex(8)}-${hex(4)}-${hex(4)}-${hex(12)}';
  }
}
