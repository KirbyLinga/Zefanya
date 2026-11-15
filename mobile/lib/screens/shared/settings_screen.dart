// lib/screens/shared/settings_screen.dart
//
// The one place account-level choices live: credentials, addresses (or the
// rider's vehicle and documents), appearance, support, About, and sign-out.
//
// Profile used to carry its own theme toggle and logout button. Two places
// that can sign someone out is one too many, and a theme switch does not
// belong on a personal record. Both moved here.
//
// Developer options are not visible by default in any build. Seven taps on
// About reveals them; nothing is gated on kDebugMode, so a demo release build
// can still reach the QR generator after the same deliberate gesture.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../config/app_info.dart';
import '../../config/dev_flags.dart';
import '../../state/auth_state.dart';
import '../../state/courier_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/motion/cloud_transition.dart';
import '../../widgets/theme_toggle.dart';
import '../buyer/address_book_screen.dart';
import '../courier/dev_qr_generator_screen.dart';
import '../courier/rider_vehicle_screen.dart';
import 'account_security_screen.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  int _aboutTaps = 0;

  bool get _developerUnlocked => _aboutTaps >= kDeveloperOptionsTapCount;

  void _tapAbout() {
    if (_developerUnlocked) return;
    HapticFeedback.selectionClick();
    final next = _aboutTaps + 1;
    setState(() => _aboutTaps = next);
    if (next == kDeveloperOptionsTapCount) {
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(
          const SnackBar(content: Text('Developer options unlocked')),
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final auth = context.watch<AuthState>();
    final isCourier = auth.role == UserRole.courier;

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
        children: [
          const EditorialHeader(
            eyebrow: 'YOUR ACCOUNT',
            title: 'Settings',
            padding: EdgeInsets.fromLTRB(0, 0, 0, 20),
          ),
          Text('ACCOUNT', style: AppType.eyebrow),
          const SizedBox(height: 12),
          if (!isCourier)
            _SettingsTile(
              key: const ValueKey('settings-account-security'),
              icon: Icons.shield_outlined,
              title: 'Account & Security',
              subtitle: 'Password, email and phone number.',
              onTap: () => Navigator.of(context).push(
                AppPageRoute(builder: (_) => const AccountSecurityScreen()),
              ),
            ),
          if (!isCourier) const SizedBox(height: 12),
          if (!isCourier)
            _SettingsTile(
              key: const ValueKey('settings-addresses'),
              icon: Icons.location_city_outlined,
              title: 'Addresses',
              subtitle: 'Save up to 10 delivery addresses.',
              onTap: () => Navigator.of(context).push(
                AppPageRoute(builder: (_) => const AddressBookScreen()),
              ),
            ),
          if (isCourier)
            _SettingsTile(
              key: const ValueKey('settings-vehicle'),
              icon: Icons.two_wheeler_outlined,
              title: 'Vehicle & documents',
              subtitle: 'Plate, licence and OR/CR on file.',
              onTap: () => Navigator.of(context).push(
                AppPageRoute(builder: (_) => const RiderVehicleScreen()),
              ),
            ),
          const SizedBox(height: 26),
          Text('APPEARANCE', style: AppType.eyebrow),
          const SizedBox(height: 12),
          const ThemeToggle(),
          const SizedBox(height: 8),
          Text(
            'System follows your phone\'s light or dark setting.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: AppColors.textSecondary,
            ),
          ),
          const SizedBox(height: 26),
          Text('SUPPORT', style: AppType.eyebrow),
          const SizedBox(height: 12),
          _SettingsTile(
            key: const ValueKey('settings-help'),
            icon: Icons.help_outline,
            title: 'Help & Support',
            subtitle: 'Answers to the questions this build can answer.',
            onTap: () => Navigator.of(context).push(
              AppPageRoute(builder: (_) => const HelpSupportScreen()),
            ),
          ),
          const SizedBox(height: 12),
          _SettingsTile(
            key: const ValueKey('settings-terms'),
            icon: Icons.description_outlined,
            title: 'Terms & Privacy',
            subtitle: 'How this demo handles your data.',
            onTap: () => Navigator.of(context).push(
              AppPageRoute(builder: (_) => const TermsPrivacyScreen()),
            ),
          ),
          const SizedBox(height: 26),
          Text('ABOUT', style: AppType.eyebrow),
          const SizedBox(height: 12),
          _SettingsTile(
            key: const ValueKey('settings-about'),
            icon: Icons.info_outline,
            title: 'About Zefanya',
            subtitle: 'Version $kAppVersion',
            onTap: _tapAbout,
            trailing: _aboutTaps > 0 && !_developerUnlocked
                ? Text(
                    '${kDeveloperOptionsTapCount - _aboutTaps} more',
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: AppColors.textSecondary,
                    ),
                  )
                : null,
          ),
          if (_developerUnlocked) ...[
            const SizedBox(height: 26),
            Text('DEVELOPER', style: AppType.eyebrow),
            const SizedBox(height: 12),
            _SettingsTile(
              key: const ValueKey('settings-developer'),
              icon: Icons.build_outlined,
              title: 'Developer options',
              subtitle: 'QR generator and build mode.',
              onTap: () => Navigator.of(context).push(
                AppPageRoute(builder: (_) => const DeveloperOptionsScreen()),
              ),
            ),
          ],
          const SizedBox(height: 32),
          SizedBox(
            width: double.infinity,
            child: OutlinedButton.icon(
              key: const ValueKey('settings-logout'),
              onPressed: () => _logout(context),
              icon: const Icon(Icons.logout, size: 18),
              label: const Text('Log Out'),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _logout(BuildContext context) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) {
        AppColors.watch(dialogContext);
        final dialogTheme = Theme.of(dialogContext);
        return Dialog(
          shape: editorialShape(0, ShapeTier.card),
          backgroundColor: AppColors.surface,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.s20,
              AppSpacing.s20,
              AppSpacing.s20,
              AppSpacing.s16,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('LOG OUT?', style: AppType.eyebrow),
                const SizedBox(height: AppSpacing.s8),
                Text(
                  'Log out of Zefanya?',
                  style: dialogTheme.textTheme.titleMedium,
                ),
                const SizedBox(height: AppSpacing.s8),
                Text(
                  'You will need to sign back in to shop and track orders.',
                  style: dialogTheme.textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
                ),
                const SizedBox(height: AppSpacing.s20),
                Row(
                  children: [
                    Expanded(
                      child: TextButton(
                        key: const ValueKey('settings-logout-stay'),
                        onPressed: () =>
                            Navigator.of(dialogContext).pop(false),
                        child: const Text('Stay'),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.s12),
                    Expanded(
                      child: ElevatedButton(
                        key: const ValueKey('settings-logout-confirm'),
                        onPressed: () =>
                            Navigator.of(dialogContext).pop(true),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AppColors.danger,
                          foregroundColor: AppColors.textOnDark,
                        ),
                        child: const Text('Log out'),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
    if (confirmed != true || !context.mounted) return;
    await CloudTransition.run(
      context,
      swap: () {
        if (context.read<AuthState>().role == UserRole.courier) {
          context.read<CourierState>().goOffline();
        }
        context.read<AuthState>().logout();
        Navigator.of(context).popUntil((route) => route.isFirst);
      },
    );
  }
}

class _SettingsTile extends StatelessWidget {
  const _SettingsTile({
    super.key,
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.trailing,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final Widget? trailing;

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
              if (trailing != null) trailing!,
              const SizedBox(width: 4),
              Icon(Icons.chevron_right, color: AppColors.textSecondary),
            ],
          ),
        ),
      ),
    );
  }
}

class HelpSupportScreen extends StatelessWidget {
  const HelpSupportScreen({super.key});

  static const _faqs = <(String, String)>[
    (
      'How do I track my order?',
      'Open Orders, choose the order, and read the tracking timeline. '
          'The latest event is always the top card on the order.',
    ),
    (
      'How do I cancel an order?',
      'Open the order before the seller marks it Ready for Pickup, tap '
          'Cancel order, and choose a reason. The reason is required.',
    ),
    (
      'Can I return an item?',
      'A return can be requested after delivery and before the order is '
          'completed. Choose a reason, add 1 to 5 photos, and wait for the '
          'seller to respond.',
    ),
    (
      'How do I become a rider?',
      'Sign out, register as a Rider, upload your licence and OR/CR, then '
          'wait for Logistics to approve the account.',
    ),
    (
      'Is payment real in this build?',
      'Cash on delivery is the supported method. The e-wallet and card '
          'options are a simulator: no money moves and no gateway is called.',
    ),
    (
      'Why can I not chat in the live build?',
      'Chat here is a local demo. When the app is pointed at the live API '
          'the chat entry points are hidden rather than pretending to send.',
    ),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
        children: [
          const EditorialHeader(
            eyebrow: 'HELP & SUPPORT',
            title: 'Answers first',
            padding: EdgeInsets.fromLTRB(0, 0, 0, 20),
          ),
          for (final faq in _faqs) ...[
            _FaqCard(question: faq.$1, answer: faq.$2),
            const SizedBox(height: 12),
          ],
        ],
      ),
    );
  }
}

class _FaqCard extends StatelessWidget {
  const _FaqCard({required this.question, required this.answer});

  final String question;
  final String answer;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: AppColors.divider),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(question, style: theme.textTheme.titleSmall),
          const SizedBox(height: 8),
          Text(
            answer,
            style: theme.textTheme.bodySmall?.copyWith(
              color: AppColors.textSecondary,
              height: 1.45,
            ),
          ),
        ],
      ),
    );
  }
}

