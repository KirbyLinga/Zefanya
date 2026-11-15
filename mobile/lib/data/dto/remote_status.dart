// lib/data/dto/remote_status.dart
//
// The 13 server order statuses, and the two app enums they have to become.
//
// ## Why this is one file and not three
//
// The server (Laravel, `OrderStatus` enum) has a 13-state pipeline. This app
// has two coarser views of it, and both are deliberate simplifications rather
// than staleness:
//
//   * the **buyer's** 4-state `OrderStatus` (toShip / inTransit /
//     outForDelivery / toRate). A buyer does not need to distinguish
//     `AT_SORTING_CENTER` from `SORTED`; showing them that is showing them
//     the warehouse's org chart. The 4 states it does show answer the buyer's
//     only two questions — has it moved, and is it here.
//   * the **courier's** 10-state `DeliveryMilestone`, which is the opposite:
//     granular on purpose, because the courier's screen *is* the process, and
//     the two dashboards split on it (pickup leg vs delivery leg).
//
// Three separate translations would drift — one of them would learn about a
// new server state and the other two wouldn't, which is exactly the failure
// this file exists to prevent. A single mapping also makes the *gaps* between
// the two pipelines visible, which is how the `arrivedAtSeller` hole below
// was found rather than assumed.
//
// ## Layering note, stated rather than hidden
//
// This file imports two app-layer enums (`OrderStatus` from data/mock_data and
// `DeliveryMilestone` from state/courier_state), which is technically an
// inward dependency from a DTO. That is the honest shape here: a translation
// layer has to know both sides, and the alternative — relocating either enum —
// would touch every file that names one, in a pass whose whole constraint is
// "behave identically in mock mode". If the enums ever move to a shared
// `domain/` package this file simply changes its imports.

import '../../state/auth_state.dart';
import '../../state/courier_state.dart';
import '../mock_data.dart';

/// Every order status the Laravel side can send, plus [unknown].
///
/// [unknown] is not padding. It is the entire reason parsing is a method here
/// instead of `values.byName(...)`: `byName` *throws* on an unrecognised
/// string, and the server list is explicitly allowed to grow (it has 13
/// states today and there is no reason to think that's final). A new state
/// must not be able to crash the app on every screen that loads an order.
/// Unknown resolves to the least-alarming state of each mapping instead.
enum RemoteOrderStatus {
  // Placed -> the seller's own pipeline, before any courier is involved.
  placed,
  confirmed,
  preparing,
  readyForPickup,

  // Pickup leg.
  pickedUp,
  atSortingCenter,

  // Sorting centre.
  sorted,
  assignedToRider,

  // Delivery leg.
  outForDelivery,
  delivered,
  completed,

  // Failure paths.
  deliveryFailed,
  returned,

  /// A status this build doesn't know. See the enum doc.
  unknown;

  /// The wire string for this status — the exact spelling the API sends.
  ///
  /// Explicit rather than derived from the Dart name, so renaming a Dart
  /// member can't silently change what goes over the wire.
  String get wire => switch (this) {
        RemoteOrderStatus.placed => 'PLACED',
        RemoteOrderStatus.confirmed => 'CONFIRMED',
        RemoteOrderStatus.preparing => 'PREPARING',
        RemoteOrderStatus.readyForPickup => 'READY_FOR_PICKUP',
        RemoteOrderStatus.pickedUp => 'PICKED_UP',
        RemoteOrderStatus.atSortingCenter => 'AT_SORTING_CENTER',
        RemoteOrderStatus.sorted => 'SORTED',
        RemoteOrderStatus.assignedToRider => 'ASSIGNED_TO_RIDER',
        RemoteOrderStatus.outForDelivery => 'OUT_FOR_DELIVERY',
        RemoteOrderStatus.delivered => 'DELIVERED',
        RemoteOrderStatus.completed => 'COMPLETED',
        RemoteOrderStatus.deliveryFailed => 'DELIVERY_FAILED',
        RemoteOrderStatus.returned => 'RETURNED',
        RemoteOrderStatus.unknown => 'UNKNOWN',
      };

  /// The exact set of strings sent by this build, for the contract doc and
  /// for tests. Deliberately excludes [unknown], which is never sent.
  static const Set<String> allWireValues = {
    'PLACED',
    'CONFIRMED',
    'PREPARING',
    'READY_FOR_PICKUP',
    'PICKED_UP',
    'AT_SORTING_CENTER',
    'SORTED',
    'ASSIGNED_TO_RIDER',
    'OUT_FOR_DELIVERY',
    'DELIVERED',
    'COMPLETED',
    'DELIVERY_FAILED',
    'RETURNED',
  };

