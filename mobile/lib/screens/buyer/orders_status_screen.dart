// lib/screens/buyer/orders_status_screen.dart
//
// Order Status Tracking screen: To Ship / In Transit / Out for Delivery /
// Rate & Feedback tabs, each showing order cards. Orders come from
// OrderState (state/order_state.dart): orders placed at Checkout, plus the
// seeded sample history from data/mock_data.dart.
//
// This is the one screen in the app where a numbered/staged device is
// actually earned: an order's status IS a sequence a package moves
// through, so the tab selector is a stepper (dots + connecting line)
// rather than a generic TabBar — and the cards get a torn-stub
// perforation between "who it's from" and "where it's headed", since
// they're standing in for a shipping ticket, not a product card.

import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/mock_data.dart';
import '../../state/courier_state.dart';
import '../../state/order_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/staggered_reveal.dart';
import '../shared/chat_screen.dart';
import 'order_detail_screen.dart';
import 'courier_tracker_screen.dart';

// OrderStatus and the order shape now live in data/mock_data.dart, so
// Profile can read the same history instead of this screen owning a
// private copy nothing else could see. The alias keeps every call site
// in this file unchanged.
typedef _Order = Order;

class OrdersStatusScreen extends StatelessWidget {
  const OrdersStatusScreen({super.key});

