// lib/widgets/searchable_picker_field.dart
//
// A form field that opens a searchable bottom sheet instead of a Material
// dropdown. The address cascade has thousands of barangays; a dropdown menu is
// the wrong control for that, and the workbook asks for searchable pickers.
//
// The field still participates in the enclosing Form: it wraps a FormField and
// exposes the same validator seam as the dropdowns it replaces.

import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

class PickerOption {
  const PickerOption({required this.value, required this.label, this.subtitle});

  final String value;
  final String label;
  final String? subtitle;
}

class SearchablePickerField extends StatelessWidget {
  const SearchablePickerField({
    super.key,
    required this.label,
    required this.hint,
    required this.value,
    required this.options,
    required this.onChanged,
    this.validator,
    this.enabled = true,
    this.fieldKey,
  });

  final String label;
  final String hint;
  final String? value;
  final List<PickerOption> options;
  final ValueChanged<String> onChanged;
  final FormFieldValidator<String>? validator;
  final bool enabled;
  final Key? fieldKey;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final hasSelection = (value ?? '').trim().isNotEmpty;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.labelLarge),
        const SizedBox(height: 8),
        FormField<String>(
          // Recreating the FormField when the cascade resets its parent is
          // what clears the stale selection and its error message.
          key: ValueKey('$label::${value ?? ''}'),
          initialValue: value,
          validator: validator,
          builder: (state) {
            return InkWell(
              key: fieldKey,
              onTap: enabled
                  ? () async {
                      final selected = await showSearchablePickerSheet(
                        context: context,
                        title: hint,
                        searchHint: 'Search ${label.toLowerCase()}',
                        options: options,
                        selected: state.value,
                      );
                      if (selected == null) return;
                      state.didChange(selected);
                      onChanged(selected);
                    }
                  : null,
              borderRadius: BorderRadius.circular(ext.cardRadius),
              child: InputDecorator(
                isEmpty: !hasSelection,
                decoration: InputDecoration(
                  hintText: hasSelection ? null : hint,
                  errorText: state.errorText,
                  enabled: enabled,
                  suffixIcon: Icon(
                    Icons.keyboard_arrow_down_rounded,
                    color: enabled
                        ? AppColors.textSecondary
                        : AppColors.textSecondary.withValues(alpha: 0.5),
                  ),
                ),
                child: Text(
                  hasSelection ? state.value! : '',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: enabled
                        ? AppColors.textPrimary
                        : AppColors.textSecondary,
                  ),
                ),
              ),
            );
          },
        ),
      ],
    );
  }
}

Future<String?> showSearchablePickerSheet({
  required BuildContext context,
  required String title,
  required String searchHint,
  required List<PickerOption> options,
  String? selected,
}) {
  return showModalBottomSheet<String>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (_) => _SearchablePickerSheet(
      title: title,
      searchHint: searchHint,
      options: options,
      selected: selected,
    ),
  );
}

class _SearchablePickerSheet extends StatefulWidget {
  const _SearchablePickerSheet({
    required this.title,
    required this.searchHint,
    required this.options,
    required this.selected,
  });

  final String title;
  final String searchHint;
  final List<PickerOption> options;
  final String? selected;

  @override
  State<_SearchablePickerSheet> createState() => _SearchablePickerSheetState();
}

