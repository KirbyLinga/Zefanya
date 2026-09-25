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

import 'package:flutter/material.dart';
import '../../widgets/floating_nav_bar.dart';
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

  static const _items = [
    NavBarItem(icon: Icons.inbox_outlined, activeIcon: Icons.inbox, label: 'For Pickup'),
    NavBarItem(icon: Icons.local_shipping_outlined, activeIcon: Icons.local_shipping, label: 'For Delivery'),
    NavBarItem(icon: Icons.payments_outlined, activeIcon: Icons.payments, label: 'Earnings'),
    NavBarItem(icon: Icons.person_outline, activeIcon: Icons.person, label: 'Profile'),
  ];

  static const _screens = [
    PickupDashboardScreen(),
    DeliveryDashboardScreen(),
    CourierProfitScreen(),
    ProfileScreen(title: 'Courier Profile'),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      extendBody: true,
      body: IndexedStack(index: _index, children: _screens),
      bottomNavigationBar: FloatingNavBar(
        items: _items,
        currentIndex: _index,
        onTap: (i) => setState(() => _index = i),
      ),
    );
  }
}
