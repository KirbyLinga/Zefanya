// lib/screens/shared/chat_inbox_screen.dart
//
// Mock-mode inbox for every thread the buyer has opened. History comes from
// ChatState, which persists it locally; the list is ordered by latest activity
// because ChatMessage carries no timestamp to sort on.
//
// Deliberately no "new message" composer here: a conversation starts from a
// product page, where there is a seller to talk about. An empty inbox says
// exactly that.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/chat_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/motion/ease_in_frame.dart';
import 'chat_screen.dart';

class ChatInboxScreen extends StatelessWidget {
  const ChatInboxScreen({super.key});

  static List<Color> get _avatarPalette => [
    AppColors.tertiary,
    AppColors.primary,
    AppColors.secondary,
    Color.lerp(AppColors.secondary, AppColors.tertiary, 0.5)!,
  ];

  static Color _avatarColorFor(String name) =>
      _avatarPalette[name.hashCode.abs() % _avatarPalette.length];

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final conversations = context.watch<ChatState>().conversations;

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: conversations.isEmpty
          ? Column(
              children: [
                const Padding(
                  padding: EdgeInsets.fromLTRB(20, 8, 20, 0),
                  child: EditorialHeader(
                    eyebrow: 'MESSAGES',
                    title: 'Conversations',
                    padding: EdgeInsets.fromLTRB(0, 0, 0, 20),
                  ),
                ),
                const Expanded(
                  child: EmptyState(
                    icon: Icons.forum_outlined,
                    title: 'No messages yet.',
                    subtitle: 'Chat with sellers from a product page.',
                  ),
                ),
              ],
            )
          : ListView(
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
              children: [
                const EditorialHeader(
                  eyebrow: 'MESSAGES',
                  title: 'Conversations',
                  padding: EdgeInsets.fromLTRB(0, 0, 0, 20),
                ),
                for (var i = 0; i < conversations.length; i++)
                  EaseInFrame(
                    index: i.clamp(0, 6),
                    child: Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: _ConversationTile(
                        conversation: conversations[i],
                        avatarColor: _avatarColorFor(
                          conversations[i].contactName,
                        ),
                        theme: theme,
                      ),
                    ),
                  ),
              ],
            ),
    );
  }
}

class _ConversationTile extends StatelessWidget {
  const _ConversationTile({
    required this.conversation,
    required this.avatarColor,
    required this.theme,
  });

  final ChatConversation conversation;
  final Color avatarColor;
  final ThemeData theme;

  @override
  Widget build(BuildContext context) {
    final last = conversation.lastMessage;
    final text = last?.text.trim() ?? '';
    final preview = text.isEmpty
        ? (last?.attachment != null ? 'Attachment' : '')
        : '${last!.isMe ? 'You: ' : ''}$text';

    return Material(
      color: AppColors.surface,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(18),
        side: BorderSide(color: AppColors.divider),
      ),
      child: InkWell(
        key: ValueKey('chat-conversation-${conversation.contactName}'),
        onTap: () => Navigator.of(context).push(
          AppPageRoute(
            builder: (_) => ChatScreen(
              contactName: conversation.contactName,
              contactRole: conversation.contactRole,
            ),
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 13, 14, 13),
          child: Row(
            children: [
              Container(
                width: 46,
                height: 46,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [
                      Color.lerp(avatarColor, Colors.white, 0.3)!,
                      avatarColor,
                    ],
                  ),
                ),
                alignment: Alignment.center,
                child: Text(
                  conversation.contactName.isEmpty
                      ? '?'
                      : conversation.contactName[0].toUpperCase(),
                  style: TextStyle(
                    color: AppColors.textOnLight,
                    fontWeight: FontWeight.w700,
                    fontSize: 18,
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      conversation.contactName,
                      style: theme.textTheme.titleSmall,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    const SizedBox(height: 3),
                    Text(
                      preview,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: AppColors.textSecondary,
                        fontWeight: conversation.unreadCount > 0
                            ? FontWeight.w600
                            : FontWeight.w400,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 10),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    last?.time ?? '',
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: AppColors.textSecondary,
                    ),
                  ),
                  const SizedBox(height: 7),
                  if (conversation.unreadCount > 0)
                    Container(
                      key: ValueKey(
                        'chat-unread-${conversation.contactName}',
                      ),
                      width: 9,
                      height: 9,
                      decoration: BoxDecoration(
                        color: AppColors.primaryDark,
                        shape: BoxShape.circle,
                      ),
                    )
                  else
                    const SizedBox(height: 9),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
