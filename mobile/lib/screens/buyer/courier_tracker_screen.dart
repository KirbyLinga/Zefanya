// lib/screens/buyer/courier_tracker_screen.dart
//
// Read-only view of courier progress for a buyer's order. Pushed from
// the "Track Courier" button on order cards (inTransit / outForDelivery)
// and from the order detail screen.
//
// In mock mode the milestone is read directly from CourierState (the
// courier's own tracker writes it there). In live mode the screen also
// polls GET /api/orders/{id}/milestone every 30 s so the buyer's view
// stays in sync across devices — a courier advancing a milestone on
// their phone surfaces here without the buyer having to leave and
// re-enter the screen.
//
// The poll is fire-and-forget: a transient network error is silently
// discarded rather than surfaced (the milestone the screen already has
// is still valid; the next tick will try again). Only
// UnauthorizedException is forwarded — that means the session is dead
// and there is no point continuing.

import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../data/api/api_client.dart';
import '../../data/api/api_exception.dart';
import '../../data/dto/remote_status.dart';
import '../../data/mock_data.dart';
import '../../data/repositories/repositories.dart';
import '../../state/courier_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/copy_id.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/staggered_reveal.dart';
import '../shared/chat_screen.dart';

class CourierTrackerScreen extends StatefulWidget {
  const CourierTrackerScreen({super.key, required this.order});

  final Order order;

  // Buyer-visible step labels — same sequence as the full milestone
  // enum but using language that makes sense to a buyer, not a courier.
  static const _steps = [
    (DeliveryMilestone.accepted,        'Order Confirmed',      Icons.check_circle_outline),
    (DeliveryMilestone.arrivedAtSeller, 'Courier at Seller',    Icons.storefront_outlined),
    (DeliveryMilestone.pickedUp,        'Package Picked Up',    Icons.inventory_2_outlined),
    (DeliveryMilestone.atSortingCenter, 'At Sorting Center',    Icons.warehouse_outlined),
    (DeliveryMilestone.sorted,          'Sorted for Delivery',  Icons.check_box_outlined),
    (DeliveryMilestone.assignedToRider, 'On Its Way',           Icons.directions_bike_outlined),
    (DeliveryMilestone.inTransit,       'Out for Delivery',     Icons.local_shipping_outlined),
    (DeliveryMilestone.delivered,       'Delivered',            Icons.flag_outlined),
  ];

  @override
  State<CourierTrackerScreen> createState() => _CourierTrackerScreenState();
}

class _CourierTrackerScreenState extends State<CourierTrackerScreen> {
  static const _pollInterval = Duration(seconds: 30);

  Timer? _pollTimer;

  // The ApiClient is only present in live mode (Repositories.client is null
  // in mock mode). When it is null polling is skipped entirely — the
  // courier's own tracker writes directly to CourierState, which this
  // screen already watches.
  ApiClient? _apiClient;

  @override
  void initState() {
    super.initState();
    // Cache once — the client doesn't change during a session.
    _apiClient = context.read<Repositories>().client;
    if (_apiClient != null) {
      // First poll immediately, then on the timer.
      _poll();
      _pollTimer = Timer.periodic(_pollInterval, (_) => _poll());
    }
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    super.dispose();
  }

  /// Fetches the current milestone for this order from the server and
  /// updates CourierState if it has changed.
  ///
  /// Errors are silently discarded — the screen keeps showing whatever
  /// milestone it already has, and the next tick will retry. The one
  /// exception is [UnauthorizedException]: a dead session means every
  /// future tick will also fail, so there's no point continuing.
  Future<void> _poll() async {
    final client = _apiClient;
    if (client == null || !mounted) return;

    try {
      final body = await client.getJson(
        '/orders/${widget.order.id}/milestone',
        authenticated: true,
      );
      if (!mounted) return;

      final milestone = courierMilestoneForWire(body['milestone']);
      if (milestone == null) return;

      final job = context.read<CourierState>().jobForOrder(widget.order.id);
      if (job == null) return;

      context.read<CourierState>().updateMilestoneFromServer(job.id, milestone);
    } on UnauthorizedException {
      // Session expired — cancel the timer so we don't spam failed requests.
      _pollTimer?.cancel();
    } on ApiException {
      // Transient error — try again on the next tick.
    } catch (_) {
      // Unexpected — swallow so the screen stays usable.
    }
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final courier = context.watch<CourierState>();
    final job = courier.jobForOrder(widget.order.id);
    final milestone = job == null
        ? DeliveryMilestone.accepted
        : courier.milestoneFor(job.id);

    final failed = milestone == DeliveryMilestone.failedDelivery;
    final returning = milestone == DeliveryMilestone.returnToSender;

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 40),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // ---- Status hero ------------------------------------
            StaggeredReveal(
              index: 0,
              child: _StatusHero(
                theme: theme,
                milestone: milestone,
                failed: failed,
                returning: returning,
                order: widget.order,
              ),
            ),
            const SizedBox(height: 20),

