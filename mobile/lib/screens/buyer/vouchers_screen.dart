// lib/screens/buyer/vouchers_screen.dart
//
// Vouchers wallet. Pushed from Cart's voucher banner via
// Navigator.push<String>(...) — tapping "Use" here pops back with the
// voucher code, and Cart fills its field and applies it automatically.
// That's the whole point of this screen: Cart's voucher input used to
// require the buyer to already know a code with no way to discover one.
//
// Design-pass note:
// This screen was half-done rather than untouched — it already had a
// dashed seam and stub notches. Two things were wrong with that:
//
//   1. The notches were small circles filled with AppColors.background,
//      stacked over the card edge. That was correct when the scaffold
//      was a flat solid; it broke silently when main.dart started
//      painting a gradient behind every screen, because a flat swatch
//      over a gradient is the wrong color. They're now genuinely cut
//      out of the card path (see TicketClipper in editorial_shapes),
//      so whatever is behind shows through and it can't drift again.
//
//   2. The discount — the only thing anyone reads on a voucher — was
//      titleMedium, i.e. the same safe 11-24pt band as everything
//      else. It's now a display numeral, which is what makes the stub
//      read as a stub rather than a colored rectangle with text in it.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/pricing.dart';
import '../../state/cart_state.dart';
import '../../data/mock_data.dart' show Voucher;
import '../../theme/app_theme.dart';
import '../../utils/money_format.dart';
import '../../utils/timed_snackbar.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/staggered_reveal.dart';

/// Width of the dark stub, and therefore where the perforation falls.
const double _stubWidth = 96;

class VouchersScreen extends StatefulWidget {
  const VouchersScreen({super.key});

  @override
  State<VouchersScreen> createState() => _VouchersScreenState();
}

class _VouchersScreenState extends State<VouchersScreen>
    with SingleTickerProviderStateMixin {
  late final TabController _tabController =
      TabController(length: 3, vsync: this);

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    // The catalogue is the repository's answer, reached through the cart state
    // that loaded it — not a local constant, so a live build cannot show a
    // wallet of vouchers the server never offered.
    final cart = context.watch<CartState>();
    final claimedCodes = cart.claimedVoucherCodes.toSet();
    final available =
        cart.vouchers.where((v) => !claimedCodes.contains(v.code)).toList(growable: false);
    final claimed = cart.vouchers
        .where((v) => claimedCodes.contains(v.code))
        .toList(growable: false);

    return Scaffold(
      backgroundColor:
          Colors.transparent, // the app-wide gradient in main.dart paints this
      // Title out of the AppBar and into the app's own eyebrow +
      // Playfair voice, like every other non-Home screen.
      appBar: AppBar(
        bottom: TabBar(
          controller: _tabController,
          labelColor: AppColors.textPrimary,
          unselectedLabelColor: AppColors.textSecondary,
          indicatorColor: AppColors.primaryDark,
          tabs: const [
            Tab(key: ValueKey('voucher-tab-available'), text: 'AVAILABLE'),
            Tab(key: ValueKey('voucher-tab-claimed'), text: 'CLAIMED'),
            Tab(key: ValueKey('voucher-tab-used'), text: 'USED'),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: [
          _VoucherList(vouchers: available, claimable: true),
          _VoucherList(vouchers: claimed, claimable: false),
          const _VoucherList(vouchers: [], claimable: false, used: true),
        ],
      ),
    );
  }
}

/// A single wallet tab's list. [claimable] shows a Claim button and surfaces
/// the ineligible reason when a claim is blocked; otherwise a Remove button.
class _VoucherList extends StatelessWidget {
  const _VoucherList({
    required this.vouchers,
    required this.claimable,
    this.used = false,
  });

  final List<Voucher> vouchers;
  final bool claimable;
  final bool used;

  @override
  Widget build(BuildContext context) {
    if (vouchers.isEmpty) {
      final message = used
          ? 'No used vouchers yet.'
          : claimable
              ? 'Nothing to claim right now.'
              : 'No claimed vouchers yet.';
      final hint = used
          ? 'Used vouchers appear here once an order settles against them.'
          : claimable
              ? 'New vouchers land here as the server offers them.'
              : 'Claim one from Available and apply it at checkout.';
      return _EmptyState(message: message, hint: hint);
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 28),
      itemCount: vouchers.length,
      itemBuilder: (context, i) =>
          StaggeredReveal(index: i, child: _VoucherTile(voucher: vouchers[i], claimable: claimable, index: i)),
    );
  }
}

/// No vouchers to show on this tab.
class _EmptyState extends StatelessWidget {
  const _EmptyState({required this.message, required this.hint});

  final String message;
  final String hint;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.local_offer_outlined, size: 48, color: AppColors.textSecondary),
          const SizedBox(height: 16),
          Text(message, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          Text(hint, style: theme.textTheme.bodySmall?.copyWith(color: AppColors.textSecondary), textAlign: TextAlign.center),
        ],
      ),
    );
  }
}