  /// Parses a wire status. **Never throws** — unrecognised or missing input
  /// becomes [unknown].
  ///
  /// Tolerant of the shapes that arrive in practice rather than requiring one
  /// canonical one: Laravel serialises a backed enum as its scalar value, but
  /// some serialisers emit the *case name* (`deliveryFailed`) or wrap it with
  /// stray whitespace. Normalising to SHOUTY_SNAKE before lookup costs nothing
  /// and turns three failure modes into one success.
  static RemoteOrderStatus parse(Object? raw) {
    if (raw is! String) return RemoteOrderStatus.unknown;
    // Order matters here, and it was wrong the first time: the case-splitting
    // regex needs the *original* capitalisation, so it has to run before the
    // string is uppercased. Uppercasing first turns `deliveryFailed` into
    // `DELIVERYFAILED`, which then matches nothing at all — and the failure is
    // silent, because an unmatched status fails safe to `unknown`.
    final hyphenless = raw.trim().replaceAll('-', ' ');
    final snake = hyphenless
        .replaceAll(' ', '_')
        .replaceAllMapped(
          // Insert `_` at a lower-or-digit -> upper boundary, and before the
          // last capital of an acronym run that is followed by lowercase
          // (`AtSortingCenter` -> `At_Sorting_Center`).
          RegExp(r'(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])'),
          (_) => '_',
        )
        .toUpperCase();
    for (final status in RemoteOrderStatus.values) {
      if (status == RemoteOrderStatus.unknown) continue;
      if (status.wire == snake) return status;
    }
    return RemoteOrderStatus.unknown;
  }

  /// Whether this status means the parcel is not going to the buyer.
  ///
  /// Two members, not one, and they are not synonyms: `deliveryFailed` is a
  /// *moment* (an attempt that failed and may be retried), `returned` is an
  /// *outcome* (the parcel is going back to the seller). Screens that need
  /// "this is bad news" want both; screens that need "this is over" want
  /// [returned] and [completed] only.
  bool get isFailure =>
      this == RemoteOrderStatus.deliveryFailed || this == RemoteOrderStatus.returned;

  /// Whether the parcel has finished its journey, successfully or not.
  bool get isTerminal =>
      this == RemoteOrderStatus.completed ||
      this == RemoteOrderStatus.returned ||
      this == RemoteOrderStatus.delivered;
}

/// The buyer's 4-state view of [status].
///
/// Every server status maps to something; there is no null case, because a
/// buyer's order card always has to say *something*.
///
/// The two judgement calls, with their reasons:
///
///  * `DELIVERED` and `COMPLETED` both become [OrderStatus.toRate]. On the
///    buyer's side the interesting fact is the same for both — the parcel is
///    in their hands and the only thing left is a review. The server
///    distinguishes them because it also models the seller's side (COMPLETED
///    releases the payout), which the buyer neither sees nor benefits from.
///  * `DELIVERY_FAILED` becomes [OrderStatus.outForDelivery] (a retry is
///    coming) and `RETURNED` becomes [OrderStatus.inTransit] (it is moving,
///    but away). Neither collapses into one "something went wrong" bucket,
///    because the buyer's next action differs: a failed attempt means *wait*,
///    a return means *the purchase is being undone*. This matches what mock
///    mode already did for the equivalent courier milestones (see
///    `OrderState._resolve`), so the two modes agree by construction.
OrderStatus buyerStatusFor(RemoteOrderStatus status) => switch (status) {
      RemoteOrderStatus.placed ||
      RemoteOrderStatus.confirmed ||
      RemoteOrderStatus.preparing ||
      RemoteOrderStatus.readyForPickup =>
        OrderStatus.toShip,
      RemoteOrderStatus.pickedUp ||
      RemoteOrderStatus.atSortingCenter ||
      RemoteOrderStatus.sorted =>
        OrderStatus.inTransit,
      RemoteOrderStatus.assignedToRider ||
      RemoteOrderStatus.outForDelivery ||
      RemoteOrderStatus.deliveryFailed =>
        OrderStatus.outForDelivery,
      RemoteOrderStatus.delivered ||
      RemoteOrderStatus.completed =>
        OrderStatus.toRate,
      // Physically moving, but not towards the buyer — so not "out for
      // delivery", which would read as good news it isn't.
      RemoteOrderStatus.returned => OrderStatus.inTransit,
      // Fail safe for a state this build doesn't know. `toShip` is chosen over
      // the alternatives because it is the earliest stage: an order wrongly
      // shown as "not shipped yet" prompts a question, whereas one wrongly
      // shown as "out for delivery" prompts someone to sit by the door. When
      // the app is uncertain, it should under-promise.
      RemoteOrderStatus.unknown => OrderStatus.toShip,
    };

