// lib/screens/courier/rider_history_screen.dart
//
// "Delivery history": the rider's finished deliveries from WebZefanya
// (GET /api/rider/history), each with its credited pay and, when one was
// submitted, the proof-of-delivery photo (GET /api/rider/proofs/{id}, private
// to this rider). Live mode only; mock mode keeps the Earnings tab's list.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/api/api_exception.dart';
import '../../data/dto/remote_courier.dart';
import '../../state/courier_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/microcopy.dart';
import '../../utils/money_format.dart';
import '../../widgets/empty_state.dart';

class RiderHistoryScreen extends StatefulWidget {
  const RiderHistoryScreen({super.key});

  @override
  State<RiderHistoryScreen> createState() => _RiderHistoryScreenState();
}

class _RiderHistoryScreenState extends State<RiderHistoryScreen> {
  bool _loading = false;
  bool _recentOnly = false;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    if (_loading) return;
    setState(() => _loading = true);
    try {
      await context.read<CourierState>().refreshHistory();
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final courier = context.watch<CourierState>();
    final history = courier.history ?? const <RiderHistoryEntry>[];
    final failure = courier.historyFailure;
    final now = DateTime.now();
    final earliest = DateTime(now.year, now.month, now.day - 6);
    final visible = _recentOnly
        ? history
              .where(
                (entry) =>
                    entry.deliveredAt != null &&
                    !entry.deliveredAt!.isBefore(earliest),
              )
              .toList()
        : history;

    return Scaffold(
      appBar: AppBar(title: const Text('Delivery history')),
      body: _loading && courier.history == null
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _refresh,
              child: ListView.builder(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
                itemCount: visible.isEmpty ? 2 : visible.length + 1,
                itemBuilder: (context, index) {
                  if (index == 0) {
                    return Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: Wrap(
                        spacing: 8,
                        runSpacing: 6,
                        children: [
                          ChoiceChip(
                            label: const Text('All shown'),
                            selected: !_recentOnly,
                            onSelected: (_) =>
                                setState(() => _recentOnly = false),
                          ),
                          ChoiceChip(
                            label: const Text('Last 7 days'),
                            selected: _recentOnly,
                            onSelected: (_) =>
                                setState(() => _recentOnly = true),
                          ),
                        ],
                      ),
                    );
                  }
                  if (visible.isEmpty) {
                    return EmptyState(
                      icon: failure == null
                          ? Icons.history
                          : Icons.cloud_off_outlined,
                      title: failure != null
                          ? 'We couldn\'t load your history'
                          : _recentOnly
                          ? 'No deliveries in the last 7 days'
                          : 'No finished deliveries yet',
                      subtitle: failure == null
                          ? 'Delivered and returned parcels appear here.'
                          : Microcopy.calmError(failure.message),
                      tint: AppColors.tertiary,
                      action: failure?.isRetryable == true
                          ? TextButton(
                              onPressed: _refresh,
                              child: const Text('Retry'),
                            )
                          : null,
                    );
                  }
                  return _HistoryTile(entry: visible[index - 1]);
                },
              ),
            ),
    );
  }
}

class _HistoryTile extends StatelessWidget {
  const _HistoryTile({required this.entry});

  final RiderHistoryEntry entry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final when = entry.deliveredAt;
    final earning = entry.earning;

    return Container(
      key: ValueKey('rider-history-${entry.sellerOrderId}'),
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        border: Border.all(color: AppColors.divider),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            entry.returned ? 'Returned' : entry.statusLabel,
            style: AppType.eyebrow,
          ),
          const SizedBox(height: 4),
          Text(
            entry.trackingNumber ?? 'Parcel ${entry.sellerOrderId}',
            style: theme.textTheme.titleSmall,
          ),
          Text(
            [
              if ((entry.sellerName ?? '').isNotEmpty) entry.sellerName!,
              if (when != null)
                '${when.year}-${when.month.toString().padLeft(2, '0')}-${when.day.toString().padLeft(2, '0')}',
            ].join(' · '),
            style: theme.textTheme.bodySmall,
          ),
          Wrap(
            spacing: 10,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              if (earning != null)
                Text(
                  'Earned ${formatPeso(earning, showDecimals: true)}',
                  style: theme.textTheme.bodyMedium,
                ),
              if (entry.proofId != null)
                TextButton(
                  key: ValueKey('view-proof-${entry.proofId}'),
                  style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
                  onPressed: () => showProofPhoto(context, entry.proofId!),
                  child: const Text('View proof'),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Opens the rider's own proof-of-delivery photo, fetched with their token.
Future<void> showProofPhoto(BuildContext context, String proofId) {
  final courier = context.read<CourierState>();
  final photo = courier.proofPhoto(proofId);
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    builder: (sheetContext) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: FutureBuilder(
          future: photo,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const SizedBox(
                height: 240,
                child: Center(child: CircularProgressIndicator()),
              );
            }
            final error = snapshot.error;
            if (error != null || snapshot.data == null) {
              return SizedBox(
                height: 160,
                child: Center(
                  child: Text(
                    error is ApiException
                        ? Microcopy.calmError(error.message)
                        : 'The photo could not be loaded.',
                    textAlign: TextAlign.center,
                  ),
                ),
              );
            }
            return Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('PROOF OF DELIVERY', style: AppType.eyebrow),
                const SizedBox(height: 10),
                ClipRRect(
                  borderRadius: BorderRadius.circular(12),
                  child: ConstrainedBox(
                    constraints: BoxConstraints(
                      maxHeight: MediaQuery.sizeOf(context).height * .60,
                    ),
                    child: Image.memory(
                      snapshot.data!,
                      key: ValueKey('proof-photo-$proofId'),
                      fit: BoxFit.contain,
                      errorBuilder: (_, _, _) =>
                          const Text('This photo cannot be shown.'),
                    ),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    ),
  );
}
