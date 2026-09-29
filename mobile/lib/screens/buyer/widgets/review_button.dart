// lib/screens/buyer/widgets/review_button.dart
//
// The Orders action that used to be `onPressed: () {}` — in two files, both
// copies enabled and primary-styled, on the status (`toRate`, i.e. delivered)
// that the whole Orders tab is named after.
//
// It lives in its own widget because the label depends on state this file
// reads (has this order been reviewed yet?), and duplicating that decision
// across two call sites is how the two copies drifted into agreeing on a dead
// button in the first place.
//
// ## Two honest outcomes, no third
//
//  * The order's items are resolvable → a working button that opens
//    ReviewScreen, reading "Leave a Review" or "Edit Your Review".
//  * They aren't (an order placed before `Order.productIds` existed, so
//    "contents unknown" rather than "bought nothing") → **nothing**. The
//    review form has to know what it's rating; a button that opens a form with
//    no items is the same dead end in a new costume, so the absence is the
//    honest answer.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../data/mock_data.dart';
import '../../../state/review_state.dart';
import '../../../widgets/app_page_route.dart';
import '../review_screen.dart';

class ReviewButton extends StatelessWidget {
  const ReviewButton({super.key, required this.order, this.expanded = true});

  final Order order;

  /// True where the button sits in a stretched action column (the order
  /// detail's action card); false where it shares a row.
  final bool expanded;

  @override
  Widget build(BuildContext context) {
    // Resolved here rather than passed in: this is the join from an order to
    // the catalogue, and it is the same lookup in both places the button
    // appears.
    final products = mockProducts
        .where((product) => order.productIds.contains(product.id))
        .toList(growable: false);
    if (products.isEmpty) return const SizedBox.shrink();

    // watch, not read: writing a review has to turn this button into its own
    // "Edit Your Review" state without the screen being rebuilt from scratch.
    final reviewed = context.watch<ReviewState>().hasReviewedOrder(order.id);

    final icon = Icon(
      reviewed ? Icons.star_rounded : Icons.star_border,
      size: 16,
    );
    final label = Text(
      reviewed
          ? 'Edit Your Review'
          : products.length == 1
              ? 'Leave a Review'
              : 'Review ${products.length} Items',
    );

    void open() => Navigator.of(context).push(
          AppPageRoute(
            builder: (_) => ReviewScreen(order: order, products: products),
          ),
        );

    // Reviewed orders get the quieter treatment: the job is done, and the
    // emphasis belongs back on the order itself rather than on a second
    // invitation to do something already done.
    final button = reviewed
        ? OutlinedButton.icon(onPressed: open, icon: icon, label: label)
        : ElevatedButton.icon(onPressed: open, icon: icon, label: label);

    return expanded ? SizedBox(width: double.infinity, child: button) : button;
  }
}