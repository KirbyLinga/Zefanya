// lib/screens/buyer/address_form_screen.dart
//
// Add or edit one saved delivery address.
//
// The cascade is the point of this screen: Region narrows Province (except in
// NCR, which has none), Province narrows City/Municipality, and City narrows
// Barangay. Changing a parent clears the stale children rather than leaving a
// Laguna barangay under NCR. The hierarchy comes from the bundled offline PSGC
// asset (data/ph_address_catalog.dart), not a network call.
//
// The postal code is filled from the selected city and stays editable. The
// optional "Use my location" pin stores coordinates only; it never guesses a
// street address, because the app has no reverse geocoder.
//
// The form validates each field where it sits. AuthState validates again at the
// write seam, because the screen is not the only possible caller (and because
// the limit check belongs to the book, not the widget).

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../data/address_entry.dart';
import '../../data/ph_address_catalog.dart';
import '../../services/location_service.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/validators.dart';
import '../../widgets/dirty_guard.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/ph_address_fields.dart';

class AddressFormScreen extends StatefulWidget {
  const AddressFormScreen({
    super.key,
    this.address,
    this.locationService = const DeviceLocationService(),
  });

  /// Null means "add"; non-null means "edit this row".
  final AddressEntry? address;

  /// Injected in widget tests so the screen never reaches a platform channel.
  final LocationService locationService;

  @override
  State<AddressFormScreen> createState() => _AddressFormScreenState();
}

class _AddressFormScreenState extends State<AddressFormScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _recipientController;
  late final TextEditingController _phoneController;
  late final TextEditingController _streetController;

  PhAddressCatalog? _catalog;
  Object? _catalogError;
  bool _loadingCatalog = true;

  PhAddressSelection _selection = const PhAddressSelection();
  String _label = 'Home';
  bool _makeDefault = false;
  bool _dirty = false;

  GeoPoint? _pin;
  bool _locating = false;
  String? _locationError;

  bool get _isEditing => widget.address != null;
  bool get _isDefaultAddress => widget.address?.isDefault ?? false;

  @override
  void initState() {
    super.initState();
    final address = widget.address;
    _recipientController = TextEditingController(
      text: address?.recipient ?? '',
    );
    _phoneController = TextEditingController(text: address?.phone ?? '');
    _streetController = TextEditingController(text: address?.street ?? '');
    for (final controller in [
      _recipientController,
      _phoneController,
      _streetController,
    ]) {
      controller.addListener(_markDirty);
    }

    _label = AddressEntry.labels.contains(address?.label)
        ? address!.label
        : 'Home';
    _makeDefault = address?.isDefault ?? false;
    if (address != null && address.hasLocation) {
      _pin = GeoPoint(
        latitude: address.latitude!,
        longitude: address.longitude!,
      );
    }

    _loadCatalog();
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

  Future<void> _loadCatalog() async {
    final cached = PhAddressCatalog.maybeLoaded;
    if (cached != null) {
      _applyCatalog(cached);
      return;
    }
    try {
      final catalog = await PhAddressCatalog.load();
      if (!mounted) return;
      _applyCatalog(catalog);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _catalogError = error;
        _loadingCatalog = false;
      });
    }
  }

  void _applyCatalog(PhAddressCatalog catalog) {
    final address = widget.address;
    setState(() {
      _catalog = catalog;
      _loadingCatalog = false;
      _catalogError = null;
      _selection = catalog.resolveSelection(
        region: address?.region,
        province: address?.province,
        city: address?.city,
        barangay: address?.barangay,
        postalCode: address?.postalCode,
        regionCode: address?.regionCode,
        provinceCode: address?.provinceCode,
        cityCode: address?.cityCode,
        barangayCode: address?.barangayCode,
      );
    });
  }

  void _retryCatalog() {
    setState(() {
      _loadingCatalog = true;
      _catalogError = null;
    });
    _loadCatalog();
  }

  void _markDirty() {
    if (!_dirty) setState(() => _dirty = true);
  }

  Future<void> _useLocation() async {
    setState(() {
      _locating = true;
      _locationError = null;
    });
    try {
      final point = await widget.locationService.currentPosition();
      if (!mounted) return;
      setState(() {
        _pin = point;
        _locating = false;
      });
      _markDirty();
    } on LocationException catch (error) {
      if (!mounted) return;
      setState(() {
        _locating = false;
        _locationError = error.message;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _locating = false;
        _locationError =
            'Could not read your location. You can still save the address '
            'without a pin.';
      });
    }
  }

  void _clearPin() {
    setState(() {
      _pin = null;
      _locationError = null;
    });
    _markDirty();
  }

  void _save() {
    if (!_formKey.currentState!.validate()) return;
    final auth = context.read<AuthState>();
    final draft = AddressEntry(
      id: widget.address?.id ?? '',
      recipient: _recipientController.text.trim(),
      phone: _phoneController.text.trim(),
      region: _selection.region ?? '',
      province: _selection.province ?? '',
      city: _selection.city ?? '',
      barangay: _selection.barangay ?? '',
      street: _streetController.text.trim(),
      postalCode: _selection.postalCode,
      label: _label,
      regionCode: _selection.regionCode,
      provinceCode: _selection.provinceCode,
      cityCode: _selection.cityCode,
      barangayCode: _selection.barangayCode,
      latitude: _pin?.latitude,
      longitude: _pin?.longitude,
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
        body: SafeArea(
          child: _loadingCatalog
              ? const Center(child: CircularProgressIndicator())
              : _catalogError != null
              ? _CatalogError(onRetry: _retryCatalog)
              : Form(
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
                          decoration: const InputDecoration(
                            hintText: 'Marielle Santos',
                          ),
                          validator: (value) {
                            final name = (value ?? '').trim();
                            if (name.isEmpty) {
                              return 'Recipient name is required';
                            }
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
                          decoration: const InputDecoration(
                            hintText: '09XXXXXXXXX',
                          ),
                          validator: validatePhMobile,
                        ),
                      ),
                      const SizedBox(height: 20),
                      Text('Address', style: theme.textTheme.titleSmall),
                      const SizedBox(height: 12),
                      PhAddressFields(
                        catalog: _catalog!,
                        selection: _selection,
                        onChanged: (selection) {
                          setState(() => _selection = selection);
                          _markDirty();
                        },
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
                          validator: (value) => validateRequiredAddressField(
                            value,
                            label: 'Street address',
                          ),
                        ),
                      ),
                      const SizedBox(height: 20),
                      Text('Save as', style: theme.textTheme.titleSmall),
                      const SizedBox(height: 8),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          for (final label in AddressEntry.labels)
                            ChoiceChip(
                              key: ValueKey('address-label-$label'),
                              label: Text(label),
                              selected: _label == label,
                              onSelected: (selected) {
                                if (!selected) return;
                                setState(() => _label = label);
                                _markDirty();
                              },
                            ),
                        ],
                      ),
                      const SizedBox(height: 20),
                      _LocationSection(
                        pin: _pin,
                        locating: _locating,
                        error: _locationError,
                        onUseLocation: _useLocation,
                        onClear: _clearPin,
                      ),
                      const SizedBox(height: 12),
                      if (_isDefaultAddress)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 8),
                          child: Text(
                            'This is the default address. Set another address as '
                            'default from the list before deleting it.',
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
                          subtitle: const Text(
                            'Checkout will start with this address.',
                          ),
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
                        child: Text(
                          _isEditing ? 'Save Address' : 'Add Address',
                        ),
                      ),
                    ],
                  ),
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

