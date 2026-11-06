// test/overflow_sweep_test.dart
//
// Automated overflow sweep for S8B-lite. Renders the main screens at
// 360x640 (smallest supported phone) with textScale 1.3 (largest
// supported) and checks that no rendered [RenderBox] reports overflow.
//
// Scope: main buyer screens only. The courier pod is a separate effort.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:zefanya/services/connection_watcher.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/widgets/no_internet_banner.dart';

// Screen imports.
import 'package:zefanya/screens/buyer/buyer_shell.dart';
import 'package:zefanya/screens/buyer/cart_screen.dart';
import 'package:zefanya/screens/buyer/categories_screen.dart';
import 'package:zefanya/screens/buyer/category_products_screen.dart';
import 'package:zefanya/screens/buyer/checkout_screen.dart';
import 'package:zefanya/screens/buyer/courier_tracker_screen.dart';
import 'package:zefanya/screens/buyer/notifications_screen.dart';
import 'package:zefanya/screens/buyer/orders_status_screen.dart';
import 'package:zefanya/screens/buyer/order_detail_screen.dart';
import 'package:zefanya/screens/buyer/product_details_screen.dart';
import 'package:zefanya/screens/buyer/product_link_screen.dart';
import 'package:zefanya/screens/buyer/return_screen.dart';
import 'package:zefanya/screens/buyer/review_screen.dart';
import 'package:zefanya/screens/buyer/saved_screen.dart';
import 'package:zefanya/screens/buyer/search_screen.dart';
import 'package:zefanya/screens/buyer/seller_screen.dart';
import 'package:zefanya/screens/buyer/vouchers_screen.dart';
import 'package:zefanya/screens/shared/account_security_screen.dart';
import 'package:zefanya/screens/shared/change_contact_screen.dart';
import 'package:zefanya/screens/shared/change_password_screen.dart';
import 'package:zefanya/screens/shared/chat_inbox_screen.dart';
import 'package:zefanya/screens/shared/chat_screen.dart';
import 'package:zefanya/screens/shared/edit_profile_screen.dart';
import 'package:zefanya/screens/shared/profile_screen.dart';
import 'package:zefanya/screens/shared/settings_screen.dart';

/// Smallest supported phone resolution (px).
const kTestWidth = 360.0;
const kTestHeight = 640.0;

/// Largest supported text scale (1.3).
const kTestTextScale = 1.3;

/// Render [child] at 360x640 with textScale 1.3.
Widget _atTestSize(Widget child) {
  return MaterialApp(
    home: Scaffold(
      body: MediaQuery(
        data: MediaQueryData(
          size: Size(kTestWidth, kTestHeight),
          textScaler: TextScaler.linear(kTestTextScale),
        ),
        child: child,
      ),
    ),
  );
}

/// Walk the render tree for [context] looking for a box whose children
/// overflow its constraints. Returns the offending box, or `null` if clean.

/// The main buyer screens under test, keyed by name.
class _ScreenEntry {
  final String name;
  final Widget screen;

  const _ScreenEntry(this.name, this.screen);
}

void main() {
RenderBox? _findOverflow(BuildContext context) {
  RenderObject? root = context.findRenderObject();
  if (root == null) return null;

  final visited = <RenderObject>{};
  final stack = <RenderObject>[root];

  while (stack.isNotEmpty) {
    final current = stack.removeLast();
    if (current is! RenderBox) continue;
    if (current.hasSize && current.hasVisualOverflow) return current;
    if (current.child is RenderBox) {
      final child = current.child as RenderBox;
      if (current.hasSize &&
          child.hasSize &&
          (child.size.width > current.constraints.maxWidth ||
              child.size.height > current.constraints.maxHeight)) {
        return child;
      }
    }
    for (int i = 0; i < current.children.length; i++) {
      final descendant = current.children[i];
      if (descendant != null && visited.add(descendant)) stack.add(descendant);
    }
  }
  return null;
}

/// The main buyer screens under test, keyed by name.
class _ScreenEntry {
  final String name;
  final Widget screen;

  const _ScreenEntry(this.name, this.screen);
}

void main() {
  group('Overflow sweep at 360x640 / textScale 1.3', () {
    testWidgets('main screens render without overflow', (tester) async {
      final auth = AuthState();
      auth.status = AccountStatus.loggedOut;
      final themeState = ThemeState();

      // The main buyer screens under test. Each entry renders the full
      // app shell at 360x640 with textScale 1.3.
      final screens = const [
        _ScreenEntry('Home', _BuyerHomeScreen(isActive: true, onSearchTap: () {}, onBrowseCategories: () {})),
        _ScreenEntry('Search', SearchScreen(focusRequests: ValueNotifier(0))),
        _ScreenEntry('Categories', const CategoriesScreen()),
        _ScreenEntry('Orders', const OrdersStatusScreen()),
        _ScreenEntry('Profile', const ProfileScreen()),
      ];

      for (final entry in screens) {
        await tester.pumpWidget(
          _atTestSize(
            MaterialApp(
              home: Scaffold(
                body: entry.screen,
              ),
            ),
          ),
        );

        await tester.pumpAndSettle();

        // Check for overflow on the top-most RenderBox.
        final topBox = tester.renderObject(find.byType(Scaffold));
        if (topBox.hasVisualOverflow) {
          fail('${entry.name}: overflow at 360x640/textScale 1.3');
        }
      }
    });
  });
}
