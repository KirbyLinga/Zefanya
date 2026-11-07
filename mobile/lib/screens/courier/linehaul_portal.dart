import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/api/api_exception.dart' show ApiNotConfiguredException;
import '../../data/repositories/linehaul_repository.dart';
import '../../data/repositories/repositories.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/floating_nav_bar.dart';
import '../../widgets/nav_icons.dart';
import '../../widgets/zefanya_mark.dart';
import '../../utils/timed_snackbar.dart';
import 'courier_qr_scanner.dart';

class LinehaulLoginScreen extends StatefulWidget {
  const LinehaulLoginScreen({super.key});
  @override
  State<LinehaulLoginScreen> createState() => _LinehaulLoginScreenState();
}

class _LinehaulLoginScreenState extends State<LinehaulLoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;
  bool _showPassword = false;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<AuthState>().login(
        role: UserRole.linehaul,
        email: _email.text.trim(),
        password: _password.text,
      );
      if (mounted) Navigator.of(context).popUntil((route) => route.isFirst);
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(title: const Text('Courier login')),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(24, 12, 24, 40),
            children: [
              Row(
                children: [
                  const ZefanyaMark(size: 38),
                  const SizedBox(width: 12),
                  Text('ZEFANYA', style: AppType.eyebrow),
                ],
              ),
              const SizedBox(height: 28),
              const EditorialHeader(
                eyebrow: 'COURIER PORTAL',
                title: 'Every parcel.\nOne journey.',
                padding: EdgeInsets.zero,
              ),
              const SizedBox(height: 16),
              Text(
                'Move parcels between hubs, with every handover accounted for.',
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: AppColors.textSecondary,
                  height: 1.6,
                ),
              ),
              const SizedBox(height: 28),
              _CourierCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      'Welcome back',
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'Sign in with your company-provided Courier account.',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AppColors.textSecondary,
                      ),
                    ),
                    const SizedBox(height: 24),
                    AutofillGroup(
                      child: Column(
                        children: [
                          TextField(
                            controller: _email,
                            enabled: !_busy,
                            keyboardType: TextInputType.emailAddress,
                            autofillHints: const [AutofillHints.username],
                            textInputAction: TextInputAction.next,
                            decoration: const InputDecoration(
                              labelText: 'Email',
                              prefixIcon: Icon(Icons.mail_outline),
                            ),
                          ),
                          const SizedBox(height: 16),
                          TextField(
                            controller: _password,
                            enabled: !_busy,
                            obscureText: !_showPassword,
                            autofillHints: const [AutofillHints.password],
                            decoration: InputDecoration(
                              labelText: 'Password',
                              prefixIcon: const Icon(Icons.lock_outline),
                              suffixIcon: IconButton(
                                tooltip: _showPassword
                                    ? 'Hide password'
                                    : 'Show password',
                                onPressed: () => setState(
                                  () => _showPassword = !_showPassword,
                                ),
                                icon: Icon(
                                  _showPassword
                                      ? Icons.visibility_off_outlined
                                      : Icons.visibility_outlined,
                                ),
                              ),
                            ),
                            onSubmitted: (_) {
                              if (!_busy) _login();
                            },
                          ),
                        ],
                      ),
                    ),
                    if (_error != null)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 16),
                        child: Text(
                          _error!,
                          style: TextStyle(color: AppColors.danger),
                          semanticsLabel: 'Login error: $_error',
                        ),
                      ),
                    const SizedBox(height: 24),
                    SizedBox(
                      height: 52,
                      child: FilledButton(
                        onPressed: _busy ? null : _login,
                        child: Text(_busy ? 'Signing in…' : 'Login as Courier'),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 24),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    Icons.hub_outlined,
                    color: AppColors.primaryDark,
                    size: 20,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'For hub-to-hub Couriers. Buyer and Rider sign-in remain on the main login screen.',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AppColors.textSecondary,
                        height: 1.5,
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class LinehaulPortal extends StatefulWidget {
  const LinehaulPortal({super.key});
  @override
  State<LinehaulPortal> createState() => _LinehaulPortalState();
}

class _LinehaulPortalState extends State<LinehaulPortal> {
  late LinehaulRepository _repository;
  late Future<List<LinehaulTrip>> _trips;
  bool _busy = false;
  int _tab = 0;

  @override
  void initState() {
    super.initState();
    final client = context.read<Repositories>().client;
    if (client == null) {
      _trips = Future.error(const ApiNotConfiguredException());
    } else {
      _repository = LinehaulRepository(client);
      _trips = _repository.trips();
    }
  }

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
      if (mounted) _reload();
    } on ApiException catch (error) {
      if (mounted) {
        showTimedSnackBar(context, SnackBar(content: Text(error.message)));
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

  Future<void> _logout() async {
    try {
      await context.read<AuthState>().logout();
    } on ApiException catch (error) {
      if (mounted) {
        showTimedSnackBar(context, SnackBar(content: Text(error.message)));
      }
    }
  }

  Widget _tripCard(LinehaulTrip trip) {
    final theme = Theme.of(context);
    final loaded = trip.items.where((item) => item.loaded).length;
    final received = trip.items.where((item) => item.received).length;
    final completed = trip.status == 'Completed';
    final parcelLabel = (completed ? received : trip.items.length) == 1
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
            _hub('FROM', trip.origin, Icons.trip_origin),
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
            _hub('TO', trip.destination, Icons.location_on_outlined),
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
                      completed
                          ? '$received $parcelLabel received'
                          : '$loaded of ${trip.items.length} $parcelLabel loaded',
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
                ],
              ),
            ),
            if (trip.seal != null)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Text(
                  'Seal: ${trip.seal}',
                  style: theme.textTheme.bodySmall,
                ),
              ),
            ExpansionTile(
              tilePadding: EdgeInsets.zero,
              childrenPadding: EdgeInsets.zero,
              title: Text('Parcel details', style: theme.textTheme.labelLarge),
              children: [
                for (final item in trip.items)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(
                      item.received
                          ? Icons.inventory_2_outlined
                          : item.loaded
                          ? Icons.check_circle_outline
                          : Icons.radio_button_unchecked,
                      color: item.loaded
                          ? AppColors.successText
                          : AppColors.textSecondary,
                    ),
                    title: Text(
                      item.tracking,
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
              ],
            ),
            if (trip.status == 'Assigned')
              FilledButton(
                onPressed: _busy ? null : () => _act(trip, 'accept'),
                child: const Text('Accept trip'),
              ),
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
                'Handover confirmed by Logistics.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: AppColors.successText,
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _hub(String label, String name, IconData icon) => Row(
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
          title: 'Your account',
          padding: EdgeInsets.only(bottom: 24),
        ),
        _CourierCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Align(
                alignment: Alignment.centerLeft,
                child: ZefanyaMark(size: 48),
              ),
              const SizedBox(height: 20),
              Text(
                auth.name ?? 'Courier',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: 8),
              if (auth.email != null)
                Text(
                  auth.email!,
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              const SizedBox(height: 20),
              const Divider(),
              const SizedBox(height: 12),
              Text('COURIER', style: AppType.eyebrow),
              const SizedBox(height: 8),
              const Text('Hub-to-hub parcel transfers'),
              const SizedBox(height: 24),
              OutlinedButton(
                onPressed: _busy ? null : _logout,
                child: const Text('Log out'),
              ),
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
      appBar: AppBar(
        title: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const ZefanyaMark(size: 24),
            const SizedBox(width: 10),
            Text('ZEFANYA COURIER', style: AppType.eyebrow),
          ],
        ),
        actions: [
          IconButton(
            onPressed: _busy ? null : _reload,
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh trips',
          ),
          IconButton(
            onPressed: _busy ? null : _logout,
            icon: const Icon(Icons.logout),
            tooltip: 'Log out',
          ),
        ],
      ),
      bottomNavigationBar: FloatingNavBar(
        items: const [
          NavBarItem(glyph: NavGlyph.parcel, label: 'Trips'),
          NavBarItem(glyph: NavGlyph.orders, label: 'History'),
          NavBarItem(glyph: NavGlyph.profile, label: 'Account'),
        ],
        currentIndex: _tab,
        onTap: (index) => setState(() => _tab = index),
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 720),
          child: FutureBuilder<List<LinehaulTrip>>(
            future: _trips,
            builder: (context, snapshot) {
              if (_tab == 2) return _account();
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
              ].contains(trip.status);
              final trips = all
                  .where(
                    (trip) => _tab == 1 ? isHistory(trip) : !isHistory(trip),
                  )
                  .toList();
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
                      eyebrow: _tab == 1 ? 'EVERY HANDOVER' : 'HUB TO HUB',
                      title: _tab == 1 ? 'Trip history' : 'Your journeys',
                      padding: const EdgeInsets.only(bottom: 12),
                    ),
                    Padding(
                      padding: const EdgeInsets.only(bottom: 24),
                      child: Text(
                        _tab == 1
                            ? 'A record of your recent transfers.'
                            : 'From loading to handover, one parcel at a time.',
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: AppColors.textSecondary,
                          height: 1.5,
                        ),
                      ),
                    ),
                    if (_busy) const LinearProgressIndicator(),
                    if (trips.isEmpty)
                      _CourierCard(
                        child: EmptyState(
                          icon: _tab == 1
                              ? Icons.history
                              : Icons.local_shipping_outlined,
                          title: _tab == 1
                              ? 'No trip history yet'
                              : 'No trips assigned',
                          subtitle: _tab == 1
                              ? 'Completed and cancelled transfers will appear here.'
                              : 'Your next journey will appear here when Logistics assigns a trip.',
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
        ),
      ),
    );
  }
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
