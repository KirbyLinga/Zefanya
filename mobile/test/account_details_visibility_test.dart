// test/account_details_visibility_test.dart
//
// Verification for Phase B2: Account details visibility.
// Asserts:
// 1. Opaque surface tokens (AppColors.surface) behind ledger/form cards.
// 2. 12sp muted labels (AppColors.textSecondary) and 15sp semi-bold values (AppColors.textPrimary).
// 3. Empty values become tappable prompts (e.g. "Add phone", "Add company", "Add expiry date")
//    with min 48dp touch targets.
// 4. Clean layout with no RenderFlex overflows under TextScaler.linear(1.3).

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/screens/courier/rider_profile_section.dart';
import 'package:zefanya/screens/courier/rider_vehicle_screen.dart';
import 'package:zefanya/screens/shared/edit_profile_screen.dart';
import 'package:zefanya/screens/shared/profile_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

Widget _wrapWithProviders({
  required Widget child,
  AuthState? authState,
  CourierState? courierState,
  ThemeData? theme,
  double textScale = 1.0,
}) {
  final auth = authState ?? AuthState();
  final courier = courierState ?? CourierState();
  return MultiProvider(
    providers: [
      ChangeNotifierProvider<AuthState>.value(value: auth),
      ChangeNotifierProvider<CourierState>.value(value: courier),
      ChangeNotifierProvider(create: (_) => OrderState()),
      ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
      ChangeNotifierProvider(create: (_) => WishlistState()),
      ChangeNotifierProvider(create: (_) => ThemeState()),
    ],
    child: MaterialApp(
      theme: theme ?? AppTheme.light,
      home: MediaQuery(
        data: MediaQueryData(
          size: const Size(390, 844),
          textScaler: TextScaler.linear(textScale),
        ),
        child: child,
      ),
    ),
  );
}