/// The courier's milestone for [status], or **null** when the order isn't yet
/// the courier's business.
///
/// Null is the meaningful answer for `PLACED` / `CONFIRMED` / `PREPARING`:
/// those are the seller's own stages, reached before a rider exists. Returning
/// a milestone there would invent courier progress out of seller progress, and
/// the courier's dashboard would show a job as "accepted" that nobody has been
/// asked to accept. It is the same null-means-nobody-yet convention the buyer's
/// tracker already uses for an unassigned rider (see `DeliveryJob.courierName`),
/// which is why the null is documented rather than defaulted.
///
/// ## The gap this mapping exposes
///
/// `arrivedAtSeller` — "the rider is at the shop collecting it" — has **no
/// server counterpart** among the 13 statuses. `READY_FOR_PICKUP` is the
/// closest, but it is a claim about the *parcel* (the seller has it ready),
/// not about the rider's position. So that leg of the pickup pipeline is
/// currently unrepresentable on the wire, which is why nothing below maps to
/// [DeliveryMilestone.arrivedAtSeller]. Flagged in docs/api-contract.md rather
/// than papered over with a plausible-looking guess.
DeliveryMilestone? courierMilestoneFor(RemoteOrderStatus status) => switch (status) {
      RemoteOrderStatus.placed ||
      RemoteOrderStatus.confirmed ||
      RemoteOrderStatus.preparing =>
        null,
      RemoteOrderStatus.readyForPickup => DeliveryMilestone.accepted,
      RemoteOrderStatus.pickedUp => DeliveryMilestone.pickedUp,
      RemoteOrderStatus.atSortingCenter => DeliveryMilestone.atSortingCenter,
      RemoteOrderStatus.sorted => DeliveryMilestone.sorted,
      RemoteOrderStatus.assignedToRider => DeliveryMilestone.assignedToRider,
      RemoteOrderStatus.outForDelivery => DeliveryMilestone.inTransit,
      RemoteOrderStatus.delivered ||
      RemoteOrderStatus.completed =>
        DeliveryMilestone.delivered,
      RemoteOrderStatus.deliveryFailed => DeliveryMilestone.failedDelivery,
      RemoteOrderStatus.returned => DeliveryMilestone.returnToSender,
      // Fail safe: null, *not* a milestone. Guessing here would put a job on
      // the courier's dashboard in a state the server never asked for, and the
      // courier would act on it.
      RemoteOrderStatus.unknown => null,
    };

/// Convenience wrappers for raw JSON values, since most call sites have the
/// wire string in hand rather than the enum.
OrderStatus buyerStatusForWire(Object? raw) =>
    buyerStatusFor(RemoteOrderStatus.parse(raw));

DeliveryMilestone? courierMilestoneForWire(Object? raw) =>
    courierMilestoneFor(RemoteOrderStatus.parse(raw));

/// The ETA line the buyer's order card shows for a status.
///
/// Beside the mapping rather than in a screen, because it is *copy for a
/// state* and the states are defined here. A screen choosing its own wording
/// per status would be a second translation table, which is the thing this
/// file exists to prevent.
///
/// The wording mirrors mock mode's `OrderState._resolve`, so an order doesn't
/// change personality when the backend comes online.
String buyerEtaFor(RemoteOrderStatus status) => switch (status) {
      RemoteOrderStatus.placed ||
      RemoteOrderStatus.confirmed =>
        'Seller ships within 1-2 days',
      RemoteOrderStatus.preparing => 'The seller is preparing your order',
      RemoteOrderStatus.readyForPickup => 'Waiting for a courier to collect it',
      RemoteOrderStatus.pickedUp => 'Collected from the seller',
      RemoteOrderStatus.atSortingCenter => 'At the sorting center',
      RemoteOrderStatus.sorted => 'Sorted for delivery',
      RemoteOrderStatus.assignedToRider => 'A courier has been assigned',
      RemoteOrderStatus.outForDelivery => 'Out for delivery',
      RemoteOrderStatus.delivered ||
      RemoteOrderStatus.completed =>
        'Delivered',
      RemoteOrderStatus.deliveryFailed => 'Delivery attempted — will retry',
      RemoteOrderStatus.returned => 'Being returned to seller',
      // Deliberately vague rather than an invented estimate: the app does not
      // know what this state means, so it should not describe it.
      RemoteOrderStatus.unknown => 'Preparing your order',
    };

