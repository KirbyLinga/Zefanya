// lib/screens/courier/pickup_dashboard_screen.dart
//
// "Items for Pickup" — jobs the Logistics/Sorting Center has assigned
// to this courier that haven't been picked up from the seller yet.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/api/api_exception.dart';
import '../../data/dto/remote_courier.dart';
import '../../data/mock_data.dart' show DeliveryJob;
import '../../utils/money_format.dart';
import '../../state/courier_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/microcopy.dart';
import '../../utils/timed_snackbar.dart';
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
    try {
      await context.read<CourierState>().refreshJobs();
    } on ApiException {
      // loadFailure is set by refreshJobs; _buildEmptyState reads it.
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
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
                          trailing: _AvailabilityToggle(),
                          padding: EdgeInsets.fromLTRB(20, 12, 20, 16),
                        ),
                        const SizedBox(height: 40),
                        _buildEmptyState(context),
                      ],
                    )
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(20, 0, 20, 120),
                      children: _pickupList(context, jobs),
                    ),
            ),
    );
  }

  /// The header, then each seller stop (the server's `PickupSession`) with its
  /// parcels, then any parcel that belongs to no stop. Counts on the stop
  /// header are the server's, not a tally of the cards.
  List<Widget> _pickupList(BuildContext context, List<AssignedJob> jobs) {
    final courier = context.watch<CourierState>();
    final children = <Widget>[
      const EaseInFrame(
        index: 0,
        child: EditorialHeader(
          eyebrow: 'FOR PICKUP',
          title: 'Assigned to You',
          trailing: _AvailabilityToggle(),
          padding: EdgeInsets.fromLTRB(0, 12, 0, 16),
        ),
      ),
    ];
    var index = 0;

    void addCard(AssignedJob job) {
      final i = index++;
      children.add(
        EaseInFrame(
          index: i.clamp(0, 5),
          child: DeliveryJobCard(
            assignedJob: job,
            index: i,
            onTap: () => Navigator.of(context).push(
              AppPageRoute(builder: (_) => DeliveryTrackerScreen(jobId: job.job.id)),
            ),
          ),
        ),
      );
      if (courier.canReportPickupFailure &&
          job.job.serverStatus == 'READY_FOR_PICKUP') {
        children.add(_PickupFailureButton(job: job.job));
      }
    }

    final grouped = <String, List<AssignedJob>>{};
    final loose = <AssignedJob>[];
    for (final job in jobs) {
      final stop = courier.stopFor(job.job);
      if (stop == null) {
        loose.add(job);
      } else {
        grouped.putIfAbsent(stop.id, () => []).add(job);
      }
    }

    for (final stop in courier.pickupStops) {
      final members = grouped[stop.id];
      if (members == null || members.isEmpty) continue;
      children.add(_StopHeader(stop: stop));
      members.forEach(addCard);
    }
    loose.forEach(addCard);
    return children;
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
        title: 'We couldn\'t load your pickups',
        subtitle: Microcopy.calmError(failure.message),
        action: failure.isRetryable
            ? TextButton(onPressed: () => _retry(courier), child: const Text('Retry'))
            : null,
        tint: AppColors.tertiary,
      );
    }

    return EmptyState(
      icon: Icons.inbox_outlined,
      title: Microcopy.pickupsEmptyTitle,
      subtitle: Microcopy.pickupsEmptySubtitle,
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

/// The Online/Offline control the rider uses to start and stop receiving work.
///
/// Going offline is never blocked — a job already picked up stays with the
/// rider — while going online is refused for a suspended account with the
/// reason shown inline. The switch mirrors [CourierState] rather than holding
/// its own bool, so the dashboard and the profile cannot disagree.
class _AvailabilityToggle extends StatelessWidget {
  const _AvailabilityToggle();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final courier = context.watch<CourierState>();
    final suspended = courier.isSuspended;
    final busy = courier.availabilityBusy;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              courier.isOnline ? 'ONLINE' : 'OFFLINE',
              style: AppType.eyebrow.copyWith(
                color: courier.isOnline
                    ? AppColors.tertiary
                    : AppColors.textSecondary,
              ),
            ),
            const SizedBox(width: 6),
            Switch(
              key: const ValueKey('availability-switch'),
              value: courier.isOnline,
              // Disabled while the server has not answered, so a second tap
              // cannot send a second request.
              onChanged: suspended || busy
                  ? null
                  : (value) async {
                      final problem = await courier.setOnline(value);
                      if (problem != null && context.mounted) {
                        showTimedSnackBar(
                          context,
                          SnackBar(content: Text(Microcopy.calmError(problem))),
                        );
                      }
                    },
            ),
          ],
        ),
        if (suspended)
          Text(
            courier.suspensionReason ?? 'Account suspended',
            style: theme.textTheme.labelSmall?.copyWith(
              color: AppColors.danger,
            ),
          ),
      ],
    );
  }
}

