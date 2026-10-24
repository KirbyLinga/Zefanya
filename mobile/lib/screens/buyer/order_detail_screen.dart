// lib/screens/buyer/order_detail_screen.dart
//
// Order receipt view — pushed from an order card in OrdersStatusScreen.
// The torn-ticket perforation motif already established on the order
// cards is carried through here: the screen is structured as an actual
// receipt, with the shop header as the stub and the order lines as the
// body below the seam.
//
// Data available: Order (id, shopName, status, eta, itemSummary).
// Richer line-item data (individual prices, quantities, product images)
// will slot in here once the backend is wired — the layout is already
// waiting for it.

import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../data/api/api_exception.dart';
import '../../data/mock_data.dart';
import '../../state/cart_state.dart';
import '../../state/catalog_state.dart';
import '../../state/courier_state.dart';
import '../../state/order_state.dart';
import '../../state/return_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/copy_id.dart';
import '../../utils/timed_snackbar.dart';
import '../../widgets/app_page_route.dart';
import 'widgets/review_button.dart';
import '../../widgets/editorial_shapes.dart';
import '../shared/chat_screen.dart';
import 'cart_screen.dart';
import 'courier_tracker_screen.dart';
import 'return_screen.dart';

class OrderDetailScreen extends StatelessWidget {
  const OrderDetailScreen({super.key, required this.order});

