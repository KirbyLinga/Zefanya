// lib/screens/buyer/product_link_screen.dart
//
// Landing screen for an inbound product link. Resolves the id against the
// loaded catalogue and either opens the real Product Details screen or shows an
// honest unavailable state. It must never be a blank route.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/catalog_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_shapes.dart';
import 'product_details_screen.dart';

class ProductLinkScreen extends StatelessWidget {
  const ProductLinkScreen({super.key, required this.productId});

  final String productId;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final product = context.watch<CatalogState>().productById(productId);
    if (product != null) {
      return ProductDetailsScreen(product: product);
    }

    final theme = Theme.of(context);
    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Container(
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(
              color: AppColors.surfaceMuted,
              borderRadius: editorialRadius(0, ShapeTier.card),
              border: Border.all(color: AppColors.divider),
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  Icons.link_off,
                  size: 40,
                  color: AppColors.textSecondary,
                ),
                const SizedBox(height: 14),
                Text(
                  'This product is unavailable',
                  style: theme.textTheme.titleMedium,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 8),
                Text(
                  'The link may be old, or the seller may have removed the '
                  'item. Browse the store for something similar.',
                  style: theme.textTheme.bodySmall,
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 18),
                ElevatedButton(
                  onPressed: () => Navigator.of(context).maybePop(),
                  child: const Text('Continue Shopping'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
