// lib/screens/shared/profile_screen.dart
//
// Path check: lives at lib/screens/shared/profile_screen.dart
//   -> theme      => '../../theme/app_theme.dart'
//   -> auth state => '../../state/auth_state.dart'
//   -> image pick => '../../utils/image_picker_helper.dart'
//
// No page title here — the bottom nav already says "Profile", and the
// identity card is the obvious hero already, so a headline above it
// would just be a caption for something that doesn't need one. Info
// rows sit on a ledger-style rule (a thin left spine, not the
// product-pairing "band" Home uses) — this is a personal record, not
// a merchandising moment, so it shouldn't borrow that vocabulary.
//
// Design-pass note:
// The ledger's layout restraint above is still right, but restraint on
// layout was being used as an excuse for restraint on TYPOGRAPHY too,
// and the screen had no display-scale element anywhere — on the one
// screen in the app that is entirely about a specific person. Three
// things changed:
//   1. The avatar is an Arch, not a 56px circle. Every other "this is
//      the important photo" moment (product hero, seller portrait)
//      already had the arch; the avatar was the one place the shape
//      language visibly forgot itself.
//   2. A stat strip inside the hero, built from data that already
//      existed and was going unread — mockOrders, RecentlyViewedState,
//      CourierState.completedJobs, and AuthState.memberSince. Every
//      number is real; nothing here is a fabricated activity signal
//      (same rule RecentlyViewedRail set for itself).
//   3. Buyers get a strip of what they've actually been looking at, so
//      the screen reads as this person rather than as a settings form.

import 'dart:io';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../state/auth_state.dart';
import '../../state/courier_state.dart';
import '../../state/order_state.dart';
import '../../state/recently_viewed_state.dart';
import '../../state/wishlist_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/image_picker_helper.dart';
import '../../utils/money_format.dart';
import '../../widgets/editorial_shapes.dart';
import '../buyer/product_details_screen.dart';
import '../buyer/widgets/product_card.dart' show ProductImage;
import 'account_security_screen.dart';
import 'edit_profile_screen.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/motion/cloud_transition.dart';
import '../../widgets/motion/ease_in_frame.dart';
import '../../widgets/theme_toggle.dart';
import '../buyer/address_book_screen.dart';
import '../courier/rider_profile_section.dart';

