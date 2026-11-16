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
import '../../../utils/money_format.dart';
import '../../../widgets/editorial_shapes.dart';
import '../../../widgets/editorial_status_badge.dart';

class DeliveryJobCard extends StatelessWidget {
  const DeliveryJobCard({
    super.key,
    required this.assignedJob,
    required this.onTap,
    this.index = 0,
    this.pickup = false,
  });

  final AssignedJob assignedJob;
  final VoidCallback onTap;
  final bool pickup;

  /// Position in its list — alternates which diagonal the card's big
  /// corners sit on, same as ProductCard on the buyer side, so a scrolled
  /// list of jobs doesn't read as a stack of identical rounded boxes.
  final int index;

  static const _statusLabels = {
    DeliveryMilestone.accepted: 'Assigned',
    DeliveryMilestone.arrivedAtSeller: 'At Seller',
    DeliveryMilestone.pickedUp: 'Picked Up',
    DeliveryMilestone.atSortingCenter: 'At Sorting',
    DeliveryMilestone.sorted: 'Sorted',
    DeliveryMilestone.assignedToRider: 'Assigned',
    DeliveryMilestone.inTransit: 'In Transit',
    DeliveryMilestone.delivered: 'Delivered',
    DeliveryMilestone.failedDelivery: 'Delivery failed',
    DeliveryMilestone.returnToSender: 'Returning',
  };

  // Resolved per call rather than cached in a `static const` map: several
  // of these tokens (warning, primaryDark, danger) differ per mode, and a
  // const map would freeze whichever mode loaded first.
  static Map<DeliveryMilestone, Color> get _statusColors => {
    DeliveryMilestone.accepted: AppColors.neutral,
    DeliveryMilestone.arrivedAtSeller: AppColors.primary,
    DeliveryMilestone.pickedUp: AppColors.secondary,
    DeliveryMilestone.atSortingCenter: AppColors.warning,
    DeliveryMilestone.sorted: AppColors.tertiary,
    DeliveryMilestone.assignedToRider: AppColors.tertiary,
    DeliveryMilestone.inTransit: AppColors.primaryDark,
    DeliveryMilestone.delivered: AppColors.tertiary,
    DeliveryMilestone.failedDelivery: AppColors.danger,
    DeliveryMilestone.returnToSender: AppColors.danger,
  };

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final job = assignedJob.job;
    final milestone = assignedJob.milestone;
    final statusColor = _statusColors[milestone]!;
    final shape = editorialRadius(index, ShapeTier.card);
    final action = switch (milestone) {
      DeliveryMilestone.accepted => 'Open pickup',
      DeliveryMilestone.arrivedAtSeller => 'Verify pickup',
      DeliveryMilestone.pickedUp => 'View hub handoff',
      DeliveryMilestone.assignedToRider => 'Open delivery',
      DeliveryMilestone.inTransit => 'Continue delivery',
      DeliveryMilestone.failedDelivery => 'Review failed delivery',
      DeliveryMilestone.returnToSender => 'View return',
      _ => 'View job',
    };

    return InkWell(
      onTap: onTap,
      borderRadius: shape,
      child: Container(
        margin: const EdgeInsets.only(bottom: 14),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppColors.surfaceMuted,
          borderRadius: shape,
          border: Border.all(color: AppColors.divider),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Wrap(
              spacing: 10,
              runSpacing: 6,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(pickup ? 'PICKUP' : 'DELIVERY', style: AppType.eyebrow),
                EditorialStatusBadge(
                  label: _statusLabels[milestone]!,
                  tint: statusColor,
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              pickup ? job.shopName : job.buyerName,
              style: theme.textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 5),
            _iconLine(
              theme,
              Icons.location_on_outlined,
              pickup ? 'Pickup address' : 'Delivery address',
              pickup ? job.pickupAddress : job.deliveryArea,
            ),
            if (!pickup) ...[
              const SizedBox(height: 5),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    Icons.storefront_outlined,
                    size: 16,
                    color: AppColors.textSecondary,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      job.shopName,
                      semanticsLabel: 'Seller: ${job.shopName}',
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                ],
              ),
            ],
            const SizedBox(height: 6),
            _iconLine(
              theme,
              Icons.inventory_2_outlined,
              job.parcelCount == 1 ? '1 parcel' : '${job.parcelCount} parcels',
              job.packageSize,
            ),
            if (!pickup && job.hasCod) ...[
              const SizedBox(height: 6),
              _iconLine(
                theme,
                Icons.payments_outlined,
                'Collect COD',
                formatPeso(job.codAmount),
              ),
            ],
            const SizedBox(height: 8),
            Divider(height: 1, color: AppColors.divider),
            const SizedBox(height: 8),
            Wrap(
              spacing: 14,
              runSpacing: 6,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(
                  action,
                  style: theme.textTheme.labelLarge?.copyWith(
                    color: AppColors.textPrimary,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                Text(
                  'Fee ${formatPeso(job.fee)}',
                  style: theme.textTheme.labelMedium?.copyWith(
                    color: AppColors.textSecondary,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _iconLine(ThemeData theme, IconData icon, String label, String value) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 16, color: AppColors.textSecondary),
        const SizedBox(width: 8),
        Expanded(
          child: Text.rich(
            TextSpan(
              style: theme.textTheme.bodySmall,
              children: [
                TextSpan(
                  text: '$label: ',
                  style: TextStyle(
                    fontWeight: FontWeight.w600,
                    color: AppColors.textPrimary,
                  ),
                ),
                TextSpan(text: value),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
