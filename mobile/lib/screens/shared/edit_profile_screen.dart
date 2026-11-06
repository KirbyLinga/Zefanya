// lib/screens/shared/edit_profile_screen.dart
//
// Path check: lives at lib/screens/shared/edit_profile_screen.dart
//   -> theme      => '../../theme/app_theme.dart'
//   -> auth state => '../../state/auth_state.dart'
//
// Pre-fills from the current AuthState and writes back via
// updateProfile() on save. Split name fields + Sex match
// registration_screen.dart's fields so the two forms stay consistent.
// The address section is the same offline PSGC cascade the address book and
// registration use.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/ph_address_catalog.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/validators.dart';
import '../../widgets/avatar_picker.dart';
import '../../widgets/dirty_guard.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/ph_address_fields.dart';
import '../../widgets/zefanya_mark.dart';

final RegExp _phPhoneRegex = RegExp(r'^(09\d{9}|\+639\d{9})$');

class EditProfileScreen extends StatefulWidget {
  const EditProfileScreen({super.key});

  @override
  State<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends State<EditProfileScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _lastNameController;
  late final TextEditingController _firstNameController;
  late final TextEditingController _middleInitialController;
  late final TextEditingController _phoneController;
  late final TextEditingController _streetController;

  String? _sex;
  PhAddressCatalog? _catalog;
  bool _catalogLoading = true;
  PhAddressSelection _address = const PhAddressSelection();
  bool _isSaving = false;

  /// True once the user changes anything — the back-guard only
  /// interrupts when there is actually something to lose.
  bool _dirty = false;

  void _markDirty() {
    if (!_dirty) setState(() => _dirty = true);
  }

  @override
  void initState() {
    super.initState();
    final auth = context.read<AuthState>();
    _lastNameController = TextEditingController(text: auth.lastName ?? '');
    _firstNameController = TextEditingController(text: auth.firstName ?? '');
    _middleInitialController = TextEditingController(
      text: auth.middleInitial ?? '',
    );
    _phoneController = TextEditingController(text: auth.phone ?? '');
    _streetController = TextEditingController(text: auth.street ?? '');
    for (final c in [
      _lastNameController,
      _firstNameController,
      _middleInitialController,
      _phoneController,
      _streetController,
    ]) {
      c.addListener(_markDirty);
    }
    _sex = auth.sex;
    _loadCatalog();
  }

