// lib/screens/buyer/notifications_screen.dart
//
// The buyer's notification inbox. Tapping a row marks that notification read;
// "Mark all as read" clears the bell badge in one go. Navigation targets are
// kept as ids on the fixture but this screen deliberately does not invent a
// route when it has no matching order/product yet.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/notification_state.dart';
import '../../state/order_state.dart';
import '../../widgets/inline_error_notice.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/app_page_route.dart';
import 'order_detail_screen.dart';

class NotificationsScreen extends StatelessWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final state = context.watch<NotificationState>();
    final items = state.notifications;

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: RefreshIndicator(
        onRefresh: state.load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 28),
          children: [
            EditorialHeader(
              eyebrow: 'STAY IN THE LOOP',
              title: 'Notifications',
              padding: const EdgeInsets.fromLTRB(0, 0, 0, 12),
              trailing: state.unreadCount == 0
                  ? null
                  : TextButton(
                      onPressed: state.loading || state.updating
                          ? null
                          : state.markAllRead,
                      child: const Text('Mark all as read'),
                    ),
            ),
            if (state.loading || state.updating)
              const LinearProgressIndicator(
                semanticsLabel: 'Updating notifications',
              ),
            if (state.error case final error?) ...[
              InlineErrorNotice(error),
              TextButton(onPressed: state.load, child: const Text('Retry')),
            ],
            if (state.error == null && (!state.loading || items.isNotEmpty))
              Text(
                state.unreadCount == 0
                    ? 'All caught up'
                    : '${state.unreadCount} unread',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
              ),
            const SizedBox(height: 14),
            for (var i = 0; i < items.length; i++)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: _NotificationTile(
                  item: items[i],
                  unread: !state.isRead(items[i].id),
                  index: i,
                  onTap: () => _openNotification(context, state, items[i]),
                ),
              ),
            if (state.hasMore)
              TextButton(
                onPressed: state.loading || state.updating
                    ? null
                    : state.loadMore,
                child: const Text('Load more notifications'),
              ),
          ],
        ),
      ),
    );
  }

  Future<void> _openNotification(
    BuildContext context,
    NotificationState state,
    AppNotification item,
  ) async {
    if (state.loading || state.updating) return;
    await state.markRead(item.id);
    if (!context.mounted ||
        !state.isLive ||
        item.orderId == null ||
        ModalRoute.isCurrentOf(context) != true ||
        state.error != null) {
      return;
    }
    final navigator = Navigator.of(context);
    final orders = context.read<OrderState>();
    final shipments = orders.orders
        .where((order) => order.serverOrderId == item.orderId)
        .toList(growable: false);
    if (shipments.length == 1) {
      navigator.push<void>(
        AppPageRoute(
          builder: (_) => OrderDetailScreen(order: shipments.single),
        ),
      );
      return;
    }
    orders.requestOrdersTab();
    navigator.popUntil((route) => route.isFirst);
  }
}

class _NotificationTile extends StatelessWidget {
  const _NotificationTile({
    required this.item,
    required this.unread,
    required this.index,
    required this.onTap,
  });

  final AppNotification item;
  final bool unread;
  final int index;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: editorialRadius(index, ShapeTier.card),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: unread
                ? AppColors.primary.withValues(alpha: 0.14)
                : AppColors.surfaceMuted,
            borderRadius: editorialRadius(index, ShapeTier.card),
            border: Border.all(
              color: unread ? AppColors.primaryDark : AppColors.divider,
              width: unread ? 1.4 : 1,
            ),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 38,
                height: 38,
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  shape: BoxShape.circle,
                ),
                alignment: Alignment.center,
                child: Icon(
                  Icons.notifications_outlined,
                  size: 19,
                  color: AppColors.primaryDark,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            item.title,
                            style: theme.textTheme.titleSmall?.copyWith(
                              fontWeight: unread
                                  ? FontWeight.w700
                                  : FontWeight.w600,
                            ),
                          ),
                        ),
                        if (unread)
                          Container(
                            width: 8,
                            height: 8,
                            decoration: BoxDecoration(
                              color: AppColors.primaryDark,
                              shape: BoxShape.circle,
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 3),
                    Text(item.body, style: theme.textTheme.bodySmall),
                    const SizedBox(height: 6),
                    Text(
                      item.timeLabel,
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
