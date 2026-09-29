// lib/screens/courier/delivery_dashboard_screen.dart
//
// "Items for Delivery" — jobs already picked up from the seller, now
// en route to the buyer.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../state/courier_state.dart';
import '../../theme/app_theme.dart';
import 'delivery_tracker_screen.dart';
import 'widgets/delivery_job_card.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/staggered_reveal.dart';

class DeliveryDashboardScreen extends StatelessWidget {
  const DeliveryDashboardScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final courier = context.watch<CourierState>();
    final jobs = courier.jobsForDelivery;

    // This is where a courier lands after completing a job, so today's
    // takings belong here and not only on a tab they have to go and find —
    // see the tempo note in DeliveryTrackerScreen._completeDelivery. Hidden
    // at zero rather than showing "₱0", because an empty tally is noise on
    // the first run of the day.
    final tally = courier.todayEarnings > 0 ? _todayTally(theme, courier) : null;

    return Scaffold(
      backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      appBar: AppBar(),
      body: jobs.isEmpty
          ? Column(
              children: [
                EditorialHeader(
                  eyebrow: 'FOR DELIVERY',
                  title: 'Out for Delivery',
                  trailing: tally,
                  padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
                ),
                Expanded(child: _buildEmptyState(theme)),
              ],
            )
          : ListView.builder(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 120),
              itemCount: jobs.length + 1,
              itemBuilder: (context, i) {
                if (i == 0) {
                  return EditorialHeader(
                    eyebrow: 'FOR DELIVERY',
                    title: 'Out for Delivery',
                    trailing: tally,
                    padding: const EdgeInsets.fromLTRB(0, 12, 0, 16),
                  );
                }
                final job = jobs[i - 1];
                return StaggeredReveal(
                  index: i - 1,
                  child: DeliveryJobCard(
                    assignedJob: job,
                    index: i - 1,
                    onTap: () => Navigator.of(context).push(
                      AppPageRoute(builder: (_) => DeliveryTrackerScreen(jobId: job.job.id)),
                    ),
                  ),
                );
              },
            ),
    );
  }

  /// The courier's day so far, in the header of the screen they return to
  /// after every delivery.
  ///
  /// Deliberately small — a running total, not a dashboard. The Earnings tab
  /// is where these numbers are the subject rather than context.
  Widget _todayTally(ThemeData theme, CourierState courier) {
    final now = DateTime.now();
    final count = courier.completedJobs
        .where((c) =>
            c.completedAt.year == now.year &&
            c.completedAt.month == now.month &&
            c.completedAt.day == now.day)
        .length;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Text('TODAY', style: AppType.eyebrow),
        const SizedBox(height: 4),
        Text('₱${courier.todayEarnings.toStringAsFixed(0)}', style: AppType.priceNumeral(24)),
        const SizedBox(height: 2),
        Text(
          '$count delivered',
          style: theme.textTheme.labelSmall?.copyWith(color: AppColors.textSecondary),
        ),
      ],
    );
  }

  Widget _buildEmptyState(ThemeData theme) {
    return EmptyState(
      icon: Icons.local_shipping_outlined,
      title: 'Nothing out for delivery yet',
      subtitle: 'Picked-up items will show up here',
      tint: AppColors.tertiary,
    );
  }
}
