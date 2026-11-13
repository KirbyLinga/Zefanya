// lib/widgets/chat_inbox_button.dart
//
// Messages entry in the Buyer Home masthead. Live unread counts appear on
// the conversation rows after the inbox fetches them from Laravel.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../screens/shared/chat_inbox_screen.dart';
import '../state/chat_state.dart';
import '../theme/app_theme.dart';
import 'app_page_route.dart';
import 'editorial_shapes.dart';

class ChatInboxButton extends StatelessWidget {
  const ChatInboxButton({super.key});

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final chat = context.watch<ChatState?>();
    final live = chat?.liveRepository != null;
    final unread = live ? 0 : chat?.totalUnread ?? 0;
    void openInbox() => Navigator.of(
      context,
    ).push(AppPageRoute(builder: (_) => const ChatInboxScreen()));

    return Semantics(
      container: true,
      button: true,
      label: live ? 'Messages' : 'Messages, $unread unread',
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
