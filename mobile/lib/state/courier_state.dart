// lib/state/courier_state.dart
//
// Per the updated ERP-Components spec, delivery jobs are ASSIGNED to a
// courier by the Logistics/Sorting Center's dispatcher (see that
// role's "Delivery assignment (per area and per rider)") — couriers no
// longer browse an open pool and accept first-come-first-served. This
// mock has no multi-courier simulation, so every mockDeliveryJobs
// entry not yet completed is treated as "assigned to me."
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
// Jobs come from two places: the seeded `mockDeliveryJobs`, and jobs
// dispatched by real checkouts (`dispatchForOrder`) — each carries the
// `orderId` it delivers, which is how the buyer's Orders screen follows
// the courier's progress (see OrderState.resolvedOrders).
//
// Persisted via shared_preferences, so nothing resets on an app restart.

import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../data/mock_data.dart';

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
  const CompletedJob(this.job, this.completedAt);
  final DeliveryJob job;
  final DateTime completedAt;
}

class CourierState extends ChangeNotifier {
  final Map<String, DeliveryMilestone> _milestones = {};
  final Map<String, DateTime> _completedAt = {};

  /// Jobs created by real checkouts, oldest first.
  final List<DeliveryJob> _dispatched = [];

  static const _milestonesKey = 'courier_milestones'; // "jobId:milestoneName" per entry
  static const _completedKey = 'courier_completed'; // "jobId:isoTimestamp" per entry
  static const _dispatchedKey = 'courier_dispatched'; // JSON list of dispatched jobs

  /// Seeded sample jobs use REQ-55xx numbers; dispatched ones start well
  /// clear of them so an id never collides.
  static const int _firstDispatchedNumber = 5600;

  /// Every job this courier could see: seeded samples, then dispatched.
  List<DeliveryJob> get allJobs => [...mockDeliveryJobs, ..._dispatched];

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
      if (completedAt != null) result.add(CompletedJob(job, completedAt));
    }
    result.sort((a, b) => b.completedAt.compareTo(a.completedAt));
    return result;
  }

  double get todayEarnings {
    final now = DateTime.now();
    return completedJobs
        .where((c) => c.completedAt.year == now.year && c.completedAt.month == now.month && c.completedAt.day == now.day)
        .fold(0.0, (sum, c) => sum + c.job.fee);
  }

  double get weekEarnings {
    final weekAgo = DateTime.now().subtract(const Duration(days: 7));
    return completedJobs.where((c) => c.completedAt.isAfter(weekAgo)).fold(0.0, (sum, c) => sum + c.job.fee);
  }

  double get totalEarnings => completedJobs.fold(0.0, (sum, c) => sum + c.job.fee);

  DeliveryMilestone milestoneFor(String jobId) => _milestones[jobId] ?? DeliveryMilestone.accepted;

  DeliveryJob? jobById(String jobId) {
    final matches = allJobs.where((j) => j.id == jobId);
    return matches.isEmpty ? null : matches.first;
  }

  Future<void> load() async {
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
          ));
        }
      }

      _completedAt.clear();
      for (final entry in prefs.getStringList(_completedKey) ?? const []) {
        final sepIndex = entry.indexOf(':');
        if (sepIndex < 0) continue;
        final jobId = entry.substring(0, sepIndex);
        final timestamp = DateTime.tryParse(entry.substring(sepIndex + 1));
        if (timestamp != null) _completedAt[jobId] = timestamp;
      }
    } catch (_) {
      // Corrupt/old data shape — start clean rather than crash.
      _milestones.clear();
      _completedAt.clear();
      _dispatched.clear();
    }
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setStringList(_milestonesKey, _milestones.entries.map((e) => '${e.key}:${e.value.name}').toList());
    await prefs.setStringList(_completedKey, _completedAt.entries.map((e) => '${e.key}:${e.value.toIso8601String()}').toList());
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
    final sameShop = mockDeliveryJobs.where((j) => j.shopName == order.shopName);
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

  void advanceMilestone(String jobId, DeliveryMilestone next) {
    _milestones[jobId] = next;
    notifyListeners();
    _save();
  }

  /// Called once a specific job is marked delivered — records when, so
  /// it counts toward real earnings, and removes it from both
  /// dashboards for good (it can never reappear as "assigned").
  void completeJob(String jobId) {
    _completedAt[jobId] = DateTime.now();
    _milestones.remove(jobId);
    notifyListeners();
    _save();
  }
}
