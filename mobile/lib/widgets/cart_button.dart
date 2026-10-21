// lib/widgets/cart_button.dart

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../screens/buyer/cart_screen.dart';
import '../state/cart_state.dart';
import '../theme/app_theme.dart';
import 'app_page_route.dart';
import 'cart_flight.dart';
import 'editorial_shapes.dart';

/// Cart icon with badge and CartAnchor for the add-to-cart flight.
/// Used in the Home masthead, Search header, and Categories header.
class CartButton extends StatelessWidget {
  const CartButton({super.key, this.color});

  final Color? color;

  int _getCartCount(BuildContext context) {
    try {
      return Provider.of<CartState>(context).itemCount;
    } on ProviderNotFoundException {
      return 0;
    }
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final cartCount = _getCartCount(context);

    return CartAnchor(
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          IconButton(
            tooltip: 'Open cart',
            onPressed: () => Navigator.of(context).push(
              AppPageRoute(builder: (_) => const CartScreen()),
            ),
            icon: const Icon(Icons.shopping_bag_outlined),
            color: color ?? AppColors.textPrimary,
          ),
          Positioned(
            right: 2,
            top: 2,
            child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 200),
              transitionBuilder: (child, animation) => ScaleTransition(
                scale: animation,
                child: FadeTransition(opacity: animation, child: child),
              ),
              child: cartCount > 0
                  ? Container(
                      key: ValueKey(cartCount),
                      padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                      decoration: BoxDecoration(
                        color: AppColors.primaryDark,
                        borderRadius: radiusOf(ShapeTier.pill),
                      ),
                      child: Text(
                        cartCount > 9 ? '9+' : '$cartCount',
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: AppColors.textOnDark,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    )
                  : const SizedBox.shrink(key: ValueKey('no-badge')),
            ),
          ),
        ],
      ),
    );
  }
}