  @override
  Widget build(BuildContext context) {
    // Watches both: OrderState for what was placed, CourierState for how
    // far along each one is.
    final orders = context.watch<OrderState>().resolvedOrders(context.watch<CourierState>());
    return DefaultTabController(
      length: 4,
      child: Builder(
        builder: (context) {
          final controller = DefaultTabController.of(context);
          return Scaffold(
            backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
            body: SafeArea(
              child: Column(
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(24, 20, 24, 8),
                    child: _OrderStageStepper(controller: controller),
                  ),
                  Expanded(
                    child: TabBarView(
                      children: [
                        _OrderList(status: OrderStatus.toShip, orders: orders),
                        _OrderList(status: OrderStatus.inTransit, orders: orders),
                        _OrderList(status: OrderStatus.outForDelivery, orders: orders),
                        _OrderList(status: OrderStatus.toRate, orders: orders),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

/// A package's status is genuinely a sequence — it moves through these
/// four stages in order — so a stepper is the honest device here,
/// where a numbered marker on, say, Categories would just be decoration
/// pretending the content has an order it doesn't.
class _OrderStageStepper extends StatelessWidget {
  const _OrderStageStepper({required this.controller});

  final TabController controller;

  static const _labels = ['To Ship', 'In Transit', 'Out for\nDelivery', 'Delivered'];

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: controller,
      builder: (context, _) {
        final current = controller.index;
        return Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (var i = 0; i < _labels.length; i++) ...[
              if (i > 0)
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(bottom: 30),
                    child: Container(
                      height: 2,
                      color: i <= current ? AppColors.primaryDark : AppColors.divider,
                    ),
                  ),
                ),
              _StageNode(
                index: i,
                label: _labels[i],
                isPast: i < current,
                isCurrent: i == current,
                onTap: () => controller.animateTo(
                  i,
                  duration: const Duration(milliseconds: 280),
                  curve: Curves.easeOutCubic,
                ),
              ),
            ],
          ],
        );
      },
    );
  }
}

class _StageNode extends StatelessWidget {
  const _StageNode({
    required this.index,
    required this.label,
    required this.isPast,
    required this.isCurrent,
    required this.onTap,
  });

  final int index;
  final String label;
  final bool isPast;
  final bool isCurrent;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final filled = isPast || isCurrent;
    final stepLabel = isPast ? '$label, completed' : isCurrent ? '$label, current step' : label;
    return Semantics(
      button: true,
      label: stepLabel,
      onTap: onTap,
      excludeSemantics: true,
      child: InkWell(
        onTap: onTap,
        customBorder: const CircleBorder(),
        child: Column(
        children: [
          AnimatedContainer(
            duration: const Duration(milliseconds: 200),
            width: isCurrent ? 30 : 22,
            height: isCurrent ? 30 : 22,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: filled ? AppColors.primaryDark : Colors.transparent,
              border: Border.all(color: AppColors.primaryDark, width: filled ? 0 : 1.5),
            ),
            alignment: Alignment.center,
            child: isPast
                ? const Icon(Icons.check, size: 13, color: AppColors.textOnDark)
                : Text(
                    '${index + 1}',
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: filled ? AppColors.textOnDark : AppColors.primaryDark,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
          ),
          const SizedBox(height: 6),
          SizedBox(
            width: 66,
            child: Text(
              label,
              textAlign: TextAlign.center,
              style: theme.textTheme.labelSmall?.copyWith(
                color: isCurrent ? AppColors.textPrimary : AppColors.textSecondary,
                fontWeight: isCurrent ? FontWeight.w700 : FontWeight.w500,
              ),
            ),
          ),
        ],
      ),
      ),
    );
  }
}

class _OrderList extends StatelessWidget {
  const _OrderList({required this.status, required this.orders});

  final OrderStatus status;
  final List<_Order> orders;

  static IconData _emptyIcon(OrderStatus status) => switch (status) {
        OrderStatus.toShip         => Icons.inventory_2_outlined,
        OrderStatus.inTransit      => Icons.local_shipping_outlined,
        OrderStatus.outForDelivery => Icons.directions_bike_outlined,
        OrderStatus.toRate         => Icons.star_border,
      };

  static String _emptyTitle(OrderStatus status) => switch (status) {
        OrderStatus.toShip         => 'No orders to ship yet',
        OrderStatus.inTransit      => 'Nothing in transit',
        OrderStatus.outForDelivery => 'Nothing out for delivery',
        OrderStatus.toRate         => 'No delivered orders yet',
      };

  @override
  Widget build(BuildContext context) {
    final filtered = orders.where((o) => o.status == status).toList();

    if (filtered.isEmpty) {
      return EmptyState(
        icon: _emptyIcon(status),
        title: _emptyTitle(status),
        subtitle: 'Items in this stage will show up here',
        tint: AppColors.secondary,
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 120),
      itemCount: filtered.length,
      itemBuilder: (context, i) => StaggeredReveal(
        index: i,
        child: _OrderCard(order: filtered[i], index: i),
      ),
    );
  }
}

class _OrderCard extends StatelessWidget {
  const _OrderCard({required this.order, required this.index});

  final _Order order;
  final int index;

  static const _statusLabels = {
    OrderStatus.toShip: 'To Ship',
    OrderStatus.inTransit: 'In Transit',
    OrderStatus.outForDelivery: 'Out for Delivery',
    OrderStatus.toRate: 'Delivered',
  };

  static const _statusColors = {
    OrderStatus.toShip: AppColors.neutral,
    OrderStatus.inTransit: AppColors.warning,
    OrderStatus.outForDelivery: AppColors.primaryDark,
    OrderStatus.toRate: AppColors.tertiary,
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final statusColor = _statusColors[order.status]!;
    final radius = editorialRadius(index, big: 26, small: 8);

    return InkWell(
      onTap: () => Navigator.of(context).push(
        AppPageRoute(builder: (_) => OrderDetailScreen(order: order)),
      ),
      borderRadius: radius,
      child: Container(
        margin: const EdgeInsets.only(bottom: 16),
        decoration: BoxDecoration(
          color: AppColors.surfaceMuted,
          borderRadius: radius,
        ),
        clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
            child: Row(
              children: [
                Expanded(
                  child: Row(
                    children: [
                      const Icon(Icons.storefront_outlined, size: 16, color: AppColors.textSecondary),
                      const SizedBox(width: 6),
                      Expanded(
                        child: Text(
                          order.shopName,
                          style: theme.textTheme.titleSmall,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ],
                  ),
                ),
                _statusBadge(theme, statusColor),
              ],
            ),
          ),
          // The perforation: this card is standing in for a shipping
          // stub, so the seam between "what you bought" and "where it
          // is" is torn, not a flat Divider rule.
          const _PerforationLine(),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Order number as an eyebrow: it's a reference code,
                // which is exactly what the tiny all-caps kicker is
                // for, and it stops the stub half of the card from
                // opening with three lines of identical bodySmall.
                Text(order.id, style: AppType.eyebrow),
                const SizedBox(height: 6),
                Text(order.itemSummary, style: theme.textTheme.bodyMedium),
                const SizedBox(height: 4),
                Row(
                  children: [
                    const Icon(Icons.schedule, size: 14, color: AppColors.primaryDark),
                    const SizedBox(width: 6),
                    Flexible(
                      child: Text(
                        order.eta,
                        // ETA is the number/phrase the buyer is actually
                        // here to read — bumped from bodySmall to
                        // titleSmall so it has enough weight to be found
                        // at a glance.
                        style: theme.textTheme.titleSmall?.copyWith(
                          fontWeight: FontWeight.w700,
                          color: AppColors.primaryDark,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                _actionRow(context, theme),
              ],
            ),
          ),
        ],
      ),
      ),
    );
  }

  Widget _statusBadge(ThemeData theme, Color color) {
    // Darken the raw status color before using it as text — the pale
    // tokens (tertiary sage, warning amber) fail AA contrast on their
    // own tinted background. Same pattern as delivery_job_card.dart's
    // _statusTextColor helper.
    final textColor = Color.lerp(color, Colors.black, 0.40)!;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        _statusLabels[order.status]!,
        style: theme.textTheme.labelSmall?.copyWith(color: textColor, fontWeight: FontWeight.w700),
      ),
    );
  }

  /// Opens the same seller thread Product Details opens, keyed by shop
  /// name, so a conversation started there continues here.
  void _contactSeller(BuildContext context) {
    Navigator.of(context).push(
      AppPageRoute(builder: (_) => ChatScreen(contactName: order.shopName, contactRole: 'Seller')),
    );
  }

  Widget _actionRow(BuildContext context, ThemeData theme) {
    switch (order.status) {
      case OrderStatus.toShip:
        return Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () => _contactSeller(context),
                icon: const Icon(Icons.chat_bubble_outline, size: 16),
                label: const Text('Contact Seller'),
              ),
            ),
          ],
        );
      case OrderStatus.inTransit:
      case OrderStatus.outForDelivery:
        return Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () => _contactSeller(context),
                icon: const Icon(Icons.chat_bubble_outline, size: 16),
                label: const Text('Contact Seller'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: ElevatedButton.icon(
                onPressed: () => Navigator.of(context).push(
                  AppPageRoute(
                    builder: (_) => CourierTrackerScreen(order: order),
                  ),
                ),
                icon: const Icon(Icons.local_shipping_outlined, size: 16),
                label: const Text('Track Courier'),
              ),
            ),
          ],
        );
      case OrderStatus.toRate:
        return SizedBox(
          width: double.infinity,
          child: ElevatedButton.icon(
            onPressed: () {},
            icon: const Icon(Icons.star_border, size: 16),
            label: const Text('Leave Review'),
          ),
        );
    }
  }
}

/// A dashed rule with small notches at each end, standing in for a
/// torn perforation between the two halves of a shipping stub.
class _PerforationLine extends StatelessWidget {
  const _PerforationLine();

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 1,
      child: CustomPaint(
        painter: _DashPainter(color: AppColors.divider),
        size: const Size(double.infinity, 1),
      ),
    );
  }
}

class _DashPainter extends CustomPainter {
  const _DashPainter({required this.color});

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    const dashWidth = 6.0;
    const gap = 5.0;
    final paint = Paint()
      ..color = color
      ..strokeWidth = 1.4;
    var x = 0.0;
    final count = (size.width / (dashWidth + gap)).ceil();
    for (var i = 0; i < count; i++) {
      canvas.drawLine(Offset(x, 0), Offset(math.min(x + dashWidth, size.width), 0), paint);
      x += dashWidth + gap;
    }
  }

  @override
  bool shouldRepaint(covariant _DashPainter oldDelegate) => oldDelegate.color != color;
}
