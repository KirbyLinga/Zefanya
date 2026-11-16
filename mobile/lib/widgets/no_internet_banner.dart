// lib/widgets/no_internet_banner.dart
//
// A full-width banner that appears at the top of the app shell while the
// phone has no working connection. It is the same height as a SnackBar and
// uses the same editorial voice, so an offline buyer sees one clear finger
// to recovery: tap Retry to re-check, or pull down to refresh.
//
// The banner is driven by [ConnectionWatcher] (lib/services/connection_watcher.dart),
// which owns the one true connection state. Widgets that need to act on
// offline rebuild from [ConnectionWatcher.instance].
//
// Design: background = AppColors.surfaceMuted, border = AppColors.divider,
// leading icon in AppColors.warning. No new colors — uses the app's existing
// palette. The banner is silent when the connection is online.

import 'package:flutter/material.dart';

import 'package:zefanya/services/connection_watcher.dart';

import '../theme/app_theme.dart';

/// The shared banner shown while the device is offline.
class NoInternetBanner extends StatefulWidget {
  const NoInternetBanner.withCallbacks({
    super.key,
    required this.onRetry,
    required this.onRefresh,
  });

  /// Construct without a key or with explicit callbacks. The const ctor is
  /// retained for the widget tree we build in tests; this ctor exists so
  /// callers can pass non-constant VoidCallbacks without a const context.
  const NoInternetBanner({
    super.key,
    required this.onRetry,
    required this.onRefresh,
  });

  /// Re-check the connection (used by the Retry button).
  final VoidCallback onRetry;

  /// Pull-to-refresh the connection (used as an alternative to Retry).
  final VoidCallback onRefresh;

  @override
  State<NoInternetBanner> createState() => _NoInternetBannerState();
}

class _NoInternetBannerState extends State<NoInternetBanner> {
  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: ConnectionWatcher.instance,
      builder: (context, _) => _banner(context),
    );
  }

  Widget _banner(BuildContext context) {
    final watcher = ConnectionWatcher.instance;
    // NetConnectionState is imported from the same library, so no collision
    // with Flutter's ConnectionState.
    if (watcher.connectionState == NetConnectionState.online) {
      return const SizedBox.shrink();
    }

    final theme = Theme.of(context);

    return Container(
      // A banner that eats the whole width but not the height: it sits at the
      // top of the shell, above the Scaffold's regular padding, so content
      // underneath can still scroll.
      height: kToolbarHeight + 8,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(
        children: [
          Icon(Icons.wifi_off, color: AppColors.warning, size: 22),
          const SizedBox(width: 14),
          Expanded(
            child: Text(
              'No internet connection',
              style: theme.textTheme.titleMedium?.copyWith(
                color: AppColors.textPrimary,
                fontWeight: FontWeight.w700,
              ),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          IconButton(
            tooltip: 'Retry',
            onPressed: widget.onRetry,
            icon: const Icon(Icons.refresh),
            splashRadius: 20,
          ),
        ],
      ),
    );
  }
}
