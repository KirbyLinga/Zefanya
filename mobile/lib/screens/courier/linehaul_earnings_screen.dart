import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../data/api/api_exception.dart';
import '../../data/repositories/linehaul_repository.dart';
import '../../data/repositories/linehaul_earnings.dart';
import '../../widgets/editorial_header.dart';

class LinehaulEarningsScreen extends StatefulWidget {
  const LinehaulEarningsScreen({
    super.key,
    required this.repository,
    this.embedded = false,
  });
  final LinehaulRepository repository;
  final bool embedded;
  @override
  State<LinehaulEarningsScreen> createState() => _LinehaulEarningsScreenState();
}

class _LinehaulEarningsScreenState extends State<LinehaulEarningsScreen> {
  late DateTime _from, _to;
  LinehaulEarnings? _report;
  bool _busy = false;
  String? _error;
  static DateTime _today() {
    final now = DateTime.now().toUtc().add(const Duration(hours: 8));
    return DateTime(now.year, now.month, now.day);
  }

  @override
  void initState() {
    super.initState();
    _from = _to = _today();
    _load();
  }

  Future<void> _load({int page = 1}) async {
    setState(() {
      _busy = true;
      _error = null;
      _report = null;
    });
    try {
      final response = await widget.repository.client.getJson(
        '/courier/earnings',
        query: {
          'from': DateFormat('yyyy-MM-dd').format(_from),
          'to': DateFormat('yyyy-MM-dd').format(_to),
          'page': '$page',
        },
      );
      final raw = response['data'];
      if (raw is! Map) throw const MalformedResponseException();
      final report = LinehaulEarnings(raw.cast<String, Object?>());
      if (mounted) setState(() => _report = report);
    } on ApiException catch (error) {
      if (mounted) {
        setState(
          () => _error = error is ValidationException
              ? error.fieldErrors.values.join('\n')
              : error.message,
        );
      }
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'Could not load earnings. Please try again.');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _preset(bool week) {
    _to = _today();
    _from = week ? _to.subtract(Duration(days: _to.weekday - 1)) : _to;
    _load();
  }

  Future<void> _range() async {
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

  String money(int minor) =>
      NumberFormat.currency(symbol: '₱', decimalDigits: 2).format(minor / 100);
  @override
  Widget build(BuildContext context) {
    final report = _report;
    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: widget.embedded
          ? null
          : AppBar(title: const Text('Courier earnings')),
      body: RefreshIndicator(
        onRefresh: () => _busy ? Future.value() : _load(),
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: EdgeInsets.fromLTRB(20, 20, 20, widget.embedded ? 120 : 20),
          children: [
            const EditorialHeader(
              eyebrow: 'YOUR JOURNEYS',
              title: 'Your earnings',
              padding: EdgeInsets.only(bottom: 12),
            ),
            const SizedBox(height: 8),
            const Text(
              'Completed handovers by Philippine date. Payout recording is not available.',
            ),
            const SizedBox(height: 16),
            Wrap(
              spacing: 8,
              children: [
                OutlinedButton(
                  onPressed: _busy ? null : () => _preset(false),
                  child: const Text('Today'),
                ),
                OutlinedButton(
                  onPressed: _busy ? null : () => _preset(true),
                  child: const Text('This week'),
                ),
                OutlinedButton(
                  onPressed: _busy ? null : _range,
                  child: const Text('Choose dates'),
                ),
              ],
            ),
            Text(
              '${DateFormat.yMMMd().format(_from)} – ${DateFormat.yMMMd().format(_to)} · Philippine time',
            ),
            const SizedBox(height: 20),
            if (_busy) const Center(child: CircularProgressIndicator()),
            if (_error != null) ...[
              Text(_error!),
              TextButton(
                onPressed: _busy ? null : () => _load(),
                child: const Text('Retry'),
              ),
            ],
            if (report != null) ...[
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Confirmed earned pay',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      Text(
                        money(report.earnedMinor),
                        style: Theme.of(context).textTheme.headlineMedium,
                      ),
                      Text(
                        '${report.earnedTrips} confirmed of ${report.completedTrips} completed handovers',
                      ),
                      if (report.unconfirmedTrips > 0)
                        Text(
                          '${report.unconfirmedTrips} trips have unconfigured or unconfirmed pay and are excluded from this amount.',
                        ),
                      const Text(
                        'Earned pay is separate from money paid to you.',
                      ),
                    ],
                  ),
                ),
              ),
              if (report.trips.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 20),
                  child: Text('No completed handovers in this range.'),
                ),
              for (final trip in report.trips)
                Card(
                  child: ListTile(
                    title: Text('Trip #${trip.tripId} · ${trip.result}'),
                    subtitle: Text(
                      '${DateFormat.yMMMd().add_jm().format(trip.completedAt.toUtc().add(const Duration(hours: 8)))} Philippine time\n${trip.pay.status == 'earned' ? 'Earned: ${money(trip.pay.amountMinor!)}' : 'Pay not confirmed · excluded from total'}',
                    ),
                  ),
                ),
              Wrap(
                spacing: 12,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  TextButton(
                    onPressed: report.page > 1
                        ? () => _load(page: report.page - 1)
                        : null,
                    child: const Text('Previous'),
                  ),
                  Text('Page ${report.page} of ${report.lastPage}'),
                  TextButton(
                    onPressed: report.page < report.lastPage
                        ? () => _load(page: report.page + 1)
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
