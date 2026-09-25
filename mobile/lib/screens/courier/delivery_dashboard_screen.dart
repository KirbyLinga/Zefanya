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
    final jobs = context.watch<CourierState>().jobsForDelivery;

    return Scaffold(
      backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      appBar: AppBar(),
      body: jobs.isEmpty
          ? Column(
              children: [
                const EditorialHeader(
                  eyebrow: 'FOR DELIVERY',
                  title: 'Out for Delivery',
                  padding: EdgeInsets.fromLTRB(20, 12, 20, 16),
                ),
                Expanded(child: _buildEmptyState(theme)),
              ],
            )
          : ListView.builder(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 120),
              itemCount: jobs.length + 1,
              itemBuilder: (context, i) {
                if (i == 0) {
                  return const EditorialHeader(
                    eyebrow: 'FOR DELIVERY',
                    title: 'Out for Delivery',
                    padding: EdgeInsets.fromLTRB(0, 12, 0, 16),
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

  Widget _buildEmptyState(ThemeData theme) {
    return const EmptyState(
      icon: Icons.local_shipping_outlined,
      title: 'Nothing out for delivery yet',
      subtitle: 'Picked-up items will show up here',
      tint: AppColors.tertiary,
    );
  }
}
