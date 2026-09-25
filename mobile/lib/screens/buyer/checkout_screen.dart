// lib/screens/buyer/checkout_screen.dart
//
// Checkout screen. Order summary is now the *selected* items from the
// shared CartState (see state/cart_state.dart) instead of a hardcoded
// subtotal — so what you see here matches what you selected in Cart.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/pricing.dart';
import '../../state/auth_state.dart';
import '../../state/cart_state.dart';
import '../../state/courier_state.dart';
import '../../state/order_state.dart';
import '../../theme/app_theme.dart';
import '../shared/edit_profile_screen.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/pressable_scale.dart';
import '../../widgets/staggered_reveal.dart';
import '../../widgets/zefanya_mark.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/success_flourish.dart';

enum PaymentMethod { cod, eWallet }

class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key, this.voucherCode, this.voucherDiscount = 0});

  /// Carried over from Cart's applied voucher, if any — see
  /// cart_screen.dart's "Proceed to Checkout" button.
  final String? voucherCode;
  final double voucherDiscount;

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  PaymentMethod _selectedMethod = PaymentMethod.cod;

  final _notesController = TextEditingController();
  static const int _notesMaxLength = 140;

  bool _agreedToTerms = false;
  bool _showTermsError = false;
  bool _isPlacingOrder = false;

  @override
  void dispose() {
    _notesController.dispose();
    super.dispose();
  }

  Future<void> _placeOrder(List<CartItem> selectedItems, double total) async {
    // clearSnackBars() at every early-return below: these are four
    // separate validation checks a person can trip in sequence (fix
    // one, tap Place Order again, hit the next) — without clearing
    // first, each retry queued its error behind whichever one was
    // still showing instead of replacing it.
    if (selectedItems.isEmpty) {
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(const SnackBar(content: Text('Your cart has no selected items to check out.')));
      return;
    }
    if (context.read<AuthState>().addressSummary.isEmpty) {
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(const SnackBar(content: Text('Add a delivery address before placing your order.')));
      return;
    }
    if (_notesController.text.length > _notesMaxLength) {
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(SnackBar(content: Text('Delivery note must be $_notesMaxLength characters or fewer.')));
      return;
    }
    if (!_agreedToTerms) {
      setState(() => _showTermsError = true);
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(const SnackBar(content: Text('Please agree to the Terms & Return Policy to continue.')));
      return;
    }

    setState(() => _isPlacingOrder = true);

    // TODO: replace with a real "place order" API call.
    await Future.delayed(const Duration(milliseconds: 700));
    if (!mounted) return;

    // Order first, then clear: placeOrders reads the selected lines, and
    // clearSelected() removes them from the cart.
    final placed = context.read<OrderState>().placeOrders(selectedItems);
    // One delivery job per order, landing on the courier's Pickup tab.
    // The courier is paid the standard fee whether or not the buyer's
    // shipping was free (the platform absorbs it). One fee per checkout,
    // split across the orders when a cart spans several sellers.
    final auth = context.read<AuthState>();
    final courier = context.read<CourierState>();
    for (final order in placed) {
      courier.dispatchForOrder(
        order: order,
        buyerName: (auth.name == null || auth.name!.isEmpty) ? 'Buyer' : auth.name!,
        deliveryArea: auth.addressSummary,
        fee: kStandardShippingFee / placed.length,
      );
    }
    context.read<CartState>().clearSelected();
    setState(() => _isPlacingOrder = false);

    if (!mounted) return;
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (context) => _OrderConfirmationDialog(
        total: total,
        onDone: () {
          Navigator.of(context).pop(); // close dialog
          Navigator.of(context).popUntil((route) => route.isFirst);
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final cart = context.watch<CartState>();
    final selectedItems = cart.items.where((i) => i.selected).toList();
    final subtotal = selectedItems.fold(0.0, (sum, i) => sum + i.lineTotal);
    final shippingFee = shippingFeeFor(subtotal);
    final total = (subtotal - widget.voucherDiscount + shippingFee).clamp(0, double.infinity).toDouble();

    return Scaffold(
      backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      appBar: AppBar(),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 120),
        children: [
          const EditorialHeader(
            eyebrow: 'ALMOST THERE',
            title: 'Checkout',
            padding: EdgeInsets.fromLTRB(0, 12, 0, 16),
          ),
          StaggeredReveal(index: 0, child: _buildAddressSection(context, theme, ext)),
          const SizedBox(height: 20),
          StaggeredReveal(index: 1, child: _buildOrderSummarySection(theme, ext, selectedItems, subtotal, shippingFee, total)),
          const SizedBox(height: 20),
          StaggeredReveal(index: 2, child: _buildNotesSection(theme, ext)),
          const SizedBox(height: 20),
          StaggeredReveal(index: 3, child: _buildPaymentSection(theme, ext)),
          const SizedBox(height: 16),
          StaggeredReveal(index: 4, child: _buildTermsCheckbox(theme)),
        ],
      ),
      bottomNavigationBar: _buildBottomBar(theme, selectedItems, total),
    );
  }

  // ---------------------------------------------------------------------

  Widget _sectionCard(AppThemeExtension ext, {required Widget child, Color? accentColor, int index = 0}) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        // Alternating asymmetric corners — same shape language as
        // product cards, cart tiles, and order cards. Four identical
        // uniform-radius surfaces stacked vertically reads as a flat
        // block, not four individual cards.
        borderRadius: editorialRadius(index, big: 22, small: 8),
        border: accentColor != null
            ? Border(left: BorderSide(color: accentColor, width: 3))
            : null,
      ),
      child: child,
    );
  }

  Widget _sectionHeader(ThemeData theme, IconData icon, String title, {VoidCallback? onEdit}) {
    return Row(
      children: [
        Icon(icon, size: 18, color: AppColors.primaryDark),
        const SizedBox(width: 8),
        Expanded(child: Text(title, style: theme.textTheme.titleSmall)),
        if (onEdit != null)
          TextButton(onPressed: onEdit, child: const Text('Change')),
      ],
    );
  }

  Widget _buildAddressSection(BuildContext context, ThemeData theme, AppThemeExtension ext) {
    final auth = context.watch<AuthState>();
    final hasAddress = auth.addressSummary.isNotEmpty;

    return _sectionCard(
      ext,
      index: 0,
      accentColor: AppColors.primaryDark,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _sectionHeader(
            theme,
            Icons.location_on_outlined,
            'Delivery Address',
            onEdit: () => Navigator.of(context).push(
              AppPageRoute(builder: (_) => const EditProfileScreen()),
            ),
          ),
          const SizedBox(height: 10),
          Text(
            [auth.name, auth.phone].where((v) => v != null && v.isNotEmpty).join('  •  '),
            style: theme.textTheme.bodyMedium,
          ),
          const SizedBox(height: 4),
          Text(
            hasAddress ? auth.addressSummary : 'No delivery address on file — tap Change to add one.',
            style: theme.textTheme.bodySmall?.copyWith(
              height: 1.5,
              color: hasAddress ? null : AppColors.danger,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildOrderSummarySection(
    ThemeData theme,
    AppThemeExtension ext,
    List<CartItem> items,
    double subtotal,
    double shippingFee,
    double total,
  ) {
    return _sectionCard(
      ext,
      index: 1,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _sectionHeader(theme, Icons.receipt_long_outlined, 'Order Summary'),
          const SizedBox(height: 10),
          if (items.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: Text('No items selected for checkout.', style: theme.textTheme.bodySmall),
            )
          else
            ...items.map((i) => _orderLine(
                  theme,
                  '${i.product.name}${i.variant.isNotEmpty ? ' (${i.variant})' : ''}  x${i.quantity}',
                  '₱${i.lineTotal.toStringAsFixed(2)}',
                )),
          const Divider(height: 24),
          _orderLine(theme, 'Subtotal', '₱${subtotal.toStringAsFixed(2)}'),
          if (widget.voucherDiscount > 0)
            _orderLine(
              theme,
              widget.voucherCode == null ? 'Voucher discount' : 'Voucher (${widget.voucherCode})',
              '- ₱${widget.voucherDiscount.toStringAsFixed(2)}',
              valueColor: AppColors.successText,
            ),
          _orderLine(
            theme,
            'Shipping fee',
            shippingFee == 0 ? 'Free' : '₱${shippingFee.toStringAsFixed(2)}',
            valueColor: shippingFee == 0 ? AppColors.successText : null,
          ),
          const Divider(height: 24),
          _orderLine(theme, 'Total', '₱${total.toStringAsFixed(2)}', isBold: true),
        ],
      ),
    );
  }

  Widget _orderLine(ThemeData theme, String label, String value, {bool isBold = false, Color? valueColor}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Expanded(
            child: Text(
              label,
              style: isBold ? theme.textTheme.titleSmall : theme.textTheme.bodyMedium,
            ),
          ),
          // Bold total line uses priceNumeral so the number has real
          // weight — same reasoning as every other price in the app.
          isBold
              ? Text(value, style: AppType.priceNumeral(22))
              : Text(
                  value,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                    color: valueColor ?? AppColors.textPrimary,
                  ),
                ),
        ],
      ),
    );
  }

  Widget _buildNotesSection(ThemeData theme, AppThemeExtension ext) {
    return _sectionCard(
      ext,
      index: 2,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _sectionHeader(theme, Icons.edit_note_outlined, 'Delivery Note (optional)'),
          const SizedBox(height: 10),
          TextField(
            controller: _notesController,
            maxLines: 2,
            maxLength: _notesMaxLength,
            decoration: const InputDecoration(hintText: 'e.g. "Leave with the guard if I\'m out"'),
            onChanged: (_) => setState(() {}),
          ),
        ],
      ),
    );
  }

  Widget _buildPaymentSection(ThemeData theme, AppThemeExtension ext) {
    return _sectionCard(
      ext,
      index: 3,
      accentColor: AppColors.tertiary,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _sectionHeader(theme, Icons.payments_outlined, 'Payment Method'),
          const SizedBox(height: 8),
          RadioGroup<PaymentMethod>(
            groupValue: _selectedMethod,
            onChanged: (v) => setState(() => _selectedMethod = v!),
            child: Column(
              children: [
                _paymentTile(
                  theme,
                  method: PaymentMethod.cod,
                  icon: Icons.local_shipping_outlined,
                  title: 'Cash on Delivery',
                  subtitle: 'Pay when your order arrives',
                ),
                const SizedBox(height: 10),
                _paymentTile(
                  theme,
                  method: PaymentMethod.eWallet,
                  icon: Icons.account_balance_wallet_outlined,
                  title: 'E-Wallet / Online Payment',
                  subtitle: 'GCash, Maya, or card',
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _paymentTile(
    ThemeData theme, {
    required PaymentMethod method,
    required IconData icon,
    required String title,
    required String subtitle,
  }) {
    final selected = _selectedMethod == method;
    final ext = theme.extension<AppThemeExtension>()!;
    return PressableScale(
      onTap: () => setState(() => _selectedMethod = method),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(ext.utilityRadius),
          border: Border.all(color: selected ? AppColors.primaryDark : AppColors.divider, width: selected ? 1.5 : 1),
        ),
        child: Row(
          children: [
            Icon(icon, size: 20, color: AppColors.textPrimary),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600)),
                  Text(subtitle, style: theme.textTheme.bodySmall),
                ],
              ),
            ),
            Radio<PaymentMethod>(
              value: method,
              activeColor: AppColors.primaryDark,
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildTermsCheckbox(ThemeData theme) {
    final ext = theme.extension<AppThemeExtension>()!;
    return InkWell(
      onTap: () => setState(() {
        _agreedToTerms = !_agreedToTerms;
        if (_agreedToTerms) _showTermsError = false;
      }),
      borderRadius: BorderRadius.circular(ext.utilityRadius),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Checkbox(
            value: _agreedToTerms,
            onChanged: (v) => setState(() {
              _agreedToTerms = v ?? false;
              if (_agreedToTerms) _showTermsError = false;
            }),
          ),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(top: 14),
              child: Text(
                'I agree to the Terms of Service and Return Policy.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: _showTermsError ? AppColors.danger : AppColors.textSecondary,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildBottomBar(ThemeData theme, List<CartItem> selectedItems, double total) {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
      decoration: BoxDecoration(
        color: AppColors.surface,
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
                  Text('Total Payment', style: theme.textTheme.bodySmall),
                  Text('₱${total.toStringAsFixed(0)}', style: AppType.priceNumeral(32)),
                ],
              ),
            ),
            ElevatedButton(
              onPressed: _isPlacingOrder ? null : () => _placeOrder(selectedItems, total),
              child: _isPlacingOrder
                  ? const ZefanyaLoader(size: 18, color: AppColors.textOnDark, strokeWidth: 4)
                  : const Text('Place Order'),
            ),
          ],
        ),
      ),
    );
  }
}

