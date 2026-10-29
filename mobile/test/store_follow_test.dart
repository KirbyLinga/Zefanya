// test/store_follow_test.dart
//
// Following a shop is a buyer action with its own state: it is not the same as
// hearting a product, and unfollowing must remove it from the following list,
// not just change a button label.
//
// The state tests pin persistence; the widget tests pin the two places the
// result is visible (seller hero and Saved screen) so a future refactor cannot
// leave the count and the list disagreeing.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/screens/buyer/saved_screen.dart';
import 'package:zefanya/screens/buyer/seller_screen.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

const _seller = 'Willow & Bloom Atelier';

Future<void> _pump(
  WidgetTester tester,
  Widget child, {
  WishlistState? wishlist,
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final catalog = CatalogState();
  await catalog.load();

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: catalog),
        ChangeNotifierProvider(create: (_) => wishlist ?? WishlistState()),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        home: MediaQuery(
          data: const MediaQueryData(
            size: Size(390, 844),
            disableAnimations: true,
          ),
          child: child,
        ),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 100));
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('follow state', () {
    test('BUY-WISH-004 follow/unfollow moves the shop in and out of the list',
        () async {
      final state = WishlistState();
      expect(state.isFollowing(_seller), isFalse);

      expect(state.toggleFollow(_seller), isTrue);
      expect(state.isFollowing(_seller), isTrue);
      expect(state.followedSellers, [_seller]);

      // Following twice is a toggle, not a duplicate.
      expect(state.toggleFollow(_seller), isFalse);
      expect(state.isFollowing(_seller), isFalse);
      expect(state.followedSellers, isEmpty);
    });

    test('followed shops survive a restart', () async {
      final state = WishlistState();
      state.toggleFollow(_seller);
      await pumpEventQueue();

      final restarted = WishlistState();
      await restarted.load();

      expect(restarted.isFollowing(_seller), isTrue);
      expect(restarted.followedSellers, [_seller]);
    });

    test('following does not affect the product wishlist', () async {
      final state = WishlistState();
      state.toggle(_seller);
      state.toggleFollow(_seller);

      expect(state.followedSellers, [_seller]);
      expect(state.items, isEmpty);
    });
  });

  group('where following shows', () {
    testWidgets('BUY-WISH-004 seller hero toggles Follow to Following',
        (tester) async {
      final wishlist = WishlistState();
      await _pump(
        tester,
        const SellerScreen(sellerName: _seller),
        wishlist: wishlist,
      );

      expect(find.text('Follow'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('follow-seller')));
      await tester.pump();

      expect(find.text('Following'), findsOneWidget);
      expect(wishlist.isFollowing(_seller), isTrue);
      expect(tester.takeException(), isNull);
    });

    testWidgets('BUY-WISH-004 the count goes N, N+1, N with the list',
        (tester) async {
      const other = 'Sage & Clay Studio';
      final wishlist = WishlistState()..toggleFollow(other);
      await _pump(tester, const SavedScreen(), wishlist: wishlist);

      expect(find.text('STORES YOU FOLLOW · 1'), findsOneWidget);
      expect(find.text(other), findsOneWidget);

      wishlist.toggleFollow(_seller);
      await tester.pump();
      expect(find.text('STORES YOU FOLLOW · 2'), findsOneWidget);
      expect(find.text(_seller), findsOneWidget);

      wishlist.toggleFollow(_seller);
      await tester.pump();
      expect(find.text('STORES YOU FOLLOW · 1'), findsOneWidget);
      expect(find.text(_seller), findsNothing);
      expect(find.text(other), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Saved lists the followed shop with its count',
        (tester) async {
      final wishlist = WishlistState()..toggleFollow(_seller);
      await _pump(tester, const SavedScreen(), wishlist: wishlist);

      expect(find.text('STORES YOU FOLLOW · 1'), findsOneWidget);
      expect(find.text(_seller), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}
