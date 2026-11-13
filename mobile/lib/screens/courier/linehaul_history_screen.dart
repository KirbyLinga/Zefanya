import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../data/api/api_exception.dart';
import '../../data/repositories/linehaul_repository.dart';
import '../../data/repositories/linehaul_history.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/inline_error_notice.dart';

class LinehaulHistoryScreen extends StatefulWidget {
  const LinehaulHistoryScreen({
    super.key,
    required this.repository,
    this.embedded = false,
    this.tripBuilder,
  });
  final LinehaulRepository repository;
  final bool embedded;
  final Widget Function(LinehaulTrip)? tripBuilder;
  @override
  State<LinehaulHistoryScreen> createState() => LinehaulHistoryScreenState();
}

class LinehaulHistoryScreenState extends State<LinehaulHistoryScreen> {
  late DateTime _from, _to;
  bool _busy = false;
  String? _error;
  LinehaulHistoryPage? _page;
  int _requestedPage = 1;
  Future<void> refresh() =>
      _busy ? Future.value() : _load(page: _requestedPage, retain: true);
  static DateTime _today() {
    final now = DateTime.now().toUtc().add(const Duration(hours: 8));
    return DateTime(now.year, now.month, now.day);
  }

  @override
  void initState() {
    super.initState();
    _to = _today();
    _from = _to.subtract(const Duration(days: 29));
    _load();
  }

  Future<void> _load({int page = 1, bool retain = false}) async {
    setState(() {
      _requestedPage = page;
      _busy = true;
      _error = null;
      if (!retain) _page = null;
    });
    try {
      final response = await widget.repository.history({
        'from': DateFormat('yyyy-MM-dd').format(_from),
        'to': DateFormat('yyyy-MM-dd').format(_to),
        'page': '$page',
      });
      final data = response['data'];
      if (data is! Map) throw const MalformedResponseException();
      final result = LinehaulHistoryPage(data.cast<String, Object?>());
      if (mounted) setState(() => _page = result);
    } on ApiException catch (error) {
      if (mounted) {
        setState(
          () => _error =
              error is ValidationException && error.fieldErrors.isNotEmpty
              ? error.fieldErrors.values.join('\n')
              : error.message,
        );
      }
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'Could not load history. Please try again.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _chooseDates() async {
    final range = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2000),
      lastDate: _today(),
      initialDateRange: DateTimeRange(start: _from, end: _to),
    );
    if (range == null || !mounted) return;
    _from = range.start;
    _to = range.end;
    _load();
  }

  Widget _row(LinehaulHistoryEntry entry) {
    final trip = entry.trip;
    if (widget.tripBuilder != null) {
      final recorded = DateFormat.yMMMd().add_jm().format(
        entry.recordedAt.toUtc().add(const Duration(hours: 8)),
      );
      return Card(
        child: ExpansionTile(
          key: PageStorageKey(entry.key),
          title: Text('Trip #${trip.id}'),
          subtitle: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('${trip.origin} → ${trip.destination}'),
              Text(trip.status, style: Theme.of(context).textTheme.labelLarge),
              Text(
                '$recorded Philippine time',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ),
          children: [widget.tripBuilder!(trip)],
        ),
      );
    }
    final pay = trip.tripPay;
    return Card(
      key: ValueKey(entry.key),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Trip #${trip.id} · ${trip.status}',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            Text(
              'Recorded ${DateFormat.yMMMd().add_jm().format(entry.recordedAt.toUtc().add(const Duration(hours: 8)))} Philippine time',
            ),
            const SizedBox(height: 8),
            Text('${trip.origin} → ${trip.destination}'),
            if (trip.originAddress != null) Text('From: ${trip.originAddress}'),
            if (trip.destinationAddress != null)
              Text('To: ${trip.destinationAddress}'),
            Text('${trip.parcelCount ?? trip.items.length} parcels'),
            if (trip.declineReason != null)
              Text('Decline reason: ${trip.declineReason}'),
            if (trip.reassignmentReason != null)
              Text('Reassigned by Logistics: ${trip.reassignmentReason}'),
            if (pay != null)
              Text(
                pay.status == 'not_configured'
                    ? 'Trip pay rate not configured'
                    : '${pay.status == 'earned'
                          ? 'Earned trip pay'
                          : pay.status == 'ineligible'
                          ? 'No trip pay'
                          : 'Trip pay pending handover'}: ${NumberFormat.currency(symbol: '₱', decimalDigits: 2).format(pay.amountMinor! / 100)}',
              ),
            if (trip.items.isNotEmpty)
              ExpansionTile(
                tilePadding: EdgeInsets.zero,
                title: const Text('Parcel details'),
                children: [
                  for (final parcel in trip.items)
                    ListTile(title: Text(parcel.tracking)),
                ],
              ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;
    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: widget.embedded
          ? null
          : AppBar(title: const Text('History by date')),
      body: RefreshIndicator(
        onRefresh: refresh,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: EdgeInsets.fromLTRB(20, 20, 20, widget.embedded ? 120 : 20),
          children: [
            const EditorialHeader(
              eyebrow: 'EVERY HANDOVER',
              title: 'Trip history',
              padding: EdgeInsets.only(bottom: 12),
            ),
            Wrap(
              spacing: 8,
              children: [
                OutlinedButton(
                  onPressed: _busy ? null : _chooseDates,
                  child: const Text('Choose dates'),
                ),
                TextButton(
                  onPressed: _busy
                      ? null
                      : () {
                          _from = _to = _today();
                          _load();
                        },
                  child: const Text('Today'),
                ),
                TextButton(
                  onPressed: _busy
                      ? null
                      : () {
                          _to = _today();
                          _from = _to.subtract(const Duration(days: 29));
                          _load();
                        },
                  child: const Text('Last 30 days'),
                ),
              ],
            ),
            Text(
              '${DateFormat.yMMMd().format(_from)} – ${DateFormat.yMMMd().format(_to)} · Philippine time',
            ),
            const SizedBox(height: 8),
            if (_busy)
              const LinearProgressIndicator(
                semanticsLabel: 'Loading trip history',
              ),
            if (_error != null) ...[
              InlineErrorNotice(_error!),
              if (page != null)
                const Text('Showing the last loaded history for these dates.'),
              TextButton(
                onPressed: _busy ? null : refresh,
                child: const Text('Retry'),
              ),
            ],
            if (page != null) ...[
              Text('${page.total} history records in this range'),
              if (page.rows.isEmpty)
                const Padding(
                  padding: EdgeInsets.all(16),
                  child: Text('No history in this date range.'),
                ),
              for (final row in page.rows) _row(row),
              Wrap(
                spacing: 12,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  TextButton(
                    onPressed: !_busy && page.page > 1
                        ? () => _load(page: page.page - 1)
                        : null,
                    child: const Text('Previous'),
                  ),
                  Text('Page ${page.page} of ${page.lastPage}'),
                  TextButton(
                    onPressed: !_busy && page.page < page.lastPage
                        ? () => _load(page: page.page + 1)
                        : null,
                    child: const Text('Next'),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}