/// A seller stop: who, how many collected and left (server counts), what it
/// has paid so far, and the Finish Stop action the server allows once at
/// least one parcel is collected.
class _StopHeader extends StatelessWidget {
  const _StopHeader({required this.stop});

  final PickupStop stop;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final courier = context.watch<CourierState>();
    final busy = courier.isPickupActionBusy(stopId: stop.id);

    return Container(
      key: ValueKey('pickup-stop-${stop.id}'),
      margin: const EdgeInsets.only(top: 8, bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('STOP', style: AppType.eyebrow),
          const SizedBox(height: 4),
          Text(stop.sellerName, style: theme.textTheme.titleMedium),
          if ((stop.pickupAddress ?? '').isNotEmpty)
            Text(stop.pickupAddress!, style: theme.textTheme.bodySmall),
          const SizedBox(height: 8),
          Text(
            'Collected ${stop.collected} of ${stop.total} · ${stop.remaining} remaining',
            key: ValueKey('pickup-stop-count-${stop.id}'),
            style: theme.textTheme.bodyMedium,
          ),
          Text(
            'Earned ${formatPeso(stop.earned)} so far',
            style: theme.textTheme.bodySmall?.copyWith(color: AppColors.textSecondary),
          ),
          if (stop.canFinish) ...[
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton(
                key: ValueKey('finish-stop-${stop.id}'),
                onPressed: busy ? null : () => _finish(context, courier),
                child: Text(busy ? 'Finishing…' : 'Finish Stop'),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Future<void> _finish(BuildContext context, CourierState courier) async {
    final remaining = stop.remaining;
    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      builder: (sheetContext) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('FINISH STOP', style: AppType.eyebrow),
              const SizedBox(height: 8),
              Text(
                remaining > 0
                    ? '$remaining parcel${remaining == 1 ? '' : 's'} not collected will stay assigned to you for the next visit.'
                    : 'Every parcel at this stop is collected.',
              ),
              const SizedBox(height: 16),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  key: const ValueKey('finish-stop-confirm'),
                  onPressed: () => Navigator.of(sheetContext).pop(true),
                  child: const Text('Finish Stop'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
    if (confirmed != true || !context.mounted) return;

    try {
      final finished = await courier.finishPickupStop(stop.id);
      if (finished == null || !context.mounted) return;
      showTimedSnackBar(
        context,
        const SnackBar(content: Text('Stop finished. Parcels not collected stay assigned to you.')),
      );
    } on ApiException catch (error) {
      if (!context.mounted) return;
      showTimedSnackBar(
        context,
        SnackBar(content: Text(Microcopy.calmError(error.message))),
      );
    }
  }
}

/// "Report failed pickup" under a parcel still waiting at the seller.
class _PickupFailureButton extends StatelessWidget {
  const _PickupFailureButton({required this.job});

  final DeliveryJob job;

  @override
  Widget build(BuildContext context) {
    final courier = context.watch<CourierState>();
    final busy = courier.isPickupActionBusy(jobId: job.id);

    return Align(
      alignment: Alignment.centerRight,
      child: Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextButton.icon(
          key: ValueKey('report-failed-pickup-${job.id}'),
          onPressed: busy ? null : () => _openSheet(context),
          icon: const Icon(Icons.report_gmailerrorred_outlined, size: 18),
          label: Text(busy ? 'Sending…' : 'Report failed pickup'),
          style: TextButton.styleFrom(foregroundColor: AppColors.danger),
        ),
      ),
    );
  }

  Future<void> _openSheet(BuildContext context) async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (_) => ChangeNotifierProvider.value(
        value: context.read<CourierState>(),
        child: _PickupFailureSheet(job: job, hostContext: context),
      ),
    );
  }
}

class _PickupFailureSheet extends StatefulWidget {
  const _PickupFailureSheet({required this.job, required this.hostContext});

  final DeliveryJob job;

  /// The dashboard's context, for the result message after the sheet closes.
  final BuildContext hostContext;

  @override
  State<_PickupFailureSheet> createState() => _PickupFailureSheetState();
}

class _PickupFailureSheetState extends State<_PickupFailureSheet> {
  String? _reason;
  final _notes = TextEditingController();
  bool _sending = false;
  String? _error;

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final courier = context.watch<CourierState>();
    final reasons = courier.pickupFailureReasons;
    final attempt = widget.job.pickupAttempts + 1;
    final finalAttempt = attempt >= courier.maxPickupAttempts;

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(20, 16, 20, 20 + MediaQuery.viewInsetsOf(context).bottom),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('FAILED PICKUP', style: AppType.eyebrow),
              const SizedBox(height: 6),
              Text(widget.job.shopName, style: theme.textTheme.titleMedium),
              const SizedBox(height: 4),
              Text(
                'Attempt $attempt of ${courier.maxPickupAttempts}',
                key: const ValueKey('pickup-failure-attempt'),
                style: theme.textTheme.bodySmall,
              ),
              if (finalAttempt) ...[
                const SizedBox(height: 8),
                Text(
                  'This is the last allowed attempt. If the server records it, the order is cancelled and the stock goes back to the seller.',
                  key: const ValueKey('pickup-failure-final-warning'),
                  style: theme.textTheme.bodySmall?.copyWith(color: AppColors.danger),
                ),
              ],
              const SizedBox(height: 12),
              if (reasons.isEmpty)
                const Text('Pull down to refresh your pickups, then try again.')
              else
                RadioGroup<String>(
                  groupValue: _reason,
                  onChanged: _sending ? (_) {} : (value) => setState(() => _reason = value),
                  child: Column(
                    children: [
                      for (final reason in reasons)
                        RadioListTile<String>(
                          key: ValueKey('pickup-failure-reason-${reason.value}'),
                          value: reason.value,
                          title: Text(reason.label),
                          contentPadding: EdgeInsets.zero,
                        ),
                    ],
                  ),
                ),
              TextField(
                key: const ValueKey('pickup-failure-notes'),
                controller: _notes,
                enabled: !_sending,
                maxLength: 1000,
                maxLines: 2,
                decoration: const InputDecoration(labelText: 'Notes (optional)'),
              ),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(_error!, style: theme.textTheme.bodySmall?.copyWith(color: AppColors.danger)),
                ),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  key: const ValueKey('pickup-failure-submit'),
                  onPressed: _reason == null || _sending ? null : _submit,
                  child: Text(_sending ? 'Sending…' : 'Report failed pickup'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _submit() async {
    final reason = _reason;
    if (reason == null || _sending) return;
    setState(() {
      _sending = true;
      _error = null;
    });
    final courier = context.read<CourierState>();
    try {
      final result = await courier.reportPickupFailure(
        widget.job.id,
        reason: reason,
        notes: _notes.text,
      );
      if (!mounted) return;
      Navigator.of(context).pop();
      if (result == null || !widget.hostContext.mounted) return;
      showTimedSnackBar(
        widget.hostContext,
        SnackBar(
          content: Text(
            result.cancelled
                ? 'Failed pickup recorded. The server cancelled this order.'
                : 'Failed pickup recorded. You can retry this pickup ${result.attemptsRemaining} more time${result.attemptsRemaining == 1 ? '' : 's'}.',
          ),
        ),
      );
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _sending = false;
        _error = Microcopy.calmError(error.message);
      });
    }
  }
}
