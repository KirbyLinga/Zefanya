// lib/screens/shared/account_security_screen.dart
//
// The hub for the three credentials an account owns: password, email and phone.
//
// Kept out of Edit Profile on purpose. Editing a name or address is safe to save
// immediately; changing a password or a recovery address is not, and mixing the
// two kinds of edit on one form would make the dangerous ones look as ordinary
// as the safe ones. Each row here opens a flow that re-proves ownership before
// anything changes.

import 'package:flutter/material.dart';
import '../../theme/app_theme.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_header.dart';
import 'change_contact_screen.dart';
import 'change_password_screen.dart';

class AccountSecurityScreen extends StatelessWidget {
  const AccountSecurityScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
        children: [
          const EditorialHeader(
            eyebrow: 'ACCOUNT & SECURITY',
            title: 'Keep it yours',
            padding: EdgeInsets.fromLTRB(0, 0, 0, 20),
          ),
          Text(
            'Changing a credential takes one more proof: your current password, '
            'or a code sent to the new email or number.',
            style: theme.textTheme.bodyMedium?.copyWith(
              color: AppColors.textSecondary,
              height: 1.45,
            ),
          ),
          const SizedBox(height: 24),
          _SecurityRow(
            key: const ValueKey('change-password-tile'),
            icon: Icons.lock_outline,
            title: 'Change Password',
            subtitle: 'Other phones are signed out when you do.',
            onTap: () => Navigator.of(context).push(
              AppPageRoute(builder: (_) => const ChangePasswordScreen()),
            ),
          ),
          const SizedBox(height: 12),
          _SecurityRow(
            key: const ValueKey('change-email-tile'),
            icon: Icons.alternate_email,
            title: 'Change Email',
            subtitle: 'Verified with a code before the new address is used.',
            onTap: () => Navigator.of(context).push(
              AppPageRoute(
                builder: (_) => const ChangeContactScreen.email(),
              ),
            ),
          ),
          const SizedBox(height: 12),
          _SecurityRow(
            key: const ValueKey('change-phone-tile'),
            icon: Icons.phone_outlined,
            title: 'Change Phone',
            subtitle: 'Verified with a code before the new number is saved.',
            onTap: () => Navigator.of(context).push(
              AppPageRoute(
                builder: (_) => const ChangeContactScreen.phone(),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _SecurityRow extends StatelessWidget {
  const _SecurityRow({
    super.key,
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Material(
      color: AppColors.surface,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(18),
        side: BorderSide(color: AppColors.divider),
      ),
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 12, 16),
          child: Row(
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: AppColors.primaryDark.withValues(alpha: 0.06),
                  shape: BoxShape.circle,
                ),
                child: Icon(icon, size: 20, color: AppColors.primaryDark),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: theme.textTheme.titleSmall),
                    const SizedBox(height: 3),
                    Text(
                      subtitle,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: AppColors.textSecondary,
                        height: 1.35,
                      ),
                    ),
                  ],
                ),
              ),
              Icon(Icons.chevron_right, color: AppColors.textSecondary),
            ],
          ),
        ),
      ),
    );
  }
}