class _OrderConfirmationDialog extends StatelessWidget {
  const _OrderConfirmationDialog({required this.total, required this.onDone});

  final double total;
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Dialog(
      // Was a plain circular-radius rectangle — asymmetric corners now,
      // same shape language as everywhere else, on the screen where a
      // plain rectangle had been quietly left standing the longest.
      shape: editorialShape(0, big: 30, small: 10),
      backgroundColor: AppColors.surface,
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Was a static gradient circle with a Material check icon —
            // this is the one moment in the whole purchase flow that
            // matters most, and it had never been touched since the
            // editorial pass landed everywhere else. See success_flourish.dart.
            const SuccessFlourish(size: 88),
            const SizedBox(height: 20),
            Text('Order Placed!', style: theme.textTheme.titleLarge),
            const SizedBox(height: 12),
            Text('TOTAL PAID', style: AppType.eyebrow),
            const SizedBox(height: 4),
            // Same oversized-numeral treatment as every price elsewhere —
            // the total is the number this whole dialog exists to confirm.
            Text('\u20b1${total.toStringAsFixed(0)}', style: AppType.priceNumeral(40)),
            const SizedBox(height: 16),
            Text(
              'You can track its progress from the Orders tab.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: 20),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(onPressed: onDone, child: const Text('Track Order')),
            ),
          ],
        ),
      ),
    );
  }
}
