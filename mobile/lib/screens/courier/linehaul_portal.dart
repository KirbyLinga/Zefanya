import 'dart:async';
import 'package:intl/intl.dart';
import 'package:flutter/material.dart';
import 'package:app_settings/app_settings.dart';
import 'package:provider/provider.dart';
import '../../data/api/api_exception.dart'
    show ApiNotConfiguredException, ValidationException;
import '../../data/repositories/linehaul_repository.dart';
import '../../data/repositories/repositories.dart';
import '../../state/auth_state.dart';
import '../../services/courier_location_service.dart';
import '../../services/courier_directions.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/floating_nav_bar.dart';
import '../../widgets/nav_icons.dart';
import '../../widgets/zefanya_mark.dart';
import '../../utils/timed_snackbar.dart';
import 'courier_qr_scanner.dart';
import 'linehaul_profile_screen.dart';
import 'linehaul_delay_dialog.dart';
import 'linehaul_earnings_screen.dart';
import 'linehaul_history_screen.dart';
import '../shared/settings_screen.dart';
import '../auth/login_screen.dart';

class LinehaulLoginScreen extends StatelessWidget {
  const LinehaulLoginScreen({super.key});
  @override
  Widget build(BuildContext context) =>
      const LoginScreen(initialRole: UserRole.linehaul);
}

class LinehaulPortal extends StatefulWidget {
  const LinehaulPortal({
    super.key,
    this.locationSource = const DeviceCourierLocationSource(),
    this.directions = const DeviceCourierDirections(),
  });
  final CourierLocationSource locationSource;
  final CourierDirections directions;
  @override
  State<LinehaulPortal> createState() => _LinehaulPortalState();
}

