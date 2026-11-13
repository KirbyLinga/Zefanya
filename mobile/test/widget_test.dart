// test/widget_test.dart
//
// App-boot smoke test.
//
// This replaces the Flutter counter template that shipped here: it
// asserted `find.text('0')` and tapped `Icons.add` against an app that has
// never had a counter, so it failed on every run and made `flutter test`
// useless — a real regression was indistinguishable from standing noise.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/main.dart';
import 'package:zefanya/screens/landing_screen.dart';
import 'package:zefanya/screens/onboarding_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/chat_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/notification_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';

Widget _bootApp() {
  return MultiProvider(
    providers: [
      ChangeNotifierProvider(create: (_) => AuthState()),
      ChangeNotifierProvider(create: (_) => CartState()),
      ChangeNotifierProvider(create: (_) => ChatState()),
      ChangeNotifierProvider(create: (_) => CourierState()),
      ChangeNotifierProvider(create: (_) => NotificationState()),
      ChangeNotifierProvider(create: (_) => OrderState()),
      ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
      ChangeNotifierProvider(create: (_) => WishlistState()),
      ChangeNotifierProvider(create: (_) => ThemeState()),
    ],
    child: const MarketplaceApp(),
  );
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    // Tests have no network: google_fonts has to fall back rather than try
    // to fetch Playfair/Montserrat over HTTP.
    GoogleFonts.config.allowRuntimeFetching = false;
  });

  Future<void> launch(WidgetTester tester) async {
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pumpWidget(_bootApp());
    await tester.pumpAndSettle();
  }

  void expectLanding() {
    expect(find.byType(LandingScreen), findsOneWidget);
    expect(find.text('Sign in').hitTestable(), findsOneWidget);
    expect(find.text('Create Buyer account').hitTestable(), findsOneWidget);
    expect(find.byType(OnboardingScreen), findsNothing);
  }

  testWidgets(
    'first launch supports swipe, Back, Next and persistent completion',
    (tester) async {
      await launch(tester);
      expect(find.text('Discover something extraordinary.'), findsOneWidget);
      expect(find.text('Back'), findsNothing);
      await tester.drag(
        find.byKey(const ValueKey('onboarding-pages')),
        const Offset(-500, 0),
      );
      await tester.pumpAndSettle();
      expect(find.text('Find pieces that feel like you.'), findsOneWidget);
      await tester.tap(find.text('Back'));
      await tester.pumpAndSettle();
      expect(find.text('Discover something extraordinary.'), findsOneWidget);
      for (var i = 0; i < 2; i++) {
        await tester.tap(find.text('Next'));
        await tester.pumpAndSettle();
      }
      expect(find.text('From local shops to your doorstep.'), findsOneWidget);
      await tester.tap(find.text('Get Started'));
      await tester.pumpAndSettle();
      expectLanding();
      final preferences = await SharedPreferences.getInstance();
      await preferences.reload();
      expect(preferences.getBool(OnboardingScreen.completionKey), isTrue);
      await launch(tester);
      expectLanding();
    },
  );

  for (var page = 1; page <= 3; page++) {
    testWidgets('Skip on onboarding page $page persists across launch', (
      tester,
    ) async {
      await launch(tester);
      for (var i = 1; i < page; i++) {
        await tester.tap(find.text('Next'));
        await tester.pumpAndSettle();
      }
      await tester.tap(find.text('Skip').hitTestable());
      await tester.pumpAndSettle();
      expectLanding();
      await launch(tester);
      expectLanding();
    });
  }

  testWidgets('leaving before finishing presents onboarding again', (
    tester,
  ) async {
    await launch(tester);
    expect(find.byType(OnboardingScreen), findsOneWidget);
    await tester.tap(find.text('Next'));
    await tester.pumpAndSettle();
    await launch(tester);
    expect(find.text('Discover something extraordinary.'), findsOneWidget);
  });

  testWidgets('existing signed-out installations open Landing directly', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({'auth_status': 'loggedOut'});
    await launch(tester);
    expectLanding();
  });
}
