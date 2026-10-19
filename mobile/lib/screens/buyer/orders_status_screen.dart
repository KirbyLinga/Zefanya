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
import '../../data/api/api_exception.dart';
import '../../data/mock_data.dart';
import '../../state/cart_state.dart';
import '../../state/catalog_state.dart';
import '../../state/courier_state.dart';
import '../../state/order_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/microcopy.dart';
import '../../utils/order_tab_memory.dart';
import '../../utils/timed_snackbar.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/motion/ease_in_frame.dart';
import '../shared/chat_screen.dart';
import 'cart_screen.dart';
import 'order_detail_screen.dart';
import 'courier_tracker_screen.dart';
import 'widgets/review_button.dart';

// OrderStatus and the order shape now live in data/mock_data.dart, so
// Profile can read the same history instead of this screen owning a
// private copy nothing else could see. The alias keeps every call site
// in this file unchanged.
typedef _Order = Order;

class OrdersStatusScreen extends StatefulWidget {
  const OrdersStatusScreen({super.key});

  @override
  State<OrdersStatusScreen> createState() => _OrdersStatusScreenState();
}

class _OrdersStatusScreenState extends State<OrdersStatusScreen> {
  /// Last-viewed stage, restored before first paint so the stepper
  /// opens where the buyer left it rather than always on To Ship.
  int _initialTab = 0;
  bool _tabReady = false;

  TabController? _controller;
  VoidCallback? _persistListener;

  @override
  void initState() {
    super.initState();
    loadOrdersTab().then((tab) {
      if (mounted) {
        setState(() {
          _initialTab = tab;
          _tabReady = true;
        });
      }
    });
  }

  @override
  void dispose() {
    if (_controller != null && _persistListener != null) {
      _controller!.removeListener(_persistListener!);
    }
    super.dispose();
  }

