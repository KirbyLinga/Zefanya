// lib/screens/buyer/checkout_screen.dart
//
// Checkout screen. Order summary is now the *selected* items from the
// shared CartState (see state/cart_state.dart) instead of a hardcoded
// subtotal — so what you see here matches what you selected in Cart.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/pricing.dart';
import '../../data/api/api_exception.dart';
import '../../data/mock_data.dart' show Order, Voucher, VoucherScope;
import '../../state/auth_state.dart';
import '../../state/cart_state.dart';
import '../../state/courier_state.dart';
import '../../state/order_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/money_format.dart';
import '../../utils/timed_snackbar.dart';
import 'address_book_screen.dart';
import 'courier_tracker_screen.dart';
import 'widgets/order_confirmation_dialog.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/pressable_scale.dart';
import '../../widgets/staggered_reveal.dart';
import '../../widgets/zefanya_mark.dart';
import '../../widgets/editorial_shapes.dart';

enum PaymentMethod { cod, eWallet }

class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key, this.checkoutItems});

  /// Buy Now's isolated line(s). When non-null, checkout reads these
  /// instead of the cart's selected lines and does not clear the cart
  /// afterwards — the cart was never part of this purchase.
  final List<CartItem>? checkoutItems;

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  PaymentMethod _selectedMethod = PaymentMethod.cod;

  final _notesController = TextEditingController();
  static const int _notesMaxLength = 200;

  final _addressKey = GlobalKey();
  final _itemsKey = GlobalKey();
  final _noteKey = GlobalKey();
  final _termsKey = GlobalKey();

  String? _addressError;
  String? _itemsError;
  String? _noteError;
  String? _termsError;
  bool _agreedToTerms = false;
  bool _isPlacingOrder = false;

  /// Collapses the per-line breakdown to one total row, expanded on
  /// tap. Starts collapsed: the total is what a checkout screen is
  /// for; the lines are one tap away, not gone.
  bool _summaryExpanded = false;

  @override
  void dispose() {
    _notesController.dispose();
    super.dispose();
  }

  Future<void> _placeOrder(List<CartItem> selectedItems, double total) async {
    final auth = context.read<AuthState>();
    final deliveryAddress = auth.defaultAddress?.summary ?? auth.addressSummary;
    final hasNoAddress = deliveryAddress.isEmpty;
    final hasNoItems = selectedItems.isEmpty;
    final noteTooLong = _notesController.text.length > _notesMaxLength;
    final termsNotAccepted = !_agreedToTerms;

    // A voucher can expire or disappear between Cart and Place Order. The
    // server still re-checks this (it owns usage limits and the clock); this
    // local check stops an obviously dead code from being sent.
    final cart = context.read<CartState>();
    final appliedVouchers = widget.checkoutItems == null
        ? cart.appliedVouchers
        : const <Voucher>[];
    String? voucherProblem;
    for (final voucher in appliedVouchers) {
      if (isVoucherExpired(voucher)) {
        voucherProblem = 'Voucher "${voucher.code}" has expired.';
      } else if (isVoucherNotStarted(voucher)) {
        voucherProblem = 'Voucher "${voucher.code}" is not active yet.';
      } else if (isVoucherUsageExhausted(
        voucher,
        buyerUses: cart.buyerUsesFor(voucher.code),
      )) {
        voucherProblem =
            'Voucher "${voucher.code}" has reached its usage limit.';
      } else if (voucherBase(voucher, selectedItems) < voucher.minSpend) {
        voucherProblem =
            'Voucher "${voucher.code}" no longer meets its '
            '${formatPeso(voucher.minSpend)} minimum.';
      }
      if (voucherProblem != null) break;
    }

    // Stock is a server value; this is the last local copy the app checked.
    // The server must refuse the order if its copy changed in the meantime.
    final unavailable = selectedItems
        .where((i) => i.availableStock <= 0 || i.quantity > i.availableStock)
        .toList();
    final stockProblem = unavailable.isEmpty
        ? null
        : '${unavailable.first.product.name} is no longer available in that quantity.';

    // Purchase limits are per buyer across all orders, so this check needs
    // order history as well as the selected line. Product Details blocks the
    // obvious case; this is the last line of defence for Buy Now and for a
    // cart that sat open while another order was placed.
    final orders = context.read<OrderState>();
    String? limitProblem;
    for (final line in selectedItems) {
      final limit = line.product.purchaseLimit;
      if (limit == null) continue;
      final bought = orders.purchasedQuantity(line.product.id);
      if (bought + line.quantity > limit) {
        limitProblem =
            '${line.product.name} is limited to $limit per buyer. '
            'You have already bought $bought.';
        break;
      }
    }

    setState(() {
      _addressError = hasNoAddress
          ? 'Add a delivery address before placing your order.'
          : null;
      _itemsError = hasNoItems
          ? 'Your cart has no selected items to check out.'
          : stockProblem ?? limitProblem;
      _noteError = noteTooLong
          ? 'Delivery note must be $_notesMaxLength characters or fewer.'
          : null;
      _termsError = termsNotAccepted
          ? 'Please agree to the Terms & Return Policy to continue.'
          : null;
    });

    if (voucherProblem != null) {
      for (final voucher in appliedVouchers) {
        cart.removeVoucher(voucher.code);
      }
      showTimedSnackBar(context, SnackBar(content: Text(voucherProblem)));
      return;
    }

    if (hasNoAddress ||
        hasNoItems ||
        noteTooLong ||
        termsNotAccepted ||
        stockProblem != null ||
        limitProblem != null) {
      GlobalKey? firstInvalidKey;
      if (hasNoAddress) {
        firstInvalidKey = _addressKey;
      } else if (hasNoItems || stockProblem != null || limitProblem != null) {
        firstInvalidKey = _itemsKey;
      } else if (noteTooLong) {
        firstInvalidKey = _noteKey;
      } else if (termsNotAccepted) {
        firstInvalidKey = _termsKey;
      }

      if (firstInvalidKey?.currentContext != null) {
        Scrollable.ensureVisible(
          firstInvalidKey!.currentContext!,
          duration: const Duration(milliseconds: 300),
          curve: Curves.easeOutCubic,
          alignment: 0.1,
        );
      }
      return;
    }

    setState(() => _isPlacingOrder = true);

    // Placing an order is now a real repository write (see
    // OrderRepository.place), so the artificial delay that used to stand in for
    // a network call is gone: it was simulating the thing that now actually
    // happens, and leaving it in would add 700ms to every real request. In mock
    // mode the write is local and instant, which is the honest behaviour.
    final List<Order> placed;
    try {
      // Order first, then clear: placeOrders reads the selected lines, and
      // clearSelected() removes them from the cart.
      placed = await context.read<OrderState>().placeOrders(selectedItems);
    } on ApiException catch (error) {
      // The cart is deliberately NOT cleared on failure — a checkout that
      // emptied the cart and then failed to place the order would be the worst
      // possible outcome, because the buyer would have nothing left to retry
      // with.
      if (!mounted) return;
      setState(() => _isPlacingOrder = false);
      showTimedSnackBar(
        context,
        SnackBar(
          content: Text(error.message),
          persist: false,
          action: error.isRetryable
              ? SnackBarAction(
                  label: 'Retry',
                  onPressed: () => _placeOrder(selectedItems, total),
                )
              : null,
        ),
      );
      return;
    }
    if (!mounted) return;

    // One delivery job per order, landing on the courier's Pickup tab.
    // The courier is paid the standard fee whether or not the buyer's
    // shipping was free (the platform absorbs it). One fee per checkout,
    // split across the orders when a cart spans several sellers.
    final courier = context.read<CourierState>();
    for (final order in placed) {
      courier.dispatchForOrder(
        order: order,
        buyerName: (auth.name == null || auth.name!.isEmpty)
            ? 'Buyer'
            : auth.name!,
        deliveryArea: deliveryAddress,
        fee: kStandardShippingFee / placed.length,
        buyerPhone: auth.phone,
        trackingNumber: order.id,
      );
    }
    // Record the just-used address for the one-tap shortcut next
    // time — before clearing the cart, while the summary still reads.
    auth.recordRecentAddress(deliveryAddress);

    if (widget.checkoutItems == null) {
      // Record usage before removing the lines: the counters are per buyer and
      // have to survive into the next cart for per-buyer limits to mean
      // anything. This is also what clears the applied slots.
      await cart.recordAppliedVouchersUsed();
      cart.clearSelected();
    }
    if (!mounted) return;
    setState(() => _isPlacingOrder = false);

    if (!mounted) return;

    // Guaranteed non-empty above (an empty selection returns before this), but
    // the confirmation's primary action dereferences the first order, so it's
    // worth not being able to crash on a confirmation screen.
    if (placed.isEmpty) {
      Navigator.of(context).popUntil((route) => route.isFirst);
      return;
    }

    // Captured before anything pops. After the dialog closes and the checkout
    // route unwinds, `context` here is defunct — but the NavigatorState is
    // still perfectly valid, and it's what has to carry the buyer onward.
    final navigator = Navigator.of(context);

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => OrderConfirmationDialog(
        orders: placed,
        total: total,
        // Opens the same tracker the Orders tab pushes, for the order this
        // checkout just created. Push *after* unwinding, so the buyer ends on
        // [root, tracker] rather than a tracker buried under checkout.
        onTrack: () {
          navigator.pop(); // close the dialog
          navigator.popUntil((route) => route.isFirst);
          navigator.push(
            AppPageRoute(
              builder: (_) => CourierTrackerScreen(order: placed.first),
            ),
          );
        },
        onDone: () {
          navigator.pop(); // close the dialog
          navigator.popUntil((route) => route.isFirst);
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final cart = context.watch<CartState>();
    final selectedItems =
        widget.checkoutItems ??
        cart.items.where((i) => i.selected && i.isAvailable).toList();
    final quote = quoteCart(
      selectedItems,
      widget.checkoutItems == null ? cart.appliedVouchers : const <Voucher>[],
      buyerUsesFor: (voucher) => cart.buyerUsesFor(voucher.code),
    );

    return Scaffold(
      backgroundColor: Colors
          .transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      appBar: AppBar(),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 120),
        children: [
          const EditorialHeader(
            eyebrow: 'ALMOST THERE',
            title: 'Checkout',
            padding: EdgeInsets.fromLTRB(0, 12, 0, 16),
          ),
          StaggeredReveal(
            index: 0,
            child: KeyedSubtree(
              key: _addressKey,
              child: _buildAddressSection(context, theme, ext),
            ),
          ),
          const SizedBox(height: 20),
          StaggeredReveal(
            index: 1,
            child: KeyedSubtree(
              key: _itemsKey,
              child: _buildOrderSummarySection(
                theme,
                ext,
                selectedItems,
                quote,
              ),
            ),
          ),
          const SizedBox(height: 20),
          StaggeredReveal(
            index: 2,
            child: KeyedSubtree(
              key: _noteKey,
              child: _buildNotesSection(theme, ext),
            ),
          ),
          const SizedBox(height: 20),
          StaggeredReveal(index: 3, child: _buildPaymentSection(theme, ext)),
          const SizedBox(height: 16),
          StaggeredReveal(
            index: 4,
            child: KeyedSubtree(
              key: _termsKey,
              child: _buildTermsCheckbox(theme),
            ),
          ),
        ],
      ),
      bottomNavigationBar: _buildBottomBar(theme, selectedItems, quote.total),
    );
  }

  // ---------------------------------------------------------------------

  Widget _sectionCard(
    AppThemeExtension ext, {
    required Widget child,
    Color? accentColor,
    int index = 0,
    bool hasError = false,
  }) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        // Alternating asymmetric corners — same shape language as
        // product cards, cart tiles, and order cards. Four identical
        // uniform-radius surfaces stacked vertically reads as a flat
        // block, not four individual cards.
        borderRadius: editorialRadius(index, ShapeTier.card),
        border: hasError
            ? Border.all(color: AppColors.danger, width: 1.5)
            : accentColor != null
            ? Border(left: BorderSide(color: accentColor, width: 3))
            : null,
      ),
      child: child,
    );
  }

  /// Tappable summary header: collapsed is one row with the item
  /// count; tapping reveals the per-line breakdown below. The bottom
  /// bar always carries the total, so nothing is lost either way.
  Widget _summaryHeader(ThemeData theme, double total, int count) {
    return Semantics(
      button: true,
      expanded: _summaryExpanded,
      label:
          'Order summary, $count items, '
          'total ${formatPeso(total)}',
      child: InkWell(
        onTap: () => setState(() => _summaryExpanded = !_summaryExpanded),
        borderRadius: radiusOf(ShapeTier.control),
        child: Row(
          children: [
            Icon(
              Icons.receipt_long_outlined,
              size: 18,
              color: AppColors.primaryDark,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text('Order Summary', style: theme.textTheme.titleSmall),
            ),
            Text(
              '$count item${count == 1 ? '' : 's'}',
              style: theme.textTheme.bodySmall?.copyWith(
                color: AppColors.textSecondary,
              ),
            ),
            const SizedBox(width: 2),
            AnimatedRotation(
              turns: _summaryExpanded ? 0.5 : 0,
              duration: const Duration(milliseconds: 200),
              child: Icon(
                Icons.expand_more,
                size: 20,
                color: AppColors.textSecondary,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _sectionHeader(
    ThemeData theme,
    IconData icon,
    String title, {
    VoidCallback? onEdit,
  }) {
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

  Widget _buildAddressSection(
    BuildContext context,
    ThemeData theme,
    AppThemeExtension ext,
  ) {
    final auth = context.watch<AuthState>();
    final saved = auth.defaultAddress;
    final summary = saved?.summary ?? auth.addressSummary;
    final hasAddress = summary.isNotEmpty;
    if (hasAddress && _addressError != null) {
      _addressError = null;
    }
    final hasError = _addressError != null;

    return _sectionCard(
      ext,
      index: 0,
      accentColor: AppColors.primaryDark,
      hasError: hasError,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _sectionHeader(
            theme,
            Icons.location_on_outlined,
            'Delivery Address',
            onEdit: hasAddress
                ? () => Navigator.of(context).push(
                    AppPageRoute(builder: (_) => const AddressBookScreen()),
                  )
                : null,
          ),
          const SizedBox(height: 10),
          if (hasAddress) ...[
            Text(
              [
                saved?.recipient ?? auth.name,
                saved?.phone ?? auth.phone,
              ].where((v) => v != null && v.isNotEmpty).join('  •  '),
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: 4),
            Text(
              summary,
              style: theme.textTheme.bodySmall?.copyWith(height: 1.5),
            ),
            if (saved != null)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  'Default address \u00b7 tap Change to use another',
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
                ),
              ),
          ] else ...[
            Text(
              'No delivery address on file.',
              style: theme.textTheme.bodySmall?.copyWith(
                height: 1.5,
                color: AppColors.textSecondary,
              ),
            ),
            const SizedBox(height: 12),
            ElevatedButton.icon(
              onPressed: () => Navigator.of(
                context,
              ).push(AppPageRoute(builder: (_) => const AddressBookScreen())),
              icon: const Icon(Icons.add_location_alt_outlined, size: 18),
              label: const Text('Add delivery address'),
            ),
          ],
          if (hasError) ...[
            const SizedBox(height: 10),
            Row(
              children: [
                Icon(Icons.error_outline, size: 16, color: AppColors.danger),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    _addressError!,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: AppColors.danger,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildOrderSummarySection(
    ThemeData theme,
    AppThemeExtension ext,
    List<CartItem> items,
    CartQuote quote,
  ) {
    // "Nothing selected" is the only item error that fixes itself when lines
    // appear. A stock or purchase-limit refusal must stay on screen: the lines
    // are still there, and clearing the sentence would hide why Place Order
    // did nothing.
    if (items.isNotEmpty &&
        _itemsError == 'Your cart has no selected items to check out.') {
      _itemsError = null;
    }
    final hasError = _itemsError != null;

    return _sectionCard(
      ext,
      index: 1,
      hasError: hasError,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _summaryHeader(theme, quote.total, items.length),
          if (hasError) ...[
            const SizedBox(height: 10),
            Row(
              children: [
                Icon(Icons.error_outline, size: 16, color: AppColors.danger),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    _itemsError!,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: AppColors.danger,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ],
            ),
          ],
          if (_summaryExpanded) ...[
            const SizedBox(height: 10),
            if (items.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Text(
                  'No items selected for checkout.',
                  style: theme.textTheme.bodySmall,
                ),
              )
            else
              ...items.map(
                (i) => _orderLine(
                  theme,
                  '${i.product.name}${i.variant.isNotEmpty ? ' (${i.variant})' : ''}  x${i.quantity}',
                  formatPeso(i.lineTotal),
                ),
              ),
            const Divider(height: 24),
            _orderLine(theme, 'Subtotal', formatPeso(quote.merchandise)),
            for (final discount in quote.voucherDiscounts)
              _orderLine(
                theme,
                '${switch (discount.voucher.scope) {
                  VoucherScope.store => 'Store',
                  VoucherScope.platform => 'Platform',
                  VoucherScope.shipping => 'Shipping',
                }} voucher (${discount.voucher.code})',
                '- ${formatPeso(discount.amount)}',
                valueColor: AppColors.successText,
              ),
            _orderLine(
              theme,
              'Shipping fee',
              quote.shipping == 0 ? 'Free' : formatPeso(quote.shipping),
              valueColor: quote.shipping == 0
                  ? AppColors.successText
                  : null,
            ),
            const Divider(height: 24),
            _orderLine(
              theme,
              'Total',
              formatPeso(quote.total),
              isBold: true,
            ),
          ],
        ],
      ),
    );
  }

  Widget _orderLine(
    ThemeData theme,
    String label,
    String value, {
    bool isBold = false,
    Color? valueColor,
  }) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Expanded(
            child: Text(
              label,
              style: isBold
                  ? theme.textTheme.titleSmall
                  : theme.textTheme.bodyMedium,
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
    final hasError = _noteError != null;
    return _sectionCard(
      ext,
      index: 2,
      hasError: hasError,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _sectionHeader(
            theme,
            Icons.edit_note_outlined,
            'Delivery Note (optional)',
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _notesController,
            maxLines: 2,
            maxLength: _notesMaxLength,
            decoration: InputDecoration(
              hintText: 'e.g. "Leave with the guard if I\'m out"',
              errorText: _noteError,
              errorBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(ext.utilityRadius),
                borderSide: BorderSide(color: AppColors.danger, width: 1.5),
              ),
              focusedErrorBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(ext.utilityRadius),
                borderSide: BorderSide(color: AppColors.danger, width: 2),
              ),
            ),
            onChanged: (text) {
              if (_noteError != null && text.length <= _notesMaxLength) {
                setState(() => _noteError = null);
              } else {
                setState(() {});
              }
            },
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
          border: Border.all(
            color: selected ? AppColors.primaryDark : AppColors.divider,
            width: selected ? 1.5 : 1,
          ),
        ),
        child: Row(
          children: [
            Icon(icon, size: 20, color: AppColors.textPrimary),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
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
    final hasError = _termsError != null;

    return Container(
      decoration: BoxDecoration(
        color: hasError
            ? AppColors.danger.withValues(alpha: 0.08)
            : Colors.transparent,
        borderRadius: BorderRadius.circular(ext.utilityRadius),
        border: Border.all(
          color: hasError ? AppColors.danger : Colors.transparent,
          width: 1.5,
        ),
      ),
      padding: hasError ? const EdgeInsets.all(8) : EdgeInsets.zero,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          InkWell(
            onTap: () => setState(() {
              _agreedToTerms = !_agreedToTerms;
              if (_agreedToTerms) _termsError = null;
            }),
            borderRadius: BorderRadius.circular(ext.utilityRadius),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Checkbox(
                  value: _agreedToTerms,
                  onChanged: (v) => setState(() {
                    _agreedToTerms = v ?? false;
                    if (_agreedToTerms) _termsError = null;
                  }),
                ),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(top: 14),
                    child: Text(
                      'I agree to the Terms of Service and Return Policy.',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: hasError
                            ? AppColors.danger
                            : AppColors.textSecondary,
                        fontWeight: hasError ? FontWeight.w600 : null,
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          if (hasError) ...[
            Padding(
              padding: const EdgeInsets.only(left: 12, bottom: 6, top: 2),
              child: Row(
                children: [
                  Icon(Icons.error_outline, size: 14, color: AppColors.danger),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      _termsError!,
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: AppColors.danger,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildBottomBar(
    ThemeData theme,
    List<CartItem> selectedItems,
    double total,
  ) {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        boxShadow: [
          BoxShadow(
            color: AppColors.neutral.withValues(alpha: 0.12),
            blurRadius: 16,
            offset: const Offset(0, -4),
          ),
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
                  Text(formatPeso(total), style: AppType.priceNumeral(32)),
                ],
              ),
            ),
            ElevatedButton(
              onPressed: _isPlacingOrder
                  ? null
                  : () => _placeOrder(selectedItems, total),
              child: _isPlacingOrder
                  ? ZefanyaLoader(
                      size: 18,
                      color: AppColors.textOnDark,
                      strokeWidth: 4,
                    )
                  : const Text('Place Order'),
            ),
          ],
        ),
      ),
    );
  }
}
