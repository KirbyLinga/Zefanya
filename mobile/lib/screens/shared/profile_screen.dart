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
import '../../theme/app_theme.dart';
import '../../utils/image_picker_helper.dart';
import '../../widgets/editorial_shapes.dart';
import '../buyer/product_details_screen.dart';
import '../buyer/widgets/product_card.dart' show ProductImage, productTint;
import 'edit_profile_screen.dart';
import '../../widgets/app_page_route.dart';

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
          padding: const EdgeInsets.fromLTRB(20, 18, 20, 24),
          children: [
            _buildHeroCard(context, theme, ext, auth, isCourier),
            const SizedBox(height: 26),
            if (!isCourier) ...[
              const _RecentlyLookedAt(),
            ],
            // Curve transitions from the hero into the record zone —
            // without it the drop from the gradient card to the plain
            // left-spine ledger reads as the design running out, not
            // as two intentional zones.
            CurvedSectionBreak(
              color: AppColors.surfaceMuted.withValues(alpha: 0.7),
              height: 36,
            ),
            const SizedBox(height: 8),
            Text('ACCOUNT DETAILS', style: AppType.eyebrow),
            const SizedBox(height: 12),
            _LedgerCard(
              rows: [
                _LedgerRow(Icons.phone_outlined, 'Phone', auth.phone),
                _LedgerRow(Icons.wc_outlined, 'Sex', auth.sex),
                _LedgerRow(Icons.location_on_outlined, 'Address', auth.addressSummary),
              ],
            ),
            const SizedBox(height: 26),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                onPressed: () => _logout(context),
                icon: const Icon(Icons.logout, size: 18),
                label: const Text('Log Out'),
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
      borderRadius: editorialRadius(0, big: ext.heroRadius * 1.4, small: 8),
      child: Container(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 18),
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
                    const SizedBox(width: 16),
                    Expanded(
                      child: Padding(
                        padding: const EdgeInsets.only(top: 6),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              isCourier ? 'COURIER' : 'BUYER',
                              style: AppType.eyebrow.copyWith(
                                color: AppColors.secondary,
                              ),
                            ),
                            const SizedBox(height: 8),
                            Text(
                              auth.name ?? 'Guest',
                              style: theme.textTheme.titleLarge?.copyWith(
                                color: AppColors.textOnDark,
                                height: 1.15,
                              ),
                            ),
                            const SizedBox(height: 2),
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
                      icon: const Icon(Icons.edit_outlined, color: AppColors.textOnDark),
                      tooltip: 'Edit Profile',
                    ),
                  ],
                ),
                const SizedBox(height: 18),
                Divider(color: AppColors.textOnDark.withValues(alpha: 0.18), height: 1),
                const SizedBox(height: 16),
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
              padding: const EdgeInsets.all(5),
              decoration: const BoxDecoration(color: AppColors.surface, shape: BoxShape.circle),
              child: const Icon(Icons.camera_alt, size: 12, color: AppColors.primaryDark),
            ),
          ),
        ],
      ),
      ),
    );
  }

  Widget _placeholderAvatar(AuthState auth) {
    // Same "placeholder, not a licensing shortcut" caveat as
    // ProductImage in widgets/product_card.dart — shown only until the
    // user picks a real photo via the camera badge above.
    final seed = auth.email ?? auth.name ?? 'guest';
    return Image.network(
      'https://picsum.photos/seed/$seed/160/200',
      fit: BoxFit.cover,
      errorBuilder: (context, error, stackTrace) => Container(
        color: AppColors.tertiary,
        alignment: Alignment.center,
        child: const Icon(Icons.person, color: AppColors.textOnDark, size: 30),
      ),
    );
  }

  void _logout(BuildContext context) {
    context.read<AuthState>().logout();
    // AuthGate (in main.dart) rebuilds to LoginScreen once the state
    // changes — this just unwinds back to that root route.
    Navigator.of(context).popUntil((route) => route.isFirst);
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
    final String primaryValue;
    final String primaryLabel;
    final String secondValue;
    final String secondLabel;

    if (isCourier) {
      final courier = context.watch<CourierState>();
      primaryValue = '${courier.completedJobs.length}';
      primaryLabel = 'DELIVERIES DONE';
      secondValue = '\u20b1${courier.totalEarnings.toStringAsFixed(0)}';
      secondLabel = 'EARNED';
    } else {
      final viewed = context.watch<RecentlyViewedState>().items.length;
      primaryValue = '${context.watch<OrderState>().orders.length}';
      primaryLabel = 'ORDERS PLACED';
      secondValue = '$viewed';
      secondLabel = 'PIECES VIEWED';
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
            padding: const EdgeInsets.only(left: 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _stat(secondLabel, secondValue, 20),
                const SizedBox(height: 12),
                _stat('MEMBER SINCE', _memberSinceLabel(auth.memberSince), 20),
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
            letterSpacing: 2.0,
          ),
        ),
        const SizedBox(height: 6),
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
class _RecentlyLookedAt extends StatelessWidget {
  const _RecentlyLookedAt();

  @override
  Widget build(BuildContext context) {
    final products = context.watch<RecentlyViewedState>().items;
    if (products.isEmpty) return const SizedBox.shrink();

    final shown = products.take(8).toList(growable: false);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('RECENTLY LOOKED AT', style: AppType.eyebrow),
        const SizedBox(height: 12),
        SizedBox(
          height: 96,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: EdgeInsets.zero,
            itemCount: shown.length,
            separatorBuilder: (_, _) => const SizedBox(width: 10),
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
                  borderRadius: editorialRadius(i, big: 20, small: 5),
                  child: SizedBox(
                    width: 74,
                    height: 96,
                    child: ProductImage(
                      productId: product.id,
                      tint: productTint(product.category),
                      iconSize: 24,
                    ),
                  ),
                ),
              );
            },
          ),
        ),
        const SizedBox(height: 26),
      ],
    );
  }
}

// ---------------------------------------------------------------------
// Ledger
// ---------------------------------------------------------------------

class _LedgerRow {
  const _LedgerRow(this.icon, this.label, this.value);
  final IconData icon;
  final String label;
  final String? value;
}

/// A record card, not a merchandising band: a thin left spine ties the
/// rows together like entries on a form, each icon sitting on the
/// spine instead of floating loose in front of its own text.
class _LedgerCard extends StatelessWidget {
  const _LedgerCard({required this.rows});

  final List<_LedgerRow> rows;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 4),
      decoration: const BoxDecoration(
        border: Border(left: BorderSide(color: AppColors.divider, width: 2)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (var i = 0; i < rows.length; i++) ...[
            _rowTile(theme, rows[i]),
            if (i < rows.length - 1)
              const Padding(
                padding: EdgeInsets.symmetric(horizontal: 14),
                child: Divider(height: 1, color: AppColors.divider),
              ),
          ],
        ],
      ),
    );
  }

  Widget _rowTile(ThemeData theme, _LedgerRow row) {
    final hasValue = row.value != null && row.value!.isNotEmpty;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(row.icon, size: 18, color: AppColors.textSecondary),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(row.label, style: theme.textTheme.labelSmall),
                Text(
                  hasValue ? row.value! : 'Not set',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: hasValue ? AppColors.textPrimary : AppColors.textSecondary,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
