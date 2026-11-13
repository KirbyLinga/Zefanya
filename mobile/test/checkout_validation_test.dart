import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/address_entry.dart';
import 'package:zefanya/data/repositories/order_repository.dart';
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
  OrderState? orders,
  bool canGoBack = false,
  Map<String, Object> preferences = const {},
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  SharedPreferences.setMockInitialValues(preferences);
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: auth),
        ChangeNotifierProvider.value(value: cart),
        ChangeNotifierProvider(create: (_) => CourierState()),
        ChangeNotifierProvider(create: (_) => orders ?? OrderState()),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        home: canGoBack
            ? const Scaffold(body: Text('Cart route'))
            : const CheckoutScreen(),
        initialRoute: canGoBack ? '/checkout' : '/',
        routes: {'/checkout': (_) => const CheckoutScreen()},
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 300));
}

class _QuotedOrders extends MockOrderRepository implements QuotesCheckout {
  _QuotedOrders(this.unknown, {this.pending});
  final bool unknown;
  final Completer<void>? pending;
  double? reviewed;
  int submissions = 0;

  @override
  Future<double> quoteTotal(
    List<OrderLineRequest> lines, {
    List<String> voucherCodes = const [],
  }) async => 4321;

  @override
  Future<List<Order>> place(
    List<OrderLineRequest> lines, {
    List<String> voucherCodes = const [],
    double? expectedTotal,
  }) async {
    submissions++;
    reviewed = expectedTotal;
    if (pending != null) await pending!.future;
    if (unknown) throw const CheckoutOutcomeUnknown();
    return super.place(
      lines,
      voucherCodes: voucherCodes,
      expectedTotal: expectedTotal,
    );
  }
}

void main() {
  testWidgets('reopened checkout keeps the unresolved submission blocked', (
    tester,
  ) async {
    final requests = <String>[];
    final repository = LiveOrderRepository(
      ApiClient(
        baseUrl: 'https://example.test',
        tokenStore: InMemoryTokenStore(),
        httpClient: MockClient((request) async {
          requests.add(request.url.path);
          return http.Response('{"data":{"status":"unknown"}}', 200);
        }),
      ),
    )..setCheckoutOwner('buyer-a');
    final cart = CartState()..addItem(mockProducts.first);
    await _pumpCheckout(
      tester,
      auth: AuthState(),
      cart: cart,
      orders: OrderState(repository: repository),
      canGoBack: true,
      preferences: {
        'live_checkout:https://example.test:buyer-a':
            '{"token":"existing-token"}',
      },
    );
    await tester.pumpAndSettle();
    final navigator = tester.state<NavigatorState>(find.byType(Navigator));
    expect(find.text('Check checkout status'), findsOneWidget);
    navigator.pop();
    await tester.pumpAndSettle();
    navigator.pushNamed<void>('/checkout');
    await tester.pumpAndSettle();
    expect(find.text('Place Order'), findsNothing);
    await tester.tap(find.text('Check checkout status'));
    await tester.pumpAndSettle();
    expect(requests, ['/buyer/checkout/reconcile']);
    expect(find.text('Place Order'), findsNothing);
    await tester.pump(const Duration(seconds: 4));
    await tester.pumpAndSettle();
  });

  testWidgets('live checkout reviews the server address without a local one', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({});
    final auth = AuthState();
    final cart = CartState();
    await cart.load();
    cart.addItem(mockProducts.first);
    final requests = <String>[];
    final client = ApiClient(
      baseUrl: 'https://api.zefanya.test',
      tokenStore: InMemoryTokenStore(),
      httpClient: MockClient((request) async {
        requests.add(request.url.path);
        return http.Response(
          '{"data":{"totals":{"payable_total_minor":54900},'
          '"deliver_to":{"full":"25 Demo Street, Pila"},'
          '"address_fingerprint":"reviewed-address"}}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    await _pumpCheckout(
      tester,
      auth: auth,
      cart: cart,
      orders: OrderState(repository: LiveOrderRepository(client)),
    );
    expect(find.text('Add delivery address'), findsNothing);
    await tester.scrollUntilVisible(
      find.byType(Checkbox),
      180,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byType(Checkbox));
    await tester.tap(find.text('Place Order'));
    await tester.pumpAndSettle();
    expect(
      find.textContaining('Deliver to: 25 Demo Street, Pila'),
      findsOneWidget,
    );
    expect(find.textContaining('₱549'), findsOneWidget);
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();
    expect(requests, ['/v1/buyer/checkout/quote']);
  });

  for (final unknown in [false, true]) {
    testWidgets(
      'checkout uses the reviewed total and handles unknown outcome=$unknown',
      (tester) async {
        SharedPreferences.setMockInitialValues({});
        final auth = AuthState()
          ..updateProfile(
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
        final pending = Completer<void>();
        final repository = _QuotedOrders(unknown, pending: pending);
        await _pumpCheckout(
          tester,
          auth: auth,
          cart: cart,
          orders: OrderState(repository: repository),
          canGoBack: true,
        );
        await tester.scrollUntilVisible(
          find.byType(Checkbox),
          180,
          scrollable: find.byType(Scrollable).first,
        );
        await tester.pumpAndSettle();
        await tester.tap(find.byType(Checkbox));
        await tester.tap(find.text('Place Order'));
        await tester.pumpAndSettle();
        expect(find.textContaining('₱4,321'), findsOneWidget);
        final confirm = find.byKey(const ValueKey('confirm-server-total'));
        await tester.tap(confirm);
        await tester.tap(confirm);
        await tester.pump(const Duration(milliseconds: 400));
        await tester.binding.handlePopRoute();
        await tester.pump();
        expect(find.byType(CheckoutScreen), findsOneWidget);
        expect(find.text('Cart route'), findsNothing);
        expect(repository.submissions, 1);
        pending.complete();
        await tester.pumpAndSettle();
        expect(repository.reviewed, 4321);
        expect(repository.submissions, 1);
        if (unknown) {
          expect(find.text('Check Orders'), findsOneWidget);
          expect(find.text('Retry'), findsNothing);
          expect(find.text('Place Order'), findsNothing);
        } else {
          expect(find.textContaining('₱4,321'), findsOneWidget);
          expect(find.text('Track Order'), findsOneWidget);
        }
      },
    );
  }

  testWidgets(
    'shows Add delivery address button and inline validation errors on empty submission',
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
      expect(
        find.text('Add a delivery address before placing your order.'),
        findsOneWidget,
      );
      // Terms error is set and exists in the tree (offstage since address is at top of list)
      expect(
        find.text(
          'Please agree to the Terms & Return Policy to continue.',
          skipOffstage: false,
        ),
        findsOneWidget,
      );

      // No SnackBars must be shown for validation errors
      expect(find.byType(SnackBar), findsNothing);
    },
  );

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
    expect(
      find.text('Please agree to the Terms & Return Policy to continue.'),
      findsOneWidget,
    );
    expect(find.byType(SnackBar), findsNothing);
  });

  testWidgets('BUY-ADDR-006 checkout pre-selects the address-book default', (
    tester,
  ) async {
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
        postalCode: '1100',
        latitude: 14.59950,
        longitude: 120.98420,
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
        '88 Sampaguita St, Commonwealth, Quezon City, Metro Manila, 1100',
      ),
      findsOneWidget,
    );
    expect(find.textContaining('1 Profile Street'), findsNothing);
    expect(find.textContaining('Default address'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('checkout-address-label')),
      findsOneWidget,
    );
    expect(find.text('HOME'), findsOneWidget);
    expect(find.byKey(const ValueKey('checkout-address-pin')), findsOneWidget);
  });
}