class _LinehaulPortalState extends State<LinehaulPortal>
    with WidgetsBindingObserver {
  late LinehaulRepository _repository;
  late Future<List<LinehaulTrip>> _trips;
  bool _busy = false;
  int _tab = 0;
  final _visitedTabs = <int>{0};
  final _historyKey = GlobalKey<LinehaulHistoryScreenState>();
  final _earningsKey = GlobalKey<LinehaulEarningsScreenState>();
  LinehaulDuty? _duty;
  String? _dutyError;
  bool _dutyLocationError = false;
  bool _dutyBusy = false;
  bool _heartbeatBusy = false;
  bool _foreground = true;
  int _dutyRevision = 0;
  Timer? _dutyTimer;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    final client = context.read<Repositories>().client;
    if (client == null) {
      _trips = Future.error(const ApiNotConfiguredException());
    } else {
      _repository = LinehaulRepository(client);
      _trips = _repository.trips();
      unawaited(_loadDuty());
    }
  }

  @override
  void dispose() {
    _dutyTimer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _foreground = state == AppLifecycleState.resumed;
    if (_foreground && context.read<Repositories>().client != null) {
      unawaited(_loadDuty());
    }
  }

  void _setDuty(LinehaulDuty duty) {
    setState(() {
      _duty = duty;
      _dutyError = null;
      _dutyLocationError = false;
    });
    if (duty.onDuty) {
      _dutyTimer ??= Timer.periodic(const Duration(minutes: 1), (_) {
        if (_foreground) unawaited(_loadDuty());
      });
    } else {
      _dutyTimer?.cancel();
      _dutyTimer = null;
    }
  }

  void _dutyFailure(String message, {required bool location}) {
    if (!mounted) return;
    setState(() {
      _dutyError = message;
      _dutyLocationError = location;
    });
  }

  Future<void> _loadDuty({bool requestPermission = false}) async {
    if (_dutyBusy || _heartbeatBusy) return;
    final revision = _dutyRevision;
    _heartbeatBusy = true;
    try {
      var duty = await _repository.duty();
      if (!mounted || revision != _dutyRevision) return;
      _setDuty(duty);
      if (duty.onDuty && _foreground) {
        final point = await widget.locationSource.current(
          requestPermission: requestPermission,
        );
        if (!mounted || !_foreground || revision != _dutyRevision) return;
        duty = await _repository.changeDuty('heartbeat', point.toJson());
        if (mounted && revision == _dutyRevision) _setDuty(duty);
      }
    } on ApiException catch (error) {
      if (mounted && revision == _dutyRevision) {
        _dutyFailure(error.message, location: false);
      }
    } on CourierLocationException catch (error) {
      if (mounted && revision == _dutyRevision) {
        _dutyFailure(error.message, location: true);
      }
    } finally {
      _heartbeatBusy = false;
    }
  }

  Future<void> _changeDuty() async {
    if (_duty == null || _duty!.setupRequired || _dutyBusy) return;
    _dutyRevision++;
    setState(() => _dutyBusy = true);
    try {
      LinehaulDuty duty;
      if (_duty!.onDuty) {
        duty = await _repository.changeDuty('off', {});
      } else {
        final point = await widget.locationSource.current();
        if (!mounted) return;
        final data = await _checklist();
        if (data == null || !mounted) return;
        duty = await _repository.changeDuty('on', {...point.toJson(), ...data});
      }
      if (mounted) _setDuty(duty);
    } on ApiException catch (error) {
      if (mounted) _dutyFailure(error.message, location: false);
    } on CourierLocationException catch (error) {
      if (mounted) _dutyFailure(error.message, location: true);
    } finally {
      if (mounted) setState(() => _dutyBusy = false);
    }
  }

  Future<Map<String, Object?>?> _checklist() async {
    final form = GlobalKey<FormState>();
    final answers = <String, bool>{};
    var note = '';
    const labels = {
      'tires': 'Tires',
      'lights': 'Lights',
      'brakes': 'Brakes',
      'fuel': 'Fuel',
      'cargo_lock': 'Cargo door lock',
    };
    return showDialog<Map<String, Object?>>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Pre-trip checklist'),
        content: SingleChildScrollView(
          child: Form(
            key: form,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                for (final entry in labels.entries)
                  DropdownButtonFormField<bool>(
                    decoration: InputDecoration(labelText: entry.value),
                    items: const [
                      DropdownMenuItem(value: true, child: Text('OK')),
                      DropdownMenuItem(value: false, child: Text('Not OK')),
                    ],
                    onChanged: (value) {
                      if (value != null) answers[entry.key] = value;
                    },
                    validator: (value) =>
                        value == null ? 'Answer this item.' : null,
                  ),
                TextFormField(
                  maxLength: 500,
                  maxLines: 3,
                  decoration: const InputDecoration(
                    labelText: 'Note for Not OK items',
                  ),
                  onChanged: (value) => note = value,
                  validator: (_) =>
                      answers.containsValue(false) && note.trim().isEmpty
                      ? 'Explain the Not OK items.'
                      : null,
                ),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () {
              if (form.currentState!.validate()) {
                Navigator.pop(dialogContext, {
                  'checklist': answers,
                  'note': note.trim(),
                });
              }
            },
            child: const Text('Submit checklist'),
          ),
        ],
      ),
    );
  }

  Widget _dutyCard() => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text('Duty & readiness', style: Theme.of(context).textTheme.titleMedium),
      const SizedBox(height: 8),
      Text(
        _dutyError != null
            ? (_duty == null
                  ? 'Duty status unavailable'
                  : 'Last known duty: ${_duty!.status}')
            : _duty?.status ?? 'Loading duty status…',
      ),
      if (_dutyError != null) Text(_dutyError!),
      if (_duty?.setupRequired == true)
        const Text('Duty setup is pending. Contact your administrator.'),
      if (_duty?.lastCheckPassed == false)
        const Text(
          'Checklist not passed. You remain Off Duty; Logistics has been notified.',
        ),
      const SizedBox(height: 8),
      const Text(
        'Keep the app open while On Duty. Location updates stop in the background; assignment readiness expires after five minutes without an update.',
      ),
      const SizedBox(height: 12),
      if (_duty != null && !_duty!.setupRequired)
        FilledButton(
          onPressed: _dutyBusy ? null : _changeDuty,
          child: Text(
            _dutyBusy
                ? 'Updating duty…'
                : _duty!.onDuty
                ? 'Go Off Duty'
                : 'Go On Duty',
          ),
        ),
      TextButton(
        onPressed: _dutyBusy ? null : () => unawaited(_loadDuty()),
        child: const Text('Refresh duty status'),
      ),
      if (_dutyLocationError && _duty?.onDuty == true)
        TextButton(
          onPressed: _dutyBusy
              ? null
              : () => unawaited(_loadDuty(requestPermission: true)),
          child: const Text('Update location'),
        ),
      if (_dutyLocationError)
        TextButton(
          onPressed: () async {
            try {
              await AppSettings.openAppSettings();
            } catch (_) {}
          },
          child: const Text('Open location settings'),
        ),
    ],
  );

  void _reload() {
    final client = context.read<Repositories>().client;
    if (client != null) {
      final trips = _repository.trips();
      // Observe immediate failures before the next frame subscribes the builder.
      // The original future still carries the error for its Retry state.
      trips.ignore();
      setState(() {
        _trips = trips;
      });
    }
  }

  Future<void> _act(
    LinehaulTrip trip,
    String action, {
    Map<String, Object?> body = const {},
  }) async {
    setState(() {
      _busy = true;
    });
    try {
      await _repository.act(trip.id, action, body: body);
      if (mounted) {
        _reload();
        unawaited(_historyKey.currentState?.refresh() ?? Future<void>.value());
      }
    } on ApiException catch (error) {
      if (mounted) {
        final message =
            action == 'decline' &&
                error is ValidationException &&
                error.fieldErrors.isNotEmpty
            ? error.fieldErrors.values.join('\n')
            : error.message;
        showTimedSnackBar(context, SnackBar(content: Text(message)));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _scan(LinehaulTrip trip) async {
    var method = 'manual';
    final value = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => CourierQrScanner(
          manualHint: 'Parcel tracking number',
          allowCode128: true,
          onInputMethod: (value) => method = value,
        ),
      ),
    );
    if (value != null && value.trim().isNotEmpty && mounted) {
      // Both camera and manual entry pass through the same server scan guard.
      await _act(
        trip,
        'scan',
        body: {'tracking_number': value.trim(), 'method': method},
      );
    }
  }

  Future<void> _decline(LinehaulTrip trip) async {
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => const _CourierDeclineDialog(),
    );
    if (reason != null && mounted) {
      await _act(
        trip,
        'decline',
        body: {'reason': reason, 'offer_version': trip.offerVersion},
      );
    }
  }

  Future<void> _depart(LinehaulTrip trip) async {
    var value = '';
    final seal = await showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Confirm departure'),
        content: TextField(
          onChanged: (text) => value = text,
          autofocus: true,
          textCapitalization: TextCapitalization.characters,
          decoration: const InputDecoration(
            labelText: 'Seal number',
            hintText: 'BH-0004521',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, value.trim()),
            child: const Text('Depart'),
          ),
        ],
      ),
    );
    if (seal != null && mounted) {
      await _act(trip, 'depart', body: {'seal_number': seal});
    }
  }

  Future<void> _reportDelay(LinehaulTrip trip) async {
    final saved = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (_) => LinehaulDelayDialog(trip: trip, repository: _repository),
    );
    if (saved == true && mounted) _reload();
  }

  Future<void> _handoverNote(LinehaulTrip trip, LinehaulParcel parcel) async {
    var value = '';
    final note = await showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Your handover note'),
        content: TextField(
          maxLength: 500,
          maxLines: 4,
          onChanged: (text) => value = text,
          decoration: const InputDecoration(
            labelText: 'What happened to this parcel?',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () {
              if (value.trim().isNotEmpty) {
                Navigator.pop(dialogContext, value.trim());
              }
            },
            child: const Text('Save note'),
          ),
        ],
      ),
    );
    if (note != null && mounted) {
      await _act(
        trip,
        'exception-note',
        body: {'tracking_number': parcel.tracking, 'note': note},
      );
    }
  }

  Widget _tripCard(LinehaulTrip trip) {
    final theme = Theme.of(context);
    final loaded = trip.items.where((item) => item.loaded).length;
    final received = trip.items.where((item) => item.received).length;
    final completed = trip.status == 'Completed';
    final declined = trip.status == 'Declined';
    final parcelLabel =
        (declined
                ? trip.parcelCount ?? 0
                : completed
                ? received
                : trip.items.length) ==
            1
        ? 'parcel'
        : 'parcels';
    final tint = completed ? AppColors.successText : AppColors.primaryDark;
    return Padding(
      padding: const EdgeInsets.only(bottom: 20),
      child: _CourierCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Wrap(
              alignment: WrapAlignment.spaceBetween,
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: 12,
              runSpacing: 10,
              children: [
                Text('Trip #${trip.id}', style: theme.textTheme.titleLarge),
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 12,
                    vertical: 7,
                  ),
                  decoration: BoxDecoration(
                    color: tint.withValues(alpha: .10),
                    borderRadius: radiusOf(ShapeTier.control),
                  ),
                  child: Text(
                    trip.status,
                    style: theme.textTheme.labelMedium?.copyWith(
                      color: tint,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 22),
            _hub(
              'FROM',
              trip.origin,
              Icons.trip_origin,
              address: trip.originAddress,
            ),
            Padding(
              padding: const EdgeInsets.only(left: 10),
              child: Align(
                alignment: Alignment.centerLeft,
                child: SizedBox(
                  width: 1,
                  height: 20,
                  child: ColoredBox(color: AppColors.divider),
                ),
              ),
            ),
            _hub(
              'TO',
              trip.destination,
              Icons.location_on_outlined,
              address: trip.destinationAddress,
            ),
            if (const [
              'Assigned',
              'Loading',
              'In Transit',
              'Arrived',
            ].contains(trip.status)) ...[
              const SizedBox(height: 16),
              if (DeviceCourierDirections.destinationUri(
                    trip.destination,
                    trip.destinationAddress ?? '',
                  ) !=
                  null)
                OutlinedButton.icon(
                  onPressed: _busy ? null : () => _navigate(trip),
                  icon: const Icon(Icons.directions_outlined),
                  label: const Text('Directions to destination'),
                )
              else
                const Text(
                  'Destination address unavailable. Contact Logistics for directions.',
                ),
              if (DeviceCourierDirections.destinationUri(
                    trip.destination,
                    trip.destinationAddress ?? '',
                  ) !=
                  null)
                Text(
                  'Opens driving directions using the saved hub address.',
                  style: theme.textTheme.bodySmall,
                ),
            ],
            const SizedBox(height: 20),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AppColors.surfaceMuted,
                borderRadius: radiusOf(ShapeTier.control),
              ),
              child: Row(
                children: [
                  Icon(
                    Icons.inventory_2_outlined,
                    size: 20,
                    color: AppColors.primaryDark,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      declined
                          ? '${trip.parcelCount ?? 0} $parcelLabel in original offer'
                          : completed
                          ? '$received $parcelLabel received'
                          : '$loaded of ${trip.items.length} $parcelLabel loaded',
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                ],
              ),
            ),
            if (!declined) ...[
              const SizedBox(height: 16),
              Text('Trip details', style: theme.textTheme.titleMedium),
              Text(
                trip.scheduledDepartureAt == null
                    ? 'Planned departure not set'
                    : 'Planned departure: ${DateFormat.yMMMd().add_jm().format(trip.scheduledDepartureAt!.toLocal())}',
              ),
              Text(
                '${trip.parcelCount ?? trip.items.length} ${trip.items.length == 1 ? 'parcel' : 'parcels'} in this batch',
              ),
              Text(
                trip.recordedWeightGrams == null
                    ? 'Parcel weight not fully recorded'
                    : 'Recorded parcel weight: ${NumberFormat('0.###').format(trip.recordedWeightGrams! / 1000)} kg',
              ),
              if (trip.acceptedAt != null)
                Text(
                  'Accepted: ${DateFormat.yMMMd().add_jm().format(trip.acceptedAt!.toLocal())}',
                ),
              if (trip.departedAt != null)
                Text(
                  'Departed: ${DateFormat.yMMMd().add_jm().format(trip.departedAt!.toLocal())}',
                ),
              if (trip.arrivedAt != null)
                Text(
                  'Arrived: ${DateFormat.yMMMd().add_jm().format(trip.arrivedAt!.toLocal())}',
                ),
            ],
            if (trip.delay != null) ...[
              const SizedBox(height: 16),
              Text(
                trip.status == 'In Transit' ? 'Delayed' : 'Last delay report',
                style: theme.textTheme.titleMedium,
              ),
              Text(
                'Courier-reported ETA: ${DateFormat.yMMMd().add_jm().format(trip.delay!.newEta.toLocal())}',
                style: theme.textTheme.bodyMedium,
              ),
              Text(trip.delay!.reason, style: theme.textTheme.bodyMedium),
              Text(
                'Reported ${DateFormat.yMMMd().add_jm().format(trip.delay!.reportedAt.toLocal())}',
                style: theme.textTheme.bodySmall,
              ),
              if (trip.status == 'In Transit' &&
                  !trip.delay!.newEta.isAfter(DateTime.now()))
                const Text('Reported ETA has passed. Update Logistics.'),
            ],
            if (trip.seal != null)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Text(
                  'Seal: ${trip.seal}',
                  style: theme.textTheme.bodySmall,
                ),
              ),
            if (trip.tripPay != null) ...[
              const SizedBox(height: 12),
              Text(
                trip.tripPay!.status == 'not_configured'
                    ? 'Trip pay rate not configured'
                    : '${switch (trip.tripPay!.status) {
                        'earned' => 'Earned trip pay',
                        'pending' => 'Trip pay after completed handover',
                        'ineligible' => 'No trip pay',
                        _ => 'Estimated trip pay',
                      }}: ${NumberFormat.currency(symbol: '₱', decimalDigits: 2).format(trip.tripPay!.amountMinor! / 100)}',
              ),
              const Text(
                'Trip pay is separate from payment. Payout recording is not available here.',
              ),
            ],
            if (!declined)
              ExpansionTile(
                tilePadding: EdgeInsets.zero,
                childrenPadding: EdgeInsets.zero,
                title: Text(
                  'Parcel details',
                  style: theme.textTheme.labelLarge,
                ),
                children: [
                  for (final item in trip.items)
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(
                        item.issue != null
                            ? Icons.warning_amber_rounded
                            : item.received
                            ? Icons.inventory_2_outlined
                            : item.loaded
                            ? Icons.check_circle_outline
                            : Icons.radio_button_unchecked,
                        color: item.issue != null
                            ? theme.colorScheme.error
                            : item.loaded
                            ? AppColors.successText
                            : AppColors.textSecondary,
                      ),
                      title: Text(
                        item.tracking,
                        style: theme.textTheme.bodySmall,
                      ),
                      subtitle: item.issue == null
                          ? null
                          : Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  item.issue == 'missing'
                                      ? 'Missing at handover · reported'
                                      : 'Received damaged · reported',
                                ),
                                if (item.courierNote != null)
                                  Text('Your note: ${item.courierNote}')
                                else
                                  TextButton(
                                    onPressed: _busy
                                        ? null
                                        : () => _handoverNote(trip, item),
                                    child: const Text('Add handover note'),
                                  ),
                              ],
                            ),
                    ),
                ],
              ),
            if (trip.status == 'Assigned')
              FilledButton(
                onPressed: _busy ? null : () => _act(trip, 'accept'),
                child: const Text('Accept trip'),
              ),
            if (trip.status == 'Assigned' && trip.canDecline)
              OutlinedButton(
                onPressed: _busy ? null : () => _decline(trip),
                child: const Text('Decline trip'),
              ),
            if (declined) ...[
              const SizedBox(height: 16),
              Text(
                'Reason: ${trip.declineReason ?? 'Decline recorded'}',
                style: theme.textTheme.bodyMedium,
              ),
              if (trip.declinedAt != null &&
                  DateTime.tryParse(trip.declinedAt!) != null)
                Text(
                  'Declined ${DateFormat.yMMMd().add_jm().format(DateTime.parse(trip.declinedAt!).toLocal())}',
                  style: theme.textTheme.bodySmall,
                ),
              const SizedBox(height: 8),
              const Text(
                'This offer was not completed. Logistics manages the next assignment.',
              ),
            ],
            if (trip.status == 'Loading') ...[
              OutlinedButton(
                onPressed: _busy ? null : () => _scan(trip),
                child: const Text('Scan or enter parcel'),
              ),
              const SizedBox(height: 8),
              FilledButton(
                onPressed: _busy ? null : () => _depart(trip),
                child: const Text('Depart'),
              ),
            ],
            if (trip.status == 'In Transit')
              FilledButton(
                onPressed: _busy ? null : () => _act(trip, 'arrive'),
                child: const Text('Report arrival'),
              ),
            if (trip.canReportDelay)
              OutlinedButton.icon(
                onPressed: _busy ? null : () => _reportDelay(trip),
                icon: const Icon(Icons.schedule_outlined),
                label: const Text('Report delay'),
              ),
            if (trip.status == 'Arrived')
              Text(
                'Awaiting parcel receipt by Logistics. Refresh after handover.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                  height: 1.6,
                ),
              ),
            if (completed)
              Text(
                trip.status == 'Received with Issues'
                    ? 'Closed by Logistics with recorded issues.'
                    : 'Handover confirmed by Logistics.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: AppColors.successText,
                ),
              ),
          ],
        ),
      ),
    );
  }

  Future<void> _navigate(LinehaulTrip trip) async {
    setState(() => _busy = true);
    try {
      final opened = await widget.directions.open(
        trip.destination,
        trip.destinationAddress!,
      );
      if (!opened && mounted) {
        showTimedSnackBar(
          context,
          const SnackBar(
            content: Text(
              'Could not open Maps. Try again or use the hub address shown above.',
            ),
          ),
        );
      }
    } catch (_) {
      if (mounted) {
        showTimedSnackBar(
          context,
          const SnackBar(
            content: Text(
              'Could not open Maps. Try again or use the hub address shown above.',
            ),
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _hub(String label, String name, IconData icon, {String? address}) =>
      Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 22, color: AppColors.primaryDark),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: AppType.eyebrow),
                const SizedBox(height: 4),
                Text(
                  name,
                  style: Theme.of(
                    context,
                  ).textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 6),
                Text(
                  address?.trim().isNotEmpty == true
                      ? address!
                      : 'Address not provided',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
                ),
              ],
            ),
          ),
        ],
      );

  Widget _account() {
    final auth = context.watch<AuthState>();
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 120),
      children: [
        const EditorialHeader(
          eyebrow: 'YOUR ZEFANYA',
          title: 'Your profile',
          padding: EdgeInsets.only(bottom: 24),
        ),
        _CourierCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AppColors.primaryDark,
                  borderRadius: radiusOf(ShapeTier.card),
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'COURIER',
                            style: AppType.eyebrow.copyWith(
                              color: AppColors.textOnDark,
                            ),
                          ),
                          Text(
                            auth.name ?? 'Courier',
                            style: Theme.of(context).textTheme.titleLarge
                                ?.copyWith(color: AppColors.textOnDark),
                          ),
                          if (auth.email != null)
                            Text(
                              auth.email!,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: Theme.of(context).textTheme.bodySmall
                                  ?.copyWith(color: AppColors.textOnDark),
                            ),
                        ],
                      ),
                    ),
                    IconButton(
                      key: const ValueKey('open-settings'),
                      tooltip: 'Settings',
                      color: AppColors.textOnDark,
                      onPressed: _busy
                          ? null
                          : () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) => const SettingsScreen(),
                              ),
                            ),
                      icon: const Icon(Icons.settings_outlined),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),
              const Divider(),
              const SizedBox(height: 12),
              const Text('Hub-to-hub parcel transfers'),
              const SizedBox(height: 20),
              OutlinedButton.icon(
                onPressed: _busy || context.read<Repositories>().client == null
                    ? null
                    : () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) =>
                              LinehaulProfileScreen(repository: _repository),
                        ),
                      ),
                icon: const Icon(Icons.badge_outlined),
                label: const Text('Company & profile'),
              ),
              const SizedBox(height: 24),
              _dutyCard(),
            ],
          ),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Scaffold(
      backgroundColor: Colors.transparent,
      extendBody: true,
      appBar: _tab == 0
          ? AppBar(
              title: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const ZefanyaMark(size: 24),
                      const SizedBox(width: 10),
                      Text('ZEFANYA COURIER', style: AppType.eyebrow),
                    ],
                  ),
                  Text(
                    'Duty: ${_dutyError != null ? 'status unavailable' : _duty?.status ?? 'checking…'}',
                    style: Theme.of(context).textTheme.labelSmall,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
              actions: [
                IconButton(
                  onPressed: _busy ? null : _reload,
                  icon: const Icon(Icons.refresh),
                  tooltip: 'Refresh trips',
                ),
              ],
            )
          : null,
      bottomNavigationBar: FloatingNavBar(
        items: const [
          NavBarItem(glyph: NavGlyph.parcel, label: 'Trips'),
          NavBarItem(glyph: NavGlyph.orders, label: 'History'),
          NavBarItem(glyph: NavGlyph.peso, label: 'Earnings'),
          NavBarItem(glyph: NavGlyph.profile, label: 'Profile'),
        ],
        currentIndex: _tab,
        onTap: (index) {
          if (index == _tab) return;
          if (index == 1) {
            unawaited(
              _historyKey.currentState?.refresh() ?? Future<void>.value(),
            );
          } else if (index == 2) {
            unawaited(
              _earningsKey.currentState?.refresh() ?? Future<void>.value(),
            );
          }
          setState(() {
            _visitedTabs.add(index);
            _tab = index;
          });
        },
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 720),
          child: IndexedStack(
            index: _tab,
            children: [
              FutureBuilder<List<LinehaulTrip>>(
                future: _trips,
                builder: (context, snapshot) {
                  if (snapshot.connectionState != ConnectionState.done) {
                    return const Center(child: ZefanyaLoader(size: 48));
                  }
                  if (snapshot.hasError) {
                    return SingleChildScrollView(
                      padding: const EdgeInsets.only(bottom: 100),
                      child: EmptyState(
                        icon: Icons.cloud_off_outlined,
                        title: 'Your trips could not be loaded',
                        subtitle: snapshot.error is ApiException
                            ? (snapshot.error as ApiException).message
                            : 'Please try again.',
                        action: OutlinedButton(
                          onPressed: _reload,
                          child: const Text('Retry'),
                        ),
                      ),
                    );
                  }
                  final all = snapshot.data ?? const <LinehaulTrip>[];
                  bool isHistory(LinehaulTrip trip) => const [
                    'Completed',
                    'Received with Issues',
                    'Cancelled',
                    'Declined',
                  ].contains(trip.status);
                  final trips = all.where((trip) => !isHistory(trip)).toList();
                  return RefreshIndicator(
                    onRefresh: () async {
                      _reload();
                      try {
                        await _trips;
                      } on ApiException {
                        // The FutureBuilder displays the server error and Retry.
                      }
                    },
                    child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.fromLTRB(20, 8, 20, 120),
                      children: [
                        EditorialHeader(
                          eyebrow: 'HUB TO HUB',
                          title: 'Your journeys',
                          padding: const EdgeInsets.only(bottom: 12),
                        ),
                        Padding(
                          padding: const EdgeInsets.only(bottom: 24),
                          child: Text(
                            'From loading to handover, one parcel at a time.',
                            style: Theme.of(context).textTheme.bodyMedium
                                ?.copyWith(
                                  color: AppColors.textSecondary,
                                  height: 1.5,
                                ),
                          ),
                        ),
                        if (_busy) const LinearProgressIndicator(),
                        if (trips.isEmpty)
                          _CourierCard(
                            child: EmptyState(
                              icon: Icons.local_shipping_outlined,
                              title: 'No trips assigned',
                              subtitle:
                                  'Your next journey will appear here when Logistics assigns a trip.',
                              action: OutlinedButton(
                                onPressed: _reload,
                                child: const Text('Refresh trips'),
                              ),
                            ),
                          ),
                        for (final trip in trips) _tripCard(trip),
                      ],
                    ),
                  );
                },
              ),
              if (_visitedTabs.contains(1) &&
                  context.read<Repositories>().client != null)
                SafeArea(
                  bottom: false,
                  child: LinehaulHistoryScreen(
                    key: _historyKey,
                    repository: _repository,
                    embedded: true,
                    tripBuilder: _tripCard,
                  ),
                )
              else
                const SizedBox.shrink(),
              if (_visitedTabs.contains(2) &&
                  context.read<Repositories>().client != null)
                SafeArea(
                  bottom: false,
                  child: LinehaulEarningsScreen(
                    key: _earningsKey,
                    repository: _repository,
                    embedded: true,
                  ),
                )
              else
                const SizedBox.shrink(),
              SafeArea(bottom: false, child: _account()),
            ],
          ),
        ),
      ),
    );
  }
}