            // ---- Failure notice ---------------------------------
            if (failed || returning)
              StaggeredReveal(
                index: 1,
                child: _FailureNotice(theme: theme, returning: returning),
              ),

            if (!failed && !returning) ...[
              // ---- Progress timeline ----------------------------
              StaggeredReveal(
                index: 1,
                child: _ProgressTimeline(
                  theme: theme,
                  milestone: milestone,
                ),
              ),
              const SizedBox(height: 20),
            ],

            // ---- Who has it, then what it is --------------------
            // Order is deliberate: the buyer's first question at this
            // stage is "who is bringing this", not "what size is the box".
            if (job != null) ...[
              StaggeredReveal(
                index: 2,
                child: _CourierCard(
                  theme: theme,
                  job: job,
                  // The rider's rating across all their rated deliveries —
                  // null when they have none, which the card renders as
                  // nothing rather than as a zero.
                  averageRating: courier.averageRating,
                  ratingCount: courier.ratingCount,
                ),
              ),
              const SizedBox(height: 12),
              StaggeredReveal(
                index: 3,
                child: _DeliveryDetails(theme: theme, job: job, order: widget.order),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------

class _StatusHero extends StatelessWidget {
  const _StatusHero({
    required this.theme,
    required this.milestone,
    required this.failed,
    required this.returning,
    required this.order,
  });

  final ThemeData theme;
  final DeliveryMilestone milestone;
  final bool failed;
  final bool returning;
  final Order order;

  String get _headline {
    if (failed) return 'Delivery Attempted';
    if (returning) return 'Returning to Seller';
    if (milestone == DeliveryMilestone.delivered) return 'Delivered!';
    if (milestone == DeliveryMilestone.inTransit ||
        milestone == DeliveryMilestone.assignedToRider) {
      return 'On Its Way';
    }
    return 'Getting Ready';
  }

  Color get _accentColor {
    if (failed || returning) return AppColors.danger;
    if (milestone == DeliveryMilestone.delivered) return AppColors.tertiary;
    if (milestone == DeliveryMilestone.inTransit ||
        milestone == DeliveryMilestone.assignedToRider) {
      return AppColors.primaryDark;
    }
    return AppColors.neutral; // getting ready — neutral on dark, no blush
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return ClipRRect(
      borderRadius: editorialRadius(0, ShapeTier.card),
      child: Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          color: _accentColor.withValues(alpha: 0.12),
          border: Border(
            left: BorderSide(color: _accentColor, width: 4),
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('YOUR ORDER', style: AppType.eyebrow),
            const SizedBox(height: 6),
            Text(order.shopName, style: theme.textTheme.titleSmall),
            const SizedBox(height: 2),
            Text(order.itemSummary,
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: AppColors.textSecondary)),
            const SizedBox(height: 16),
            Text(_headline,
                style: AppType.sectionDisplay.copyWith(
                  fontSize: 26,
                  color: _accentColor,
                )),
            const SizedBox(height: 4),
            Text(
              order.eta,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w600,
                color: AppColors.textPrimary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------

class _ProgressTimeline extends StatelessWidget {
  const _ProgressTimeline({required this.theme, required this.milestone});

  final ThemeData theme;
  final DeliveryMilestone milestone;

  static const _steps = CourierTrackerScreen._steps;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final currentIdx =
        _steps.indexWhere((s) => s.$1 == milestone).clamp(0, _steps.length - 1);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(1, ShapeTier.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('DELIVERY PROGRESS', style: AppType.eyebrow),
          const SizedBox(height: 16),
          for (var i = 0; i < _steps.length; i++)
            _step(
              label: _steps[i].$2,
              icon: _steps[i].$3,
              done: i < currentIdx,
              active: i == currentIdx,
              isLast: i == _steps.length - 1,
            ),
        ],
      ),
    );
  }

  Widget _step({
    required String label,
    required IconData icon,
    required bool done,
    required bool active,
    required bool isLast,
  }) {
    final color = done || active ? AppColors.primaryDark : AppColors.divider;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 32,
            child: Column(
              children: [
                Container(
                  width: 28,
                  height: 28,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: done ? AppColors.primaryDark : Colors.transparent,
                    border: Border.all(color: color, width: active ? 2 : 1.5),
                  ),
                  child: done
                      ? Icon(Icons.check,
                          size: 14, color: AppColors.textOnDark)
                      : Icon(icon,
                          size: 14,
                          color: active
                              ? AppColors.primaryDark
                              : AppColors.textSecondary),
                ),
                if (!isLast)
                  Expanded(
                    child: Container(
                      width: 2,
                      color: done ? AppColors.primaryDark : AppColors.divider,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: 18, top: 4),
              child: Text(
                label,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: active
                      ? AppColors.primaryDark
                      : done
                          ? AppColors.textPrimary
                          : AppColors.textSecondary,
                  fontWeight: active ? FontWeight.w700 : FontWeight.w400,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------

class _FailureNotice extends StatelessWidget {
  const _FailureNotice({required this.theme, required this.returning});

  final ThemeData theme;
  final bool returning;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Container(
      margin: const EdgeInsets.only(bottom: 20),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.danger.withValues(alpha: 0.08),
        borderRadius: editorialRadius(0, ShapeTier.card),
        border: Border(left: BorderSide(color: AppColors.danger, width: 3)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.error_outline,
                  size: 18, color: AppColors.danger),
              const SizedBox(width: 8),
              Text(
                returning ? 'Order Being Returned' : 'Delivery Attempt Failed',
                style: theme.textTheme.titleSmall
                    ?.copyWith(color: AppColors.danger),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            returning
                ? 'This package is being returned to the seller. '
                    'Contact the seller for a refund or reshipment.'
                : 'The courier was unable to deliver your order. '
                    'A retry will be attempted shortly. '
                    'Make sure your address is correct and someone is available to receive it.',
            style: theme.textTheme.bodySmall
                ?.copyWith(color: AppColors.textSecondary, height: 1.5),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------

/// The parcel's own details: request id, size, destination.
///
/// Renamed from `_CourierInfo`, which is what it was called while it was
/// the only courier-ish block on this screen — and while it contained no
/// courier at all. Its header text already said "DELIVERY DETAILS"; the
/// class name was the only thing still claiming otherwise.
class _DeliveryDetails extends StatelessWidget {
  const _DeliveryDetails({required this.theme, required this.job, required this.order});

  final ThemeData theme;
  final DeliveryJob job;
  final Order order;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(0, ShapeTier.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('DELIVERY DETAILS', style: AppType.eyebrow),
          const SizedBox(height: 12),
          _CopyLine(theme: theme, label: 'Order ID', value: order.id),
          const SizedBox(height: 6),
          _CopyLine(theme: theme, label: 'Request ID', value: job.id),
          const SizedBox(height: 6),
          _line(theme, 'Package', job.packageSize),
          const SizedBox(height: 6),
          _line(theme, 'Deliver to', job.deliveryArea),
        ],
      ),
    );
  }

  Widget _line(ThemeData theme, String label, String value) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 100,
          child: Text(label,
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: AppColors.textSecondary)),
        ),
        Expanded(
          child: Text(value,
              style: theme.textTheme.bodyMedium
                  ?.copyWith(fontWeight: FontWeight.w500)),
        ),
      ],
    );
  }
}

/// A copyable ID line — tap copies the value, with the same light
/// haptic + "Copied" snackbar as the order detail screen.
class _CopyLine extends StatelessWidget {
  const _CopyLine({required this.theme, required this.label, required this.value});

  final ThemeData theme;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 100,
          child: Text(label,
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: AppColors.textSecondary)),
        ),
        Expanded(
          child: InkWell(
            onTap: () => copyId(context, value),
            // Announced as a copy button — a bare tappable ID is
            // silent to a screen reader.
            child: Semantics(
              button: true,
              label: 'Copy $label $value',
              excludeSemantics: true,
              child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Flexible(
                  child: Text(value,
                      style: theme.textTheme.bodyMedium
                          ?.copyWith(fontWeight: FontWeight.w500)),
                ),
                const SizedBox(width: 6),
                Icon(Icons.copy_outlined, size: 13, color: AppColors.textSecondary),
              ],
            ),
          ),
        ),
        ),
      ],
    );
  }
}

