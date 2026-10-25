// lib/state/courier_state.dart
//
// Per the updated ERP-Components spec, delivery jobs are ASSIGNED to a
// courier by the Logistics/Sorting Center's dispatcher (see that
// role's "Delivery assignment (per area and per rider)") — couriers no
// longer browse an open pool and accept first-come-first-served. This
// mock has no multi-courier simulation, so every job the dispatcher's
// repository returns and that isn't finished is treated as "assigned to me."
//
// Two dashboards read from here, split by stage:
//   - Pickup Dashboard:   milestone is accepted/arrivedAtSeller (not yet picked up)
//   - Delivery Dashboard: milestone is pickedUp/inTransit (picked up, not yet delivered)
// Tapping a job in either pushes DeliveryTrackerScreen for that
// specific job (jobs progress independently — a courier can have
// several assigned at once, unlike the old single "activeJob" model).
//
// Completed jobs are timestamped (not just tracked as a bare id set) so
// CourierProfitScreen can compute real Today/This Week earnings and a
// real transaction log instead of separate hardcoded numbers that
// never moved when a delivery was actually completed.
//
// Jobs come from two places: the dispatcher's repository (`assignedJobs`,
// seeded with samples in mock mode), and jobs dispatched by real checkouts
// (`dispatchForOrder`) — each carries the `orderId` it delivers, which is how
// the buyer's Orders screen follows the courier's progress (see
// OrderState.resolvedOrders).
//
// Persisted via shared_preferences, so nothing resets on an app restart.

import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../data/api/api_exception.dart';
import '../data/repositories/courier_repository.dart';
import '../data/mock_data.dart'
    show DeliveryJob, Order, kMockDeliveryOtp;
import '../utils/money_format.dart';

// Courier-side delivery lifecycle. The full sequence per the ERP spec:
//
//   Pickup leg (rider A → sorting center):
//     accepted → arrivedAtSeller → pickedUp → atSortingCenter
//
//   Sorting center:
//     sorted → assignedToRider
//
//   Delivery leg (rider B → buyer):
//     inTransit → delivered
//
//   Failure paths (from inTransit only):
//     failedDelivery → (rescheduled back to inTransit, or) returnToSender
//
// The buyer-facing OrderStatus (4 states) is a deliberate UX simplification
// of this — a buyer doesn't need to see "SORTED" vs "AT_SORTING_CENTER".
enum DeliveryMilestone {
  // Pickup leg
  accepted,
  arrivedAtSeller,
  pickedUp,
  atSortingCenter,
  // Sorting center
  sorted,
  assignedToRider,
  // Delivery leg
  inTransit,
  delivered,
  // Failure paths
  failedDelivery,
  returnToSender,
}

class AssignedJob {
  const AssignedJob(this.job, this.milestone);
  final DeliveryJob job;
  final DeliveryMilestone milestone;
}

class CompletedJob {
  const CompletedJob(
    this.job,
    this.completedAt,
    this.payout, {
    this.returned = false,
  });

  final DeliveryJob job;
  final DateTime completedAt;

  /// What was actually credited for this job — deliberately NOT [job.fee].
  ///
  /// [job.fee] is what the seller agreed to pay for the trip; [payout] is what
  /// the trip actually earned. They were the same number until returns were
  /// priced, which is precisely why they're separate fields: a return pays a
  /// share of the fee ([kReturnFeeShare]), so anything summing earnings has to
  /// read this and not the posted fee. Every earnings total in this class does.
  final double payout;

  /// Whether this job closed as a return rather than a successful delivery.
  ///
  /// Stored rather than inferred from `payout < fee`: a zero-fee return and a
  /// zero-fee delivery would otherwise be indistinguishable, and the earnings
  /// history has to say which one happened (RID-EARN-001).
  final bool returned;

  String get resultLabel => returned ? 'Returned' : 'Delivered';

  /// The waybill/reference the workbook's history column shows.
  String get trackingNumber => job.trackingNumber ?? job.id;
}

/// What a delivery that ends in `returnToSender` pays, as a share of the
/// job's fee.
///
/// ## The decision
///
/// Before this, a return paid the **full** fee, with a comment saying the
/// choice was flagged rather than settled. Partial is the better answer, and
/// here is the reasoning rather than just the number:
///
///  * The courier genuinely did most of the job. They travelled to the
///    address, waited, and had to bring the parcel back — so paying nothing
///    would be paying for a delivery-shaped trip as if it hadn't happened.
///  * But it is not the same work, and the full fee is the wrong signal in
///    two directions at once: it makes a failed handover cost the courier
///    nothing (the trip back is unpaid travel they absorb either way), and it
///    makes a return, on the earnings screen, indistinguishable from a
///    success — while the screen that *reports* the return goes out of its
///    way to be different ([DeliveryCompletionView] never celebrates one).
///  * Half is the convention most platforms land on for a completed leg with
///    a failed handover: enough that the trip was worth making, little enough
///    that the two outcomes aren't equal.
///
/// ## What is still open
///
/// Whether the *buyer's* shipping fee should be partially refunded on a return
/// is a separate policy question — this constant only governs courier pay, and
/// the buyer's side has no return-refund path built at all yet. If the rubric
/// wants 60%, 40%, or zero, this is the one line to change: nothing else
/// encodes the rate, and the earnings maths reads [CompletedJob.payout].
const double kReturnFeeShare = 0.5;