class _CourierDeclineDialog extends StatefulWidget {
  const _CourierDeclineDialog();
  @override
  State<_CourierDeclineDialog> createState() => _CourierDeclineDialogState();
}

class _CourierDeclineDialogState extends State<_CourierDeclineDialog> {
  final _controller = TextEditingController();
  final _form = GlobalKey<FormState>();
  bool _submitted = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Decline this trip?'),
    content: SingleChildScrollView(
      child: Form(
        key: _form,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'Logistics can offer it to another Courier. Add a reason so they can plan the next handover.',
            ),
            const SizedBox(height: 16),
            DropdownButtonFormField<String>(
              decoration: const InputDecoration(labelText: 'Suggested reason'),
              isExpanded: true,
              items: [
                for (final value in [
                  'Vehicle problem',
                  'Schedule conflict',
                  'Other',
                ])
                  DropdownMenuItem(value: value, child: Text(value)),
              ],
              onChanged: (value) =>
                  _controller.text = value == 'Other' ? '' : value ?? '',
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _controller,
              maxLength: 500,
              minLines: 2,
              maxLines: 4,
              decoration: const InputDecoration(labelText: 'Reason'),
              validator: (value) => value == null || value.trim().isEmpty
                  ? 'Enter a reason for declining'
                  : null,
            ),
          ],
        ),
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Keep trip'),
      ),
      FilledButton(
        onPressed: () {
          if (!_submitted && _form.currentState!.validate()) {
            _submitted = true;
            Navigator.pop(context, _controller.text.trim());
          }
        },
        child: const Text('Confirm decline'),
      ),
    ],
  );
}

class _CourierCard extends StatelessWidget {
  const _CourierCard({required this.child});
  final Widget child;
  @override
  Widget build(BuildContext context) => Material(
    color: AppColors.surface,
    clipBehavior: Clip.antiAlias,
    shape: RoundedRectangleBorder(
      borderRadius: editorialRadius(0, ShapeTier.card),
      side: BorderSide(color: AppColors.divider),
    ),
    child: Padding(padding: const EdgeInsets.all(20), child: child),
  );
}
