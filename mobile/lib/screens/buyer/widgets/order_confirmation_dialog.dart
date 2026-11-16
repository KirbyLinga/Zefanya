// lib/screens/buyer/widgets/order_confirmation_dialog.dart
//
// The moment a purchase lands. Extracted from checkout_screen.dart for the
// same two reasons the courier's completion view was extracted: it is a whole
// screen's worth of layout living inside a State class that also owns the
// order-placing logic, and a pure data-in widget can be rendered in a test.
//
// ## What was wrong with it
//
// Its only action was a button reading **Track Order** which called
// `popUntil((route) => route.isFirst)` — it landed the buyer on Home and
// tracked nothing, while the copy underneath said to go and find the order in
// the Orders tab. So the label, the copy and the behaviour all disagreed, and
// the buyer never saw the thing they had just bought.
//
// Now the primary action does what it says: it opens the buyer's own delivery
// tracker for the order that was just created, using the same screen the
// Orders tab pushes. The dialog also states the order *number*, which the
// buyer previously had no way to see until they went looking for it — the one
// piece of information they need if they have to ask anyone about it.
//
// "Track Order" is the label only when there is exactly one order to track.
// A cart spanning two sellers creates two orders and one delivery job each, so
// the button says "Track First Order" rather than making a singular promise
// about a plural checkout.

import 'package:flutter/material.dart';

import '../../../data/mock_data.dart';
import '../../../theme/app_theme.dart';
import '../../../utils/money_format.dart';
import '../../../widgets/editorial_shapes.dart';
import '../../../widgets/success_flourish.dart';

class OrderConfirmationDialog extends StatelessWidget {
  const OrderConfirmationDialog({
    super.key,
    required this.orders,
    required this.total,
    required this.onTrack,
    required this.onDone,
  });

  /// Every order this checkout created — one per seller, in the order they
  /// were placed. Never empty: this dialog only exists because orders were.
  final List<Order> orders;

  /// What was paid, across all of them.
  final double total;

  /// Opens the tracker for `orders.first`.
  final VoidCallback onTrack;

  /// Leaves — back to the top of the app.
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final first = orders.first;
    final extra = orders.length - 1;

    return Dialog(
      // Asymmetric corners now, same shape language as everywhere else, on the
      // screen where a plain rectangle had been quietly left standing the
      // longest.
      shape: editorialShape(0, ShapeTier.card),
      backgroundColor: AppColors.surface,
      child: SingleChildScrollView(
        // Insurance, not decoration: this column is fixed-height content and
        // the app supports 1.3x system text on a short phone.
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // The one moment in the whole purchase flow that matters most.
            // See widgets/success_flourish.dart.
            const SuccessFlourish(size: 88),
            const SizedBox(height: 20),
            Text('Order Placed!', style: theme.textTheme.titleLarge),
            const SizedBox(height: 12),
            Text('TOTAL PAID', style: AppType.eyebrow),
            const SizedBox(height: 4),
            // Same oversized-numeral treatment as every price elsewhere —
            // the total is the number this dialog exists to confirm.
            Text(formatPeso(total), style: AppType.priceNumeral(40)),
            const SizedBox(height: 18),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.surfaceMuted,
                // index 1: the mirrored lean, so this block and the dialog it
                // sits in don't lean the same way.
                borderRadius: editorialRadius(1, ShapeTier.accent),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('ORDER NUMBER', style: AppType.eyebrow),
                  const SizedBox(height: 4),
                  Text(first.id, style: theme.textTheme.titleSmall),
                  const SizedBox(height: 2),
                  Text(
                    first.shopName,
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: AppColors.textSecondary),
                  ),
                  if (extra > 0) ...[
                    const SizedBox(height: 8),
                    Text(
                      // Not an estimate: this is arithmetic. One cart can hold
                      // several sellers, and each seller ships its own parcel,
                      // so the checkout became exactly this many orders.
                      extra == 1
                          ? 'This checkout also created 1 more order, from another shop.'
                          : 'This checkout also created $extra more orders, from other shops.',
                      style: theme.textTheme.bodySmall
                          ?.copyWith(color: AppColors.textSecondary),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 16),
            Text(
              'You can also find it in the Orders tab.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: 20),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: onTrack,
                child: Text(extra == 0 ? 'Track Order' : 'Track First Order'),
              ),
            ),
            const SizedBox(height: 4),
            TextButton(onPressed: onDone, child: const Text('Done')),
          ],
        ),
      ),
    );
  }
}