// ---------------------------------------------------------------------------
// Registration statuses
// ---------------------------------------------------------------------------
//
// The second set of server status strings, and the one that decides *routing*
// rather than display. `pending_verification -> pending_approval -> approved |
// rejected` is two gates, not one: an email OTP the person can do something
// about, and an admin review they cannot. Collapsing them would send someone
// who hasn't confirmed their email to a screen that says "wait for an admin".

/// A registration's state on the server.
enum RemoteRegistrationStatus {
  /// Email OTP not yet confirmed. The person has an action available.
  pendingVerification,

  /// Email confirmed, waiting on an administrator or the Logistics center.
  pendingApproval,

  approved,

  rejected,

  /// A state this build doesn't know — see [RemoteOrderStatus.unknown] for the
  /// same reasoning.
  unknown;

  /// The server's own spelling, per the contract.
  String get wire => switch (this) {
        RemoteRegistrationStatus.pendingVerification => 'pending_verification',
        RemoteRegistrationStatus.pendingApproval => 'pending_approval',
        RemoteRegistrationStatus.approved => 'approved',
        RemoteRegistrationStatus.rejected => 'rejected',
        RemoteRegistrationStatus.unknown => 'unknown',
      };

  static const Set<String> allWireValues = {
    'pending_verification',
    'pending_approval',
    'approved',
    'rejected',
  };

  /// Parses a wire value. Never throws; unrecognised input becomes [unknown].
  ///
  /// Lowercased and hyphen-normalised before lookup because these values are
  /// already lowercase snake on the wire, and a serde that emits
  /// `pendingVerification` would otherwise fail every one of them.
  static RemoteRegistrationStatus parse(Object? raw) {
    if (raw is! String) return RemoteRegistrationStatus.unknown;
    final normalized =
        raw.trim().toLowerCase().replaceAll('-', '_').replaceAll(' ', '_');
    for (final status in RemoteRegistrationStatus.values) {
      if (status == RemoteRegistrationStatus.unknown) continue;
      if (status.wire == normalized) return status;
    }
    return RemoteRegistrationStatus.unknown;
  }
}

/// The app's gate status for a registration state.
///
/// `rejected` maps to `loggedOut` rather than to a fourth case, and that is the
/// honest simplification: there is no screen for a rejected registration, so
/// sending the person to Login is the only truthful destination the app has.
/// Inventing a "rejected" `AccountStatus` with nothing rendering it would be a
/// state that exists only to be unhandled. Worth knowing as a gap — see the
/// contract's OPEN QUESTIONS §0.6.
AccountStatus accountStatusFor(RemoteRegistrationStatus status) => switch (status) {
      RemoteRegistrationStatus.pendingVerification ||
      RemoteRegistrationStatus.pendingApproval =>
        AccountStatus.pendingApproval,
      RemoteRegistrationStatus.approved => AccountStatus.active,
      RemoteRegistrationStatus.rejected => AccountStatus.loggedOut,
      // Fail safe: the *pending* state, not active. An unrecognised
      // registration status means the account's approval is not established,
      // and granting access on doubt is the one direction that cannot be
      // walked back.
      RemoteRegistrationStatus.unknown => AccountStatus.pendingApproval,
    };

/// Whether this state still needs the person to confirm their email.
///
/// Separate from [accountStatusFor] because both pending states route to the
/// same screen and that screen's *copy* differs — one tells the person to check
/// their inbox, the other tells them to wait. Getting this wrong is a small
/// lie with a real cost (they wait for a person who was never going to be
/// asked).
bool awaitsEmailVerification(RemoteRegistrationStatus status) =>
    status == RemoteRegistrationStatus.pendingVerification ||
    // Unknown is treated as needing an action rather than as waiting: "check
    // your inbox" is something the person can resolve alone, whereas "wait for
    // an admin" stalls them on a review that may not exist.
    status == RemoteRegistrationStatus.unknown;