  Future<void> _loadCatalog() async {
    final auth = context.read<AuthState>();
    final cached = PhAddressCatalog.maybeLoaded;
    if (cached != null) {
      _applyCatalog(cached, auth);
      return;
    }
    try {
      final catalog = await PhAddressCatalog.load();
      if (!mounted) return;
      _applyCatalog(catalog, auth);
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _catalogLoading = false;
      });
    }
  }

  void _applyCatalog(PhAddressCatalog catalog, AuthState auth) {
    setState(() {
      _catalog = catalog;
      _catalogLoading = false;
      _address = catalog.resolveSelection(
        region: auth.region,
        province: auth.province,
        city: auth.municipality,
        barangay: auth.barangay,
        postalCode: auth.postalCode,
      );
    });
  }

  void _retryCatalog() {
    setState(() {
      _catalogLoading = true;
    });
    _loadCatalog();
  }

  @override
  void dispose() {
    for (final c in [
      _lastNameController,
      _firstNameController,
      _middleInitialController,
      _phoneController,
      _streetController,
    ]) {
      c.removeListener(_markDirty);
    }
    _lastNameController.dispose();
    _firstNameController.dispose();
    _middleInitialController.dispose();
    _phoneController.dispose();
    _streetController.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    if (_catalog == null) {
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(
          const SnackBar(
            content: Text(
              'Address data is still loading. Try again in a moment.',
            ),
          ),
        );
      return;
    }

    setState(() => _isSaving = true);
    // TODO: replace with a real "update profile" API call.
    await Future.delayed(const Duration(milliseconds: 500));
    if (!mounted) return;

    context.read<AuthState>().updateProfile(
      lastName: _lastNameController.text.trim(),
      firstName: _firstNameController.text.trim(),
      middleInitial: _middleInitialController.text.trim(),
      sex: _sex,
      phone: _phoneController.text.trim(),
      region: _address.region,
      province: _address.province,
      municipality: _address.city,
      barangay: _address.barangay,
      street: _streetController.text.trim(),
      postalCode: _address.postalCode,
    );

    setState(() => _isSaving = false);
    if (!mounted) return;
    _dirty = false;
    ScaffoldMessenger.of(context)
      ..clearSnackBars()
      ..showSnackBar(const SnackBar(content: Text('Profile updated')));
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return DirtyGuard(
      hasUnsavedInput: () => _dirty,
      message: 'Your profile changes will be lost.',
      child: Scaffold(
        backgroundColor: Colors
            .transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
        appBar: AppBar(),
        body: Form(
          key: _formKey,
          autovalidateMode: AutovalidateMode.onUserInteraction,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
            children: [
              const EditorialHeader(
                eyebrow: 'YOUR DETAILS',
                title: 'Edit Profile',
                padding: EdgeInsets.fromLTRB(0, 0, 0, 20),
              ),
              Center(
                child: Column(
                  children: [
                    AvatarPicker(
                      key: const ValueKey('edit-profile-avatar'),
                      width: 88,
                      height: 110,
                      onChanged: _markDirty,
                    ),
                    const SizedBox(height: 10),
                    Text(
                      'Tap the photo to change it',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: AppColors.divider),
                  boxShadow: [
                    BoxShadow(
                      color: AppColors.cardShadow,
                      blurRadius: 10,
                      offset: const Offset(0, 3),
                    ),
                  ],
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('PERSONAL INFORMATION', style: AppType.eyebrow),
                    const SizedBox(height: 16),
                    _labeledField(
                      theme,
                      'Last Name',
                      TextFormField(
                        controller: _lastNameController,
                        style: TextStyle(
                          fontFamily: kBodyFontFamily,
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                          color: AppColors.textPrimary,
                        ),
                        decoration: const InputDecoration(hintText: 'Dela Cruz'),
                        validator: (v) => validatePersonName(v, label: 'Last name'),
                      ),
                    ),
                    const SizedBox(height: 16),
                    _labeledField(
                      theme,
                      'First Name',
                      TextFormField(
                        controller: _firstNameController,
                        style: TextStyle(
                          fontFamily: kBodyFontFamily,
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                          color: AppColors.textPrimary,
                        ),
                        decoration: const InputDecoration(hintText: 'Juan'),
                        validator: (v) => validatePersonName(v, label: 'First name'),
                      ),
                    ),
                    const SizedBox(height: 16),
                    _labeledField(
                      theme,
                      'Middle Initial',
                      TextFormField(
                        controller: _middleInitialController,
                        maxLength: 2,
                        style: TextStyle(
                          fontFamily: kBodyFontFamily,
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                          color: AppColors.textPrimary,
                        ),
                        decoration: const InputDecoration(
                          hintText: 'D  (optional)',
                          counterText: '',
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),
                    _labeledField(
                      theme,
                      'Sex',
                      DropdownButtonFormField<String>(
                        initialValue: _sex,
                        style: TextStyle(
                          fontFamily: kBodyFontFamily,
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                          color: AppColors.textPrimary,
                        ),
                        decoration: const InputDecoration(hintText: 'Select sex'),
                        items: const [
                          DropdownMenuItem(value: 'Male', child: Text('Male')),
                          DropdownMenuItem(value: 'Female', child: Text('Female')),
                        ],
                        onChanged: (v) => setState(() => _sex = v),
                        validator: (v) => v == null ? 'Select sex' : null,
                      ),
                    ),
                    const SizedBox(height: 16),
                    _labeledField(
                      theme,
                      'Phone Number',
                      TextFormField(
                        controller: _phoneController,
                        keyboardType: TextInputType.phone,
                        style: TextStyle(
                          fontFamily: kBodyFontFamily,
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                          color: AppColors.textPrimary,
                        ),
                        decoration: const InputDecoration(hintText: '09XXXXXXXXX'),
                        validator: (v) {
                          final value =
                              v?.trim().replaceAll(RegExp(r'[\s-]'), '') ?? '';
                          if (value.isEmpty) {
                            return null; // optional here — required at registration only
                          }
                          if (!_phPhoneRegex.hasMatch(value)) {
                            return 'Enter a valid PH number (09XXXXXXXXX)';
                          }
                          return null;
                        },
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: AppColors.divider),
                  boxShadow: [
                    BoxShadow(
                      color: AppColors.cardShadow,
                      blurRadius: 10,
                      offset: const Offset(0, 3),
                    ),
                  ],
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('ADDRESS DETAILS', style: AppType.eyebrow),
                    const SizedBox(height: 16),
                    _buildAddressPicker(theme),
                    const SizedBox(height: 16),
                    _labeledField(
                      theme,
                      'Street / Detailed Address',
                      TextFormField(
                        controller: _streetController,
                        maxLines: 2,
                        style: TextStyle(
                          fontFamily: kBodyFontFamily,
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                          color: AppColors.textPrimary,
                        ),
                        decoration: const InputDecoration(
                          hintText: 'House no., street, subdivision',
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 32),
              ElevatedButton(
                onPressed: _isSaving ? null : _save,
                child: _isSaving
                    ? ZefanyaLoader(
                        size: 18,
                        color: AppColors.textOnDark,
                        strokeWidth: 4,
                      )
                    : const Text('Save Changes'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildAddressPicker(ThemeData theme) {
    if (_catalogLoading) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 20),
        child: Center(child: ZefanyaLoader()),
      );
    }
    final catalog = _catalog;
    if (catalog == null) {
      return Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AppColors.divider),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Address data could not be loaded',
              style: theme.textTheme.titleSmall,
            ),
            const SizedBox(height: 6),
            Text(
              'The Philippine address list ships with the app. Try again to '
              'edit your address.',
              style: theme.textTheme.bodySmall?.copyWith(
                color: AppColors.textSecondary,
              ),
            ),
            const SizedBox(height: 10),
            OutlinedButton(
              key: const ValueKey('retry-profile-address'),
              onPressed: _retryCatalog,
              child: const Text('Try again'),
            ),
          ],
        ),
      );
    }
    return PhAddressFields(
      catalog: catalog,
      selection: _address,
      onChanged: (selection) => setState(() => _address = selection),
    );
  }

  Widget _labeledField(ThemeData theme, String label, Widget field) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: TextStyle(
            fontFamily: kBodyFontFamily,
            fontSize: 12,
            fontWeight: FontWeight.w500,
            color: AppColors.textSecondary,
          ),
        ),
        const SizedBox(height: 8),
        field,
      ],
    );
  }
}
