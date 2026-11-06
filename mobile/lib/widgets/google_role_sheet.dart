// lib/widgets/google_role_sheet.dart
//
// The one question Google cannot answer: whether this new account is a buyer
// or a courier.
//
// A bottom sheet rather than a route because it is a single choice with a
// cancel path, and because the login screen is still underneath — backing out
// returns to sign-in without losing the Google flow's state. The sheet returns
// the chosen role, or null when dismissed.

import 'package:flutter/material.dart';

import '../state/auth_state.dart';
import '../theme/app_theme.dart';
import 'editorial_shapes.dart';

Future<UserRole?> showGoogleRoleSheet(
  BuildContext context, {
  required String email,
}) {
  return showModalBottomSheet<UserRole>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (_) => GoogleRoleSheet(email: email),
  );
}

class GoogleRoleSheet extends StatelessWidget {
  const GoogleRoleSheet({super.key, required this.email});

  final String email;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);

    return SafeArea(
      top: false,
      child: Container(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
          boxShadow: [
            BoxShadow(
              color: AppColors.neutral.withValues(alpha: 0.18),
              blurRadius: 24,
              offset: const Offset(0, -6),
            ),
          ],
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: AppColors.divider,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
            const SizedBox(height: 22),
            Text(
              'One more step',
              style: AppType.masthead.copyWith(fontSize: 30),
            ),
            const SizedBox(height: 8),
            Text(
              "We couldn't find a Zefanya account for $email. "
              'How will you use Zefanya?',
              style: theme.textTheme.bodyMedium?.copyWith(
                color: AppColors.textSecondary,
              ),
            ),
            const SizedBox(height: 20),
            _RoleOption(
              optionKey: const ValueKey('google-role-buyer'),
              title: 'Buyer',
              description: 'Shop from local sellers and track your orders.',
              icon: Icons.storefront_outlined,
              tint: AppColors.primary,
              onTap: () => Navigator.of(context).pop(UserRole.buyer),
            ),
            const SizedBox(height: 12),
            _RoleOption(
              optionKey: const ValueKey('google-role-courier'),
              title: 'Courier',
              description: 'Deliver orders and manage your earnings.',
              icon: Icons.local_shipping_outlined,
              tint: AppColors.tertiary,
              onTap: () => Navigator.of(context).pop(UserRole.courier),
            ),
            const SizedBox(height: 8),
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Not now'),
            ),
          ],
        ),
      ),
    );
  }
}

class _RoleOption extends StatelessWidget {
  const _RoleOption({
    required this.optionKey,
    required this.title,
    required this.description,
    required this.icon,
    required this.tint,
    required this.onTap,
  });

  final Key optionKey;
  final String title;
  final String description;
  final IconData icon;
  final Color tint;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final radius = editorialRadius(1, ShapeTier.card);

    return Material(
      key: optionKey,
      color: AppColors.surfaceMuted,
      borderRadius: radius,
      child: InkWell(
        onTap: onTap,
        borderRadius: radius,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 14, 16),
          child: Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: tint,
                  borderRadius: radiusOf(ShapeTier.pill),
                ),
                child: Icon(icon, color: AppColors.textOnLight, size: 22),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: theme.textTheme.titleMedium),
                    const SizedBox(height: 2),
                    Text(
                      description,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Icon(
                Icons.arrow_forward,
                size: 18,
                color: AppColors.textSecondary,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
