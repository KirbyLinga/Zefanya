// lib/screens/courier/webzefanya_courier_portal.dart
//
// The Transfer Courier's app against WebZefanya: read-only.
//
// WebZefanya owns the process. Logistics assigns Couriers; station staff scan
// dispatch and receipt; custody moves only with those scans. This portal shows
// the Courier what WebZefanya says (current, upcoming, history, profile) and
// offers no action that changes a transfer.
//
// Session rules (Phase 3 contract):
//  * every load carries the portal's generation; a reply that arrives after a
//    sign-out, account switch or newer load is dropped;
//  * a 401 means WebZefanya ended this session (signed in elsewhere, access
//    revoked, company suspended, feature paused): the app signs out locally;
//  * a 403 means the account exists but may not use the app right now.

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../data/api/api_exception.dart';
import '../../data/repositories/courier_transfer_repository.dart';
import '../../data/repositories/repositories.dart';
import '../../state/auth_state.dart';
import '../../utils/timed_snackbar.dart';

class WebZefanyaCourierPortal extends StatefulWidget {
  const WebZefanyaCourierPortal({super.key, this.repository});

  /// Injected in tests; otherwise built from the app's WebZefanya client.
  final CourierTransferRepository? repository;

  @override
  State<WebZefanyaCourierPortal> createState() =>
      _WebZefanyaCourierPortalState();
}

class _WebZefanyaCourierPortalState extends State<WebZefanyaCourierPortal> {
  late final CourierTransferRepository _repo;
  int _generation = 0;
  int _tab = 0;

  bool _loading = true;
  String? _blocked;
  CourierProfile? _profile;
  CourierAssignments? _assignments;
  final List<CourierTransfer> _history = [];
  CourierHistoryPage? _lastHistoryPage;
  bool _loadingMore = false;

  @override
  void initState() {
    super.initState();
    final client = context.read<Repositories?>()?.client;
    _repo = widget.repository ?? CourierTransferRepository(client!);
    _load();
  }

  @override
  void dispose() {
    _generation++; // late replies are dropped
    super.dispose();
  }

  bool _current(int generation) => mounted && generation == _generation;

