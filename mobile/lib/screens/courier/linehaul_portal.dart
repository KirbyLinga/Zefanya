import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/api/api_exception.dart' show ApiNotConfiguredException;
import '../../data/repositories/linehaul_repository.dart';
import '../../data/repositories/repositories.dart';
import '../../state/auth_state.dart';
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
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Courier login')),
    body: ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text(
          'Hub-to-hub transport',
          style: Theme.of(context).textTheme.headlineSmall,
        ),
        const Text(
          'Use the Courier account provided by your logistics company.',
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
                decoration: const InputDecoration(labelText: 'Email'),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: _password,
                enabled: !_busy,
                obscureText: true,
                autofillHints: const [AutofillHints.password],
                decoration: const InputDecoration(labelText: 'Password'),
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
            child: Text(_error!, semanticsLabel: 'Login error: $_error'),
          ),
        const SizedBox(height: 24),
        FilledButton(
          onPressed: _busy ? null : _login,
          child: Text(_busy ? 'Signing in…' : 'Login as Courier'),
        ),
      ],
    ),
  );
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
  String? _error;

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
      _error = null;
    });
    try {
      await _repository.act(trip.id, action, body: body);
      if (mounted) _reload();
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
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
      if (mounted) setState(() => _error = error.message);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: const Text('Courier trips'),
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
    body: FutureBuilder<List<LinehaulTrip>>(
      future: _trips,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    snapshot.error is ApiException
                        ? (snapshot.error as ApiException).message
                        : 'Trips could not be loaded.',
                  ),
                  TextButton(onPressed: _reload, child: const Text('Retry')),
                ],
              ),
            ),
          );
        }
        final trips = snapshot.data ?? const [];
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            if (trips.isEmpty)
              const Padding(
                padding: EdgeInsets.all(24),
                child: Text(
                  'No trips assigned. Refresh after Logistics assigns a manifest.',
                ),
              ),
            for (final trip in trips)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Trip #${trip.id}',
                        style: Theme.of(context).textTheme.titleLarge,
                      ),
                      Text('${trip.origin} → ${trip.destination}'),
                      Text(trip.status),
                      if (trip.seal != null) Text('Seal: ${trip.seal}'),
                      for (final item in trip.items)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: Icon(
                            item.received
                                ? Icons.inventory_2_outlined
                                : item.loaded
                                ? Icons.check_circle_outline
                                : Icons.radio_button_unchecked,
                          ),
                          title: Text(item.tracking),
                        ),
                      if (trip.status == 'Assigned')
                        FilledButton(
                          onPressed: _busy ? null : () => _act(trip, 'accept'),
                          child: const Text('Accept trip'),
                        ),
                      if (trip.status == 'Loading')
                        Wrap(
                          spacing: 12,
                          children: [
                            OutlinedButton(
                              onPressed: _busy ? null : () => _scan(trip),
                              child: const Text('Scan or enter parcel'),
                            ),
                            FilledButton(
                              onPressed: _busy ? null : () => _depart(trip),
                              child: const Text('Depart'),
                            ),
                          ],
                        ),
                      if (trip.status == 'In Transit')
                        FilledButton(
                          onPressed: _busy ? null : () => _act(trip, 'arrive'),
                          child: const Text('Report arrival'),
                        ),
                      if (trip.status == 'Arrived')
                        const Text(
                          'Awaiting parcel receipt by Logistics. Refresh after handover.',
                        ),
                    ],
                  ),
                ),
              ),
          ],
        );
      },
    ),
  );
}
