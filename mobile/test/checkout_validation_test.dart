import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/address_entry.dart';
import 'package:zefanya/screens/buyer/checkout_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/theme/app_theme.dart';

Future<void> _pumpCheckout(
  WidgetTester tester, {
  required AuthState auth,
  required CartState cart,
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  SharedPreferences.setMockInitialValues({});
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: auth),
        ChangeNotifierProvider.value(value: cart),
        ChangeNotifierProvider(create: (_) => CourierState()),
        ChangeNotifierProvider(create: (_) => OrderState()),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        home: const CheckoutScreen(),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 300));
}

void main() {
  testWidgets('shows Add delivery address button and inline validation errors on empty submission',
      (tester) async {
    SharedPreferences.setMockInitialValues({});
    final auth = AuthState();
    final cart = CartState();
    await cart.load();
    cart.addItem(mockProducts.first);

    await _pumpCheckout(tester, auth: auth, cart: cart);

    // Initial state: no address -> shows inline "Add delivery address" button, no "Change" text
    expect(find.text('Add delivery address'), findsOneWidget);
    expect(find.text('Change'), findsNothing);

    // Tap "Place Order" without address and without terms
    await tester.tap(find.text('Place Order'));
    await tester.pump(const Duration(milliseconds: 300));

    // Address error is on-screen because it auto-scrolled to the first invalid section (address)
    expect(find.text('Add a delivery address before placing your order.'), findsOneWidget);
    // Terms error is set and exists in the tree (offstage since address is at top of list)
    expect(
      find.text('Please agree to the Terms & Return Policy to continue.', skipOffstage: false),
      findsOneWidget,
    );

    // No SnackBars must be shown for validation errors
    expect(find.byType(SnackBar), findsNothing);
  });

  testWidgets('scrolls to terms error when address is valid', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final auth = AuthState();
    auth.updateProfile(
      firstName: 'Maria',
      lastName: 'Santos',
      phone: '09171234567',
      street: '123 Rizal St',
      barangay: 'Poblacion',
      municipality: 'Makati',
      province: 'Metro Manila',
    );
    final cart = CartState();
    await cart.load();
    cart.addItem(mockProducts.first);

    await _pumpCheckout(tester, auth: auth, cart: cart);

    // With address present, shows "Change" button
    expect(find.text('Change'), findsOneWidget);
    expect(find.text('Add delivery address'), findsNothing);

    // Tap "Place Order" without agreeing to terms
    await tester.tap(find.text('Place Order'));
    // Start ticker with bare pump() then elapse time for scroll animation
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pump(const Duration(milliseconds: 400));

    // Scrolled directly to terms error, now visible on screen
    expect(find.text('Please agree to the Terms & Return Policy to continue.'), findsOneWidget);
    expect(find.byType(SnackBar), findsNothing);
  });

  testWidgets('BUY-ADDR-006 checkout pre-selects the address-book default',
      (tester) async {
    SharedPreferences.setMockInitialValues({});
    final auth = AuthState();
    auth.updateProfile(
      firstName: 'Maria',
      lastName: 'Santos',
      phone: '09171234567',
      street: '1 Profile Street',
      barangay: 'Poblacion',
      municipality: 'Makati',
      province: 'Metro Manila',
    );
    auth.ensureAddressBookSeeded();
    auth.addAddress(
      const AddressEntry(
        id: '',
        recipient: 'Ana Reyes',
        phone: '09987654321',
        region: 'National Capital Region (NCR)',
        city: 'Quezon City',
        barangay: 'Commonwealth',
        street: '88 Sampaguita St',
      ),
    );
    auth.setDefaultAddress('ADDR-2');

    final cart = CartState();
    await cart.load();
    cart.addItem(mockProducts.first);
    await _pumpCheckout(tester, auth: auth, cart: cart);

    expect(find.textContaining('Ana Reyes'), findsOneWidget);
    expect(
      find.text(
        '88 Sampaguita St, Commonwealth, Quezon City, '
        'National Capital Region (NCR)',
      ),
      findsOneWidget,
    );
    expect(find.textContaining('1 Profile Street'), findsNothing);
    expect(find.textContaining('Default address'), findsOneWidget);
  });
}
