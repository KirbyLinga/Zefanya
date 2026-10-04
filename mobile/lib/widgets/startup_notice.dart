// lib/widgets/startup_notice.dart
//
// One line naming what `main()` could not load, and a Retry that re-runs it.
//
// It sits above the shell (see `AuthGate` in main.dart) rather than inside a
// screen, because it is the catch-all for everything that has no screen of its own
// yet — chat threads, the voucher list, reviews. A failure that *does* have a
// screen able to explain it (the Orders tab) is explained there instead; this
// notice is what stops the rest from being silently empty, which would read as
// "you have no chats" when the truth is "we could not ask".

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../state/startup_state.dart';
import '../theme/app_theme.dart';

class StartupNotice extends StatelessWidget {
  const StartupNotice({super.key});

  @override
  Widget build(BuildContext context) {
    final startup = context.watch<StartupState>();

    // Nothing to say — and, just as importantly, no space taken when there is
    // nothing to say.
    if (!startup.hasFailures) return const SizedBox.shrink();

    final theme = Theme.of(context);

    return Material(
      color: AppColors.surfaceMuted,
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
          child: Row(
            children: [
              Icon(Icons.cloud_off_outlined, size: 18, color: AppColors.textSecondary),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  startup.message,
                  style: theme.textTheme.bodySmall?.copyWith(color: AppColors.textSecondary),
                ),
              ),
              // Only when trying again could work: an endpoint the server has not
              // built answers no to that, and a button that cannot work teaches a
              // person to mash it.
              if (startup.anyRetryable)
                TextButton(
                  onPressed: () => startup.retry(),
                  child: const Text('Retry'),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
