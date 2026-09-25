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

import '../../data/mock_data.dart';
import '../../state/courier_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_shapes.dart';
import '../shared/chat_screen.dart';
import 'courier_tracker_screen.dart';

class OrderDetailScreen extends StatelessWidget {
  const OrderDetailScreen({super.key, required this.order});

  final Order order;

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
    final textColor = Color.lerp(statusColor, Colors.black, 0.40)!;
    // Pull live courier milestone if available
    final milestone = context.watch<CourierState>().milestoneForOrder(order.id);

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
              borderRadius: editorialRadius(0, tier: EditorialTier.card),
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
                          const CircleAvatar(
                            radius: 18,
                            backgroundColor: AppColors.secondary,
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
                              borderRadius: BorderRadius.circular(999),
                            ),
                            child: Text(
                              _statusLabels[order.status]!,
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
                          Text(order.id, style: AppType.eyebrow),
                          const SizedBox(height: 10),
                          // Item summary
                          _receiptLine(theme, 'Items', order.itemSummary),
                          const SizedBox(height: 6),
                          // ETA
                          _receiptLine(theme, 'Estimated Arrival', order.eta,
                              valueColor: AppColors.primaryDark,
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
            // ---- Actions ----------------------------------------
            _ActionCard(order: order),
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
}

// ---------------------------------------------------------------------------

class _ActionCard extends StatelessWidget {
  const _ActionCard({required this.order});

  final Order order;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(1, tier: EditorialTier.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('ACTIONS', style: AppType.eyebrow),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: () => Navigator.of(context).push(AppPageRoute(
              builder: (_) => ChatScreen(
                  contactName: order.shopName, contactRole: 'Seller'),
            )),
            icon: const Icon(Icons.chat_bubble_outline, size: 16),
            label: const Text('Contact Seller'),
          ),
          if (order.status == OrderStatus.toRate) ...[
            const SizedBox(height: 10),
            ElevatedButton.icon(
              onPressed: () {},
              icon: const Icon(Icons.star_border, size: 16),
              label: const Text('Leave a Review'),
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
    final theme = Theme.of(context);
    final currentIdx = _steps.indexWhere((s) => s.$1 == milestone);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(0, tier: EditorialTier.card),
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
                      ? const Icon(Icons.check,
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
