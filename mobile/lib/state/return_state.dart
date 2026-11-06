// lib/state/return_state.dart
//
// The buyer's return/refund requests, and the rules that decide whether one
// can be made.
//
// ## Why this is separate from `OrderState`
//
// A return is not another order status. The parcel was delivered and the order
// remains delivered; what changes is that a *request* about it is now in
// flight. `OrderState` already has one flag beside its status for exactly this
// kind of second fact (`buyerConfirmed`), but a return has its own lifecycle,
// seller reason, evidence and escalation, so it gets its own model and store.
//
// The two meet in two places: `OrderState` refuses "Order Received" while a
// request is open, and the order screens overlay the request's status on the
// order badge. Both read this object through `OrderState.returns`, which keeps
// the provider graph unchanged and makes a test able to hand an `OrderState`
// one return repository.
//
// ## What is local, and what is not
//
// The mock repository persists this device's requests in
// `shared_preferences`, exactly like reviews and buyer order flags. The live
// repository names the missing endpoints instead of guessing them. There is no
// seller dashboard in this app, so a seller rejection and the seller's reason
// cannot be *produced* here; the buyer-side rendering and escalation are
// implemented and tested with a repository that supplies that state.

import 'package:flutter/foundation.dart';

import '../data/api/api_exception.dart';
import '../data/mock_data.dart' show Order, OrderStatus;
import '../data/repositories/return_repository.dart';

/// The workbook's return evidence limits. Kept here, beside the model, so the
/// form, the repository and the tests cannot drift apart.
const int returnPhotoLimit = 5;
const int returnDescriptionMinLength = 10;

/// The buyer's window, counted from the delivery timestamp.
const int returnWindowDays = 7;

enum ReturnStatus {
  /// The request has been submitted and is waiting for the seller.
  submitted,

  /// The seller refused it. [ReturnRequest.sellerReason] carries their words.
  rejected,

  /// The buyer escalated the rejection for another review.
  escalated,

  /// The money has been returned.
  refunded,
}

extension ReturnStatusCopy on ReturnStatus {
  /// What the order badge says. "Return/Refund" is the status the workbook
  /// expects while the request is open, not a fifth `OrderStatus`.
  String get orderLabel => switch (this) {
        ReturnStatus.submitted => 'Return/Refund',
        ReturnStatus.rejected => 'Rejected',
        ReturnStatus.escalated => 'Escalated',
        ReturnStatus.refunded => 'Refunded',
      };

  /// Still awaiting a decision, so the order must not be completed.
  bool get isOpen =>
      this == ReturnStatus.submitted || this == ReturnStatus.escalated;
}

/// One return request, for one order. The order id is the identity: this app
/// has one request per purchase, and a rejected request is escalated rather
/// than replaced by a second one.
class ReturnRequest {
  const ReturnRequest({
    required this.id,
    required this.orderId,
    required this.description,
    required this.photoPaths,
    required this.submittedAt,
    this.status = ReturnStatus.submitted,
    this.sellerReason,
    this.escalatedAt,
  });

  final String id;
  final String orderId;

  /// The buyer's own account of what is wrong. Trimmed before storage; at
  /// least [returnDescriptionMinLength] characters.
  final String description;

  /// Local file paths, in the order the buyer attached them. The store keeps
  /// the list as-is: reordering it here would make the buyer's chosen order a
  /// presentation detail instead of part of the evidence.
  final List<String> photoPaths;

  final DateTime submittedAt;
  final ReturnStatus status;

  /// The seller's exact reason for a rejection. Null for every other status;
  /// the UI shows "No reason provided" rather than inventing one.
  final String? sellerReason;

  final DateTime? escalatedAt;

  bool get isOpen => status.isOpen;

  /// The badge text for the order detail and order cards.
  String get orderLabel => status.orderLabel;

  ReturnRequest copyWith({
    ReturnStatus? status,
    List<String>? photoPaths,
    String? sellerReason,
    DateTime? escalatedAt,
  }) =>
      ReturnRequest(
        id: id,
        orderId: orderId,
        description: description,
        photoPaths: photoPaths ?? this.photoPaths,
        submittedAt: submittedAt,
        status: status ?? this.status,
        sellerReason: sellerReason ?? this.sellerReason,
        escalatedAt: escalatedAt ?? this.escalatedAt,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'orderId': orderId,
        'description': description,
        'photoPaths': photoPaths,
        'submittedAt': submittedAt.toIso8601String(),
        'status': status.name,
        'sellerReason': sellerReason,
        'escalatedAt': escalatedAt?.toIso8601String(),
      };

  /// Returns null for an unreadable entry instead of throwing: one corrupt
  /// request must not take the whole return history with it.
  static ReturnRequest? fromJson(Map<String, dynamic> map) {
    final id = map['id'];
    final orderId = map['orderId'];
    final description = map['description'];
    final submittedAt = DateTime.tryParse(map['submittedAt'] as String? ?? '');
    if (id is! String ||
        id.isEmpty ||
        orderId is! String ||
        orderId.isEmpty ||
        description is! String ||
        submittedAt == null) {
      return null;
    }

    final statusName = map['status'];
    ReturnStatus? status;
    if (statusName is String) {
      for (final value in ReturnStatus.values) {
        if (value.name == statusName) {
          status = value;
          break;
        }
      }
    }

    final rawPhotos = map['photoPaths'];
    final photoPaths = rawPhotos is List
        ? rawPhotos.whereType<String>().toList(growable: false)
        : const <String>[];

    return ReturnRequest(
      id: id,
      orderId: orderId,
      description: description,
      photoPaths: photoPaths,
      submittedAt: submittedAt,
      status: status ?? ReturnStatus.submitted,
      sellerReason: map['sellerReason'] as String?,
      escalatedAt: DateTime.tryParse(map['escalatedAt'] as String? ?? ''),
    );
  }
}