/// Profile screen reused by both BuyerShell and CourierShell. Reads
/// name/email/phone/address from the shared AuthState and edits them
/// via EditProfileScreen — the part that matters most is still the
/// logout action.
class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key, this.title = 'Profile'});

  final String title;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final auth = context.watch<AuthState>();
    final isCourier = auth.role == UserRole.courier;

    return Scaffold(
      backgroundColor: Colors.transparent, // the app-wide gradient in main.dart paints this
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(AppSpacing.s20, AppSpacing.s16, AppSpacing.s20, AppSpacing.s24),
          children: [
            EaseInFrame(
              index: 0,
              child: _buildHeroCard(context, theme, ext, auth, isCourier),
            ),
            const SizedBox(height: AppSpacing.s24),
            if (!isCourier) ...[
              const EaseInFrame(
                index: 1,
                child: _RecentlyLookedAt(),
              ),
            ],
            // Curve transitions from the hero into the record zone —
            // without it the drop from the gradient card to the plain
            // left-spine ledger reads as the design running out, not
            // as two intentional zones.
            CurvedSectionBreak(
              color: AppColors.surfaceMuted.withValues(alpha: 0.7),
              height: 36,
            ),
            const SizedBox(height: AppSpacing.s8),
            EaseInFrame(
              index: 2,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('ACCOUNT DETAILS', style: AppType.eyebrow),
                  const SizedBox(height: AppSpacing.s12),
                  _LedgerCard(
                    rows: [
                      _LedgerRow(
                        Icons.phone_outlined,
                        'Phone',
                        auth.phone,
                        emptyPrompt: 'Add phone',
                        onTap: () => Navigator.of(context).push(
                          AppPageRoute(builder: (_) => const EditProfileScreen()),
                        ),
                      ),
                      _LedgerRow(
                        Icons.wc_outlined,
                        'Sex',
                        auth.sex,
                        emptyPrompt: 'Add sex',
                        onTap: () => Navigator.of(context).push(
                          AppPageRoute(builder: (_) => const EditProfileScreen()),
                        ),
                      ),
                      _LedgerRow(
                        Icons.location_on_outlined,
                        'Address',
                        auth.defaultAddress?.summary ?? auth.addressSummary,
                        emptyPrompt: 'Add address',
                        onTap: () => Navigator.of(context).push(
                          AppPageRoute(
                            builder: (_) => isCourier
                                ? const EditProfileScreen()
                                : const AddressBookScreen(),
                          ),
                        ),
                      ),
                    ],
                  ),
                  if (!isCourier) ...[
                    const SizedBox(height: AppSpacing.s12),
                    SizedBox(
                      width: double.infinity,
                      child: OutlinedButton.icon(
                        key: const ValueKey('manage-addresses'),
                        onPressed: () => Navigator.of(context).push(
                          AppPageRoute(
                            builder: (_) => const AddressBookScreen(),
                          ),
                        ),
                        icon: const Icon(Icons.location_city_outlined, size: 18),
                        label: const Text('Manage Addresses'),
                      ),
                    ),
                    const SizedBox(height: AppSpacing.s12),
                    SizedBox(
                      width: double.infinity,
                      child: OutlinedButton.icon(
                        key: const ValueKey('account-security'),
                        onPressed: () => Navigator.of(context).push(
                          AppPageRoute(
                            builder: (_) => const AccountSecurityScreen(),
                          ),
                        ),
                        icon: const Icon(Icons.shield_outlined, size: 18),
                        label: const Text('Account & Security'),
                      ),
                    ),
                  ],
                ],
              ),
            ),
            if (isCourier) ...[
              const SizedBox(height: AppSpacing.s24),
              const EaseInFrame(index: 3, child: RiderProfileSection()),
            ],
            const SizedBox(height: AppSpacing.s24),
            EaseInFrame(
              index: 3,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('APPEARANCE', style: AppType.eyebrow),
                  const SizedBox(height: AppSpacing.s12),
                  const ThemeToggle(),
                ],
              ),
            ),
            const SizedBox(height: AppSpacing.s24),
            EaseInFrame(
              index: 4,
              child: SizedBox(
                width: double.infinity,
                child: OutlinedButton.icon(
                  onPressed: () => _logout(context),
                  icon: const Icon(Icons.logout, size: 18),
                  label: const Text('Log Out'),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildHeroCard(
    BuildContext context,
    ThemeData theme,
    AppThemeExtension ext,
    AuthState auth,
    bool isCourier,
  ) {
    return ClipRRect(
      // Asymmetric corners instead of the old uniform heroRadius — the
      // one feature surface on this screen picks up the same "leaf"
      // shape language as Home's cover story and feature tiles.
      borderRadius: editorialRadiusRaw(0, big: ext.heroRadius * 1.4, small: 8),
      child: Container(
        padding: const EdgeInsets.fromLTRB(AppSpacing.s16, AppSpacing.s16, AppSpacing.s16, AppSpacing.s16),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [AppColors.primaryDark, Color.lerp(AppColors.primaryDark, Colors.black, 0.25)!],
          ),
        ),
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            Positioned(
              top: -50,
              right: -50,
              child: Container(
                width: 160,
                height: 160,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: RadialGradient(
                    colors: [
                      AppColors.textOnDark.withValues(alpha: 0.12),
                      AppColors.textOnDark.withValues(alpha: 0.0),
                    ],
                  ),
                ),
              ),
            ),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _buildAvatar(context, auth),
                    const SizedBox(width: AppSpacing.s16),
                    Expanded(
                      child: Padding(
                        padding: const EdgeInsets.only(top: AppSpacing.s4),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              isCourier ? 'COURIER' : 'BUYER',
                              style: AppType.eyebrow.copyWith(
                                color: AppColors.secondary,
                              ),
                            ),
                            const SizedBox(height: AppSpacing.s8),
                            Text(
                              auth.name ?? 'Guest',
                              style: theme.textTheme.titleLarge?.copyWith(
                                color: AppColors.textOnDark,
                                height: 1.15,
                              ),
                            ),
                            const SizedBox(height: AppSpacing.s4),
                            Text(
                              auth.email ?? '',
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: AppColors.textOnDark.withValues(alpha: 0.8),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    IconButton(
                      onPressed: () => Navigator.of(context).push(
                        AppPageRoute(builder: (_) => const EditProfileScreen()),
                      ),
                      icon: Icon(Icons.edit_outlined, color: AppColors.textOnDark),
                      tooltip: 'Edit Profile',
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.s16),
                Divider(color: AppColors.textOnDark.withValues(alpha: 0.18), height: 1),
                const SizedBox(height: AppSpacing.s16),
                _StatStrip(isCourier: isCourier, auth: auth),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildAvatar(BuildContext context, AuthState auth) {
    final hasRealPhoto = auth.avatarPath != null && File(auth.avatarPath!).existsSync();

    return Semantics(
      button: true,
      label: 'Change profile photo',
      child: InkWell(
        onTap: () async {
          final picked = await pickAndSaveImage(context, filenamePrefix: 'avatar');
          if (picked != null) {
            // ignore: use_build_context_synchronously
            context.read<AuthState>().updateProfile(avatarPath: picked.path);
          }
        },
        customBorder: const CircleBorder(),
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          // Arch, not ClipOval. This is the app's "important photo"
          // silhouette and the avatar is the most important photo on
          // the screen — a plain circle here was the one place the
          // shape language didn't reach.
          Arch(
            footRadius: 10,
            child: SizedBox(
              width: 68,
              height: 84,
              child: hasRealPhoto
                  ? Image.file(File(auth.avatarPath!), fit: BoxFit.cover)
                  : _placeholderAvatar(auth),
            ),
          ),
          Positioned(
            right: -4,
            bottom: -4,
            child: Container(
              padding: const EdgeInsets.all(AppSpacing.s4),
              decoration: BoxDecoration(color: AppColors.surface, shape: BoxShape.circle),
              child: Icon(Icons.camera_alt, size: 12, color: AppColors.primaryDark),
            ),
          ),
        ],
      ),
      ),
    );
  }

  Widget _placeholderAvatar(AuthState auth) {
    // A monogram, not a stranger's face. The old Picsum portrait put an
    // unrelated stock photograph on the most personal surface in the app;
    // the initial is honest, on-brand, and cannot fail to load.
    final source = (auth.name ?? auth.email ?? '').trim();
    final initial = source.isEmpty
        ? 'Z'
        : String.fromCharCode(source.runes.first).toUpperCase();
    return Semantics(
      image: true,
      label: 'Profile photo placeholder',
      excludeSemantics: true,
      child: Container(
        color: AppColors.secondary,
        alignment: Alignment.center,
        child: Text(
          initial,
          style: AppType.masthead.copyWith(
            fontSize: 34,
            height: 1,
            color: AppColors.textPrimary,
          ),
        ),
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
            padding: const EdgeInsets.fromLTRB(AppSpacing.s20, AppSpacing.s20, AppSpacing.s20, AppSpacing.s16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('LOG OUT?', style: AppType.eyebrow),
                const SizedBox(height: AppSpacing.s8),
                Text('Log out of Zefanya?', style: dialogTheme.textTheme.titleMedium),
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
                        onPressed: () => Navigator.of(dialogContext).pop(false),
                        child: const Text('Stay'),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.s12),
                    Expanded(
                      child: ElevatedButton(
                        onPressed: () => Navigator.of(dialogContext).pop(true),
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
        // A rider signing out is no longer available for work, even though the
        // job already picked up stays with them when they sign back in.
        if (context.read<AuthState>().role == UserRole.courier) {
          context.read<CourierState>().goOffline();
        }
        context.read<AuthState>().logout();
        // AuthGate (in main.dart) rebuilds to LoginScreen once the state
        // changes — this just unwinds back to that root route.
        Navigator.of(context).popUntil((route) => route.isFirst);
      },
    );
  }
}

// ---------------------------------------------------------------------
// Stats
// ---------------------------------------------------------------------

const List<String> _monthAbbrev = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];

String _memberSinceLabel(DateTime? since) =>
    since == null ? '—' : '${_monthAbbrev[since.month - 1]} ${since.year}';

/// The screen's one display-scale moment: one dominant numeral plus two
/// supporting ones. Deliberately NOT three equal 40pt numbers — three
/// things at the same size is the "no focal point" problem the type
/// scale exists to fix, just louder.
class _StatStrip extends StatelessWidget {
  const _StatStrip({required this.isCourier, required this.auth});

  final bool isCourier;
  final AuthState auth;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final String primaryValue;
    final String primaryLabel;
    final String secondValue;
    final String secondLabel;
    String? followingCount;

    if (isCourier) {
      final courier = context.watch<CourierState>();
      primaryValue = '${courier.completedJobs.length}';
      primaryLabel = 'DELIVERIES DONE';
      secondValue = formatPeso(courier.totalEarnings);
      secondLabel = 'EARNED';
    } else {
      final viewed = context.watch<RecentlyViewedState>().items.length;
      final following = context.watch<WishlistState>().followedSellers.length;
      primaryValue = '${context.watch<OrderState>().orders.length}';
      primaryLabel = 'ORDERS PLACED';
      secondValue = '$viewed';
      secondLabel = 'PIECES VIEWED';
      followingCount = '$following';
    }

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          flex: 5,
          child: _stat(primaryLabel, primaryValue, 44),
        ),
        Container(
          width: 1,
          height: 62,
          color: AppColors.textOnDark.withValues(alpha: 0.18),
        ),
        Expanded(
          flex: 4,
          child: Padding(
            padding: const EdgeInsets.only(left: AppSpacing.s16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _stat(secondLabel, secondValue, 20),
                const SizedBox(height: AppSpacing.s12),
                _stat('MEMBER SINCE', _memberSinceLabel(auth.memberSince), 20),
                if (followingCount != null) ...[
                  const SizedBox(height: AppSpacing.s12),
                  _stat('FOLLOWING', followingCount, 20),
                ],
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _stat(String label, String value, double size) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: AppType.eyebrow.copyWith(
            color: AppColors.textOnDark.withValues(alpha: 0.65),
            letterSpacing: 1.2,
          ),
        ),
        const SizedBox(height: AppSpacing.s4),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerLeft,
          child: Text(
            value,
            maxLines: 1,
            style: AppType.priceNumeral(size, color: AppColors.textOnDark),
          ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------
// Recently looked at
// ---------------------------------------------------------------------

/// Buyer-only. Same rule RecentlyViewedRail set: if there's no real
/// history, this renders nothing rather than showing a placeholder —
/// an empty "here's what you've been up to" is worse than no section.
///
/// Deliberately does NOT filter out what's already been bought, unlike
/// Home's "pick up where you left off" rail (see `unpurchased` in
/// recently_viewed_rail.dart). This one is a *record* of what this person
/// looked at; a record that quietly edited out the purchases would be a
/// worse lie than a slightly redundant strip. The merchandising surface
/// filters because it's making a suggestion. A history doesn't.
class _RecentlyLookedAt extends StatelessWidget {
  const _RecentlyLookedAt();

  @override
  Widget build(BuildContext context) {
    final products = context.watch<RecentlyViewedState>().items;
    AppColors.watch(context);
    if (products.isEmpty) return const SizedBox.shrink();

    final shown = products.take(8).toList(growable: false);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('RECENTLY LOOKED AT', style: AppType.eyebrow),
        const SizedBox(height: AppSpacing.s12),
        SizedBox(
          height: 96,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: EdgeInsets.zero,
            itemCount: shown.length,
            separatorBuilder: (_, _) => const SizedBox(width: AppSpacing.s8),
            itemBuilder: (context, i) {
              final product = shown[i];
              return GestureDetector(
                onTap: () => Navigator.of(context).push(
                  AppPageRoute(builder: (_) => ProductDetailsScreen(product: product)),
                ),
                child: ClipRRect(
                  // Alternating lean, same as any other row of these —
                  // a line of identical shapes is a uniform pattern
                  // again, which is what editorialRadius exists to break.
                  borderRadius: editorialRadius(i, ShapeTier.accent),
                  child: SizedBox(
                    width: 74,
                    height: 96,
                    child: ProductImage(product: product, iconSize: 24),
                  ),
                ),
              );
            },
          ),
        ),
        const SizedBox(height: AppSpacing.s24),
      ],
    );
  }
}

// ---------------------------------------------------------------------
// Ledger
// ---------------------------------------------------------------------

class _LedgerRow {
  const _LedgerRow(
    this.icon,
    this.label,
    this.value, {
    this.onTap,
    this.emptyPrompt,
  });
  final IconData icon;
  final String label;
  final String? value;
  final VoidCallback? onTap;
  final String? emptyPrompt;
}

/// Opaque surface token card hosting account details rows.
/// Meets WCAG AA contrast (>=4.5:1) in both light and dark modes.
class _LedgerCard extends StatelessWidget {
  const _LedgerCard({required this.rows});

  final List<_LedgerRow> rows;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.divider),
        boxShadow: [
          BoxShadow(
            color: AppColors.cardShadow,
            blurRadius: 10,
            offset: const Offset(0, 3),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < rows.length; i++) ...[
            _rowTile(context, rows[i]),
            if (i < rows.length - 1)
              Divider(height: 1, thickness: 1, color: AppColors.divider),
          ],
        ],
      ),
    );
  }

  Widget _rowTile(BuildContext context, _LedgerRow row) {
    final hasValue = row.value != null && row.value!.trim().isNotEmpty;
    final prompt = row.emptyPrompt ?? "Add ${row.label.toLowerCase()}";

    final content = Padding(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.s16, vertical: AppSpacing.s12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Icon(row.icon, size: 20, color: AppColors.textSecondary),
          const SizedBox(width: AppSpacing.s12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  row.label,
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w500,
                    color: AppColors.textSecondary,
                  ),
                ),
                const SizedBox(height: AppSpacing.s4),
                if (hasValue)
                  Text(
                    row.value!,
                    style: TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w600,
                      color: AppColors.textPrimary,
                    ),
                  )
                else
                  Text(
                    prompt,
                    style: TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w600,
                      color: AppColors.accent,
                      decoration: TextDecoration.underline,
                    ),
                  ),
              ],
            ),
          ),
          if (!hasValue && row.onTap != null)
            Icon(Icons.add_circle_outline, size: 18, color: AppColors.accent),
        ],
      ),
    );

    if (!hasValue && row.onTap != null) {
      return Semantics(
        label: prompt,
        button: true,
        child: InkWell(
          onTap: row.onTap,
          borderRadius: BorderRadius.circular(14),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 48),
            child: content,
          ),
        ),
      );
    }

    return ConstrainedBox(
      constraints: const BoxConstraints(minHeight: 48),
      child: content,
    );
  }
}
