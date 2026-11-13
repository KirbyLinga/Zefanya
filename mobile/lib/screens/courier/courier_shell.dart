// lib/screens/courier/courier_shell.dart
//
// Top-level navigation shell for the Courier app: For Pickup,
// For Delivery, Earnings, Profile — behind the same floating rounded
// nav bar used by the Buyer shell.
//
// No more "accept a request" flow / onAccept callback — jobs are
// assigned by the Logistics/Sorting Center dispatcher (see
// state/courier_state.dart), so both dashboard tabs just show
// whatever's currently assigned to this courier.

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/api/api_exception.dart';
import '../../state/courier_state.dart';
import '../../state/notification_state.dart';
import '../../widgets/floating_nav_bar.dart';
import '../../widgets/motion/fade_through.dart';
import '../../widgets/nav_icons.dart';
import '../shared/profile_screen.dart';
import 'courier_profit_screen.dart';
import 'delivery_dashboard_screen.dart';
import 'pickup_dashboard_screen.dart';

class CourierShell extends StatefulWidget {
  const CourierShell({super.key});

  @override
  State<CourierShell> createState() => _CourierShellState();
}

class _CourierShellState extends State<CourierShell> {
  int _index = 0;
  bool _refreshingJobs = false;

  static const _items = [
    NavBarItem(
      glyph: NavGlyph.parcel,
      label: 'For Pickup',
      shortLabel: 'Pickups',
    ),
    NavBarItem(
      glyph: NavGlyph.scooter,
      label: 'For Delivery',
      shortLabel: 'Deliveries',
    ),
    NavBarItem(glyph: NavGlyph.peso, label: 'Earnings'),
    NavBarItem(glyph: NavGlyph.profile, label: 'Profile'),
  ];

  @override
  void initState() {
    super.initState();
    // Live mode: this inbox is the rider's (`/api/rider/notifications`).
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      try {
        context.read<NotificationState>().useInbox(rider: true);
      } on ProviderNotFoundException {
        // A test that pumps the shell alone has no inbox to point.
      }
    });
  }

  static const _screens = [
    PickupDashboardScreen(),
    DeliveryDashboardScreen(),
    CourierProfitScreen(),
    ProfileScreen(title: 'Courier Profile'),
  ];

  Future<void> _refreshJobs() async {
    final courier = context.read<CourierState>();
    if (!courier.isServerAuthoritative || _refreshingJobs) return;
    _refreshingJobs = true;
    try {
      await courier.refreshJobs();
    } on ApiException {
      // The queue retains the error and the last confirmed jobs.
    } finally {
      _refreshingJobs = false;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      extendBody: true,
      body: FadeThroughIndexedStack(index: _index, children: _screens),
      bottomNavigationBar: FloatingNavBar(
        items: _items,
        currentIndex: _index,
        onTap: (i) {
          if (i != _index) setState(() => _index = i);
          if (i == 0 || i == 1) unawaited(_refreshJobs());
        },
      ),
      // Dev tooling lives behind Settings > About (seven taps). Nothing
      // developer-only floats over the app by default in any build.
    );
  }
}
