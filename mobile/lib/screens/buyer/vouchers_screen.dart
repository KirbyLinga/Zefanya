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
import '../../widgets/editorial_header.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/staggered_reveal.dart';

/// Width of the dark stub, and therefore where the perforation falls.
const double _stubWidth = 96;

class VouchersScreen extends StatelessWidget {
  const VouchersScreen({super.key});

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    // The catalogue is the repository's answer, reached through the cart state
    // that loaded it — not a local constant, so a live build cannot show a
    // wallet of vouchers the server never offered.
    final vouchers = context.watch<CartState>().vouchers;

    return Scaffold(
      backgroundColor:
          Colors.transparent, // the app-wide gradient in main.dart paints this
      // Title out of the AppBar and into the app's own eyebrow +
      // Playfair voice, like every other non-Home screen.
      appBar: AppBar(),
      body: ListView.builder(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 28),
        itemCount: vouchers.length + 1,
        itemBuilder: (context, i) {
          if (i == 0) {
            return const EditorialHeader(
              eyebrow: 'YOUR WALLET',
              title: 'Vouchers',
              padding: EdgeInsets.fromLTRB(0, 0, 0, 20),
            );
          }
          final index = i - 1;
          return StaggeredReveal(
            index: index,
            child: _VoucherTile(voucher: vouchers[index], index: index),
          );
        },
      ),
    );
  }
}

class _VoucherTile extends StatelessWidget {
  const _VoucherTile({required this.voucher, required this.index});

  final Voucher voucher;
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
                      Row(
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
                          const Spacer(),
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
                          ElevatedButton(
                            key: ValueKey('claim-${voucher.code}'),
                            onPressed: claimed || unavailable
                                ? null
                                : () async {
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
                            style: ElevatedButton.styleFrom(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 18,
                                vertical: 8,
                              ),
                            ),
                            child: Text(claimed ? 'Claimed' : 'Claim'),
                          ),
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
