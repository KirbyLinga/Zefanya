// lib/screens/courier/courier_profit_screen.dart
//
// Earnings & Profit Dashboard. Reads everything from CourierState, so
// completing a delivery in DeliveryTrackerScreen actually moves the
// numbers here (Today / This Week / Completed / breakdown / log).
//
// Tips: DeliveryJob has no tip field yet, so this screen only shows
// delivery fees. Add `tip` to DeliveryJob (and the API payload) and put
// the Tips row back in the breakdown when that data exists — showing a
// permanent ₱0.00 tips line would be worse than not showing one.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../state/courier_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/staggered_reveal.dart';

class CourierProfitScreen extends StatelessWidget {
  const CourierProfitScreen({super.key});

  static const _months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  /// "Sep 19, 2:14 PM" — no intl dependency needed for one format.
  static String _formatWhen(DateTime d) {
    final hour12 = d.hour % 12 == 0 ? 12 : d.hour % 12;
    final minute = d.minute.toString().padLeft(2, '0');
    final suffix = d.hour >= 12 ? 'PM' : 'AM';
    return '${_months[d.month - 1]} ${d.day}, $hour12:$minute $suffix';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final courier = context.watch<CourierState>();
    final completed = courier.completedJobs;

    return Scaffold(
      backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      appBar: AppBar(),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 120),
        children: [
          const EditorialHeader(
            eyebrow: 'YOUR MONEY',
            title: 'Earnings',
            padding: EdgeInsets.fromLTRB(0, 12, 0, 16),
          ),
          _buildSummaryCard(
            theme,
            weekEarnings: courier.weekEarnings,
            todayEarnings: courier.todayEarnings,
            totalDeliveries: completed.length,
          ),
          const SizedBox(height: 16),
          _buildBreakdownCard(theme, feesEarned: courier.totalEarnings),
          const SizedBox(height: 20),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('COMPLETED JOBS', style: AppType.eyebrow),
                  const SizedBox(height: 4),
                  Text('Recent Transactions', style: theme.textTheme.titleSmall),
                ],
              ),
            ],
          ),
          const SizedBox(height: 10),
          if (completed.isEmpty)
            _buildEmptyTransactions(theme)
          else
            for (var i = 0; i < completed.length; i++)
              StaggeredReveal(
                index: i,
                child: _buildTransactionTile(theme, completed[i], i),
              ),
        ],
      ),
    );
  }

  Widget _buildEmptyTransactions(ThemeData theme) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(0, big: 22, small: 8),
      ),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(color: AppColors.primary.withValues(alpha: 0.25), shape: BoxShape.circle),
            child: const Icon(Icons.payments_outlined, color: AppColors.primaryDark, size: 18),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              'Nothing here yet. Finish a delivery and it lands on this list.',
              style: theme.textTheme.bodyMedium?.copyWith(color: AppColors.textSecondary),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSummaryCard(
    ThemeData theme, {
    required double weekEarnings,
    required double todayEarnings,
    required int totalDeliveries,
  }) {
    return ClipRRect(
      // Asymmetric corners instead of the uniform heroRadius — same
      // shape language as the buyer side's hero surfaces.
      borderRadius: editorialRadius(0, big: 40, small: 10),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(20),
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
              top: -70,
              right: -70,
              child: Container(
                width: 200,
                height: 200,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: RadialGradient(
                    colors: [AppColors.textOnDark.withValues(alpha: 0.12), AppColors.textOnDark.withValues(alpha: 0.0)],
                  ),
                ),
              ),
            ),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'THIS WEEK',
                  style: AppType.eyebrow.copyWith(color: AppColors.secondary),
                ),
                const SizedBox(height: 6),
                // Biggest number on the courier side gets the same
                // oversized-numeral treatment buyer prices got — this is
                // the one figure a courier actually opens this tab to see.
                Text(
                  '₱${weekEarnings.toStringAsFixed(0)}',
                  style: AppType.priceNumeral(40, color: AppColors.textOnDark),
                ),
                const SizedBox(height: 16),
                Row(
                  children: [
                    Expanded(
                      child: _summaryStat(theme, 'Today', '₱${todayEarnings.toStringAsFixed(2)}'),
                    ),
                    Container(width: 1, height: 32, color: AppColors.secondary.withValues(alpha: 0.4)),
                    Expanded(
                      child: _summaryStat(theme, 'Completed', '$totalDeliveries ${totalDeliveries == 1 ? 'delivery' : 'deliveries'}'),
                    ),
                  ],
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _summaryStat(ThemeData theme, String label, String value) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.labelSmall?.copyWith(color: AppColors.secondary)),
        const SizedBox(height: 2),
        Text(value, style: theme.textTheme.titleSmall?.copyWith(color: AppColors.textOnDark)),
      ],
    );
  }

  Widget _buildBreakdownCard(ThemeData theme, {required double feesEarned}) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(1, big: 28, small: 8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Earnings Breakdown', style: theme.textTheme.titleSmall),
          const SizedBox(height: 12),
          _breakdownRow(theme, 'Delivery fees', feesEarned, AppColors.primaryDark),
          const Divider(height: 24),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text('Total', style: theme.textTheme.titleSmall),
              Text(
                '₱${feesEarned.toStringAsFixed(2)}',
                style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700, color: AppColors.primaryDark),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _breakdownRow(ThemeData theme, String label, double value, Color dotColor) {
    return Row(
      children: [
        Container(width: 8, height: 8, decoration: BoxDecoration(color: dotColor, shape: BoxShape.circle)),
        const SizedBox(width: 8),
        Expanded(child: Text(label, style: theme.textTheme.bodyMedium)),
        Text('₱${value.toStringAsFixed(2)}', style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
      ],
    );
  }

  Widget _buildTransactionTile(ThemeData theme, CompletedJob c, int index) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(index, big: 22, small: 8),
      ),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: LinearGradient(
                colors: [AppColors.tertiary, Color.lerp(AppColors.tertiary, AppColors.primaryDark, 0.35)!],
              ),
            ),
            child: const Icon(Icons.check, color: AppColors.textOnDark, size: 18),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(c.job.id, style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
                Text('${c.job.shopName} · ${_formatWhen(c.completedAt)}', style: theme.textTheme.bodySmall, maxLines: 1, overflow: TextOverflow.ellipsis),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Text('₱${c.job.fee.toStringAsFixed(0)}', style: AppType.priceNumeral(20)),
        ],
      ),
    );
  }
}