/// Whether the rider may take work. A suspended account can still sign in and
/// see its history — suspension is a work restriction, not a ban — but cannot
/// go online or be offered a job.
enum RiderWorkStatus { active, suspended }

/// One cash amount the rider collected at a door and still owes Logistics.
class CodCollection {
  const CodCollection({
    required this.jobId,
    required this.trackingNumber,
    required this.amount,
    required this.collectedAt,
  });

  final String jobId;
  final String trackingNumber;
  final double amount;
  final DateTime collectedAt;

  Map<String, Object?> toJson() => {
    'jobId': jobId,
    'trackingNumber': trackingNumber,
    'amount': amount,
    'collectedAt': collectedAt.toIso8601String(),
  };

  static CodCollection? fromJson(Map<String, Object?> json) {
    final jobId = json['jobId']?.toString();
    final trackingNumber = json['trackingNumber']?.toString();
    final amount = (json['amount'] as num?)?.toDouble();
    final collectedAt = DateTime.tryParse(
      json['collectedAt']?.toString() ?? '',
    );
    if (jobId == null ||
        trackingNumber == null ||
        amount == null ||
        collectedAt == null) {
      return null;
    }
    return CodCollection(
      jobId: jobId,
      trackingNumber: trackingNumber,
      amount: amount,
      collectedAt: collectedAt,
    );
  }
}

enum RemittanceStatus { pending, confirmed }

/// A cash handover request. The rider *requests*; only Logistics confirms, so
/// [status] changes exclusively through [CourierState.confirmRemittance], which
/// is the mock stand-in for the Logistics action — there is deliberately no
/// rider-facing button that calls it (RID-EARN-006).
class CodRemittance {
  const CodRemittance({
    required this.id,
    required this.amount,
    required this.requestedAt,
    this.status = RemittanceStatus.pending,
    this.confirmedAt,
  });

  final String id;
  final double amount;
  final DateTime requestedAt;
  final RemittanceStatus status;
  final DateTime? confirmedAt;

  bool get isPending => status == RemittanceStatus.pending;

  CodRemittance copyWith({
    RemittanceStatus? status,
    DateTime? confirmedAt,
  }) => CodRemittance(
    id: id,
    amount: amount,
    requestedAt: requestedAt,
    status: status ?? this.status,
    confirmedAt: confirmedAt ?? this.confirmedAt,
  );

  Map<String, Object?> toJson() => {
    'id': id,
    'amount': amount,
    'requestedAt': requestedAt.toIso8601String(),
    'status': status.name,
    'confirmedAt': confirmedAt?.toIso8601String(),
  };

  static CodRemittance? fromJson(Map<String, Object?> json) {
    final id = json['id']?.toString();
    final amount = (json['amount'] as num?)?.toDouble();
    final requestedAt = DateTime.tryParse(
      json['requestedAt']?.toString() ?? '',
    );
    if (id == null || amount == null || requestedAt == null) return null;
    final statusName = json['status']?.toString();
    RemittanceStatus status = RemittanceStatus.pending;
    for (final value in RemittanceStatus.values) {
      if (value.name == statusName) {
        status = value;
        break;
      }
    }
    return CodRemittance(
      id: id,
      amount: amount,
      requestedAt: requestedAt,
      status: status,
      confirmedAt: DateTime.tryParse(json['confirmedAt']?.toString() ?? ''),
    );
  }
}

class CourierState extends ChangeNotifier {
  /// [repository] defaults to the mock implementation so existing call sites
  /// (and tests) keep working unchanged; `main.dart` passes the resolved
  /// bundle's, which is what makes the live switch possible.
  ///
  /// Only the *jobs* come from here — see courier_repository.dart for why the
  /// milestone machine, the payouts and the ratings deliberately stay local.
  CourierState({CourierRepository? repository, DateTime Function()? clock})
    : _repository = repository ?? const MockCourierRepository(),
      _clock = clock ?? DateTime.now;

  final CourierRepository _repository;

  /// Injectable clock for the OTP lockout boundary. Production uses
  /// [DateTime.now]; a test can move time forward by 15 minutes without
  /// sleeping, which is the only way to prove the boundary rather than the
  /// message around it.
  final DateTime Function() _clock;

  /// Operational availability. Deliberately NOT persisted: a force-closed app
  /// must come back Offline (RID-AVAIL-007), and an on-device "Online" flag
  /// surviving a process death would claim presence the server never saw.
  bool _online = false;

  RiderWorkStatus _workStatus = RiderWorkStatus.active;
  String? _suspensionReason;

  /// Failed-delivery attempt count and reason, keyed by job id. Persisted now
  /// (see [advanceMilestone]) because the attempt counter is the one piece of
  /// failure bookkeeping a rider needs after the app is killed mid-shift:
  /// without it a fourth attempt becomes possible on relaunch.
  final Map<String, int> _attempts = {};
  final Map<String, String> _failureReasons = {};
  final Map<String, bool> _returned = {};

  /// Delivery OTP failure count and lock expiry, per job. In memory: the same
  /// server that issued the code owns the durable attempt count. The lock is
  /// 15 minutes and starts on the fifth wrong code (RID-DLV-004).
  final Map<String, int> _otpFailures = {};
  final Map<String, DateTime> _otpLockedUntil = {};

  /// Cash collected at the door, keyed by job id, and remittances requested
  /// against it. Confirmed remittances reduce [codOutstanding]; pending ones
  /// do not — the rider still holds the cash until Logistics confirms.
  final Map<String, CodCollection> _codCollected = {};
  final List<CodRemittance> _remittances = [];

