// lib/widgets/cart_button.dart

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../screens/buyer/cart_screen.dart';
import '../state/cart_state.dart';
import '../theme/app_theme.dart';
import '../utils/motion.dart';
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
      return Provider.of<CartState>(context).productCount;
    } on ProviderNotFoundException {
      return 0;
    }
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final cartCount = _getCartCount(context);
    final productLabel = cartCount == 1 ? 'product' : 'products';
    void openCart() => Navigator.of(
      context,
    ).push(AppPageRoute(builder: (_) => const CartScreen()));

    return Semantics(
      container: true,
      button: true,
      label: 'Cart, $cartCount $productLabel',
      onTap: openCart,
      // The badge text is a visual echo of the count in the label; reading
      // both would say "Cart, 2 products, 2".
      excludeSemantics: true,
      child: CartAnchor(
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            IconButton(
              tooltip: 'Open cart',
              onPressed: openCart,
              icon: const Icon(Icons.shopping_bag_outlined),
              color: color ?? AppColors.textPrimary,
            ),
            Positioned(
              right: 2,
              top: 2,
              child: AnimatedSwitcher(
                // The badge is the only confirmation that survives when the
                // product-flight overlay cannot run (reduced motion, no anchor
                // on this route), so it gets a small overshoot rather than a
                // flat scale. Zero duration keeps that end state instant for
                // anyone who asked the OS to stop animations.
                duration: reduceMotion(context)
                    ? Duration.zero
                    : const Duration(milliseconds: 280),
                transitionBuilder: (child, animation) {
                  final scale = Tween<double>(begin: 0.68, end: 1.0).animate(
                    CurvedAnimation(
                      parent: animation,
                      curve: Curves.easeOutBack,
                      reverseCurve: Curves.easeInCubic,
                    ),
                  );
                  return FadeTransition(
                    opacity: animation,
                    child: ScaleTransition(scale: scale, child: child),
                  );
                },
                child: cartCount > 0
                    ? Container(
                        key: ValueKey(cartCount),
                        padding: const EdgeInsets.symmetric(
                          horizontal: 5,
                          vertical: 1,
                        ),
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
      ),
    );
  }
}
