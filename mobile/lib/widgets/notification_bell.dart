// lib/widgets/notification_bell.dart
//
// Bell + unread badge for Buyer Home. Tapping opens the notification centre.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../screens/buyer/notifications_screen.dart';
import '../state/notification_state.dart';
import '../theme/app_theme.dart';
import 'app_page_route.dart';

class NotificationBell extends StatelessWidget {
  const NotificationBell({super.key});

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final unread = context.watch<NotificationState>().unreadCount;

    return Semantics(
      button: true,
      label: unread == 0
          ? 'Notifications'
          : 'Notifications, $unread unread',
      child: Tooltip(
        message: 'Notifications',
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: () => Navigator.of(
            context,
          ).push(AppPageRoute(builder: (_) => const NotificationsScreen())),
          child: Padding(
            padding: const EdgeInsets.all(8),
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                Icon(
                  Icons.notifications_none_rounded,
                  size: 24,
                  color: AppColors.textPrimary,
                ),
                if (unread > 0)
                  Positioned(
                    right: -4,
                    top: -4,
                    child: Container(
                      constraints: const BoxConstraints(
                        minWidth: 16,
                        minHeight: 16,
                      ),
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                      decoration: BoxDecoration(
                        color: AppColors.danger,
                        shape: BoxShape.rectangle,
                        borderRadius: const BorderRadius.all(
                          Radius.circular(8),
                        ),
                      ),
                      alignment: Alignment.center,
                      child: Text(
                        unread > 9 ? '9+' : '$unread',
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 9,
                          fontWeight: FontWeight.w700,
                          height: 1.1,
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