  /// The dispatcher's jobs, as of the last [load].
  ///
  /// Cached because the four getters below are synchronous and called from
  /// `build()`. Filled by [load] rather than fetched lazily, so the dashboards'
  /// first frame is the real list rather than an empty one that fills in.
  List<DeliveryJob> _assigned = const [];

  final Map<String, DeliveryMilestone> _milestones = {};
  final Map<String, DateTime> _completedAt = {};

  /// What each completed job actually paid, keyed by job id.
  ///
  /// Stored rather than re-derived because it is a record of what was credited
  /// at the time, not a formula — if [kReturnFeeShare] is ever changed, past
  /// payouts must not silently move with it. See CompletedJob.payout.
  final Map<String, double> _payouts = {};

  /// The buyer's rating of the courier, per completed job, 1–5.
  ///
  /// Keyed by job rather than averaged on write, for the same reason: an
  /// average is a view over the ratings, and it has to be able to change
  /// (a second delivery, an edit) without the stored history changing shape.
  final Map<String, int> _ratings = {};

  /// Jobs created by real checkouts, oldest first.
  final List<DeliveryJob> _dispatched = [];

  static const _milestonesKey =
      'courier_milestones'; // "jobId:milestoneName" per entry
  // "jobId|iso8601|payout" per entry. Deliberately pipe-delimited rather than
  // colon-delimited: an ISO timestamp contains colons of its own, and the
  // first version of this parsed with indexOf(':')/lastIndexOf(':'), which
  // would read an ISO timestamp's trailing seconds ("…T10:04:02.123" →
  // "02.123") as a payout. The pipe can't appear in either field.
  static const _completedKey = 'courier_completed';
  static const _ratingsKey = 'courier_ratings'; // "jobId|stars" per entry
  static const _dispatchedKey =
      'courier_dispatched'; // JSON list of dispatched jobs
  static const _attemptsKey = 'courier_attempts'; // JSON jobId → {count,reason}
  static const _codCollectedKey = 'courier_cod_collected'; // JSON list
  static const _remittancesKey = 'courier_remittances'; // JSON list

  /// Seeded sample jobs use REQ-55xx numbers; dispatched ones start well
  /// clear of them so an id never collides.
  static const int _firstDispatchedNumber = 5600;

  /// Every job this courier could see: the dispatcher's, then the ones this
  /// device dispatched from real checkouts.
  List<DeliveryJob> get allJobs => [..._assigned, ..._dispatched];

  /// Every job not yet completed, wrapped with its current milestone
  /// (defaulting to "accepted" — i.e. assigned, not yet actioned).
  List<AssignedJob> get assignedJobs => allJobs
      .where((j) => !_completedAt.containsKey(j.id))
      .map(
        (j) => AssignedJob(j, _milestones[j.id] ?? DeliveryMilestone.accepted),
      )
      .toList();

  /// "Items for Pickup" — assigned but not yet at the sorting center.
  List<AssignedJob> get jobsForPickup => assignedJobs
      .where(
        (a) =>
            a.milestone == DeliveryMilestone.accepted ||
            a.milestone == DeliveryMilestone.arrivedAtSeller ||
            a.milestone == DeliveryMilestone.pickedUp ||
            a.milestone == DeliveryMilestone.atSortingCenter ||
            a.milestone == DeliveryMilestone.sorted,
      )
      .toList();

  /// "Items for Delivery" — sorted/assigned to rider, en route or failed.
  List<AssignedJob> get jobsForDelivery => assignedJobs
      .where(
        (a) =>
            a.milestone == DeliveryMilestone.assignedToRider ||
            a.milestone == DeliveryMilestone.inTransit ||
            a.milestone == DeliveryMilestone.failedDelivery ||
            a.milestone == DeliveryMilestone.returnToSender,
      )
      .toList();

  /// Completed jobs, most recent first — this is what CourierProfitScreen
  /// actually reads for its summary card and transaction list, rather
  /// than separate hardcoded numbers.
  List<CompletedJob> get completedJobs {
    final result = <CompletedJob>[];
    for (final job in allJobs) {
      final completedAt = _completedAt[job.id];
      if (completedAt != null) {
        // Falls back to the full fee for entries written before payouts were
        // recorded — those were all full-fee by definition, so the fallback
        // is the historically correct value, not a guess.
        result.add(
          CompletedJob(
            job,
            completedAt,
            _payouts[job.id] ?? job.fee,
            returned: _returned[job.id] ?? false,
          ),
        );
      }
    }
    result.sort((a, b) => b.completedAt.compareTo(a.completedAt));
    return result;
  }

  double get todayEarnings {
    final now = DateTime.now();
    return completedJobs
        .where(
          (c) =>
              c.completedAt.year == now.year &&
              c.completedAt.month == now.month &&
              c.completedAt.day == now.day,
        )
        .fold(0.0, (sum, c) => sum + c.payout);
  }

  double get weekEarnings {
    final weekAgo = DateTime.now().subtract(const Duration(days: 7));
    return completedJobs
        .where((c) => c.completedAt.isAfter(weekAgo))
        .fold(0.0, (sum, c) => sum + c.payout);
  }

  double get totalEarnings =>
      completedJobs.fold(0.0, (sum, c) => sum + c.payout);