  final Order order;

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
    final orderState = context.watch<OrderState>();
    final returnRequest = orderState.returns.latestForOrder(order.id);
    final courier = context.watch<CourierState>();
    final deliveredAt =
        order.deliveredAt ?? courier.completedAtForOrder(order.id);
    final statusColor = order.cancelled
        ? AppColors.danger
        : returnRequest != null
            ? _returnStatusColor(returnRequest.status)
            : _statusColors[order.status]!;
    final statusLabel = order.cancelled
        ? 'Cancelled'
        : order.buyerConfirmed
            ? 'Completed'
            : returnRequest != null
                ? returnRequest.orderLabel
                : _statusLabels[order.status]!;
    final textColor = Color.lerp(statusColor, Colors.black, 0.40)!;
    // Pull live courier milestone if available
    final milestone = courier.milestoneForOrder(order.id);

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 40),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // ---- Receipt card ------------------------------------
            ClipRRect(
              borderRadius: editorialRadius(0, ShapeTier.card),
              child: Container(
                color: AppColors.surfaceMuted,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Stub: shop identity + status badge
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 16, 16, 14),
                      child: Row(
                        children: [
                          CircleAvatar(
                            radius: 18,
                            backgroundColor: AppColors.surface,
                            child: Icon(Icons.storefront_outlined,
                                size: 18, color: AppColors.primaryDark),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text('FROM THE SELLER', style: AppType.eyebrow),
                                const SizedBox(height: 2),
                                Text(order.shopName,
                                    style: theme.textTheme.titleSmall),
                              ],
                            ),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 10, vertical: 4),
                            decoration: BoxDecoration(
                              color: statusColor.withValues(alpha: 0.15),
                              borderRadius: radiusOf(ShapeTier.pill),
                            ),
                            child: Text(
                              statusLabel,
                              style: theme.textTheme.labelSmall?.copyWith(
                                  color: textColor, fontWeight: FontWeight.w700),
                            ),
                          ),
                        ],
                      ),
                    ),
                    // Perforation seam
                    const _PerforationLine(),
                    // Body: order details
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 14, 16, 18),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          // Reference number
                          InkWell(
                            onTap: () => copyId(context, order.id),
                            child: Semantics(button: true, label: 'Copy order ID', excludeSemantics: true, child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Text(order.id, style: AppType.eyebrow),
                                const SizedBox(width: 6),
                                Icon(Icons.copy_outlined, size: 13, color: AppColors.textSecondary),
                              ],
                            ),
                          ),
                          ),
                          const SizedBox(height: 10),
                          // Item summary
                          _receiptLine(theme, 'Items', order.itemSummary),
                          const SizedBox(height: 6),
                          // ETA
                          _receiptLine(theme, 'Estimated Arrival', order.eta,
                              valueColor: AppColors.accent,
                              valueBold: true),
                          if (milestone != null) ...[
                            const SizedBox(height: 6),
                            _receiptLine(theme, 'Courier Status',
                                _milestoneLabel(milestone)),
                          ],
                          const Padding(
                            padding: EdgeInsets.symmetric(vertical: 14),
                            child: Divider(height: 1),
                          ),
                          _receiptLine(theme, 'Payment Method',
                              'Cash on Delivery'),
                          const SizedBox(height: 6),
                          _receiptLine(theme, 'Order Date',
                              'See app notification'), // placeholder until API
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 20),
            // ---- Return / refund status --------------------------
            if (returnRequest != null)
              ReturnStatusCard(request: returnRequest),
            // ---- Actions ----------------------------------------
            _ActionCard(
              order: order,
              returnRequest: returnRequest,
              deliveredAt: deliveredAt,
            ),
            const SizedBox(height: 20),
            // ---- Timeline (milestone progress) ------------------
            if (milestone != null)
              _MilestoneTimeline(milestone: milestone),
          ],
        ),
      ),
    );
  }

  Widget _receiptLine(ThemeData theme, String label, String value,
      {Color? valueColor, bool valueBold = false}) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 120,
          child: Text(label,
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: AppColors.textSecondary)),
        ),
        Expanded(
          child: Text(
            value,
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: valueBold ? FontWeight.w700 : FontWeight.w500,
              color: valueColor,
            ),
          ),
        ),
      ],
    );
  }

  String _milestoneLabel(DeliveryMilestone m) => switch (m) {
        DeliveryMilestone.accepted        => 'Assigned to courier',
        DeliveryMilestone.arrivedAtSeller => 'Courier at seller',
        DeliveryMilestone.pickedUp        => 'Package picked up',
        DeliveryMilestone.atSortingCenter => 'At sorting center',
        DeliveryMilestone.sorted          => 'Sorted',
        DeliveryMilestone.assignedToRider => 'Assigned to delivery rider',
        DeliveryMilestone.inTransit       => 'In transit',
        DeliveryMilestone.delivered       => 'Delivered',
        DeliveryMilestone.failedDelivery  => 'Delivery failed — retry pending',
        DeliveryMilestone.returnToSender  => 'Returning to seller',
      };

  static Color _returnStatusColor(ReturnStatus status) => switch (status) {
        ReturnStatus.submitted => AppColors.warning,
        ReturnStatus.rejected => AppColors.danger,
        ReturnStatus.escalated => AppColors.primaryDark,
        ReturnStatus.refunded => AppColors.tertiary,
      };
}

// ---------------------------------------------------------------------------

class _ActionCard extends StatelessWidget {
  const _ActionCard({
    required this.order,
    required this.returnRequest,
    required this.deliveredAt,
  });

  final Order order;
  final ReturnRequest? returnRequest;
  final DateTime? deliveredAt;

  /// Adds every resolvable product from this order back to the cart.
  ///
  /// Products that are no longer in the catalogue (id not found) are
  /// silently skipped — CatalogState.products() only contains active listings,
  /// so a delisted product is genuinely unavailable, not a bug.
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

  Future<void> _confirmReceived(BuildContext context) async {
    try {
      final error =
          await context.read<OrderState>().confirmOrderReceived(order);
      if (!context.mounted) return;
      showTimedSnackBar(
        context,
        SnackBar(
          content: Text(
            error ?? 'Order marked as received. You can rate it now.',
          ),
        ),
      );
      if (error == null) Navigator.of(context).maybePop();
    } on ApiException catch (error) {
      if (!context.mounted) return;
      showTimedSnackBar(context, SnackBar(content: Text(error.message)));
    }
  }