class TermsPrivacyScreen extends StatelessWidget {
  const TermsPrivacyScreen({super.key});

  static const _sections = <(String, String)>[
    (
      'Using this build',
      'Zefanya is a demonstration marketplace. Orders, payments, vouchers '
          'and delivery events shown in mock mode are examples, not real '
          'transactions. Do not send money to anyone because of this app.',
    ),
    (
      'Your account',
      'You are responsible for the details you enter and for keeping your '
          'password private. Rider accounts are reviewed by Logistics before '
          'they can accept work.',
    ),
    (
      'What we store',
      'In mock mode your profile, addresses, cart and chat stay on this '
          'device. In live mode the fields you submit are sent to the API '
          'you configured, over HTTPS, so the server can answer.',
    ),
    (
      'Photos and documents',
      'Profile photos, review photos and rider documents are re-encoded '
          'before storage and stripped of EXIF location data. Rider '
          'documents are uploaded to private storage.',
    ),
    (
      'Your choices',
      'You can remove your profile photo, delete saved addresses, and sign '
          'out at any time from Settings. Uninstalling the app clears the '
          'local mock data.',
    ),
  ];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
        children: [
          const EditorialHeader(
            eyebrow: 'TERMS & PRIVACY',
            title: 'The short version',
            padding: EdgeInsets.fromLTRB(0, 0, 0, 20),
          ),
          for (final section in _sections) ...[
            Text(section.$1, style: theme.textTheme.titleSmall),
            const SizedBox(height: 6),
            Text(
              section.$2,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: AppColors.textSecondary,
                height: 1.5,
              ),
            ),
            const SizedBox(height: 20),
          ],
        ],
      ),
    );
  }
}

