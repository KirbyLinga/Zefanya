// lib/screens/buyer/address_form_screen.dart
//
// Add or edit one saved delivery address. The cascade is the point of this
// screen: Region narrows City, City narrows Barangay, and changing a parent
// clears the stale children rather than leaving a Laguna barangay under NCR.
//
// The form validates each field where it sits. AuthState validates again at the
// write seam, because the sheet is not the only possible caller (and because the
// limit check belongs to the book, not the widget).

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/address_entry.dart';
import '../../data/ph_regions.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/validators.dart';
import '../../widgets/dirty_guard.dart';
import '../../widgets/editorial_header.dart';

class AddressFormScreen extends StatefulWidget {
  const AddressFormScreen({super.key, this.address});

  /// Null means "add"; non-null means "edit this row".
  final AddressEntry? address;

  @override
  State<AddressFormScreen> createState() => _AddressFormScreenState();
}

class _AddressFormScreenState extends State<AddressFormScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _recipientController;
  late final TextEditingController _phoneController;
  late final TextEditingController _streetController;

  String? _region;
  String? _city;
  String? _barangay;
  bool _makeDefault = false;
  bool _dirty = false;

  bool get _isEditing => widget.address != null;
  bool get _isDefaultAddress => widget.address?.isDefault ?? false;

  @override
  void initState() {
    super.initState();
    final address = widget.address;
    _recipientController = TextEditingController(text: address?.recipient ?? '');
    _phoneController = TextEditingController(text: address?.phone ?? '');
    _streetController = TextEditingController(text: address?.street ?? '');
    for (final controller in [
      _recipientController,
      _phoneController,
      _streetController,
    ]) {
      controller.addListener(_markDirty);
    }

    final savedRegion = address?.region;
    if (savedRegion != null && phRegionData.containsKey(savedRegion)) {
      _region = savedRegion;
      final savedCity = address?.city;
      if (savedCity != null && phRegionData[savedRegion]!.containsKey(savedCity)) {
        _city = savedCity;
        final savedBarangay = address?.barangay;
        if (savedBarangay != null &&
            phRegionData[savedRegion]![savedCity]!.contains(savedBarangay)) {
          _barangay = savedBarangay;
        }
      }
    }
    _makeDefault = address?.isDefault ?? false;
  }

  @override
  void dispose() {
    for (final controller in [
      _recipientController,
      _phoneController,
      _streetController,
    ]) {
      controller.removeListener(_markDirty);
      controller.dispose();
    }
    super.dispose();
  }

  void _markDirty() {
    if (!_dirty) setState(() => _dirty = true);
  }

  Map<String, List<String>> get _cities =>
      _region == null ? const {} : phRegionData[_region]!;

  List<String> get _barangays =>
      (_region == null || _city == null)
          ? const []
          : phRegionData[_region]![_city]!;

  void _save() {
    if (!_formKey.currentState!.validate()) return;
    final auth = context.read<AuthState>();
    final draft = AddressEntry(
      id: widget.address?.id ?? '',
      recipient: _recipientController.text.trim(),
      phone: _phoneController.text.trim(),
      region: _region ?? '',
      city: _city ?? '',
      barangay: _barangay ?? '',
      street: _streetController.text.trim(),
      // An existing default stays default; a new/non-default row can opt in.
      isDefault: _isDefaultAddress || _makeDefault,
    );
    final error = _isEditing
        ? auth.updateAddress(draft)
        : auth.addAddress(draft);
    if (error != null) {
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(SnackBar(content: Text(error)));
      return;
    }
    _dirty = false;
    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return DirtyGuard(
      hasUnsavedInput: () => _dirty,
      message: 'Your address changes will be lost.',
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(),
        body: Form(
          key: _formKey,
          autovalidateMode: AutovalidateMode.onUserInteraction,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
            children: [
              EditorialHeader(
                eyebrow: _isEditing ? 'SAVED ADDRESS' : 'NEW ADDRESS',
                title: _isEditing ? 'Edit Address' : 'Add Address',
                padding: const EdgeInsets.fromLTRB(0, 0, 0, 20),
              ),
              _labeledField(
                theme,
                'Recipient Name',
                TextFormField(
                  key: const ValueKey('address-recipient'),
                  controller: _recipientController,
                  keyboardType: TextInputType.name,
                  textCapitalization: TextCapitalization.words,
                  decoration: const InputDecoration(hintText: 'Marielle Santos'),
                  validator: (value) {
                    final name = (value ?? '').trim();
                    if (name.isEmpty) return 'Recipient name is required';
                    if (name.length < 2) {
                      return 'Recipient name must be at least 2 characters';
                    }
                    return null;
                  },
                ),
              ),
              const SizedBox(height: 16),
              _labeledField(
                theme,
                'Phone Number',
                TextFormField(
                  key: const ValueKey('address-phone'),
                  controller: _phoneController,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(hintText: '09XXXXXXXXX'),
                  validator: validatePhMobile,
                ),
              ),
              const SizedBox(height: 16),
              _labeledField(
                theme,
                'Region',
                DropdownButtonFormField<String>(
                  key: const ValueKey('address-region'),
                  isExpanded: true,
                  initialValue: _region,
                  decoration: const InputDecoration(hintText: 'Select region'),
                  items: phRegionData.keys
                      .map(
                        (region) => DropdownMenuItem(
                          value: region,
                          child: Text(region),
                        ),
                      )
                      .toList(),
                  onChanged: (value) {
                    setState(() {
                      _region = value;
                      _city = null;
                      _barangay = null;
                    });
                    _markDirty();
                  },
                  validator: (value) =>
                      value == null ? 'Select a region' : null,
                ),
              ),
              const SizedBox(height: 16),
              _labeledField(
                theme,
                'City',
                DropdownButtonFormField<String>(
                  key: ValueKey('address-city-${_region ?? 'none'}'),
                  isExpanded: true,
                  // Rebuilding the field when its parent changes clears the
                  // old selection rather than relying on the list to drop it.
                  initialValue: _city,
                  decoration: const InputDecoration(hintText: 'Select city'),
                  items: _cities.keys
                      .map(
                        (city) => DropdownMenuItem(value: city, child: Text(city)),
                      )
                      .toList(),
                  onChanged: _region == null
                      ? null
                      : (value) {
                          setState(() {
                            _city = value;
                            _barangay = null;
                          });
                          _markDirty();
                        },
                  validator: (value) => value == null ? 'Select a city' : null,
                ),
              ),
              const SizedBox(height: 16),
              _labeledField(
                theme,
                'Barangay',
                DropdownButtonFormField<String>(
                  key: ValueKey(
                    'address-barangay-${_region ?? 'none'}-${_city ?? 'none'}',
                  ),
                  isExpanded: true,
                  initialValue: _barangay,
                  decoration: const InputDecoration(hintText: 'Select barangay'),
                  items: _barangays
                      .map(
                        (barangay) => DropdownMenuItem(
                          value: barangay,
                          child: Text(barangay),
                        ),
                      )
                      .toList(),
                  onChanged: _city == null
                      ? null
                      : (value) {
                          setState(() => _barangay = value);
                          _markDirty();
                        },
                  validator: (value) => value == null ? 'Select a barangay' : null,
                ),
              ),
              const SizedBox(height: 16),
              _labeledField(
                theme,
                'Street / Detailed Address',
                TextFormField(
                  key: const ValueKey('address-street'),
                  controller: _streetController,
                  keyboardType: TextInputType.streetAddress,
                  maxLines: 2,
                  decoration: const InputDecoration(
                    hintText: 'House no., street, subdivision',
                  ),
                  validator: (value) =>
                      validateRequiredAddressField(value, label: 'Street address'),
                ),
              ),
              const SizedBox(height: 8),
              if (_isDefaultAddress)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: Text(
                    'This is the default address. Set another address as default '
                    'from the list before deleting it.',
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: AppColors.textSecondary,
                    ),
                  ),
                )
              else
                SwitchListTile(
                  key: const ValueKey('address-default'),
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Make this the default address'),
                  subtitle: const Text('Checkout will start with this address.'),
                  value: _makeDefault,
                  onChanged: (value) {
                    setState(() => _makeDefault = value);
                    _markDirty();
                  },
                ),
              const SizedBox(height: 24),
              ElevatedButton(
                key: const ValueKey('save-address'),
                onPressed: _save,
                child: Text(_isEditing ? 'Save Address' : 'Add Address'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _labeledField(ThemeData theme, String label, Widget field) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.labelLarge),
        const SizedBox(height: 8),
        field,
      ],
    );
  }
}