  Future<void> _startReturn(BuildContext context) async {
    await Navigator.of(context).push(
      AppPageRoute(
        builder: (_) => ReturnScreen(
          order: order,
          deliveredAt: deliveredAt,
        ),
      ),
    );
  }

  Future<void> _cancelOrder(BuildContext context) async {
    final state = context.read<OrderState>();
    final reason = await showModalBottomSheet<String>(
      context: context,
      backgroundColor: AppColors.surface,
      shape: editorialShape(0, ShapeTier.card),
      builder: (sheetContext) {
        String? selected;
        return StatefulBuilder(
          builder: (sheetContext, setSheetState) {
            final theme = Theme.of(sheetContext);
            return SafeArea(
              top: false,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(20, 20, 20, 4),
                    child: Text(
                      'Cancel ${order.id}?',
                      style: theme.textTheme.titleMedium,
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                    child: Text(
                      'The seller is notified and the item returns to stock. '
                      'This cannot be undone.',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ),
                  for (final option in OrderState.cancellationReasons)
                    RadioListTile<String>(
                      value: option,
                      groupValue: selected,
                      title: Text(option),
                      onChanged: (value) =>
                          setSheetState(() => selected = value),
                    ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
                    child: ElevatedButton(
                      onPressed: selected == null
                          ? null
                          : () => Navigator.of(sheetContext).pop(selected),
                      child: const Text('Cancel order'),
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
    if (reason == null || !context.mounted) return;

    try {
      final error = await state.cancelOrder(order, reason);
      if (!context.mounted) return;
      showTimedSnackBar(
        context,
        SnackBar(content: Text(error ?? 'Order cancelled.')),
      );
      if (error == null) Navigator.of(context).maybePop();
    } on ApiException catch (error) {
      if (!context.mounted) return;
      showTimedSnackBar(context, SnackBar(content: Text(error.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final returns = context.watch<OrderState>().returns;
    final returnOpen = returnRequest?.isOpen ?? false;
    final canReturn = returnRequest == null &&
        returns.returnRefusal(order, deliveredAt: deliveredAt) == null;
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(1, ShapeTier.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('ACTIONS', style: AppType.eyebrow),
          const SizedBox(height: 12),
          if (returnOpen) ...[
            Text(
              'This order has an open return request. Order Received is '
              'unavailable until it is resolved.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 12),
          ] else if (order.cancelled) ...[
            Text(
              'This order was cancelled${order.cancellationReason == null ? '' : ': ${order.cancellationReason}'}.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 12),
          ] else if (returnRequest == null && order.canConfirmReceived) ...[
            ElevatedButton.icon(
              onPressed: () => _confirmReceived(context),
              icon: const Icon(Icons.inventory_2_outlined, size: 16),
              label: const Text('Order Received'),
            ),
            const SizedBox(height: 10),
          ] else if (order.canCancel) ...[
            OutlinedButton.icon(
              onPressed: () => _cancelOrder(context),
              style: OutlinedButton.styleFrom(
                foregroundColor: AppColors.danger,
                side: BorderSide(color: AppColors.danger),
              ),
              icon: const Icon(Icons.close, size: 16),
              label: const Text('Cancel order'),
            ),
            const SizedBox(height: 10),
          ],
          if (canReturn) ...[
            OutlinedButton.icon(
              onPressed: () => _startReturn(context),
              icon: const Icon(Icons.assignment_return_outlined, size: 16),
              label: const Text('Return / Refund'),
            ),
            const SizedBox(height: 8),
          ],
          OutlinedButton.icon(
            onPressed: () => Navigator.of(context).push(AppPageRoute(
              builder: (_) => ChatScreen(
                  contactName: order.shopName, contactRole: 'Seller'),
            )),
            icon: const Icon(Icons.chat_bubble_outline, size: 16),
            label: const Text('Contact Seller'),
          ),
          const SizedBox(height: 8),
          // Copies the reference number AND opens the seller thread in
          // one tap: the thing a buyer pastes first when asking about
          // an order is its ID, so hand it over with the chat.
          OutlinedButton.icon(
            onPressed: () {
              copyId(context, order.id);
              Navigator.of(context).push(AppPageRoute(
                builder: (_) => ChatScreen(
                    contactName: order.shopName, contactRole: 'Seller'),
              ));
            },
            icon: const Icon(Icons.copy_outlined, size: 16),
            label: const Text('Copy ID & Chat with Seller'),
          ),
          if (order.status == OrderStatus.toRate && !returnOpen) ...[
            const SizedBox(height: 10),
            ReviewButton(order: order),
            const SizedBox(height: 10),
            // "Buy Again" on delivered orders: re-adds items to cart,
            // resolving product ids through the live catalogue.
            ElevatedButton.icon(
              onPressed: () => _buyAgain(context),
              icon: const Icon(Icons.shopping_bag_outlined, size: 16),
              label: const Text('Buy Again'),
            ),
          ],
          if (order.status == OrderStatus.inTransit ||
              order.status == OrderStatus.outForDelivery) ...[
            const SizedBox(height: 10),
            ElevatedButton.icon(
              onPressed: () => Navigator.of(context).push(
                AppPageRoute(
                  builder: (_) => CourierTrackerScreen(order: order),
                ),
              ),
              icon: const Icon(Icons.local_shipping_outlined, size: 16),
              label: const Text('Track Courier'),
            ),
          ],
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------

class _MilestoneTimeline extends StatelessWidget {
  const _MilestoneTimeline({required this.milestone});

  final DeliveryMilestone milestone;

  static const _steps = [
    (DeliveryMilestone.accepted,        'Order Received'),
    (DeliveryMilestone.arrivedAtSeller, 'Courier at Seller'),
    (DeliveryMilestone.pickedUp,        'Package Picked Up'),
    (DeliveryMilestone.atSortingCenter, 'At Sorting Center'),
    (DeliveryMilestone.sorted,          'Sorted'),
    (DeliveryMilestone.assignedToRider, 'Assigned to Rider'),
    (DeliveryMilestone.inTransit,       'In Transit'),
    (DeliveryMilestone.delivered,       'Delivered'),
  ];

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final currentIdx = _steps.indexWhere((s) => s.$1 == milestone);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(0, ShapeTier.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('DELIVERY PROGRESS', style: AppType.eyebrow),
          const SizedBox(height: 16),
          for (var i = 0; i < _steps.length; i++) ...[
            _timelineStep(
              theme,
              label: _steps[i].$2,
              done: i <= currentIdx,
              active: i == currentIdx,
              isLast: i == _steps.length - 1,
            ),
          ],
        ],
      ),
    );
  }

  Widget _timelineStep(ThemeData theme,
      {required String label,
      required bool done,
      required bool active,
      required bool isLast}) {
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Dot + line column
          SizedBox(
            width: 28,
            child: Column(
              children: [
                Container(
                  width: 14,
                  height: 14,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: done ? AppColors.primaryDark : Colors.transparent,
                    border: Border.all(
                      color: done ? AppColors.primaryDark : AppColors.divider,
                      width: active ? 2.5 : 1.5,
                    ),
                  ),
                  child: done && !active
                      ? Icon(Icons.check,
                          size: 8, color: AppColors.textOnDark)
                      : null,
                ),
                if (!isLast)
                  Expanded(
                    child: Container(
                      width: 2,
                      color: done ? AppColors.primaryDark : AppColors.divider,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: 18),
              child: Text(
                label,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: active
                      ? AppColors.primaryDark
                      : done
                          ? AppColors.textPrimary
                          : AppColors.textSecondary,
                  fontWeight:
                      active ? FontWeight.w700 : FontWeight.w400,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------

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
      canvas.drawLine(
        Offset(x, 0),
        Offset(math.min(x + dashWidth, size.width), 0),
        paint,
      );
      x += dashWidth + gap;
    }
  }

  @override
  bool shouldRepaint(covariant _DashPainter old) => old.color != color;
}
