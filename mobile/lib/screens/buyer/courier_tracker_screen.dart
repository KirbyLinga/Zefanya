// lib/screens/buyer/courier_tracker_screen.dart
//
// Read-only view of courier progress for a buyer's order. Pushed from
// the "Track Courier" button on order cards (inTransit / outForDelivery)
// and from the order detail screen.
//
// Reuses the milestone data already computed by CourierState — no new
// state, no polling stub, just a live read of the same milestone map
// the courier's own tracker writes to.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../data/mock_data.dart';
import '../../state/courier_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/staggered_reveal.dart';

class CourierTrackerScreen extends StatelessWidget {
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
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final courier = context.watch<CourierState>();
    final job = courier.jobForOrder(order.id);
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
                order: order,
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

            // ---- Courier info -----------------------------------
            if (job != null)
              StaggeredReveal(
                index: 2,
                child: _CourierInfo(theme: theme, job: job),
              ),
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
    return AppColors.secondary;
  }

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: editorialRadius(0, tier: EditorialTier.card),
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
    final currentIdx =
        _steps.indexWhere((s) => s.$1 == milestone).clamp(0, _steps.length - 1);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(1, tier: EditorialTier.card),
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
                      ? const Icon(Icons.check,
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
    return Container(
      margin: const EdgeInsets.only(bottom: 20),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.danger.withValues(alpha: 0.08),
        borderRadius: editorialRadius(0, tier: EditorialTier.card),
        border: Border(left: BorderSide(color: AppColors.danger, width: 3)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.error_outline,
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

class _CourierInfo extends StatelessWidget {
  const _CourierInfo({required this.theme, required this.job});

  final ThemeData theme;
  final DeliveryJob job;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(0, tier: EditorialTier.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('DELIVERY DETAILS', style: AppType.eyebrow),
          const SizedBox(height: 12),
          _line(theme, 'Request ID', job.id),
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