  Future<void> _load() async {
    final generation = ++_generation;
    setState(() {
      _loading = true;
      _blocked = null;
    });
    try {
      final results = await Future.wait([
        _repo.profile(),
        _repo.assignments(),
        _repo.history(),
      ]);
      if (!_current(generation)) return;
      final page = results[2] as CourierHistoryPage;
      setState(() {
        _profile = results[0] as CourierProfile;
        _assignments = results[1] as CourierAssignments;
        _history
          ..clear()
          ..addAll(page.items);
        _lastHistoryPage = page;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!_current(generation)) return;
      await _handle(e);
    }
  }

  Future<void> _loadMore() async {
    final page = _lastHistoryPage;
    if (page == null || !page.hasMore || _loadingMore) return;
    final generation = _generation;
    setState(() => _loadingMore = true);
    try {
      final next = await _repo.history(page: page.currentPage + 1);
      if (!_current(generation)) return;
      setState(() {
        final seen = _history.map((t) => t.key).toSet();
        _history.addAll(next.items.where((t) => !seen.contains(t.key)));
        _lastHistoryPage = next;
        _loadingMore = false;
      });
    } on ApiException catch (e) {
      if (!_current(generation)) return;
      setState(() => _loadingMore = false);
      await _handle(e);
    }
  }

  Future<void> _handle(ApiException e) async {
    if (e is UnauthorizedException) {
      const message = 'Your Courier session ended. Please sign in again.';
      await _endSession(message);
      // The app's AuthGate replaces this screen once signed out; until then
      // (or if it is hosted elsewhere) it must not keep spinning.
      if (mounted) {
        setState(() {
          _loading = false;
          _blocked = message;
        });
      }
      return;
    }
    if (e is ForbiddenException) {
      setState(() {
        _loading = false;
        _blocked =
            'Your Courier access is not available right now. Contact your logistics company.';
      });
      return;
    }
    setState(() {
      _loading = false;
      _blocked = e.message;
    });
  }

  Future<void> _endSession(String message) async {
    _generation++;
    final messenger = ScaffoldMessenger.maybeOf(context);
    try {
      await context.read<AuthState>().logout();
    } on ApiException {
      // The server already ended it; the local session is cleared regardless.
    }
    messenger?.showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _signOut() async {
    _generation++;
    try {
      await context.read<AuthState>().logout();
    } on ApiException catch (e) {
      if (mounted)
        showTimedSnackBar(context, SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final body = _loading
        ? const Center(child: CircularProgressIndicator())
        : _blocked != null
        ? _BlockedView(message: _blocked!, onRetry: _load, onSignOut: _signOut)
        : switch (_tab) {
            0 => _AssignedView(assignments: _assignments!, onRefresh: _load),
            1 => _HistoryView(
              items: _history,
              hasMore: _lastHistoryPage?.hasMore ?? false,
              loadingMore: _loadingMore,
              onMore: _loadMore,
              onRefresh: _load,
            ),
            _ => _ProfileView(profile: _profile!, onSignOut: _signOut),
          };

    return Scaffold(
      appBar: AppBar(
        title: const Text('Zefanya Courier'),
        actions: [
          IconButton(
            key: const ValueKey('courier-refresh'),
            tooltip: 'Refresh',
            onPressed: _loading ? null : _load,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: SafeArea(child: body),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) => setState(() => _tab = i),
        destinations: const [
          NavigationDestination(
            key: ValueKey('courier-tab-assigned'),
            icon: Icon(Icons.local_shipping_outlined),
            label: 'Assigned',
          ),
          NavigationDestination(
            key: ValueKey('courier-tab-history'),
            icon: Icon(Icons.history),
            label: 'History',
          ),
          NavigationDestination(
            key: ValueKey('courier-tab-profile'),
            icon: Icon(Icons.person_outline),
            label: 'Profile',
          ),
        ],
      ),
    );
  }
}

final DateFormat _when = DateFormat('MMM d, y h:mm a');

String _date(DateTime? value) => value == null ? '—' : _when.format(value);

class _AssignedView extends StatelessWidget {
  const _AssignedView({required this.assignments, required this.onRefresh});
  final CourierAssignments assignments;
  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return RefreshIndicator(
      onRefresh: onRefresh,
      child: ListView(
        key: const ValueKey('courier-assigned-list'),
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            'Your logistics company assigns transfers. Hub staff scan dispatch and receipt; this app only shows them.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 16),
          Text('On the road', style: theme.textTheme.titleMedium),
          const SizedBox(height: 8),
          if (assignments.current.isEmpty)
            const _Empty('Nothing on the road right now.'),
          for (final t in assignments.current) _TransferCard(t),
          const SizedBox(height: 20),
          Text('Upcoming', style: theme.textTheme.titleMedium),
          const SizedBox(height: 8),
          if (assignments.upcoming.isEmpty)
            const _Empty('No upcoming transfers.'),
          for (final t in assignments.upcoming) _TransferCard(t),
        ],
      ),
    );
  }
}

class _HistoryView extends StatelessWidget {
  const _HistoryView({
    required this.items,
    required this.hasMore,
    required this.loadingMore,
    required this.onMore,
    required this.onRefresh,
  });
  final List<CourierTransfer> items;
  final bool hasMore;
  final bool loadingMore;
  final VoidCallback onMore;
  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context) => RefreshIndicator(
    onRefresh: onRefresh,
    child: ListView(
      key: const ValueKey('courier-history-list'),
      padding: const EdgeInsets.all(16),
      children: [
        if (items.isEmpty) const _Empty('No transfers yet.'),
        for (final t in items) _TransferCard(t),
        if (hasMore)
          Center(
            child: TextButton(
              key: const ValueKey('courier-history-more'),
              onPressed: loadingMore ? null : onMore,
              child: Text(loadingMore ? 'Loading…' : 'Show older transfers'),
            ),
          ),
      ],
    ),
  );
}

class _TransferCard extends StatelessWidget {
  const _TransferCard(this.t);
  final CourierTransfer t;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final route = t.reassignedAway
        ? null
        : '${t.originName ?? 'Hub'} → ${t.destinationName ?? 'Hub'}'
              '${t.destinationProvider != null ? ' (${t.destinationProvider})' : ''}';
    return Card(
      key: ValueKey('courier-transfer-${t.key}'),
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(t.title, style: theme.textTheme.titleSmall),
            const SizedBox(height: 4),
            Text(t.statusLabel, style: theme.textTheme.bodyMedium),
            if (route != null) ...[
              const SizedBox(height: 6),
              Text(route, style: theme.textTheme.bodySmall),
            ],
            const SizedBox(height: 6),
            Text(
              [
                if (t.parcelCount != null)
                  '${t.parcelCount} parcel${t.parcelCount == 1 ? '' : 's'}',
                if (t.vehicleType != null) t.vehicleType!,
                if (t.plateNumber != null) t.plateNumber!,
              ].join(' · '),
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 4),
            Text(
              t.reassignedAway
                  ? 'Assigned ${_date(t.assignedAt)} · reassigned ${_date(t.replacedAt)}'
                  : 'Assigned ${_date(t.assignedAt)}'
                        '${t.dispatchedAt != null ? ' · dispatched ${_date(t.dispatchedAt)}' : ''}'
                        '${t.receivedAt != null ? ' · received ${_date(t.receivedAt)}' : ''}',
              style: theme.textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}

class _ProfileView extends StatelessWidget {
  const _ProfileView({required this.profile, required this.onSignOut});
  final CourierProfile profile;
  final VoidCallback onSignOut;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    Widget row(String label, String? value) => ListTile(
      dense: true,
      title: Text(label, style: theme.textTheme.bodySmall),
      subtitle: Text(value ?? '—', style: theme.textTheme.bodyLarge),
    );
    return ListView(
      key: const ValueKey('courier-profile'),
      padding: const EdgeInsets.all(16),
      children: [
        row('Name', profile.name),
        row('Login ID', profile.loginId),
        row('Logistics company', profile.providerName),
        row(
          'Vehicle',
          [
            profile.vehicleType,
            profile.plateNumber,
          ].whereType<String>().join(' · '),
        ),
        const SizedBox(height: 16),
        OutlinedButton.icon(
          key: const ValueKey('courier-sign-out'),
          onPressed: onSignOut,
          icon: const Icon(Icons.logout),
          label: const Text('Sign out'),
        ),
      ],
    );
  }
}

class _BlockedView extends StatelessWidget {
  const _BlockedView({
    required this.message,
    required this.onRetry,
    required this.onSignOut,
  });
  final String message;
  final VoidCallback onRetry;
  final VoidCallback onSignOut;

  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            message,
            key: const ValueKey('courier-blocked'),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 16),
          Wrap(
            spacing: 12,
            children: [
              OutlinedButton(
                onPressed: onRetry,
                child: const Text('Try again'),
              ),
              TextButton(onPressed: onSignOut, child: const Text('Sign out')),
            ],
          ),
        ],
      ),
    ),
  );
}

class _Empty extends StatelessWidget {
  const _Empty(this.text);
  final String text;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: Text(text, style: Theme.of(context).textTheme.bodyMedium),
  );
}
