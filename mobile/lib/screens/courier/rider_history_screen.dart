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
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    setState(() => _loading = true);
    await context.read<CourierState>().refreshHistory();
    if (mounted) setState(() => _loading = false);
  }

  @override
  Widget build(BuildContext context) {
    final courier = context.watch<CourierState>();
    final history = courier.history ?? const <RiderHistoryEntry>[];
    final failure = courier.historyFailure;

    return Scaffold(
      appBar: AppBar(title: const Text('Delivery history')),
      body: _loading && courier.history == null
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _refresh,
              child: history.isEmpty
                  ? ListView(
                      children: [
                        const SizedBox(height: 60),
                        EmptyState(
                          icon: failure == null ? Icons.history : Icons.cloud_off_outlined,
                          title: failure == null ? 'No finished deliveries yet' : 'We couldn\'t load your history',
                          subtitle: failure == null
                              ? 'Delivered and returned parcels appear here.'
                              : Microcopy.calmError(failure.message),
                          tint: AppColors.tertiary,
                        ),
                      ],
                    )
                  : ListView.separated(
                      padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
                      itemCount: history.length,
                      separatorBuilder: (_, _) => Divider(height: 1, color: AppColors.divider),
                      itemBuilder: (context, i) => _HistoryTile(entry: history[i]),
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

    return ListTile(
      key: ValueKey('rider-history-${entry.sellerOrderId}'),
      contentPadding: const EdgeInsets.symmetric(vertical: 4),
      title: Text(entry.trackingNumber ?? 'Parcel ${entry.sellerOrderId}', style: theme.textTheme.titleSmall),
      subtitle: Text(
        [
          entry.returned ? 'Returned' : entry.statusLabel,
          if ((entry.sellerName ?? '').isNotEmpty) entry.sellerName!,
          if (when != null) '${when.year}-${when.month.toString().padLeft(2, '0')}-${when.day.toString().padLeft(2, '0')}',
        ].join(' · '),
        style: theme.textTheme.bodySmall,
      ),
      trailing: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          if (earning != null) Text(formatPeso(earning, showDecimals: true), style: theme.textTheme.bodyMedium),
          if (entry.proofId != null)
            TextButton(
              key: ValueKey('view-proof-${entry.proofId}'),
              style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(0, 28)),
              onPressed: () => showProofPhoto(context, entry.proofId!),
              child: const Text('View proof'),
            ),
        ],
      ),
    );
  }
}

/// Opens the rider's own proof-of-delivery photo, fetched with their token.
Future<void> showProofPhoto(BuildContext context, String proofId) {
  final courier = context.read<CourierState>();
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    builder: (sheetContext) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: FutureBuilder(
          future: courier.proofPhoto(proofId),
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const SizedBox(height: 240, child: Center(child: CircularProgressIndicator()));
            }
            final error = snapshot.error;
            if (error != null || snapshot.data == null) {
              return SizedBox(
                height: 160,
                child: Center(
                  child: Text(
                    error is ApiException ? Microcopy.calmError(error.message) : 'The photo could not be loaded.',
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
                  child: Image.memory(
                    snapshot.data!,
                    key: ValueKey('proof-photo-$proofId'),
                    fit: BoxFit.contain,
                    errorBuilder: (_, _, _) => const Text('This photo cannot be shown.'),
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