class _CatalogError extends StatelessWidget {
  const _CatalogError({required this.onRetry});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            color: AppColors.surface,
            borderRadius: BorderRadius.circular(ext.cardRadius),
            border: Border.all(color: AppColors.divider),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.location_off_outlined,
                size: 32,
                color: AppColors.textSecondary,
              ),
              const SizedBox(height: 12),
              Text(
                'Address data could not be loaded',
                textAlign: TextAlign.center,
                style: theme.textTheme.titleSmall,
              ),
              const SizedBox(height: 6),
              Text(
                'The Philippine address list ships with the app. Try again; if '
                'it keeps failing, restart Zefanya.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
              ),
              const SizedBox(height: 14),
              OutlinedButton(
                key: const ValueKey('retry-address-catalog'),
                onPressed: onRetry,
                child: const Text('Try again'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _LocationSection extends StatelessWidget {
  const _LocationSection({
    required this.pin,
    required this.locating,
    required this.error,
    required this.onUseLocation,
    required this.onClear,
  });

  final GeoPoint? pin;
  final bool locating;
  final String? error;
  final VoidCallback onUseLocation;
  final VoidCallback onClear;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final pinned = pin != null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Location pin', style: theme.textTheme.titleSmall),
        const SizedBox(height: 4),
        Text(
          'Optional. Helps the courier find the exact spot; your address is '
          'still chosen from the list above.',
          style: theme.textTheme.bodySmall?.copyWith(
            color: AppColors.textSecondary,
          ),
        ),
        const SizedBox(height: 10),
        if (pinned)
          Container(
            key: const ValueKey('address-location-pin'),
            padding: const EdgeInsets.fromLTRB(14, 12, 10, 12),
            decoration: BoxDecoration(
              color: AppColors.surfaceMuted,
              borderRadius: BorderRadius.circular(ext.cardRadius),
              border: Border.all(color: AppColors.divider),
            ),
            child: Row(
              children: [
                Icon(
                  Icons.location_on_rounded,
                  size: 22,
                  color: AppColors.primaryDark,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Pinned location',
                        style: theme.textTheme.labelLarge,
                      ),
                      const SizedBox(height: 2),
                      Text(
                        pin!.display,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: AppColors.textSecondary,
                        ),
                      ),
                    ],
                  ),
                ),
                TextButton(
                  key: const ValueKey('address-location-update'),
                  onPressed: locating ? null : onUseLocation,
                  child: const Text('Update'),
                ),
                IconButton(
                  key: const ValueKey('address-location-clear'),
                  tooltip: 'Remove pin',
                  onPressed: onClear,
                  icon: const Icon(Icons.close_rounded, size: 20),
                ),
              ],
            ),
          )
        else
          OutlinedButton.icon(
            key: const ValueKey('address-use-location'),
            onPressed: locating ? null : onUseLocation,
            icon: locating
                ? const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.my_location_rounded, size: 18),
            label: Text(locating ? 'Finding you…' : 'Use my location'),
          ),
        if (error != null) ...[
          const SizedBox(height: 8),
          Text(
            error!,
            key: const ValueKey('address-location-error'),
            style: theme.textTheme.bodySmall?.copyWith(color: AppColors.danger),
          ),
        ],
      ],
    );
  }
}
