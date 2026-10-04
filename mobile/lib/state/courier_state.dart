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
import '../data/mock_data.dart' show DeliveryJob, Order;

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
  const CompletedJob(this.job, this.completedAt, this.payout);

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

class CourierState extends ChangeNotifier {
  /// [repository] defaults to the mock implementation so existing call sites
  /// (and tests) keep working unchanged; `main.dart` passes the resolved
  /// bundle's, which is what makes the live switch possible.
  ///
  /// Only the *jobs* come from here — see courier_repository.dart for why the
  /// milestone machine, the payouts and the ratings deliberately stay local.
  CourierState({CourierRepository? repository})
      : _repository = repository ?? const MockCourierRepository();

  final CourierRepository _repository;

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

  static const _milestonesKey = 'courier_milestones'; // "jobId:milestoneName" per entry
  // "jobId|iso8601|payout" per entry. Deliberately pipe-delimited rather than
  // colon-delimited: an ISO timestamp contains colons of its own, and the
  // first version of this parsed with indexOf(':')/lastIndexOf(':'), which
  // would read an ISO timestamp's trailing seconds ("…T10:04:02.123" →
  // "02.123") as a payout. The pipe can't appear in either field.
  static const _completedKey = 'courier_completed';
  static const _ratingsKey = 'courier_ratings'; // "jobId|stars" per entry
  static const _dispatchedKey = 'courier_dispatched'; // JSON list of dispatched jobs

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
      .map((j) => AssignedJob(j, _milestones[j.id] ?? DeliveryMilestone.accepted))
      .toList();

  /// "Items for Pickup" — assigned but not yet at the sorting center.
  List<AssignedJob> get jobsForPickup => assignedJobs
      .where((a) =>
          a.milestone == DeliveryMilestone.accepted ||
          a.milestone == DeliveryMilestone.arrivedAtSeller ||
          a.milestone == DeliveryMilestone.pickedUp ||
          a.milestone == DeliveryMilestone.atSortingCenter ||
          a.milestone == DeliveryMilestone.sorted)
      .toList();

  /// "Items for Delivery" — sorted/assigned to rider, en route or failed.
  List<AssignedJob> get jobsForDelivery => assignedJobs
      .where((a) =>
          a.milestone == DeliveryMilestone.assignedToRider ||
          a.milestone == DeliveryMilestone.inTransit ||
          a.milestone == DeliveryMilestone.failedDelivery ||
          a.milestone == DeliveryMilestone.returnToSender)
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
        result.add(CompletedJob(job, completedAt, _payouts[job.id] ?? job.fee));
      }
    }
    result.sort((a, b) => b.completedAt.compareTo(a.completedAt));
    return result;
  }

  double get todayEarnings {
    final now = DateTime.now();
    return completedJobs
        .where((c) => c.completedAt.year == now.year && c.completedAt.month == now.month && c.completedAt.day == now.day)
        .fold(0.0, (sum, c) => sum + c.payout);
  }

  double get weekEarnings {
    final weekAgo = DateTime.now().subtract(const Duration(days: 7));
    return completedJobs.where((c) => c.completedAt.isAfter(weekAgo)).fold(0.0, (sum, c) => sum + c.payout);
  }

  double get totalEarnings => completedJobs.fold(0.0, (sum, c) => sum + c.payout);

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

  DeliveryMilestone milestoneFor(String jobId) => _milestones[jobId] ?? DeliveryMilestone.accepted;

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
          _dispatched.add(DeliveryJob(
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
          ));
        }
      }

      _completedAt.clear();
      _payouts.clear();
      // New shape: "jobId|iso8601|payout". The legacy colon-only shape is
      // still parsed, because an install that already completed jobs must not
      // lose its earnings history — those entries simply have no stored payout
      // and fall back to the job's fee (correct, since they predate partial
      // returns).
      for (final entry in prefs.getStringList(_completedKey) ?? const []) {
        if (entry.contains('|')) {
          final parts = entry.split('|');
          if (parts.length < 2) continue;
          final timestamp = DateTime.tryParse(parts[1]);
          if (timestamp == null) continue;
          _completedAt[parts[0]] = timestamp;
          final payout = parts.length > 2 ? double.tryParse(parts[2]) : null;
          if (payout != null) _payouts[parts[0]] = payout;
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
    } catch (_) {
      // Corrupt/old data shape — start clean rather than crash.
      _milestones.clear();
      _completedAt.clear();
      _payouts.clear();
      _ratings.clear();
      _dispatched.clear();
    }
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setStringList(_milestonesKey, _milestones.entries.map((e) => '${e.key}:${e.value.name}').toList());
    await prefs.setStringList(_completedKey, _completedAt.entries
        .map((e) => '${e.key}|${e.value.toIso8601String()}|${_payouts[e.key] ?? jobById(e.key)?.fee ?? 0}')
        .toList());
    await prefs.setStringList(_ratingsKey, _ratings.entries.map((e) => '${e.key}|${e.value}').toList());
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
          },
      ]),
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
  }) {
    final sameShop = _assigned.where((j) => j.shopName == order.shopName);
    _dispatched.add(DeliveryJob(
      id: 'REQ-${_firstDispatchedNumber + _dispatched.length}',
      shopName: order.shopName,
      pickupAddress: sameShop.isEmpty ? 'Pickup address shared once the seller confirms' : sameShop.first.pickupAddress,
      buyerName: buyerName,
      deliveryArea: deliveryArea,
      packageSize: 'Parcel',
      fee: fee,
      orderId: order.id,
    ));
    notifyListeners();
    _save();
  }

  /// Advances a job's milestone, optionally recording who is carrying it.
  ///
  /// [courierName] matters at [DeliveryMilestone.assignedToRider] — the
  /// dispatch step, per the ERP spec — because that is the first moment a
  /// buyer can be told who has their parcel. Earlier steps pass null: on
  /// the pickup leg the rider who collected it is not the rider who
  /// delivers it, so naming one would be wrong rather than early.
  ///
  /// Only checkout-dispatched jobs are re-assignable; the repository's
  /// assigned jobs are shared samples this device does not own.
  void advanceMilestone(
    String jobId,
    DeliveryMilestone next, {
    String? courierName,
    String? courierPhotoPath,
  }) {
    _milestones[jobId] = next;
    // The guard lives here rather than at the call site so the invariant
    // can't be violated by whoever calls this next: identity is only ever
    // recorded at dispatch. On any other step the rider being named isn't
    // the one who delivers the parcel, so recording it would be wrong
    // rather than merely early.
    if (next == DeliveryMilestone.assignedToRider &&
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
  }

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
    _completedAt[jobId] = DateTime.now();
    _milestones.remove(jobId);
    notifyListeners();
    _save();
  }
}