/// Who is carrying the parcel.
///
/// This is the half of the tracking experience the app was missing: the
/// milestones above answer "what stage is it at" and nothing answered "who
/// is doing this for me". Real delivery apps show a name because a name is
/// what turns a progress bar into a person.
///
/// [DeliveryJob.courierName] is null until dispatch assigns a rider, and
/// that's rendered as its own state rather than hidden — most of an order's
/// life genuinely has no courier attached yet, and "waiting for a rider" is
/// more honest than an empty card.
///
/// ## Photo, rating and contact: what each of them actually is
///
/// All three are now shown, and each is sourced rather than fabricated:
///
///  * **Photo** — the rider's own profile avatar, taken from their session at
///    the dispatch step ([DeliveryJob.courierPhotoPath]). When it's absent
///    (the common case — most riders never set one) the card falls back to a
///    *monogram* of their initial, not a stock silhouette. A generic person
///    glyph is a picture of nobody standing in for somebody; a letter is
///    derived from data that is actually present.
///  * **Rating** — the mean of the ratings buyers have left for this rider's
///    deliveries, and it renders *nothing* until there is at least one (see
///    CourierState.averageRating). A new rider must not be shown "0.0", which
///    reads as the worst possible score rather than the absence of one.
///  * **Contact** — a button into the same role-agnostic ChatScreen the
///    seller threads already use, and only once a rider exists. This was
///    previously left out as a policy question rather than an engineering
///    one; it's included now because the alternative on a delivery day is a
///    buyer with no way to say "the gate code is 4417", which is worse for
///    both people.
///
/// ## Consent, because the photo is a real person
///
/// It's the rider's own avatar from their own profile screen, shown to one
/// buyer about one parcel they are currently carrying. Nothing is uploaded,
/// no buyer can browse riders, and it stops being shown when the job does.
class _CourierCard extends StatelessWidget {
  const _CourierCard({
    required this.theme,
    required this.job,
    required this.averageRating,
    required this.ratingCount,
  });