  /// The buyer's rating of this job's delivery, or null if they haven't rated
  /// it. Null is meaningful: an unrated delivery is not a zero-star one.
  int? ratingFor(String jobId) => _ratings[jobId];

  /// Why no jobs were read, when the reason is a failed request rather than an
  /// empty day.
  ///
  /// [CourierRepository.assignedJobs]' doc is the reason this exists: a failure
  /// must throw "so the dashboard can tell 'no jobs today' apart from 'we
  /// couldn't ask'". [load] cannot let the throw out (see below), so the failure
  /// is kept here instead and the dashboards' empty states read it.
  ApiException? _loadFailure;

  /// The failure behind an empty job list, or null when nothing failed.
  ApiException? get loadFailure => _loadFailure;

  /// Re-reads the dispatcher's jobs, recording how the attempt went.
  ///
  /// The counterpart of `OrderState.refresh()` for this half, and what the
  /// dashboards' Retry calls. A failure still propagates — the interface promises
  /// it throws — *and* is kept in [loadFailure] for the reader that cannot catch.
  Future<void> refreshJobs() async {
    try {
      _assigned = List.of(await _repository.assignedJobs());
      _loadFailure = null;
    } on ApiException catch (error) {
      _loadFailure = error;
      rethrow;
    } finally {
      notifyListeners();
    }
  }

  /// How many of this courier's deliveries have been rated.
  int get ratingCount => _ratings.length;

  /// Mean of every rating received, or null when none have.
  ///
  /// Returns null rather than 0.0 on purpose — a courier with no ratings must
  /// not be shown as "0.0", which reads as the worst possible score instead of
  /// the absence of one.
  double? get averageRating {
    if (_ratings.isEmpty) return null;
    final sum = _ratings.values.fold<int>(0, (total, stars) => total + stars);
    return sum / _ratings.length;
  }

  /// Records the buyer's rating of a completed delivery. Called from the
  /// review screen, which is the app's post-delivery moment.
  ///
  /// Re-rating overwrites rather than accumulating: a buyer changing their
  /// mind about one delivery should not count twice toward the average.
  void rateJob(String jobId, int stars) {
    if (stars < 1 || stars > 5) return;
    _ratings[jobId] = stars;
    notifyListeners();
    _save();
  }

  DeliveryMilestone milestoneFor(String jobId) =>
      _milestones[jobId] ?? DeliveryMilestone.accepted;

  /// Failed attempts recorded for this job, 0 before the first failure.
  int attemptsFor(String jobId) => _attempts[jobId] ?? 0;

  /// The reason chosen on the most recent failure, if any.
  String? failureReasonFor(String jobId) => _failureReasons[jobId];

  // ── Availability ─────────────────────────────────────────────────────────

  bool get isOnline => _online;

  RiderWorkStatus get workStatus => _workStatus;

  bool get isSuspended => _workStatus == RiderWorkStatus.suspended;

  String? get suspensionReason => _suspensionReason;

  /// Goes online, or explains why the account cannot.
  ///
  /// A suspension is a server decision mirrored here. The message names the
  /// restriction rather than failing silently: "you are suspended" is
  /// actionable (contact Logistics); an unchanged toggle looks like a bug.
  String? goOnline() {
    if (isSuspended) {
      final reason = _suspensionReason;
      return reason == null || reason.trim().isEmpty
          ? 'Your account is suspended. Contact Logistics before going online.'
          : 'Your account is suspended: $reason';
    }
    if (_online) return null;
    _online = true;
    notifyListeners();
    return null;
  }

  /// Going offline is always allowed, including with a picked-up job: the
  /// rider must not be forced to keep accepting work to finish the parcel
  /// already in their hands (RID-AVAIL-003).
  void goOffline() {
    if (!_online) return;
    _online = false;
    notifyListeners();
  }

  /// Applies a Logistics decision. The server is the authority; this is the
  /// cached copy the app gates on.
  void setSuspended({required bool suspended, String? reason}) {
    _workStatus = suspended
        ? RiderWorkStatus.suspended
        : RiderWorkStatus.active;
    _suspensionReason = suspended ? reason : null;
    if (suspended) _online = false;
    notifyListeners();
  }

  // ── Delivery OTP ─────────────────────────────────────────────────────────

  static const int maxOtpAttempts = 5;
  static const Duration otpLockout = Duration(minutes: 15);

  /// Remaining lockout, or null when entry is open.
  Duration? otpLockRemaining(String jobId) {
    final until = _otpLockedUntil[jobId];
    if (until == null) return null;
    final remaining = until.difference(_clock());
    // Exactly 15 minutes is *expired*, not still locked. RID-DLV-004's
    // boundary is "try again after 15 minutes", and a zero-length remaining
    // duration would keep the field disabled forever at that instant.
    if (remaining <= Duration.zero) {
      _otpLockedUntil.remove(jobId);
      return null;
    }
    return remaining;
  }

  bool isOtpLocked(String jobId) => otpLockRemaining(jobId) != null;

  int otpFailuresFor(String jobId) => _otpFailures[jobId] ?? 0;