  /// Attaches the persist-on-settle listener once per controller, after
  /// first paint (the controller only exists below this build).
  void _watchController(TabController controller) {
    if (identical(_controller, controller)) return;
    if (_controller != null && _persistListener != null) {
      _controller!.removeListener(_persistListener!);
    }
    _controller = controller;
    _persistListener = () {
      // Persist on settle, not on every animation tick: indexIsChanging
      // is false once the swipe/tap lands.
      if (!controller.indexIsChanging) saveOrdersTab(controller.index);
    };
    controller.addListener(_persistListener!);
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    // Watches both: OrderState for what was placed, CourierState for how
    // far along each one is.
    final orderState = context.watch<OrderState>();
    final orders = orderState.resolvedOrders(context.watch<CourierState>());

    // An empty history and a request that failed must not look the same. Boot
    // deliberately does not die on the failure (see `OrderState.load`), so this
    // is the screen that has to say why the list is empty — and it is the screen
    // the person opened, which is the whole reason the failure was allowed to
    // wait this long.
    final failure = orderState.loadFailure;
    if (failure != null) {
      return Scaffold(
        backgroundColor: Colors.transparent, // the app-wide gradient in main.dart paints this
        body: SafeArea(child: _OrdersLoadFailure(error: failure)),
      );
    }

    return DefaultTabController(
      length: ordersTabCount,
      initialIndex: _tabReady ? _initialTab : 0,
      child: Builder(
        builder: (context) {
          final controller = DefaultTabController.of(context);
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) _watchController(controller);
          });
          return Scaffold(
            backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
            body: SafeArea(
              child: Column(
                children: [
                  EaseInFrame(
                    index: 0,
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(24, 20, 24, 8),
                      child: _OrderStageStepper(controller: controller),
                    ),
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

/// "Your orders could not be loaded" — this screen's half of the bargain
/// [OrderState.load] makes.
///
/// Boot does not die on a failed request, so the failure has to be said out loud
/// somewhere, and the screen the person actually opened is the only place that
/// can say it. Without this, an unreachable server would render the honest "no
/// orders yet" empty state over a request that never succeeded — an absence
/// presented as a fact.
///
/// Retry is offered only when the failure says trying again could work
/// ([ApiException.isRetryable]). An endpoint the server has not built answers no
/// to that, and a Retry button there would be a button that cannot work.
class _OrdersLoadFailure extends StatelessWidget {
  const _OrdersLoadFailure({required this.error});

  final ApiException error;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return EmptyState(
      icon: Icons.cloud_off_outlined,
      title: 'We couldn\'t load your orders',
      subtitle: Microcopy.calmError(error.message),
      action: error.isRetryable
          ? TextButton(onPressed: () => _retry(context), child: const Text('Retry'))
          : null,
      tint: AppColors.secondary,
    );
  }

  /// Re-reads the orders. The failure is not handled here on purpose: the state
  /// records it and this widget rebuilds from `loadFailure`, so catching it again
  /// would swallow the same error twice.
  Future<void> _retry(BuildContext context) async {
    final orders = context.read<OrderState>();

    try {
      await orders.refresh();
    } on ApiException {
      // Rendered above, from the state.
    }
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
    AppColors.watch(context);
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
    AppColors.watch(context);
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
                ? Icon(Icons.check, size: 13, color: AppColors.textOnDark)
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
    AppColors.watch(context);
    final filtered = orders.where((o) => o.status == status).toList();

    if (filtered.isEmpty) {
      // Empty states inside a RefreshIndicator: the indicator needs something
      // scrollable to attach to, so wrap the empty state in a single-child
      // CustomScrollView rather than returning it bare.
      return RefreshIndicator(
        color: AppColors.primaryDark,
        onRefresh: () => context.read<OrderState>().refresh(),
        child: CustomScrollView(
          slivers: [
            SliverFillRemaining(
              child: EmptyState(
                icon: _emptyIcon(status),
                title: _emptyTitle(status),
                subtitle: Microcopy.ordersEmptySubtitle,
                tint: AppColors.secondary,
                action: OutlinedButton(
                  onPressed: () => Navigator.of(context).maybePop(),
                  child: const Text('Browse products'),
                ),
              ),
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      color: AppColors.primaryDark,
      onRefresh: () => context.read<OrderState>().refresh(),
      child: ListView.builder(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 120),
        itemCount: filtered.length,
        itemBuilder: (context, i) => EaseInFrame(
          index: i.clamp(0, 5),
          child: _OrderCard(order: filtered[i], index: i),
        ),
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

  // Resolved per call rather than cached in a `static const` map: warning
  // and primaryDark have a different value in each mode, and a const map
  // would freeze whichever mode happened to be active at first touch.
  static Map<OrderStatus, Color> get _statusColors => {
        OrderStatus.toShip: AppColors.neutral,
        OrderStatus.inTransit: AppColors.warning,
        OrderStatus.outForDelivery: AppColors.primaryDark,
        OrderStatus.toRate: AppColors.tertiary,
      };

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final statusColor = _statusColors[order.status]!;
    final radius = editorialRadius(index, ShapeTier.card);

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
                      Icon(Icons.storefront_outlined, size: 16, color: AppColors.textSecondary),
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
                    Icon(Icons.schedule, size: 14, color: AppColors.accent),
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
                          color: AppColors.accent,
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
        borderRadius: radiusOf(ShapeTier.pill),
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

  /// Re-adds every resolvable item from this delivered order to the cart.
  void _buyAgain(BuildContext context) {
    final catalog = context.read<CatalogState>();
    final cart = context.read<CartState>();
    final byId = {for (final p in catalog.products) p.id: p};

    var added = 0;
    for (final id in order.productIds) {
      final product = byId[id];
      if (product == null) continue;
      cart.addItem(product);
      added++;
    }

    if (added == 0) {
      showTimedSnackBar(
        context,
        const SnackBar(content: Text('These items are no longer available')),
      );
      return;
    }
    showTimedSnackBar(
      context,
      SnackBar(
        content: Text('$added item${added == 1 ? '' : 's'} added to cart'),
        action: SnackBarAction(
          label: 'View Cart',
          onPressed: () => Navigator.of(context).push(
            AppPageRoute(builder: (_) => const CartScreen()),
          ),
        ),
      ),
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
        // Row+Expanded like the cases above rather than a bare button, so the
        // delivered tab's action lines up with the other three.
        return Row(
          children: [
            Expanded(child: ReviewButton(order: order, expanded: false)),
            const SizedBox(width: 10),
            Expanded(
              child: ElevatedButton.icon(
                onPressed: () => _buyAgain(context),
                icon: const Icon(Icons.shopping_bag_outlined, size: 16),
                label: const Text('Buy Again'),
              ),
            ),
          ],
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
    AppColors.watch(context);
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
