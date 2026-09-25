// lib/screens/buyer/cart_screen.dart
//
// Shopping Cart screen. Reads/mutates the shared CartState (see
// state/cart_state.dart) so Home, Product Details, and Checkout all
// agree on what's actually in the cart. Voucher codes are validated
// against data/mock_data.dart's mockVouchers (including a real
// minimum-spend check) instead of accepting any typed string.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/pricing.dart';
import '../../data/mock_data.dart';
import '../../state/cart_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/free_shipping_meter.dart';
import 'checkout_screen.dart';
import 'vouchers_screen.dart';
import 'widgets/product_card.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/staggered_reveal.dart';
import '../../widgets/zefanya_mark.dart';
import '../../utils/timed_snackbar.dart';

class CartScreen extends StatefulWidget {
  const CartScreen({super.key});

  @override
  State<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends State<CartScreen> {
  final _voucherController = TextEditingController();
  Voucher? _appliedVoucher;
  bool _isApplyingVoucher = false;

  @override
  void dispose() {
    _voucherController.dispose();
    super.dispose();
  }

  /// The discount rules live in config/pricing.dart so Cart and Checkout
  /// can't disagree; this just feeds them the current cart.
  double _discountFor(Voucher voucher, CartState cart) => voucherDiscountFor(
        voucher,
        base: voucherBase(voucher, cart.items),
        shippingFee: shippingFeeFor(cart.subtotal),
      );

  Future<void> _applyCode(String rawCode) async {
    final code = rawCode.trim().toUpperCase();
    if (code.isEmpty) return;

    setState(() => _isApplyingVoucher = true);
    // TODO: replace with a real voucher-validation API call.
    await Future.delayed(const Duration(milliseconds: 500));
    if (!mounted) return;
    setState(() => _isApplyingVoucher = false);

    final match = mockVouchers.where((v) => v.code == code).toList();
    if (match.isEmpty) {
      // showTimedSnackBar replaces (rather than queues) the previous bar,
      // so mistyping a code twice in a row doesn't stack errors, and it
      // guarantees removal even if someone later adds an `action` here.
      showTimedSnackBar(
        context,
        SnackBar(content: Text('"$code" isn\'t a valid voucher code')),
      );
      return;
    }

    final voucher = match.first;
    // Read the cart now, after the await, rather than trusting a subtotal
    // captured before it. A category voucher only counts its own category.
    final base = voucherBase(voucher, context.read<CartState>().items);
    if (base < voucher.minSpend) {
      final scope = voucher.category == null ? '' : ' of ${voucher.category}';
      showTimedSnackBar(
        context,
        SnackBar(content: Text('Spend at least ₱${voucher.minSpend.toStringAsFixed(0)}$scope to use "$code"')),
      );
      return;
    }

    setState(() => _appliedVoucher = voucher);
    showTimedSnackBar(context, SnackBar(content: Text('Voucher "$code" applied')));
  }

  void _removeVoucher() {
    setState(() {
      _appliedVoucher = null;
      _voucherController.clear();
    });
    showTimedSnackBar(context, const SnackBar(content: Text('Voucher removed')));
  }

  /// Called from build() — removes the voucher automatically if the
  /// cart drops below the voucher's minSpend after the initial apply.
  void _checkVoucherValidity(CartState cart) {
    if (_appliedVoucher == null) return;
    final base = voucherBase(_appliedVoucher!, cart.items);
    if (base < _appliedVoucher!.minSpend) {
      final minSpend = _appliedVoucher!.minSpend;
      final scope = _appliedVoucher!.category == null ? '' : ' of ${_appliedVoucher!.category}';
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted || _appliedVoucher == null) return;
        setState(() => _appliedVoucher = null);
        showTimedSnackBar(
          context,
          SnackBar(
            content: Text(
              'Voucher removed — cart dropped below '
              '₱${minSpend.toStringAsFixed(0)}$scope minimum',
            ),
          ),
        );
      });
    }
  }

  Future<void> _browseVouchers() async {
    final code = await Navigator.of(context).push<String>(
      AppPageRoute(builder: (_) => const VouchersScreen()),
    );
    if (code == null || !mounted) return;
    _voucherController.text = code;
    _applyCode(code);
  }

  void _removeItem(BuildContext context, CartItem item) {
    final cart = context.read<CartState>();
    final index = cart.items.indexOf(item);
    final removed = cart.removeItem(item);
    // Replaces any Undo prompt already showing (removing two items in
    // quick succession should show the SECOND item's prompt) and removes
    // it after 4s on its own — see utils/timed_snackbar.dart for why the
    // framework's timeout can't be relied on for SnackBars with actions.
    showTimedSnackBar(
      context,
      SnackBar(
        content: Text('Removed ${removed.product.name}'),
        persist: false,
        action: SnackBarAction(
          label: 'Undo',
          onPressed: () => cart.restoreItem(removed, index: index),
        ),
      ),
      duration: const Duration(seconds: 4),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final cart = context.watch<CartState>();
    final items = cart.items;
    final selectedCount = items.where((i) => i.selected).length;
    final allSelected = items.isNotEmpty && items.every((i) => i.selected);

    // Re-derive the applied voucher's discount against the current
    // subtotal (it changes as quantities/selection change) rather than
    // freezing it at apply-time. Also auto-removes if subtotal drops
    // below the voucher's minimum spend.
    _checkVoucherValidity(cart);
    final voucherDiscount = _appliedVoucher == null
        ? 0.0
        : _discountFor(_appliedVoucher!, cart);
    final total = (cart.subtotal - voucherDiscount).clamp(0, double.infinity).toDouble();

    return Scaffold(
      backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      appBar: AppBar(),
      body: items.isEmpty
          ? _buildEmptyState(theme)
          : Column(
              children: [
                const EditorialHeader(
                  eyebrow: 'READY TO GO',
                  title: 'My Cart',
                  padding: EdgeInsets.fromLTRB(20, 0, 20, 12),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
                  child: Column(
                    children: [
                      Row(
                        children: [
                          Checkbox(
                            value: allSelected,
                            onChanged: (v) => context.read<CartState>().setAllSelected(v ?? false),
                          ),
                          Text('Select all', style: theme.textTheme.bodyMedium),
                          const Spacer(),
                          Text('${items.length} item(s)', style: theme.textTheme.bodySmall),
                        ],
                      ),
                      const Divider(height: 1),
                    ],
                  ),
                ),
                Expanded(
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(20, 8, 20, 8),
                    children: [
                      for (var i = 0; i < items.length; i++)
                        StaggeredReveal(
                          index: i,
                          child: _buildCartTile(context, theme, ext, items[i], i),
                        ),
                      const SizedBox(height: 8),
                      FreeShippingMeter(subtotal: cart.subtotal),
                      const SizedBox(height: 12),
                      _buildVoucherBanner(theme, ext),
                      const SizedBox(height: 100),
                    ],
                  ),
                ),
              ],
            ),
      bottomNavigationBar: items.isEmpty
          ? null
          : _buildSummaryBar(context, theme, selectedCount, total, voucherDiscount),
    );
  }

  Widget _buildEmptyState(ThemeData theme) {
    return EmptyState(
      icon: Icons.shopping_bag_outlined,
      title: 'Your cart is empty',
      subtitle: 'Items you add will show up here',
      action: OutlinedButton(
        onPressed: () => Navigator.of(context).maybePop(),
        child: const Text('Continue Shopping'),
      ),
    );
  }

  Widget _buildCartTile(BuildContext context, ThemeData theme, AppThemeExtension ext, CartItem item, int index) {
    final cart = context.read<CartState>();
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(index, big: 22, small: 8),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Checkbox(
            value: item.selected,
            onChanged: (v) => cart.toggleSelected(item, v ?? false),
          ),
          ClipRRect(
            borderRadius: editorialRadius(index, big: 18, small: 6),
            child: SizedBox(
              width: 72,
              height: 72,
              child: ProductImage(productId: item.product.id, tint: productTint(item.product.category)),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Trash lives beside the name (not in its own column to the
                // right of everything) so the price + stepper row below gets
                // the full width — that row is what overflowed on phones.
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(item.product.name, style: theme.textTheme.bodyMedium, maxLines: 1, overflow: TextOverflow.ellipsis),
                          if (item.variant.isNotEmpty) ...[
                            const SizedBox(height: 2),
                            Text(item.variant, style: theme.textTheme.bodySmall, maxLines: 1, overflow: TextOverflow.ellipsis),
                          ],
                        ],
                      ),
                    ),
                    IconButton(
                      onPressed: () => _removeItem(context, item),
                      tooltip: 'Remove from cart',
                      padding: EdgeInsets.zero,
                      style: IconButton.styleFrom(
                        minimumSize: const Size(32, 32),
                        fixedSize: const Size(32, 32),
                        tapTargetSize: MaterialTapTargetSize.padded,
                      ),
                      icon: const Icon(Icons.delete_outline, size: 20, color: AppColors.danger),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                // Wrap, not Row: on very narrow screens the stepper drops
                // under the price instead of overflowing.
                Wrap(
                  alignment: WrapAlignment.spaceBetween,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  runSpacing: 6,
                  spacing: 8,
                  children: [
                    Text(
                      '₱${item.product.price.toStringAsFixed(2)}',
                      style: theme.textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: AppColors.primaryDark,
                      ),
                    ),
                    _buildQtyStepper(cart, item),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildQtyStepper(CartState cart, CartItem item) {
    // Each button is a 32pt visual with a padded 48pt hit area (Material's
    // minimum) — the pill stays compact but is no longer easy to miss.
    Widget button(IconData icon, String tooltip, VoidCallback onPressed) {
      return IconButton(
        onPressed: onPressed,
        tooltip: tooltip,
        icon: Icon(icon, size: 16, color: AppColors.textPrimary),
        padding: EdgeInsets.zero,
        style: IconButton.styleFrom(
          minimumSize: const Size(32, 32),
          fixedSize: const Size(32, 32),
          tapTargetSize: MaterialTapTargetSize.padded,
        ),
      );
    }

    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: AppColors.divider),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          button(
            Icons.remove,
            item.quantity <= 1 ? 'Remove from cart' : 'Decrease quantity',
            // At qty 1, "−" is a removal: route it through the same
            // "Removed X / Undo" flow as the trash icon instead of
            // silently deleting.
            () => item.quantity <= 1 ? _removeItem(context, item) : cart.updateQuantity(item, -1),
          ),
          SizedBox(
            width: 24,
            child: Text('${item.quantity}', textAlign: TextAlign.center, style: const TextStyle(fontSize: 13)),
          ),
          button(Icons.add, 'Increase quantity', () => cart.updateQuantity(item, 1)),
        ],
      ),
    );
  }

  Widget _buildVoucherBanner(ThemeData theme, AppThemeExtension ext) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.secondary.withValues(alpha: 0.5),
        borderRadius: editorialRadius(1, big: 24, small: 8),
        border: Border.all(color: AppColors.divider),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.local_offer_outlined, size: 18, color: AppColors.primaryDark),
              const SizedBox(width: 8),
              Expanded(child: Text('Voucher & Promo Code', style: theme.textTheme.titleSmall)),
              TextButton(
                onPressed: _browseVouchers,
                child: const Text('Browse Vouchers'),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _voucherController,
                  textCapitalization: TextCapitalization.characters,
                  decoration: const InputDecoration(hintText: 'Enter code'),
                ),
              ),
              const SizedBox(width: 10),
              ElevatedButton(
                onPressed: _isApplyingVoucher ? null : () => _applyCode(_voucherController.text),
                child: _isApplyingVoucher
                    ? const ZefanyaLoader(size: 16, color: AppColors.textOnDark, strokeWidth: 4)
                    : const Text('Apply'),
              ),
            ],
          ),
          if (_appliedVoucher != null) ...[
            const SizedBox(height: 8),
            Row(
              children: [
                const Icon(Icons.check_circle_outline, size: 16, color: AppColors.successText),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    '"${_appliedVoucher!.code}" applied — ${_appliedVoucher!.discountLabel}',
                    style: theme.textTheme.bodySmall?.copyWith(color: AppColors.successText, fontWeight: FontWeight.w600),
                  ),
                ),
                // Remove button — the applied row was static with no way out.
                IconButton(
                  onPressed: _removeVoucher,
                  tooltip: 'Remove voucher',
                  padding: EdgeInsets.zero,
                  style: IconButton.styleFrom(
                    minimumSize: const Size(28, 28),
                    fixedSize: const Size(28, 28),
                    tapTargetSize: MaterialTapTargetSize.padded,
                  ),
                  icon: const Icon(Icons.close, size: 16, color: AppColors.textSecondary),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildSummaryBar(
    BuildContext context,
    ThemeData theme,
    int selectedCount,
    double total,
    double voucherDiscount,
  ) {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 14, 20, 16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: const BorderRadius.only(
          topLeft: Radius.circular(28),
          topRight: Radius.circular(10),
        ),
        boxShadow: [
          BoxShadow(color: AppColors.neutral.withValues(alpha: 0.12), blurRadius: 16, offset: const Offset(0, -4)),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text('Total ($selectedCount item${selectedCount == 1 ? '' : 's'})', style: theme.textTheme.bodySmall),
                  Text('₱${total.toStringAsFixed(0)}', style: AppType.priceNumeral(26)),
                ],
              ),
            ),
            ElevatedButton(
              onPressed: selectedCount == 0
                  ? null
                  : () => Navigator.of(context).push(
                        AppPageRoute(
                          builder: (_) => CheckoutScreen(
                            voucherCode: _appliedVoucher?.code,
                            voucherDiscount: voucherDiscount,
                          ),
                        ),
                      ),
              child: const Text('Proceed to Checkout'),
            ),
          ],
        ),
      ),
    );
  }
}