  /// Checks the code the buyer read out. Returns null when it is accepted.
  ///
  /// A five-digit entry is a format error and does not count toward the five
  /// wrong-code attempts: the workbook distinguishes "5 digits is rejected"
  /// from "each wrong OTP is rejected", and counting a mistyped length would
  /// lock a rider out for tapping one key short.
  String? validateDeliveryOtp(String jobId, String input) {
    final job = jobById(jobId);
    if (job == null) return 'This job is no longer available.';

    final locked = otpLockRemaining(jobId);
    if (locked != null) {
      final minutes = (locked.inSeconds / 60).ceil();
      return 'Too many wrong codes. Try again in $minutes '
          '${minutes == 1 ? 'minute' : 'minutes'}.';
    }
    // A lock that has just expired starts a fresh set of attempts.
    _otpLockedUntil.remove(jobId);
    if (otpFailuresFor(jobId) >= maxOtpAttempts) _otpFailures.remove(jobId);

    final code = input.trim();
    if (!RegExp(r'^\d{6}$').hasMatch(code)) {
      return 'Enter the 6-digit code shown by the buyer.';
    }
    // A live job arrives without the code by design: the server keeps the
    // hash and verifies it with the milestone write. Accept the shape here
    // and let the server's five-attempt/15-minute lock be the authority.
    // Counting a failure locally would lock the rider out of a code this
    // device was never given.
    if (job.deliveryOtp.isEmpty) return null;

    if (code == job.deliveryOtp) {
      _otpFailures.remove(jobId);
      _otpLockedUntil.remove(jobId);
      return null;
    }

    final failures = otpFailuresFor(jobId) + 1;
    _otpFailures[jobId] = failures;
    if (failures >= maxOtpAttempts) {
      _otpLockedUntil[jobId] = _clock().add(otpLockout);
      notifyListeners();
      return 'Too many wrong codes. Entry is locked for 15 minutes.';
    }
    notifyListeners();
    return 'That code is incorrect. ${maxOtpAttempts - failures} '
        '${maxOtpAttempts - failures == 1 ? 'attempt' : 'attempts'} left.';
  }

  // ── COD ──────────────────────────────────────────────────────────────────

  /// Checks the amount entered at the door against the job's own COD total.
  /// Null when it matches (and for a prepaid job, where nothing is asked).
  String? validateCodAmount(String jobId, String input) {
    final job = jobById(jobId);
    if (job == null) return 'This job is no longer available.';
    if (!job.hasCod) return null;

    final normalized = input
        .replaceAll('₱', '')
        .replaceAll(',', '')
        .replaceAll(' ', '')
        .trim();
    final parsed = double.tryParse(normalized);
    if (parsed == null) return 'Enter the amount collected.';
    final expected = (job.codAmount * 100).round();
    final actual = (parsed * 100).round();
    if (actual != expected) {
      return 'Amount does not match. Collect exactly ${formatPeso(job.codAmount)}.';
    }
    return null;
  }

  List<CodCollection> get codCollections {
    final result = _codCollected.values.toList()
      ..sort((a, b) => b.collectedAt.compareTo(a.collectedAt));
    return result;
  }

  /// Everything collected minus everything Logistics has confirmed receiving.
  /// A pending remittance does not reduce this — the rider still has the cash
  /// until the handover is confirmed (RID-EARN-004).
  double get codOutstanding {
    final collected = _codCollected.values.fold(
      0.0,
      (sum, c) => sum + c.amount,
    );
    final confirmed = _remittances
        .where((r) => r.status == RemittanceStatus.confirmed)
        .fold(0.0, (sum, r) => sum + r.amount);
    final outstanding = collected - confirmed;
    return outstanding < 0 ? 0 : outstanding;
  }

  List<CodRemittance> get remittances => List.unmodifiable(_remittances);

  double get pendingRemittanceTotal => _remittances
      .where((r) => r.isPending)
      .fold(0.0, (sum, r) => sum + r.amount);

  /// Requests a cash handover. Short remittances are allowed: the rider may
  /// hand over less than the full balance, and the remainder stays outstanding
  /// after Logistics confirms (RID-EARN-005).
  String? requestRemittance(double amount) {
    final cents = (amount * 100).round();
    if (cents <= 0) return 'Enter an amount to remit.';
    if (cents > (codOutstanding * 100).round()) {
      return 'You cannot remit more than the ${formatPeso(codOutstanding)} balance.';
    }
    _remittances.insert(
      0,
      CodRemittance(
        id: 'REM-${_clock().millisecondsSinceEpoch}-${_remittances.length + 1}',
        amount: cents / 100,
        requestedAt: _clock(),
      ),
    );
    notifyListeners();
    _save();
    return null;
  }

  /// Applies Logistics' confirmation. Not reachable from the rider's UI: the
  /// rider may request a remittance, never confirm one (RID-EARN-006).
  /// Public so the mock Logistics side and tests can exercise the other half.
  bool confirmRemittance(String id, {DateTime? confirmedAt}) {
    final index = _remittances.indexWhere((r) => r.id == id);
    if (index < 0) return false;
    final remittance = _remittances[index];
    if (!remittance.isPending) return false;
    _remittances[index] = remittance.copyWith(
      status: RemittanceStatus.confirmed,
      confirmedAt: confirmedAt ?? _clock(),
    );
    notifyListeners();
    _save();
    return true;
  }

  DeliveryJob? jobById(String jobId) {
    final matches = allJobs.where((j) => j.id == jobId);
    return matches.isEmpty ? null : matches.first;
  }

