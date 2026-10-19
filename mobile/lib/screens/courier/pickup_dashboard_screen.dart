// lib/screens/courier/pickup_dashboard_screen.dart
//
// "Items for Pickup" — jobs the Logistics/Sorting Center has assigned
// to this courier that haven't been picked up from the seller yet.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/api/api_exception.dart';
import '../../state/courier_state.dart';
import '../../theme/app_theme.dart';
import 'delivery_tracker_screen.dart';
import 'widgets/delivery_job_card.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/shimmer_box.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/motion/ease_in_frame.dart';

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
    AppColors.watch(context);
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
                          return const EaseInFrame(
                            index: 0,
                            child: EditorialHeader(
                              eyebrow: 'FOR PICKUP',
                              title: 'Assigned to You',
                              padding: EdgeInsets.fromLTRB(0, 12, 0, 16),
                            ),
                          );
                        }
                        final job = jobs[i - 1];
                        return EaseInFrame(
                          index: (i - 1).clamp(0, 5),
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
          decoration: BoxDecoration(color: AppColors.surfaceMuted, borderRadius: editorialRadius(i, ShapeTier.card)),
        ),
      ),
    );
  }

  /// The empty state says which *kind* of empty this is — see the note on
  /// [CourierState.loadFailure]. "Pull down to refresh" is only honest when
  /// nothing actually failed.
  Widget _buildEmptyState(BuildContext context) {
    final courier = context.watch<CourierState>();
    final failure = courier.loadFailure;

    if (failure != null) {
      return EmptyState(
        icon: Icons.cloud_off_outlined,
        title: 'Your pickups could not be loaded',
        subtitle: failure.message,
        action: failure.isRetryable
            ? TextButton(onPressed: () => _retry(courier), child: const Text('Retry'))
            : null,
        tint: AppColors.tertiary,
      );
    }

    return EmptyState(
      icon: Icons.inbox_outlined,
      title: 'No pickups assigned right now',
      subtitle: 'Pull down to refresh',
      tint: AppColors.tertiary,
    );
  }

  /// Re-reads the dispatcher's jobs. The failure is not handled here on purpose:
  /// the state keeps it and this screen rebuilds from `loadFailure`, so catching
  /// it again would swallow the same error twice.
  Future<void> _retry(CourierState courier) async {
    try {
      await courier.refreshJobs();
    } on ApiException {
      // Rendered above, from the state.
    }
  }
}
