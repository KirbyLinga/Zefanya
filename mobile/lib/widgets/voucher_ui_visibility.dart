// lib/widgets/voucher_ui_visibility.dart
//
// Step 6: Live-mode honesty.
//
// A thin wrapper that hides or shows voucher-related UI depending on the
// backend mode. The cart and checkout screens set [show] to !kUseLiveApi;
// tests can pump it with either value to cover both code paths without
// needing a --dart-define compile flag.

import 'package:flutter/widgets.dart';

/// Conditionally renders [child] only when [show] is true.
///
/// The production screens pass `show: !kUseLiveApi` so voucher entry,
/// voucher strips, and locally-computed savings are hidden in live builds.
/// Widget tests pass `show: true` or `show: false` directly.
class VoucherUiVisibility extends StatelessWidget {
  const VoucherUiVisibility({
    super.key,
    required this.show,
    required this.child,
  });

  /// Whether voucher UI is shown.
  ///
  /// `true`  → mock mode: client-side vouchers are active, show everything.
  /// `false` → live mode: server owns totals, hide voucher entry and savings.
  final bool show;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    if (!show) return const SizedBox.shrink();
    return child;
  }
}