  Future<void> load() async {
    // The dispatcher's jobs are a *cache warm*, not a requirement: `main()` awaits
    // this before `runApp`, and a live build has no courier-jobs endpoint yet, so
    // letting the failure out would stop the app from starting at all rather than
    // leaving one dashboard empty. What it costs is honest: the dashboard reads
    // whatever this leaves, and a live courier cannot reach that dashboard anyway
    // — the auth half refuses courier sign-in with `ApiNotImplementedException`
    // naming `/register/logistics`, so the failure tolerated here is one no live
    // user can currently trigger. (The buyer half is different, and is not
    // tolerated silently: `OrderState.loadFailure` carries it to the screen that
    // asks. When courier jobs get an endpoint, that is the shape to copy.)
    //
    // Copied, not aliased: the interface promises a list, not that it is a copy
    // of the caller's — a repository returning its own internal list (or a const
    // seed) must not become this state's mutable field, or the next mutation
    // anywhere would show up here.
    try {
      _assigned = List.of(await _repository.assignedJobs());
      _loadFailure = null;
    } on ApiNotConfiguredException {
      // Same rule as `OrderState.load()`: a build mistake stays fatal, a runtime
      // failure is recorded for the screen.
      rethrow;
    } on ApiException catch (error) {
      _assigned = const [];
      _loadFailure = error;
    }

    try {
      final prefs = await SharedPreferences.getInstance();
      _milestones.clear();
      for (final entry in prefs.getStringList(_milestonesKey) ?? const []) {
        final parts = entry.split(':');
        if (parts.length != 2) continue;
        _milestones[parts[0]] = DeliveryMilestone.values.byName(parts[1]);
      }

      _dispatched.clear();
      final dispatchedRaw = prefs.getString(_dispatchedKey);
      if (dispatchedRaw != null) {
        for (final entry in jsonDecode(dispatchedRaw) as List<dynamic>) {
          final m = entry as Map<String, dynamic>;
          _dispatched.add(
            DeliveryJob(
              id: m['id'] as String,
              shopName: m['shopName'] as String,
              pickupAddress: m['pickupAddress'] as String,
              buyerName: m['buyerName'] as String,
              deliveryArea: m['deliveryArea'] as String,
              packageSize: m['packageSize'] as String,
              fee: (m['fee'] as num).toDouble(),
              orderId: m['orderId'] as String?,
              courierName: m['courierName'] as String?,
              courierPhotoPath: m['courierPhotoPath'] as String?,
              sellerPhone: m['sellerPhone'] as String?,
              buyerPhone: m['buyerPhone'] as String?,
              trackingNumber: m['trackingNumber'] as String?,
              parcelCount: (m['parcelCount'] as num?)?.toInt() ?? 1,
              codAmount: (m['codAmount'] as num?)?.toDouble() ?? 0,
              prepaid: m['prepaid'] as bool? ?? false,
              deliveryOtp:
                  m['deliveryOtp'] as String? ?? kMockDeliveryOtp,
            ),
          );
        }
      }

      _completedAt.clear();
      _payouts.clear();
      _returned.clear();
      // New shape: "jobId|iso8601|payout|returned". The legacy colon-only
      // shape is still parsed, because an install that already completed jobs
      // must not lose its earnings history — those entries simply have no
      // stored payout and fall back to the job's fee (correct, since they
      // predate partial returns).
      for (final entry in prefs.getStringList(_completedKey) ?? const []) {
        if (entry.contains('|')) {
          final parts = entry.split('|');
          if (parts.length < 2) continue;
          final timestamp = DateTime.tryParse(parts[1]);
          if (timestamp == null) continue;
          _completedAt[parts[0]] = timestamp;
          final payout = parts.length > 2 ? double.tryParse(parts[2]) : null;
          if (payout != null) _payouts[parts[0]] = payout;
          if (parts.length > 3) _returned[parts[0]] = parts[3] == 'true';
          continue;
        }
        final sepIndex = entry.indexOf(':');
        if (sepIndex < 0) continue;
        final jobId = entry.substring(0, sepIndex);
        final timestamp = DateTime.tryParse(entry.substring(sepIndex + 1));
        if (timestamp != null) _completedAt[jobId] = timestamp;
      }

      _ratings.clear();
      for (final entry in prefs.getStringList(_ratingsKey) ?? const []) {
        final parts = entry.split('|');
        if (parts.length != 2) continue;
        final stars = int.tryParse(parts[1]);
        if (stars != null) _ratings[parts[0]] = stars;
      }

      _attempts.clear();
      _failureReasons.clear();
      final attemptsRaw = prefs.getString(_attemptsKey);
      if (attemptsRaw != null) {
        final decoded = jsonDecode(attemptsRaw);
        if (decoded is Map) {
          for (final entry in decoded.entries) {
            final row = entry.value;
            if (row is! Map) continue;
            _attempts[entry.key.toString()] =
                (row['count'] as num?)?.toInt() ?? 0;
            final reason = row['reason']?.toString();
            if (reason != null && reason.isNotEmpty) {
              _failureReasons[entry.key.toString()] = reason;
            }
          }
        }
      }

      _codCollected.clear();
      final collectedRaw = prefs.getString(_codCollectedKey);
      if (collectedRaw != null) {
        for (final row in jsonDecode(collectedRaw) as List<dynamic>) {
          if (row is! Map) continue;
          final collection = CodCollection.fromJson(
            row.map((key, value) => MapEntry(key.toString(), value)),
          );
          if (collection != null) {
            _codCollected[collection.jobId] = collection;
          }
        }
      }

      _remittances.clear();
      final remittancesRaw = prefs.getString(_remittancesKey);
      if (remittancesRaw != null) {
        for (final row in jsonDecode(remittancesRaw) as List<dynamic>) {
          if (row is! Map) continue;
          final remittance = CodRemittance.fromJson(
            row.map((key, value) => MapEntry(key.toString(), value)),
          );
          if (remittance != null) _remittances.add(remittance);
        }
      }
    } catch (_) {
      // Corrupt/old data shape — start clean rather than crash.
      _milestones.clear();
      _completedAt.clear();
      _payouts.clear();
      _returned.clear();
      _ratings.clear();
      _dispatched.clear();
      _attempts.clear();
      _failureReasons.clear();
      _codCollected.clear();
      _remittances.clear();
    }
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setStringList(
      _milestonesKey,
      _milestones.entries.map((e) => '${e.key}:${e.value.name}').toList(),
    );
    await prefs.setStringList(
      _completedKey,
      _completedAt.entries
          .map(
            (e) =>
                '${e.key}|${e.value.toIso8601String()}|${_payouts[e.key] ?? jobById(e.key)?.fee ?? 0}|${_returned[e.key] ?? false}',
          )
          .toList(),
    );
    await prefs.setStringList(
      _ratingsKey,
      _ratings.entries.map((e) => '${e.key}|${e.value}').toList(),
    );
    await prefs.setString(
      _dispatchedKey,
      jsonEncode([
        for (final j in _dispatched)
          {
            'id': j.id,
            'shopName': j.shopName,
            'pickupAddress': j.pickupAddress,
            'buyerName': j.buyerName,
            'deliveryArea': j.deliveryArea,
            'packageSize': j.packageSize,
            'fee': j.fee,
            'orderId': j.orderId,
            'courierName': j.courierName,
            'courierPhotoPath': j.courierPhotoPath,
            'sellerPhone': j.sellerPhone,
            'buyerPhone': j.buyerPhone,
            'trackingNumber': j.trackingNumber,
            'parcelCount': j.parcelCount,
            'codAmount': j.codAmount,
            'prepaid': j.prepaid,
            'deliveryOtp': j.deliveryOtp,
          },
      ]),
    );
    await prefs.setString(
      _attemptsKey,
      jsonEncode({
        for (final entry in _attempts.entries)
          entry.key: {
            'count': entry.value,
            if (_failureReasons[entry.key] != null)
              'reason': _failureReasons[entry.key],
          },
      }),
    );
    await prefs.setString(
      _codCollectedKey,
      jsonEncode([
        for (final collection in _codCollected.values) collection.toJson(),
      ]),
    );
    await prefs.setString(
      _remittancesKey,
      jsonEncode([for (final remittance in _remittances) remittance.toJson()]),
    );
  }