  final ThemeData theme;
  final DeliveryJob job;

  /// The rider's mean rating across their rated deliveries, or null when
  /// they have none — null renders nothing rather than a zero.
  final double? averageRating;
  final int ratingCount;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final name = job.courierName;
    final assigned = name != null;
    final photo = job.courierPhotoPath;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(1, ShapeTier.card),
      ),
      child: Row(
        children: [
          _CourierAvatar(photoPath: photo, name: name),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(assigned ? 'YOUR COURIER' : 'NO COURIER YET', style: AppType.eyebrow),
                const SizedBox(height: 3),
                Text(
                  name ?? 'Waiting for a rider to be assigned',
                  style: theme.textTheme.titleSmall,
                ),
                // Only once there is something to say. A rider with no
                // ratings gets no rating line rather than an empty one.
                if (assigned && averageRating != null) ...[
                  const SizedBox(height: 4),
                  Row(
                    children: [
                      Icon(Icons.star_rounded,
                          size: 14, color: AppColors.ratingStar),
                      const SizedBox(width: 4),
                      Text(
                        '${averageRating!.toStringAsFixed(1)} · $ratingCount '
                        '${ratingCount == 1 ? 'delivery rated' : 'deliveries rated'}',
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),
          if (assigned)
            // Contact, kept to an icon button so it doesn't compete with the
            // order's own actions elsewhere on this screen.
            IconButton(
              tooltip: 'Message $name',
              onPressed: () => Navigator.of(context).push(
                AppPageRoute(
                  builder: (_) => ChatScreen(
                    contactName: name,
                    contactRole: 'Courier',
                  ),
                ),
              ),
              icon: Icon(Icons.chat_bubble_outline, color: AppColors.primaryDark),
            ),
        ],
      ),
    );
  }
}

/// The rider's photo when they have one, their initial when they don't.
///
/// Never a generic person icon — see [_CourierCard].
class _CourierAvatar extends StatelessWidget {
  const _CourierAvatar({required this.photoPath, required this.name});

  final String? photoPath;
  final String? name;

  static const double _size = 48;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    if (photoPath != null) {
      return ClipOval(
        child: Image.file(
          File(photoPath!),
          width: _size,
          height: _size,
          fit: BoxFit.cover,
          // The file is gone (storage cleared, or this value restored on
          // another device) — fall through to the monogram, which is the same
          // fallback as having no photo at all, rather than a broken image.
          errorBuilder: (context, error, stack) => _monogram(),
        ),
      );
    }
    return _monogram();
  }

  Widget _monogram() {
    final trimmed = name?.trim() ?? '';
    final initial = trimmed.isEmpty ? '?' : trimmed.characters.first.toUpperCase();

    return Container(
      width: _size,
      height: _size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: name == null
            ? AppColors.divider
            : AppColors.hueOnSurface(AppColors.tertiary),
      ),
      child: name == null
          ? Icon(Icons.hourglass_empty, size: 20, color: AppColors.textSecondary)
          : Text(
              initial,
              style: AppType.sectionDisplay.copyWith(
                fontSize: 20,
                // Ink for a pale sage fill, in both modes — same rule as the
                // Featured tag and the review badge (AppPalette.tintInk).
                color: AppColors.tintInk,
              ),
            ),
    );
  }
}