class ReturnState extends ChangeNotifier {
  /// [repository] defaults to the mock implementation so existing call sites
  /// and tests keep working; `main.dart` passes the resolved bundle's.
  ReturnState({ReturnRepository? repository})
      : _repository = repository ?? MockReturnRepository();

  final ReturnRepository _repository;

  /// Newest first. Filled by [load] and refreshed after every write.
  List<ReturnRequest> _requests = const [];

  List<ReturnRequest> get all => List.unmodifiable(_requests);

  /// The latest request for an order, or null. A completed order can have an
  /// old request, so callers that only care about active work use
  /// [openRequestFor].
  ReturnRequest? latestForOrder(String orderId) {
    ReturnRequest? latest;
    for (final request in _requests) {
      if (request.orderId != orderId) continue;
      if (latest == null || request.submittedAt.isAfter(latest.submittedAt)) {
        latest = request;
      }
    }
    return latest;
  }

  /// A request that still blocks completion, if one exists.
  ReturnRequest? openRequestFor(String orderId) {
    final latest = latestForOrder(orderId);
    return latest != null && latest.isOpen ? latest : null;
  }

  ApiException? _loadFailure;

  /// Why no requests could be read, when the reason is a failed request rather
  /// than an empty history. In live mode the returns endpoint does not exist
  /// yet, so this is how the screen can explain the absence.
  ApiException? get loadFailure => _loadFailure;

  Future<void> load() async {
    await _repository.load();
    try {
      _requests = await _repository.all();
      _loadFailure = null;
    } on ApiNotConfiguredException {
      rethrow;
    } on ApiException catch (error) {
      _requests = const [];
      _loadFailure = error;
    }
  }

  /// Why this order cannot be returned, or null when it can.
  ///
  /// [deliveredAt] is passed by the screen because a freshly delivered order's
  /// timestamp may live in `CourierState`, not on the order row yet. [now] is
  /// injectable so the day-7 boundary is testable without changing the device
  /// clock.
  String? returnRefusal(
    Order order, {
    DateTime? deliveredAt,
    DateTime? now,
  }) {
    if (order.cancelled) return 'This order was cancelled.';
    if (order.status != OrderStatus.toRate) {
      return 'Returns are available only after delivery.';
    }
    if (order.buyerConfirmed) return 'This order has already been completed.';
    if (latestForOrder(order.id) != null) {
      return 'A return request already exists for this order.';
    }

    final delivered = deliveredAt ?? order.deliveredAt;
    if (delivered != null) {
      final deadline = delivered.add(const Duration(days: returnWindowDays));
      if ((now ?? DateTime.now()).isAfter(deadline)) {
        return 'The 7-day return window has closed.';
      }
    }
    return null;
  }

  /// Submits a request. Returns null on success, or the sentence to show when
  /// the action is refused.
  ///
  /// Every rule is checked at this write seam, not only in the form: a hidden
  /// button is not a rule, and another screen calling `submit` must be refused
  /// too.
  Future<String?> submit({
    required Order order,
    required String description,
    required List<String> photoPaths,
    DateTime? deliveredAt,
    DateTime? now,
  }) async {
    if (_submitting.contains(order.id)) {
      return 'This return request is already being submitted.';
    }

    final refusal = returnRefusal(order, deliveredAt: deliveredAt, now: now);
    if (refusal != null) return refusal;

    final text = description.trim();
    if (text.length < returnDescriptionMinLength) {
      return 'Describe the problem in at least 10 characters.';
    }

    final photos = [
      for (final path in photoPaths)
        if (path.trim().isNotEmpty) path,
    ];
    if (photos.isEmpty) {
      return 'Attach at least 1 photo of the item.';
    }
    if (photos.length > returnPhotoLimit) {
      return 'Attach no more than 5 photos.';
    }

    _submitting.add(order.id);
    try {
      await _repository.submit(
        orderId: order.id,
        description: text,
        photoPaths: List.unmodifiable(photos),
        submittedAt: now ?? DateTime.now(),
      );
      _requests = await _repository.all();
      _loadFailure = null;
      notifyListeners();
      return null;
    } on ApiNotConfiguredException {
      rethrow;
    } finally {
      _submitting.remove(order.id);
    }
  }

  final Set<String> _submitting = {};

  /// Whether a rejected request can still be escalated. Escalation is the
  /// second decision, not a resubmission: a request already escalated has no
  /// second escalation button.
  bool canEscalate(ReturnRequest request) =>
      request.status == ReturnStatus.rejected;

  /// Sends a rejected request for another review. Returns null on success, or
  /// the sentence to show when the action is refused.
  Future<String?> escalate(ReturnRequest request, {DateTime? now}) async {
    final latest = latestForOrder(request.orderId);
    if (latest == null) return 'No return request was found for this order.';
    if (latest.status != ReturnStatus.rejected) {
      return 'Only a rejected return can be escalated.';
    }

    await _repository.escalate(latest.id, now ?? DateTime.now());
    _requests = await _repository.all();
    _loadFailure = null;
    notifyListeners();
    return null;
  }
}