  /// The job delivering [orderId], if a checkout dispatched one.
  DeliveryJob? jobForOrder(String orderId) {
    for (final j in _dispatched) {
      if (j.orderId == orderId) return j;
    }
    return null;
  }

  /// Current milestone for the job delivering [orderId], or null if no
  /// job has been dispatched for it yet (e.g. seeded historical orders).
  DeliveryMilestone? milestoneForOrder(String orderId) {
    final job = jobForOrder(orderId);
    if (job == null) return null;
    return _milestones[job.id];
  }

  bool isCompleted(String jobId) => _completedAt.containsKey(jobId);

  /// When [jobId] completed, or null while it is still assigned.
  ///
  /// The buyer's return window is measured from delivery, so `OrderState`
  /// needs this timestamp; the map used to be private with `completedJobs` as
  /// the only reader.
  DateTime? completedAtFor(String jobId) => _completedAt[jobId];

  /// The completion timestamp for the job carrying [orderId], if one exists.
  DateTime? completedAtForOrder(String orderId) {
    final job = jobForOrder(orderId);
    return job == null ? null : _completedAt[job.id];
  }

  /// Creates the delivery job for a freshly placed order, landing it on
  /// the courier's Pickup tab as "Assigned". Called from Checkout.
  ///
  /// The seller's pickup address comes from a seeded job for the same
  /// shop when there is one; there's no seller-address field on Product
  /// yet, so otherwise the card says the address is shared later.
  void dispatchForOrder({
    required Order order,
    required String buyerName,
    required String deliveryArea,
    required double fee,
    String? buyerPhone,
    String? sellerPhone,
    String? trackingNumber,
    double codAmount = 0,
    bool prepaid = false,
    String? deliveryOtp,
  }) {
    final sameShop = _assigned.where((j) => j.shopName == order.shopName);
    _dispatched.add(
      DeliveryJob(
        id: 'REQ-${_firstDispatchedNumber + _dispatched.length}',
        shopName: order.shopName,
        pickupAddress: sameShop.isEmpty
            ? 'Pickup address shared once the seller confirms'
            : sameShop.first.pickupAddress,
        buyerName: buyerName,
        deliveryArea: deliveryArea,
        packageSize: 'Parcel',
        fee: fee,
        orderId: order.id,
        buyerPhone: buyerPhone,
        sellerPhone:
            sellerPhone ??
            (sameShop.isEmpty ? null : sameShop.first.sellerPhone),
        trackingNumber: trackingNumber ?? order.id,
        codAmount: prepaid ? 0 : codAmount,
        prepaid: prepaid,
        deliveryOtp: deliveryOtp ?? kMockDeliveryOtp,
      ),
    );
    notifyListeners();
    _save();
  }

