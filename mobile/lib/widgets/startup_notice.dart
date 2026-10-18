// lib/widgets/startup_notice.dart
//
// One line naming what `main()` could not load, and a Retry that re-runs it.
//
// Retryable failures only: a known-unbuilt endpoint is logged quietly in
// state, never bannered (see StartupState.actionable).
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
    AppColors.watch(context);
    final startup = context.watch<StartupState>();

    // Retryable failures only: a known-unbuilt endpoint (reviews, chat,
    // courier jobs in live mode) is logged quietly in state, never
    // bannered — a permanent bar a customer cannot act on is noise over
    // every screen. So when nothing actionable failed, there is nothing
    // to say and no space taken.
    if (!startup.hasActionable) return const SizedBox.shrink();

    final theme = Theme.of(context);

    // Retry is unconditional here: hasActionable means at least one
    // failure says retrying could work, and retry() re-runs only what
    // failed.

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