class _VoucherTile extends StatelessWidget {
  const _VoucherTile({required this.voucher, required this.claimable, required this.index});

  final Voucher voucher;
  final bool claimable;
  final int index;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final cart = context.watch<CartState>();
    final claimed = cart.isClaimed(voucher.code);
    final unavailable =
        isVoucherExpired(voucher) ||
        isVoucherNotStarted(voucher) ||
        isVoucherUsageExhausted(
          voucher,
          buyerUses: cart.buyerUsesFor(voucher.code),
        );

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Ticket(
        notchFromLeft: _stubWidth,
        notchRadius: 9,
        cornerRadius: ext.cardRadius,
        child: Container(
          color: AppColors.surfaceMuted,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _Stub(voucher: voucher),
              // The seam sits ON the perforation line, between the two
              // punched holes.
              const SizedBox(width: 1, child: _DashedSeam()),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 14, 14, 14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(voucher.title, style: theme.textTheme.titleSmall),
                      const SizedBox(height: 3),
                      Text(
                        voucher.scopeLabel,
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: AppColors.primaryDark,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        voucher.discountLabel,
                        style: theme.textTheme.bodySmall,
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'Min. spend ${formatPeso(voucher.minSpend)} \u00b7 ${voucher.expiryLabel}',
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: AppColors.textSecondary,
                        ),
                      ),
                      if (_expiryCountdown(voucher.expiresAt) != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 2),
                          child: Text(
                            _expiryCountdown(voucher.expiresAt)!,
                            style: theme.textTheme.labelSmall?.copyWith(
                              // Expired: muted. Within 3 days: danger.
                              // Otherwise quiet secondary.
                              color: _isExpired(voucher.expiresAt)
                                  ? AppColors.textSecondary.withValues(
                                      alpha: 0.6,
                                    )
                                  : _isExpiringSoon(voucher.expiresAt)
                                  ? AppColors.danger
                                  : AppColors.textSecondary,
                              fontWeight:
                                  _isExpiringSoon(voucher.expiresAt) &&
                                      !_isExpired(voucher.expiresAt)
                                  ? FontWeight.w700
                                  : FontWeight.w400,
                            ),
                          ),
                        ),
                      const SizedBox(height: 12),
                      Wrap(
                        alignment: WrapAlignment.spaceBetween,
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 4,
                            ),
                            decoration: BoxDecoration(
                              color: AppColors.surface,
                              borderRadius: BorderRadius.circular(
                                ext.utilityRadius,
                              ),
                              border: Border.all(color: AppColors.divider),
                            ),
                            child: Text(
                              voucher.code,
                              style: theme.textTheme.labelSmall?.copyWith(
                                fontWeight: FontWeight.w700,
                                letterSpacing: 1.0,
                              ),
                            ),
                          ),
                          if (claimable) ...[
                            if (unavailable) ...[
                              Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 10,
                                  vertical: 7,
                                ),
                                decoration: BoxDecoration(
                                  color: AppColors.danger.withValues(alpha: 0.1),
                                  borderRadius: BorderRadius.circular(
                                    ext.utilityRadius,
                                  ),
                                  border: Border.all(
                                    color: AppColors.danger.withValues(
                                      alpha: 0.35,
                                    ),
                                  ),
                                ),
                                child: Text(
                                  _claimBlocker(voucher, cart)!,
                                  style: theme.textTheme.labelSmall?.copyWith(
                                    color: AppColors.danger,
                                    fontWeight: FontWeight.w600,
                                    fontSize: 12,
                                  ),
                                ),
                              ),
                            ] else ...[
                              FilledButton.tonal(
                                key: ValueKey('claim-${voucher.code}'),
                                onPressed: () async {
                                  final error = await context
                                      .read<CartState>()
                                      .claimVoucher(voucher.code);
                                  if (!context.mounted) return;
                                  showTimedSnackBar(
                                    context,
                                    SnackBar(
                                      content: Text(
                                        error ??
                                            'Voucher "${voucher.code}" claimed.',
                                      ),
                                    ),
                                  );
                                },
                                child: const Text('CLAIM'),
                              ),
                            ],
                          ] else ...[
                            if (claimed && !unavailable) ...[
                              OutlinedButton(
                                key: ValueKey('use-${voucher.code}'),
                                onPressed: () =>
                                    Navigator.of(context).pop(voucher.code),
                                style: OutlinedButton.styleFrom(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 14,
                                    vertical: 8,
                                  ),
                                ),
                                child: const Text('Use'),
                              ),
                              const SizedBox(width: 8),
                            ],
                            OutlinedButton(
                              key: ValueKey('remove-${voucher.code}'),
                              onPressed: () =>
                                  cart.removeVoucher(voucher.code),
                              child: const Text('Remove'),
                            ),
                          ],
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The dark tear-off stub. The number is the whole point of a voucher,
/// so it gets display scale and the words around it shrink to eyebrow —
/// the same big/tiny pairing the rest of the app uses.
class _Stub extends StatelessWidget {
  const _Stub({required this.voucher});

