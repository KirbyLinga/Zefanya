// lib/screens/courier/pickup_dashboard_screen.dart
//
// "Items for Pickup" — jobs the Logistics/Sorting Center has assigned
// to this courier that haven't been picked up from the seller yet.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../state/courier_state.dart';
import '../../theme/app_theme.dart';
import 'delivery_tracker_screen.dart';
import 'widgets/delivery_job_card.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/shimmer_box.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/staggered_reveal.dart';

class PickupDashboardScreen extends StatefulWidget {
  const PickupDashboardScreen({super.key});

  @override
  State<PickupDashboardScreen> createState() => _PickupDashboardScreenState();
}

class _PickupDashboardScreenState extends State<PickupDashboardScreen> {
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    setState(() => _isLoading = true);
    // TODO: replace with a real assignment feed fetch (websocket/polling).
    await Future.delayed(const Duration(milliseconds: 700));
    if (!mounted) return;
    setState(() => _isLoading = false);
  }

  @override
  Widget build(BuildContext context) {
    final jobs = context.watch<CourierState>().jobsForPickup;

    return Scaffold(
      backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      appBar: AppBar(),
      body: _isLoading
          ? _buildLoadingState()
          : RefreshIndicator(
              color: AppColors.primaryDark,
              onRefresh: _refresh,
              child: jobs.isEmpty
                  ? ListView(
                      padding: EdgeInsets.zero,
                      children: [
                        const EditorialHeader(
                          eyebrow: 'FOR PICKUP',
                          title: 'Assigned to You',
                          padding: EdgeInsets.fromLTRB(20, 12, 20, 16),
                        ),
                        const SizedBox(height: 40),
                        _buildEmptyState(context),
                      ],
                    )
                  : ListView.builder(
                      padding: const EdgeInsets.fromLTRB(20, 0, 20, 120),
                      itemCount: jobs.length + 1,
                      itemBuilder: (context, i) {
                        if (i == 0) {
                          return const EditorialHeader(
                            eyebrow: 'FOR PICKUP',
                            title: 'Assigned to You',
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
            ),
    );
  }

  Widget _buildLoadingState() {
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 120),
      itemCount: 3,
      itemBuilder: (context, i) => ShimmerBox(
        child: Container(
          margin: const EdgeInsets.only(bottom: 14),
          height: 150,
          // Matches the real card's asymmetric corners, same reasoning as
          // the buyer grid's skeletons: shape shouldn't visibly change
          // when content replaces it.
          decoration: BoxDecoration(color: AppColors.surfaceMuted, borderRadius: editorialRadius(i, big: 28, small: 8)),
        ),
      ),
    );
  }

  Widget _buildEmptyState(BuildContext context) {
    return const EmptyState(
      icon: Icons.inbox_outlined,
      title: 'No pickups assigned right now',
      subtitle: 'Pull down to refresh',
      tint: AppColors.tertiary,
    );
  }
}