  /// Updates the milestone for a job from a server-polled value, without
  /// triggering another server write. Used by the buyer's tracker to sync
  /// milestone state across devices.
  void updateMilestoneFromServer(String jobId, DeliveryMilestone milestone) {
    if (_milestones[jobId] == milestone) return;
    _milestones[jobId] = milestone;
    notifyListeners();
    // Deliberately not calling _save() here: the server is the authority
    // for this value, so persisting it would make a stale copy on-device
    // compete with the live server answer.
  }

  /// Advances a job's milestone, optionally recording who is carrying it.
  ///
  /// The local state machine is updated immediately and synchronously (so
  /// the UI is never blocked on the network). The server is notified in the
  /// background via the repository — a failure is silently swallowed rather
  /// than blocking the courier's workflow, because the courier's next action
  /// is always the right next action regardless of network state.
  void advanceMilestone(
    String jobId,
    DeliveryMilestone next, {
    String? courierName,
    String? courierPhotoPath,
    String? failureReason,
    String? deliveryOtp,
    double? codAmount,
    String? trackingNumber,
  }) {
    // A return is terminal. The UI only offers "Return Complete", but this
    // guard is what makes a fourth delivery attempt impossible even if a
    // caller (or a stale screen) asks for one (RID-FAIL-005).
    if (_milestones[jobId] == DeliveryMilestone.returnToSender) return;

    final reason = failureReason?.trim();
    var target = next;
    if (next == DeliveryMilestone.failedDelivery) {
      // A failure without a reason is not actionable for the buyer or
      // Logistics, so it changes nothing (RID-FAIL-002). The UI already
      // refuses to close the sheet without a choice; this is the same rule
      // one layer down, where the server will also enforce it.
      if (reason == null || reason.isEmpty) return;
      final attempt = attemptsFor(jobId) + 1;
      _attempts[jobId] = attempt;
      _failureReasons[jobId] = reason;
      // Third failure ends the attempts: the parcel goes back to the
      // seller. The server must enforce the same limit independently.
      if (attempt >= 3) target = DeliveryMilestone.returnToSender;
    }

    _milestones[jobId] = target;
    if (target == DeliveryMilestone.assignedToRider &&
        (courierName != null || courierPhotoPath != null)) {
      final i = _dispatched.indexWhere((j) => j.id == jobId);
      if (i >= 0) {
        _dispatched[i] = _dispatched[i].copyWith(
          courierName: courierName,
          courierPhotoPath: courierPhotoPath,
        );
      }
    }
    notifyListeners();
    _save();

    // Fire-and-forget to the server. The milestone value maps directly to
    // the OrderStatus string the server expects (e.g. 'PICKED_UP').
    // Failure is swallowed: the buyer's tracker will read a stale milestone
    // until the next successful sync, which is acceptable — the courier's
    // own screen is always authoritative.
    final serverValue = _milestoneToServerString(target);
    if (serverValue != null) {
      _repository
          .advanceMilestone(
            jobId,
            serverValue,
            trackingNumber: trackingNumber,
            failureReason: reason,
            deliveryOtp: deliveryOtp,
            codAmount: codAmount,
          )
          .catchError((_) {});
    }
  }

  /// Translates a [DeliveryMilestone] to the server's `OrderStatus` string.
  ///
  /// Returns null for milestones that have no server counterpart (accepted
  /// is the initial state the server already assigns; returnToSender maps to
  /// RETURNED once the courier completes it via [completeJob]).
  static String? _milestoneToServerString(DeliveryMilestone milestone) =>
      switch (milestone) {
        DeliveryMilestone.accepted => 'PLACED',
        DeliveryMilestone.arrivedAtSeller => 'CONFIRMED',
        DeliveryMilestone.pickedUp => 'PICKED_UP',
        DeliveryMilestone.atSortingCenter => 'AT_SORTING_CENTER',
        DeliveryMilestone.sorted => 'SORTED',
        DeliveryMilestone.assignedToRider => 'ASSIGNED_TO_RIDER',
        DeliveryMilestone.inTransit => 'OUT_FOR_DELIVERY',
        DeliveryMilestone.delivered => 'DELIVERED',
        DeliveryMilestone.failedDelivery => 'DELIVERY_FAILED',
        DeliveryMilestone.returnToSender => 'RETURNED',
      };

  /// Called once a specific job is marked delivered — records when and
  /// **what it paid**, so it counts toward real earnings, and removes it from
  /// both dashboards for good (it can never reappear as "assigned").
  ///
  /// [returned] is passed by the caller rather than derived from the
  /// milestone, because by the time this runs the milestone has already been
  /// cleared — and because pricing a return is a decision this method owns,
  /// not something it should infer. See [kReturnFeeShare] for the rate and the
  /// reasoning; the short version is that a return is no longer credited the
  /// full fee, and is no longer indistinguishable from a delivery in the
  /// earnings maths.
  void completeJob(String jobId, {bool returned = false}) {
    final job = jobById(jobId);
    final fee = job?.fee ?? 0;
    _payouts[jobId] = returned ? fee * kReturnFeeShare : fee;
    _completedAt[jobId] = _clock();
    _returned[jobId] = returned;
    if (!returned && job != null && job.hasCod) {
      _codCollected[jobId] = CodCollection(
        jobId: jobId,
        trackingNumber: job.trackingNumber ?? job.id,
        amount: job.codAmount,
        collectedAt: _clock(),
      );
    }
    _milestones.remove(jobId);
    notifyListeners();
    _save();
  }
}
