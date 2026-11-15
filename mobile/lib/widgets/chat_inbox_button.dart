// lib/widgets/chat_inbox_button.dart
//
// The mock-mode Messages entry in the Home masthead, beside the cart. It
// counts unread messages across every thread and hides its badge at zero.
//
// Live builds never construct it (see FeatureFlags.chatLive): the server has
// no conversation endpoints, so a badge there could only count messages the
// app invented.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../screens/shared/chat_inbox_screen.dart';
import '../state/chat_state.dart';
import '../theme/app_theme.dart';
import 'app_page_route.dart';
import 'editorial_shapes.dart';

class ChatInboxButton extends StatelessWidget {
  const ChatInboxButton({super.key});

  int _unread(BuildContext context) {
    try {
      return Provider.of<ChatState>(context).totalUnread;
    } on ProviderNotFoundException {
      return 0;
    }
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final unread = _unread(context);
    void openInbox() => Navigator.of(
      context,
    ).push(AppPageRoute(builder: (_) => const ChatInboxScreen()));

    return Semantics(
      container: true,
      button: true,
      label: 'Messages, $unread unread',
      onTap: openInbox,
      // The badge repeats the number already in the label.
      excludeSemantics: true,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          IconButton(
            key: const ValueKey('open-chat-inbox'),
            tooltip: 'Messages',
            onPressed: openInbox,
            icon: const Icon(Icons.chat_bubble_outline),
            color: AppColors.textPrimary,
          ),
          if (unread > 0)
            Positioned(
              right: 2,
              top: 2,
              child: Container(
                key: ValueKey('chat-unread-badge-$unread'),
                padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                decoration: BoxDecoration(
                  color: AppColors.primaryDark,
                  borderRadius: radiusOf(ShapeTier.pill),
                ),
                child: Text(
                  unread > 9 ? '9+' : '$unread',
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: AppColors.textOnDark,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