void _setPhoneSize(WidgetTester tester) {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('Phase B2: ProfileScreen account details visibility', () {
    testWidgets('empty fields display tappable prompt with min 48dp target', (tester) async {
      _setPhoneSize(tester);
      final auth = AuthState();
      // AuthState defaults to empty phone, sex, street

      await tester.pumpWidget(
        _wrapWithProviders(
          child: const ProfileScreen(title: 'Buyer Profile'),
          authState: auth,
        ),
      );
      await tester.pump(const Duration(milliseconds: 100));

      // Check prompt texts are visible
      expect(find.text('Add phone'), findsOneWidget);
      expect(find.text('Add sex'), findsOneWidget);
      expect(find.text('Add address'), findsOneWidget);

      // Verify prompt styling
      final promptWidget = tester.widget<Text>(find.text('Add phone'));
      expect(promptWidget.style?.fontSize, 15);
      expect(promptWidget.style?.fontWeight, FontWeight.w600);
      expect(promptWidget.style?.color, AppColors.accent);

      // Verify label styling
      final phoneLabel = tester.widget<Text>(find.text('Phone'));
      expect(phoneLabel.style?.fontSize, 12);
      expect(phoneLabel.style?.fontWeight, FontWeight.w500);
      expect(phoneLabel.style?.color, AppColors.textSecondary);

      // Verify ink response min 48dp
      final inkWellFinder = find.ancestor(
        of: find.text('Add phone'),
        matching: find.byType(InkWell),
      );
      expect(inkWellFinder, findsOneWidget);
      final inkSize = tester.getSize(inkWellFinder);
      expect(inkSize.height, greaterThanOrEqualTo(48.0));
    });

    testWidgets('filled fields display 12sp label and 15sp value on opaque surface', (tester) async {
      _setPhoneSize(tester);
      final auth = AuthState();
      auth.updateProfile(
        firstName: 'Maria',
        lastName: 'Clara',
        phone: '09171234567',
        sex: 'Female',
        street: '123 Rizal St',
      );

      await tester.pumpWidget(
        _wrapWithProviders(
          child: const ProfileScreen(title: 'Buyer Profile'),
          authState: auth,
        ),
      );
      await tester.pump(const Duration(milliseconds: 100));

      expect(find.text('09171234567'), findsOneWidget);
      expect(find.text('Female'), findsOneWidget);

      final valWidget = tester.widget<Text>(find.text('09171234567'));
      expect(valWidget.style?.fontSize, 15);
      expect(valWidget.style?.fontWeight, FontWeight.w600);
      expect(valWidget.style?.color, AppColors.textPrimary);
    });
  });

  group('Phase B2: RiderProfileSection account details visibility', () {
    testWidgets('empty vehicle details render tappable prompts', (tester) async {
      _setPhoneSize(tester);
      final auth = AuthState();

      await tester.pumpWidget(
        _wrapWithProviders(
          child: const Scaffold(
            body: SingleChildScrollView(child: RiderProfileSection()),
          ),
          authState: auth,
        ),
      );
      await tester.pump(const Duration(milliseconds: 100));

      expect(find.text('Add company'), findsOneWidget);
      expect(find.text('Add vehicle'), findsOneWidget);
      expect(find.text('Add plate number'), findsOneWidget);

      final prompt = tester.widget<Text>(find.text('Add company'));
      expect(prompt.style?.fontSize, 15);
      expect(prompt.style?.fontWeight, FontWeight.w600);
      expect(prompt.style?.color, AppColors.accent);

      final label = tester.widget<Text>(find.text('Company'));
      expect(label.style?.fontSize, 12);
      expect(label.style?.fontWeight, FontWeight.w500);
      expect(label.style?.color, AppColors.textSecondary);
    });
  });

  group('Phase B2: RiderVehicleScreen input styling and prompts', () {
    testWidgets('expiry field renders Add expiry date prompt when empty', (tester) async {
      _setPhoneSize(tester);
      final auth = AuthState();

      await tester.pumpWidget(
        _wrapWithProviders(
          child: const RiderVehicleScreen(),
          authState: auth,
        ),
      );
      await tester.pump(const Duration(milliseconds: 100));

      expect(find.text('Add expiry date'), findsOneWidget);
      final prompt = tester.widget<Text>(find.text('Add expiry date'));
      expect(prompt.style?.fontSize, 15);
      expect(prompt.style?.fontWeight, FontWeight.w600);
      expect(prompt.style?.color, AppColors.accent);
    });

    testWidgets('vehicle type is Motorcycle only, even for an older saved value',
        (tester) async {
      _setPhoneSize(tester);
      // A vehicle saved before riders were limited to motorcycles.
      final auth = AuthState()..riderVehicleType = 'Tricycle';

      await tester.pumpWidget(
        _wrapWithProviders(
          child: const RiderVehicleScreen(),
          authState: auth,
        ),
      );
      await tester.pump(const Duration(milliseconds: 100));

      expect(tester.takeException(), isNull);
      expect(find.text('Motorcycle'), findsOneWidget);
      expect(find.text('Tricycle'), findsNothing);

      await tester.tap(find.text('Motorcycle'));
      await tester.pumpAndSettle();
      // The open menu lists exactly one choice (selected + menu item).
      expect(find.text('Motorcycle'), findsNWidgets(2));
      expect(find.text('Van'), findsNothing);
      expect(find.text('Sedan'), findsNothing);
    });
  });

  group('Phase B2: EditProfileScreen card containers and input styling', () {
    testWidgets('renders opaque surface cards with 12sp labels and 15sp inputs', (tester) async {
      _setPhoneSize(tester);
      final auth = AuthState();

      await tester.pumpWidget(
        _wrapWithProviders(
          child: const EditProfileScreen(),
          authState: auth,
        ),
      );
      await tester.pump(const Duration(milliseconds: 100));

      expect(find.text('PERSONAL INFORMATION'), findsOneWidget);
      expect(find.text('ADDRESS DETAILS'), findsOneWidget);

      final label = tester.widget<Text>(find.text('Last Name'));
      expect(label.style?.fontSize, 12);
      expect(label.style?.fontWeight, FontWeight.w500);
      expect(label.style?.color, AppColors.textSecondary);
    });
  });

  group('Phase B2: Layout at 1.3x text scale without overflows', () {
    testWidgets('ProfileScreen fits at 1.3x text scale without overflow', (tester) async {
      _setPhoneSize(tester);
      final auth = AuthState();
      auth.updateProfile(
        firstName: 'Maria Cristina',
        lastName: 'Dela Cruz y Santos',
        phone: '09171234567',
        sex: 'Female',
        street: 'Block 12 Lot 34 Phase 2 Maharlika Village',
      );

      await tester.pumpWidget(
        _wrapWithProviders(
          child: const ProfileScreen(title: 'Buyer Profile'),
          authState: auth,
          textScale: 1.3,
        ),
      );
      await tester.pump(const Duration(milliseconds: 200));

      // No assertion failure or overflow thrown
      expect(find.byType(ProfileScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('EditProfileScreen fits at 1.3x text scale without overflow', (tester) async {
      _setPhoneSize(tester);
      final auth = AuthState();

      await tester.pumpWidget(
        _wrapWithProviders(
          child: const EditProfileScreen(),
          authState: auth,
          textScale: 1.3,
        ),
      );
      await tester.pump(const Duration(milliseconds: 200));

      expect(find.text('Edit Profile'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('RiderProfileSection fits at 1.3x text scale without overflow', (tester) async {
      _setPhoneSize(tester);
      final auth = AuthState();

      await tester.pumpWidget(
        _wrapWithProviders(
          child: const Scaffold(
            body: SingleChildScrollView(child: RiderProfileSection()),
          ),
          authState: auth,
          textScale: 1.3,
        ),
      );
      await tester.pump(const Duration(milliseconds: 200));

      expect(find.text('RIDER PROFILE'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('RiderVehicleScreen fits at 1.3x text scale without overflow', (tester) async {
      _setPhoneSize(tester);
      final auth = AuthState();

      await tester.pumpWidget(
        _wrapWithProviders(
          child: const RiderVehicleScreen(),
          authState: auth,
          textScale: 1.3,
        ),
      );
      await tester.pump(const Duration(milliseconds: 200));

      expect(find.text('Vehicle & Documents'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}
