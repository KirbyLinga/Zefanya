// test/settings_screen_test.dart
//
// S5.2: one settings hub, role-aware rows, three theme choices, a logout that
// asks first, and Developer options that stay invisible until seven About taps.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/config/app_info.dart';
import 'package:zefanya/screens/courier/dev_qr_generator_screen.dart';
import 'package:zefanya/screens/shared/profile_screen.dart';
import 'package:zefanya/screens/shared/settings_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

Widget _wrap({
  required Widget child,
  required AuthState auth,
  required ThemeState theme,
  CourierState? courier,
}) {
  return MultiProvider(
    providers: [
      ChangeNotifierProvider<AuthState>.value(value: auth),
      ChangeNotifierProvider<ThemeState>.value(value: theme),
      ChangeNotifierProvider<CourierState>.value(
        value: courier ?? CourierState(),
      ),
      ChangeNotifierProvider(create: (_) => OrderState()),
      ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
      ChangeNotifierProvider(create: (_) => WishlistState()),
    ],
    child: MaterialApp(
      theme: AppTheme.light,
      home: child,
    ),
  );
}

void _phoneSize(WidgetTester tester) {
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  testWidgets('buyer settings shows only the account rows that work',
      (tester) async {
    _phoneSize(tester);
    final auth = AuthState();
    await tester.pumpWidget(
      _wrap(child: const SettingsScreen(), auth: auth, theme: ThemeState()),
    );

    expect(find.text('Settings'), findsOneWidget);
    expect(find.text('Account & Security'), findsOneWidget);
    expect(find.text('Addresses'), findsOneWidget);
    expect(find.text('Help & Support'), findsOneWidget);
    expect(find.text('Terms & Privacy'), findsOneWidget);
    expect(find.byKey(const ValueKey('settings-about')), findsOneWidget);
    expect(find.byKey(const ValueKey('settings-vehicle')), findsNothing);
    expect(find.byKey(const ValueKey('settings-developer')), findsNothing);

    await tester.scrollUntilVisible(
      find.byKey(const ValueKey('settings-logout')),
      300,
    );
    expect(find.byKey(const ValueKey('settings-logout')), findsOneWidget);
  });

  testWidgets('rider settings replaces addresses with vehicle and documents',
      (tester) async {
    _phoneSize(tester);
    final auth = AuthState()..role = UserRole.courier;

    await tester.pumpWidget(
      _wrap(child: const SettingsScreen(), auth: auth, theme: ThemeState()),
    );

    expect(find.byKey(const ValueKey('settings-vehicle')), findsOneWidget);
    expect(find.byKey(const ValueKey('settings-addresses')), findsNothing);
    expect(
      find.byKey(const ValueKey('settings-account-security')),
      findsNothing,
    );
  });

  testWidgets('appearance offers Light, Dark and System and applies the choice',
      (tester) async {
    _phoneSize(tester);
    final theme = ThemeState();
    await tester.pumpWidget(
      _wrap(
        child: const SettingsScreen(),
        auth: AuthState(),
        theme: theme,
      ),
    );

    expect(find.text('Light'), findsOneWidget);
    expect(find.text('Dark'), findsOneWidget);
    expect(find.text('System'), findsOneWidget);

    await tester.tap(find.text('Dark'));
    await tester.pumpAndSettle();
    expect(theme.mode, ThemeMode.dark);

    await tester.tap(find.text('System'));
    await tester.pumpAndSettle();
    expect(theme.mode, ThemeMode.system);
  });

  testWidgets('About unlocks Developer options only on the seventh tap',
      (tester) async {
    _phoneSize(tester);
    final about = find.byKey(const ValueKey('settings-about'));
    await tester.pumpWidget(
      _wrap(
        child: const SettingsScreen(),
        auth: AuthState(),
        theme: ThemeState(),
      ),
    );

    await tester.ensureVisible(about);
    for (var i = 0; i < kDeveloperOptionsTapCount - 1; i++) {
      await tester.tap(about);
      await tester.pump(const Duration(milliseconds: 40));
    }
    expect(find.byKey(const ValueKey('settings-developer')), findsNothing);

    await tester.tap(about);
    await tester.pump(const Duration(milliseconds: 100));
    expect(find.byKey(const ValueKey('settings-developer')), findsOneWidget);

    await tester.ensureVisible(
      find.byKey(const ValueKey('settings-developer')),
    );
    await tester.tap(find.byKey(const ValueKey('settings-developer')));
    await tester.pumpAndSettle();

    expect(find.text('Mock data'), findsOneWidget);
    expect(find.byKey(const ValueKey('developer-qr-generator')), findsOneWidget);

    await tester.ensureVisible(
      find.byKey(const ValueKey('developer-qr-generator')),
    );
    await tester.tap(find.byKey(const ValueKey('developer-qr-generator')));
    await tester.pumpAndSettle();
    expect(find.byType(DevQrGeneratorScreen), findsOneWidget);
  });

  testWidgets('log out asks first and Stay keeps the session', (tester) async {
    _phoneSize(tester);
    final auth = AuthState()
      ..role = UserRole.buyer
      ..status = AccountStatus.active;
    await tester.pumpWidget(
      _wrap(child: const SettingsScreen(), auth: auth, theme: ThemeState()),
    );

    final logout = find.byKey(const ValueKey('settings-logout'));
    await tester.scrollUntilVisible(logout, 300);
    await tester.tap(logout);
    await tester.pumpAndSettle();

    expect(find.text('LOG OUT?'), findsOneWidget);
    expect(find.text('Log out of Zefanya?'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('settings-logout-stay')));
    await tester.pumpAndSettle();

    expect(find.text('LOG OUT?'), findsNothing);
    expect(auth.status, AccountStatus.active);
  });

  testWidgets('confirming log out ends the session', (tester) async {
    _phoneSize(tester);
    final auth = AuthState()
      ..role = UserRole.buyer
      ..status = AccountStatus.active;
    await tester.pumpWidget(
      _wrap(child: const SettingsScreen(), auth: auth, theme: ThemeState()),
    );

    final logout = find.byKey(const ValueKey('settings-logout'));
    await tester.scrollUntilVisible(logout, 300);
    await tester.tap(logout);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('settings-logout-confirm')));
    await tester.pumpAndSettle();

    expect(auth.status, AccountStatus.loggedOut);
    expect(auth.role, isNull);
  });

  testWidgets('Profile gear opens Settings and Profile no longer signs out',
      (tester) async {
    _phoneSize(tester);
    await tester.pumpWidget(
      _wrap(
        child: const ProfileScreen(title: 'Buyer Profile'),
        auth: AuthState(),
        theme: ThemeState(),
      ),
    );

    expect(find.text('Log Out'), findsNothing);
    expect(find.text('APPEARANCE'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('open-settings')));
    await tester.pumpAndSettle();

    expect(find.byType(SettingsScreen), findsOneWidget);
    expect(find.text('Settings'), findsOneWidget);
  });
}
