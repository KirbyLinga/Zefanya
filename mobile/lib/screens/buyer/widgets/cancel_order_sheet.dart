import 'package:flutter/material.dart';

import '../../../state/order_state.dart';
import '../../../theme/app_theme.dart';
import '../../../widgets/editorial_shapes.dart';
import '../../../widgets/form_sheet_body.dart';

Future<String?> showCancelOrderSheet(BuildContext context, String orderId) {
  String? selected;
  return showModalBottomSheet<String>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    backgroundColor: AppColors.surface,
    shape: editorialShape(0, ShapeTier.card),
    builder: (sheetContext) => StatefulBuilder(
      builder: (context, setSheetState) {
        final theme = Theme.of(context);
        return FormSheetBody(
          child: RadioGroup<String>(
            groupValue: selected,
            onChanged: (value) => setSheetState(() => selected = value),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 20, 20, 4),
                  child: Text(
                    'Cancel $orderId?',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                  child: Text(
                    'The seller is notified and the item returns to stock. '
                    'This cannot be undone.',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: AppColors.textSecondary,
                    ),
                  ),
                ),
                for (final reason in OrderState.cancellationReasons)
                  RadioListTile<String>(value: reason, title: Text(reason)),
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 8, 20, 16),
                  child: ElevatedButton(
                    onPressed: selected == null
                        ? null
                        : () => Navigator.of(sheetContext).pop(selected),
                    child: const Text('Cancel order'),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    ),
  );
}