class DeveloperOptionsScreen extends StatelessWidget {
  const DeveloperOptionsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
        children: [
          const EditorialHeader(
            eyebrow: 'DEVELOPER OPTIONS',
            title: 'Build mode',
            padding: EdgeInsets.fromLTRB(0, 0, 0, 20),
          ),
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: AppColors.divider),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('DATA SOURCE', style: AppType.eyebrow),
                const SizedBox(height: 10),
                Text(
                  kUseLiveApi ? 'Live API' : 'Mock data',
                  style: theme.textTheme.titleSmall,
                ),
                const SizedBox(height: 6),
                Text(
                  kUseLiveApi
                      ? (kApiBaseUrl.isEmpty
                          ? 'Live mode is on but no API base URL was supplied. '
                              'Requests will report a configuration error.'
                          : 'Requests go to $kApiBaseUrl.')
                      : 'Everything on screen comes from the app\'s own '
                          'fixtures. No network requests are made.',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                    height: 1.45,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),
          _SettingsTile(
            key: const ValueKey('developer-qr-generator'),
            icon: Icons.qr_code,
            title: 'QR generator',
            subtitle: 'Render a Zefanya delivery pass for handover testing.',
            onTap: () => Navigator.of(context).push(
              AppPageRoute(builder: (_) => const DevQrGeneratorScreen()),
            ),
          ),
        ],
      ),
    );
  }
}
