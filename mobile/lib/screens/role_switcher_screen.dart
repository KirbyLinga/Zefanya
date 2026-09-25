// lib/screens/role_switcher_screen.dart
//
// TEMPORARY testing entry point — lets you jump straight into either
// shell without going through registration/login. Replace `home:` in
// main.dart with a real auth/role router once login is built, and
// delete this file (or gate it behind a debug flag) before shipping.

import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import 'buyer/buyer_shell.dart';
import 'courier/courier_shell.dart';
import '../widgets/app_page_route.dart';

class RoleSwitcherScreen extends StatelessWidget {
  const RoleSwitcherScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;

    return Scaffold(
      backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 28),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Text(
                  'Zefanya',
                  style: theme.textTheme.displaySmall,
                  textAlign: TextAlign.center,
                ),
              ),
              const SizedBox(height: 8),
              Center(
                child: Text(
                  'Marketplace',
                  style: theme.textTheme.bodyMedium?.copyWith(color: AppColors.textSecondary),
                ),
              ),
              const SizedBox(height: 16),
              Center(child: _buildTestingBadge(theme, ext)),
              const SizedBox(height: 48),
              _RoleCard(
                icon: Icons.storefront_outlined,
                title: 'Enter Buyer App',
                subtitle: 'Browse, order, and track deliveries',
                color: AppColors.primaryDark,
                onTap: () => Navigator.of(context).push(
                  AppPageRoute(builder: (_) => const BuyerShell()),
                ),
              ),
              const SizedBox(height: 16),
              _RoleCard(
                icon: Icons.local_shipping_outlined,
                title: 'Enter Courier App',
                subtitle: 'Accept requests and manage deliveries',
                color: AppColors.tertiary,
                onTap: () => Navigator.of(context).push(
                  AppPageRoute(builder: (_) => const CourierShell()),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildTestingBadge(ThemeData theme, AppThemeExtension ext) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
      decoration: BoxDecoration(
        color: AppColors.secondary,
        borderRadius: BorderRadius.circular(ext.pillRadius),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.science_outlined, size: 14, color: AppColors.primaryDark),
          const SizedBox(width: 6),
          Flexible(
            child: Text(
              'Testing Mode: UI Shell Router',
              style: theme.textTheme.labelSmall?.copyWith(
                color: AppColors.primaryDark,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _RoleCard extends StatelessWidget {
  const _RoleCard({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.color,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;

    return Material(
      color: AppColors.surfaceMuted,
      borderRadius: BorderRadius.circular(ext.cardRadius),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(ext.cardRadius),
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Row(
            children: [
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(color: color, shape: BoxShape.circle),
                child: Icon(icon, color: AppColors.textOnDark, size: 22),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: theme.textTheme.titleMedium),
                    const SizedBox(height: 2),
                    Text(subtitle, style: theme.textTheme.bodySmall),
                  ],
                ),
              ),
              const Icon(Icons.arrow_forward_ios, size: 14, color: AppColors.textSecondary),
            ],
          ),
        ),
      ),
    );
  }
}