class _SearchablePickerSheetState extends State<_SearchablePickerSheet> {
  final _searchController = TextEditingController();
  String _query = '';

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  List<PickerOption> get _filtered {
    final key = _searchKey(_query);
    if (key.isEmpty) return widget.options;
    return [
      for (final option in widget.options)
        if (_searchKey(option.label).contains(key) ||
            _searchKey(option.subtitle ?? '').contains(key))
          option,
    ];
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final filtered = _filtered;
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;
    final availableHeight = MediaQuery.of(context).size.height - bottomInset;
    final sheetHeight = (availableHeight * 0.8).clamp(260.0, 720.0);

    return Padding(
      padding: EdgeInsets.only(bottom: bottomInset),
      child: SizedBox(
        height: sheetHeight,
        // A Material rather than a decorated Container: ListTile paints its
        // ink on the nearest Material ancestor, and a coloured box in between
        // would hide every splash. This is also the sheet's rounded surface.
        child: Material(
          color: AppColors.surface,
          borderRadius: BorderRadius.vertical(
            top: Radius.circular(ext.cardRadius),
          ),
          clipBehavior: Clip.antiAlias,
          child: SafeArea(
            top: false,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const SizedBox(height: 10),
                Container(
                  width: 36,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AppColors.divider,
                    borderRadius: BorderRadius.circular(4),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          widget.title,
                          style: theme.textTheme.titleMedium,
                        ),
                      ),
                      IconButton(
                        tooltip: 'Close',
                        onPressed: () => Navigator.of(context).pop(),
                        icon: const Icon(Icons.close_rounded, size: 20),
                      ),
                    ],
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 10),
                  child: TextField(
                    key: const ValueKey('picker-search'),
                    controller: _searchController,
                    autofocus: false,
                    textInputAction: TextInputAction.search,
                    onChanged: (value) => setState(() => _query = value),
                    decoration: InputDecoration(
                      hintText: widget.searchHint,
                      prefixIcon: const Icon(Icons.search_rounded, size: 20),
                      suffixIcon: _query.isEmpty
                          ? null
                          : IconButton(
                              tooltip: 'Clear search',
                              onPressed: () {
                                _searchController.clear();
                                setState(() => _query = '');
                              },
                              icon: const Icon(Icons.close_rounded, size: 18),
                            ),
                    ),
                  ),
                ),
                const Divider(height: 1),
                if (filtered.isEmpty)
                  Expanded(
                    child: Center(
                      child: Padding(
                        padding: const EdgeInsets.all(32),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(
                              Icons.search_off_rounded,
                              size: 36,
                              color: AppColors.textSecondary,
                            ),
                            const SizedBox(height: 10),
                            Text(
                              'No matches for "${_query.trim()}"',
                              textAlign: TextAlign.center,
                              style: theme.textTheme.bodyMedium?.copyWith(
                                color: AppColors.textSecondary,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  )
                else
                  Expanded(
                    child: ListView.builder(
                      shrinkWrap: true,
                      padding: const EdgeInsets.only(bottom: 12),
                      itemCount: filtered.length,
                      itemBuilder: (context, index) {
                        final option = filtered[index];
                        final isSelected = option.value == widget.selected;
                        return ListTile(
                          key: ValueKey('picker-option-${option.value}'),
                          title: Text(
                            option.label,
                            style: theme.textTheme.bodyMedium?.copyWith(
                              fontWeight: isSelected
                                  ? FontWeight.w600
                                  : FontWeight.w400,
                            ),
                          ),
                          subtitle: option.subtitle == null
                              ? null
                              : Text(
                                  option.subtitle!,
                                  style: theme.textTheme.bodySmall?.copyWith(
                                    color: AppColors.textSecondary,
                                  ),
                                ),
                          trailing: isSelected
                              ? Icon(
                                  Icons.check_rounded,
                                  color: AppColors.primaryDark,
                                  size: 20,
                                )
                              : null,
                          onTap: () => Navigator.of(context).pop(option.value),
                        );
                      },
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

String _searchKey(String value) {
  var key = value.toLowerCase().trim();
  const replacements = {
    'ñ': 'n',
    'á': 'a',
    'à': 'a',
    'â': 'a',
    'é': 'e',
    'è': 'e',
    'ê': 'e',
    'í': 'i',
    'ì': 'i',
    'î': 'i',
    'ó': 'o',
    'ò': 'o',
    'ô': 'o',
    'ú': 'u',
    'ù': 'u',
    'û': 'u',
  };
  for (final entry in replacements.entries) {
    key = key.replaceAll(entry.key, entry.value);
  }
  return key;
}
