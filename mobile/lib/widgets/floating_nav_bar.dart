// lib/widgets/floating_nav_bar.dart
//
// Floating, rounded bottom navigation bar matching the "Ethereal Grace"
// board (see the pink/dark "Home / Search / Profile" pill row).
// Reused by both the Buyer shell and the Courier shell — just pass in
// a different `items` list.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../theme/app_theme.dart';
import 'nav_icons.dart';

class NavBarItem {
  const NavBarItem({
    this.icon,
    this.activeIcon,
    this.glyph,
    required this.label,
    this.badgeCount = 0,
  }) : assert(
          glyph != null || (icon != null && activeIcon != null),
          'Provide either glyph, or both icon and activeIcon.',
        );

  final NavGlyph? glyph;
  final IconData? icon;
  final IconData? activeIcon;
  final String label;

  /// When > 0 a small dot badge is shown on the icon. Pass 0 for none.
  final int badgeCount;
}

class FloatingNavBar extends StatelessWidget {
  const FloatingNavBar({
    super.key,
    required this.items,
    required this.currentIndex,
    required this.onTap,
  });

  final List<NavBarItem> items;
  final int currentIndex;
  final ValueChanged<int> onTap;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      minimum: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      child: Container(
        height: 68,
        padding: const EdgeInsets.symmetric(horizontal: 8),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(28),
          boxShadow: [
            BoxShadow(
              color: AppColors.neutral.withValues(alpha: 0.18),
              blurRadius: 20,
              offset: const Offset(0, 8),
            ),
          ],
        ),
        child: Row(
          children: List.generate(items.length, (index) {
            final selected = index == currentIndex;
            final item = items[index];
            return Expanded(
              child: _NavIcon(
                item: item,
                selected: selected,
                onTap: () {
                  HapticFeedback.selectionClick();
                  onTap(index);
                },
              ),
            );
          }),
        ),
      ),
    );
  }
}

class _NavIcon extends StatelessWidget {
  const _NavIcon({required this.item, required this.selected, required this.onTap});

  final NavBarItem item;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: selected,
      label: item.label,
      onTap: onTap,
      excludeSemantics: true,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        // TweenAnimationBuilder drives the icon/label color in sync with
        // the AnimatedContainer pill fill — they were previously instant
        // while the background eased, making the transition read as two
        // separate events rather than one.
        child: TweenAnimationBuilder<double>(
          tween: Tween(begin: 0, end: selected ? 1.0 : 0.0),
          duration: const Duration(milliseconds: 200),
          curve: Curves.easeOut,
          builder: (context, t, _) {
            final color = Color.lerp(AppColors.textSecondary, AppColors.primaryDark, t)!;
            final strokeWidth = 2.0 + 0.4 * t;
            return AnimatedContainer(
              duration: const Duration(milliseconds: 200),
              curve: Curves.easeOut,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: selected ? AppColors.secondary : Colors.transparent,
                borderRadius: BorderRadius.circular(20),
              ),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                mainAxisSize: MainAxisSize.min,
                children: [
                  item.glyph != null
                      ? Stack(
                          clipBehavior: Clip.none,
                          children: [
                            NavIcon(
                              glyph: item.glyph!,
                              size: 20,
                              color: color,
                              strokeWidth: strokeWidth,
                            ),
                            if (item.badgeCount > 0)
                              Positioned(
                                right: -4,
                                top: -4,
                                child: Container(
                                  width: 8,
                                  height: 8,
                                  decoration: const BoxDecoration(
                                    color: AppColors.primaryDark,
                                    shape: BoxShape.circle,
                                  ),
                                ),
                              ),
                          ],
                        )
                      : Stack(
                          clipBehavior: Clip.none,
                          children: [
                            Icon(
                              selected ? item.activeIcon : item.icon,
                              size: 20,
                              color: color,
                            ),
                            if (item.badgeCount > 0)
                              Positioned(
                                right: -4,
                                top: -4,
                                child: Container(
                                  width: 8,
                                  height: 8,
                                  decoration: const BoxDecoration(
                                    color: AppColors.primaryDark,
                                    shape: BoxShape.circle,
                                  ),
                                ),
                              ),
                          ],
                        ),
                  const SizedBox(height: 2),
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(
                      item.label,
                      style: Theme.of(context).textTheme.labelSmall?.copyWith(
                            color: color,
                            fontWeight: t > 0.5 ? FontWeight.w600 : FontWeight.w400,
                          ),
                    ),
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }
}
