// lib/widgets/ph_address_fields.dart
//
// The shared Region -> Province -> City/Municipality -> Barangay cascade used by
// the address book, registration and Edit Profile.
//
// NCR is province-less: when the region is NCR the province field is hidden and
// the city picker reads the region's direct cities. Selecting a city fills its
// PSGC postal-code default; the postal field stays editable because a barangay
// can have its own code.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../data/ph_address_catalog.dart';
import '../theme/app_theme.dart';
import '../utils/validators.dart';
import 'searchable_picker_field.dart';

class PhAddressFields extends StatelessWidget {
  const PhAddressFields({
    super.key,
    required this.catalog,
    required this.selection,
    required this.onChanged,
    this.enabled = true,
    this.showPostalCode = true,
  });

  final PhAddressCatalog catalog;
  final PhAddressSelection selection;
  final ValueChanged<PhAddressSelection> onChanged;
  final bool enabled;
  final bool showPostalCode;

  @override
  Widget build(BuildContext context) {
    final region = catalog.regionByName(selection.region);
    final province = region == null
        ? null
        : catalog.provinceByName(selection.province, region: region);

    final cityOptions = region == null
        ? const <PhCity>[]
        : region.isNcr
        ? region.cities
        : province?.cities ?? const <PhCity>[];

    PhCity? city;
    if (cityOptions.isNotEmpty) {
      final selectedCity = (selection.city ?? '').trim();
      if (selectedCity.isNotEmpty) {
        for (final option in cityOptions) {
          if (option.name == selectedCity) {
            city = option;
            break;
          }
        }
      }
    }

    final barangayOptions = city?.barangays ?? const <PhBarangay>[];
    final canPickCity =
        enabled && region != null && (region.isNcr || province != null);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SearchablePickerField(
          fieldKey: const ValueKey('address-region'),
          label: 'Region',
          hint: 'Select region',
          value: selection.region,
          enabled: enabled,
          options: [
            for (final option in catalog.regions)
              PickerOption(
                value: option.name,
                label: option.name,
                subtitle: option.shortName,
              ),
          ],
          onChanged: (name) {
            final selected = catalog.regionByName(name);
            if (selected == null) return;
            onChanged(selection.withRegion(selected));
          },
          validator: (value) =>
              (value ?? '').trim().isEmpty ? 'Select a region' : null,
        ),
        if (region != null && !region.isNcr) ...[
          const SizedBox(height: 16),
          SearchablePickerField(
            fieldKey: const ValueKey('address-province'),
            label: 'Province',
            hint: 'Select province',
            value: selection.province,
            enabled: enabled,
            options: [
              for (final option in region.provinces)
                PickerOption(value: option.name, label: option.name),
            ],
            onChanged: (name) {
              final selected = catalog.provinceByName(name, region: region);
              if (selected == null) return;
              onChanged(selection.withProvince(selected));
            },
            validator: (value) =>
                (value ?? '').trim().isEmpty ? 'Select a province' : null,
          ),
        ],
        const SizedBox(height: 16),
        SearchablePickerField(
          fieldKey: const ValueKey('address-city'),
          label: 'City / Municipality',
          hint: 'Select city or municipality',
          value: selection.city,
          enabled: canPickCity,
          options: [
            for (final option in cityOptions)
              PickerOption(
                value: option.name,
                label: option.name,
                subtitle: option.provinceLabel,
              ),
          ],
          onChanged: (name) {
            for (final option in cityOptions) {
              if (option.name == name) {
                onChanged(selection.withCity(option));
                return;
              }
            }
          },
          validator: (value) => (value ?? '').trim().isEmpty
              ? 'Select a city or municipality'
              : null,
        ),
        const SizedBox(height: 16),
        SearchablePickerField(
          fieldKey: const ValueKey('address-barangay'),
          label: 'Barangay',
          hint: 'Select barangay',
          value: selection.barangay,
          enabled: enabled && city != null,
          options: [
            for (final option in barangayOptions)
              PickerOption(value: option.name, label: option.name),
          ],
          onChanged: (name) {
            for (final option in barangayOptions) {
              if (option.name == name) {
                onChanged(selection.withBarangay(option));
                return;
              }
            }
          },
          validator: (value) =>
              (value ?? '').trim().isEmpty ? 'Select a barangay' : null,
        ),
        if (showPostalCode) ...[
          const SizedBox(height: 16),
          _PostalCodeField(
            value: selection.postalCode,
            enabled: enabled,
            required: city != null,
            onChanged: (value) => onChanged(selection.withPostalCode(value)),
          ),
        ],
      ],
    );
  }
}

class _PostalCodeField extends StatefulWidget {
  const _PostalCodeField({
    required this.value,
    required this.onChanged,
    required this.enabled,
    required this.required,
  });

  final String value;
  final ValueChanged<String> onChanged;
  final bool enabled;
  final bool required;

  @override
  State<_PostalCodeField> createState() => _PostalCodeFieldState();
}

class _PostalCodeFieldState extends State<_PostalCodeField> {
  late final TextEditingController _controller;

  /// Invalidates a deferred sync when the buyer types or the city changes
  /// again before the post-frame callback runs.
  int _syncGeneration = 0;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: widget.value);
  }

  @override
  void didUpdateWidget(covariant _PostalCodeField oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.value == oldWidget.value || widget.value == _controller.text) {
      return;
    }
    // didUpdateWidget runs during the parent's build. Writing the controller
    // there notifies EditableText, which calls setState mid-build. Defer the
    // sync by one frame; the value still lands before the next paint the user
    // can interact with.
    final generation = ++_syncGeneration;
    final next = widget.value;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || generation != _syncGeneration) return;
      if (_controller.text == next) return;
      _controller.value = TextEditingValue(
        text: next,
        selection: TextSelection.collapsed(offset: next.length),
      );
    });
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Postal Code', style: theme.textTheme.labelLarge),
        const SizedBox(height: 8),
        TextFormField(
          key: const ValueKey('address-postal'),
          controller: _controller,
          enabled: widget.enabled,
          keyboardType: TextInputType.number,
          textInputAction: TextInputAction.done,
          inputFormatters: [
            FilteringTextInputFormatter.digitsOnly,
            LengthLimitingTextInputFormatter(4),
          ],
          decoration: InputDecoration(
            hintText: '4-digit postal code',
            helperText: 'Auto-filled from the city; edit if yours differs.',
            helperStyle: theme.textTheme.labelSmall?.copyWith(
              color: AppColors.textSecondary,
            ),
          ),
          validator: (value) {
            if (!widget.required && (value ?? '').trim().isEmpty) return null;
            return validatePostalCode(value);
          },
          onChanged: (value) {
            // A buyer's edit outranks any city-default sync still waiting for
            // its post-frame callback.
            _syncGeneration++;
            widget.onChanged(value);
          },
        ),
      ],
    );
  }
}