  final Voucher voucher;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final isPercent = voucher.isPercentage;
    final value = isPercent
        ? '${(voucher.discountRate * 100).round()}'
        : formatPeso(voucher.flatValue).replaceFirst('₱', '').trim();

    return Container(
      width: _stubWidth,
      padding: const EdgeInsets.symmetric(vertical: 18, horizontal: 8),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [
            AppColors.primaryDark,
            Color.lerp(AppColors.primaryDark, Colors.black, 0.2)!,
          ],
        ),
      ),
      alignment: Alignment.center,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        mainAxisSize: MainAxisSize.min,
        children: [
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                if (!isPercent)
                  Padding(
                    padding: const EdgeInsets.only(top: 6, right: 1),
                    child: Text(
                      '₱',
                      style: AppType.priceNumeral(
                        18,
                        color: AppColors.textOnDark,
                      ),
                    ),
                  ),
                Text(
                  value,
                  style: AppType.priceNumeral(40, color: AppColors.textOnDark),
                ),
                if (isPercent)
                  Padding(
                    padding: const EdgeInsets.only(top: 6, left: 1),
                    child: Text(
                      '%',
                      style: AppType.priceNumeral(
                        18,
                        color: AppColors.textOnDark,
                      ),
                    ),
                  ),
                Text(
                  voucher.isShipping ? 'SHIP' : 'OFF',
                  style: AppType.eyebrow.copyWith(color: AppColors.secondary),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The reason a voucher can't be claimed right now, or null when it can be.
/// Mirrors CartState.claimVoucher's ineligible reasons so the wallet shows
/// the same message the claim would return.
String? _claimBlocker(Voucher voucher, CartState cart) {
  final now = DateTime.now();
  if (voucher.expiresAt != null && voucher.expiresAt!.isBefore(now)) {
    return 'Voucher "${voucher.code}" has expired.';
  }
  if (voucher.startsAt != null && voucher.startsAt!.isAfter(now)) {
    return 'Voucher "${voucher.code}" is not active yet.';
  }
  if (voucher.usageExhausted) {
    return 'Voucher "${voucher.code}" has reached its usage limit.';
  }
  final uses = cart.buyerUsesFor(voucher.code);
  if (voucher.perBuyerLimit != null && uses >= voucher.perBuyerLimit!) {
    return 'Voucher "${voucher.code}" is limited to ${voucher.perBuyerLimit} per buyer.';
  }
  return null;
}

/// Countdown text for a voucher's real expiry date, or null when there
/// is no computable date (then the card shows only [Voucher.expiryLabel]
/// with no countdown — never parsed from the label string).
String? _expiryCountdown(DateTime? expiresAt) {
  if (expiresAt == null) return null;
  // Calendar-day difference in local time: 23h left reads "today",
  // 25h reads "tomorrow". Duration.inDays would get both wrong near
  // midnight, and expires_at arrives as UTC so .toLocal() first.
  final local = expiresAt.toLocal();
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  final day = DateTime(local.year, local.month, local.day);
  final diff = day.difference(today).inDays;
  if (diff < 0) return 'Expired';
  if (diff == 0) return 'Expires today';
  if (diff == 1) return 'Expires tomorrow';
  return 'Expires in $diff days';
}

/// True when [expiresAt] is already past (calendar-day, local).
bool _isExpired(DateTime? expiresAt) {
  if (expiresAt == null) return false;
  final local = expiresAt.toLocal();
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  final day = DateTime(local.year, local.month, local.day);
  return day.isBefore(today);
}

/// True when expiry is within 3 days — the only case that earns the
/// danger/urgent token. Never true for an already-expired voucher.
bool _isExpiringSoon(DateTime? expiresAt) {
  if (expiresAt == null || _isExpired(expiresAt)) return false;
  final local = expiresAt.toLocal();
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  final day = DateTime(local.year, local.month, local.day);
  return day.difference(today).inDays <= 3;
}

/// Vertical dashed line for the seam between the stub and the voucher
/// details, running between the two punched notches.
class _DashedSeam extends StatelessWidget {
  const _DashedSeam();

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return LayoutBuilder(
      builder: (context, constraints) {
        const dashHeight = 4.0;
        const dashGap = 4.0;
        final available = constraints.maxHeight.isFinite
            ? constraints.maxHeight
            : 0.0;
        final count = (available / (dashHeight + dashGap)).floor();
        if (count <= 0) return const SizedBox.shrink();
        return Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: List.generate(
            count,
            (_) => Container(
              width: 1,
              height: dashHeight,
              margin: const EdgeInsets.symmetric(vertical: dashGap / 2),
              color: AppColors.divider,
            ),
          ),
        );
      },
    );
  }
}
