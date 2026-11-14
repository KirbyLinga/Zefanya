// lib/screens/courier/widgets/wind_down_banner.dart
//
// The notice a rider sees while their logistics company is suspended inside
// an Admin-granted wind-down. The wording is the server's
// (EnsureRiderActive::WIND_DOWN_MESSAGE, sent as `winding_down_message`);
// the server also refuses every action the wind-down closes, so this banner
// explains what is hidden rather than enforcing anything itself.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../../state/courier_state.dart';
import '../../../theme/app_theme.dart';

class RiderWindDownBanner extends StatelessWidget {
  const RiderWindDownBanner({super.key, this.margin = EdgeInsets.zero});

  final EdgeInsetsGeometry margin;

  @override
  Widget build(BuildContext context) {
    final courier = context.watch<CourierState>();
    if (!courier.isWindingDown) return const SizedBox.shrink();
    final theme = Theme.of(context);

    return Container(
      key: const ValueKey('rider-wind-down-banner'),
      margin: margin,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.warning.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.warning.withValues(alpha: 0.4)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.pause_circle_outline, color: AppColors.warning),
          const SizedBox(width: 10),
          Expanded(
            child: Text(courier.windingDownMessage, style: theme.textTheme.bodyMedium),
          ),
        ],
      ),
    );
  }
}
