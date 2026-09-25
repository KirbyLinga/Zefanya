// lib/screens/courier/widgets/delivery_job_card.dart
//
// Shared card for an assigned job, used by both pickup_dashboard_screen.dart
// and delivery_dashboard_screen.dart so the two stay visually consistent.
// Tapping pushes DeliveryTrackerScreen for that specific job.
//
// Brought up to the same design-system primitives the buyer side already
// uses (asymmetric card corners, the oversized-numeral treatment for the
// one number on the card that matters most) — this was the single biggest
// gap: every courier list screen renders through this one widget, so it
// had been running the pre-editorial-pass look everywhere at once.

import 'package:flutter/material.dart';
import '../../../state/courier_state.dart';
import '../../../theme/app_theme.dart';
import '../../../widgets/editorial_shapes.dart';

class DeliveryJobCard extends StatelessWidget {
  const DeliveryJobCard({super.key, required this.assignedJob, required this.onTap, this.index = 0});

  final AssignedJob assignedJob;
  final VoidCallback onTap;

  /// Position in its list — alternates which diagonal the card's big
  /// corners sit on, same as ProductCard on the buyer side, so a scrolled
  /// list of jobs doesn't read as a stack of identical rounded boxes.
  final int index;

  static const _statusLabels = {
    DeliveryMilestone.accepted:        'Assigned',
    DeliveryMilestone.arrivedAtSeller: 'At Seller',
    DeliveryMilestone.pickedUp:        'Picked Up',
    DeliveryMilestone.atSortingCenter: 'At Sorting',
    DeliveryMilestone.sorted:          'Sorted',
    DeliveryMilestone.assignedToRider: 'To Rider',
    DeliveryMilestone.inTransit:       'In Transit',
    DeliveryMilestone.delivered:       'Delivered',
    DeliveryMilestone.failedDelivery:  'Failed',
    DeliveryMilestone.returnToSender:  'Returning',
  };

  static const _statusColors = {
    DeliveryMilestone.accepted:        AppColors.neutral,
    DeliveryMilestone.arrivedAtSeller: AppColors.primary,
    DeliveryMilestone.pickedUp:        AppColors.secondary,
    DeliveryMilestone.atSortingCenter: AppColors.warning,
    DeliveryMilestone.sorted:          AppColors.tertiary,
    DeliveryMilestone.assignedToRider: AppColors.tertiary,
    DeliveryMilestone.inTransit:       AppColors.primaryDark,
    DeliveryMilestone.delivered:       AppColors.tertiary,
    DeliveryMilestone.failedDelivery:  AppColors.danger,
    DeliveryMilestone.returnToSender:  AppColors.danger,
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final job = assignedJob.job;
    final statusColor = _statusColors[assignedJob.milestone]!;
    final shape = editorialRadius(index, big: 28, small: 8);

    return InkWell(
      onTap: onTap,
      borderRadius: shape,
      child: Container(
        margin: const EdgeInsets.only(bottom: 14),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: AppColors.surfaceMuted,
          borderRadius: shape,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text(job.shopName, style: theme.textTheme.titleSmall)),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: statusColor.withValues(alpha: 0.18),
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: Text(
                    _statusLabels[assignedJob.milestone]!,
                    style: theme.textTheme.labelMedium
                        ?.copyWith(color: _statusTextColor(statusColor), fontWeight: FontWeight.w700),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            _iconLine(theme, Icons.storefront_outlined, 'Pickup', job.pickupAddress),
            const SizedBox(height: 6),
            _iconLine(theme, Icons.location_on_outlined, 'Deliver to', '${job.buyerName} — ${job.deliveryArea}'),
            const SizedBox(height: 6),
            _iconLine(theme, Icons.inventory_2_outlined, 'Package', job.packageSize),
            const SizedBox(height: 12),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text('DELIVERY FEE', style: AppType.eyebrow),
                // The fee is the one number a courier actually scans a
                // job list for — same reasoning as the buyer side's price
                // treatment, applied to whatever number matters most on
                // this screen. Was titleSmall/w700, same weight as the
                // shop name above it.
                Text('\u20b1${job.fee.toStringAsFixed(0)}', style: AppType.priceNumeral(26)),
              ],
            ),
          ],
        ),
      ),
    );
  }

  // Darken toward black so light tokens (primary pink, secondary blush,
  // tertiary sage) still read as legible text on their own pale pill
  // background — using the raw color for both the fill and the text
  // would be near-invisible for the lighter stages.
  static Color _statusTextColor(Color base) => Color.lerp(base, Colors.black, 0.35)!;

  Widget _iconLine(ThemeData theme, IconData icon, String label, String value) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 16, color: AppColors.textSecondary),
        const SizedBox(width: 8),
        Expanded(
          child: RichText(
            text: TextSpan(
              style: theme.textTheme.bodySmall,
              children: [
                TextSpan(text: '$label: ', style: const TextStyle(fontWeight: FontWeight.w600, color: AppColors.textPrimary)),
                TextSpan(text: value),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
