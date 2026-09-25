// lib/screens/shared/edit_profile_screen.dart
//
// Path check: lives at lib/screens/shared/edit_profile_screen.dart
//   -> theme      => '../../theme/app_theme.dart'
//   -> auth state => '../../state/auth_state.dart'
//   -> mock data  => '../../data/mock_data.dart' (phAddressData)
//
// Pre-fills from the current AuthState and writes back via
// updateProfile() on save. Split name fields + Sex match
// registration_screen.dart's fields so the two forms stay consistent.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/mock_data.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_header.dart';
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
  String? _province;
  String? _municipality;
  String? _barangay;
  bool _isSaving = false;

  @override
  void initState() {
    super.initState();
    final auth = context.read<AuthState>();
    _lastNameController = TextEditingController(text: auth.lastName ?? '');
    _firstNameController = TextEditingController(text: auth.firstName ?? '');
    _middleInitialController = TextEditingController(text: auth.middleInitial ?? '');
    _phoneController = TextEditingController(text: auth.phone ?? '');
    _streetController = TextEditingController(text: auth.street ?? '');
    _sex = auth.sex;
    // Only pre-select province/municipality if they're still valid
    // against the current phAddressData sample.
    if (phAddressData.containsKey(auth.province)) {
      _province = auth.province;
      if (phAddressData[_province]!.containsKey(auth.municipality)) {
        _municipality = auth.municipality;
        if (phAddressData[_province]![_municipality]!.contains(auth.barangay)) {
          _barangay = auth.barangay;
        }
      }
    }
  }

  @override
  void dispose() {
    _lastNameController.dispose();
    _firstNameController.dispose();
    _middleInitialController.dispose();
    _phoneController.dispose();
    _streetController.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

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
          province: _province,
          municipality: _municipality,
          barangay: _barangay,
          street: _streetController.text.trim(),
        );

    setState(() => _isSaving = false);
    if (!mounted) return;
    ScaffoldMessenger.of(context)
      ..clearSnackBars()
      ..showSnackBar(const SnackBar(content: Text('Profile updated')));
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final municipalities = _province == null ? <String>[] : phAddressData[_province]!.keys.toList();
    final barangays = (_province == null || _municipality == null) ? <String>[] : phAddressData[_province]![_municipality]!;

    return Scaffold(
      backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      appBar: AppBar(),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
          children: [
            const EditorialHeader(
              eyebrow: 'YOUR DETAILS',
              title: 'Edit Profile',
              padding: EdgeInsets.fromLTRB(0, 0, 0, 20),
            ),
            _labeledField(theme, 'Last Name', TextFormField(
              controller: _lastNameController,
              decoration: const InputDecoration(hintText: 'Dela Cruz'),
              validator: (v) => (v == null || v.trim().length < 2) ? 'Last name is required' : null,
            )),
            const SizedBox(height: 16),
            _labeledField(theme, 'First Name', TextFormField(
              controller: _firstNameController,
              decoration: const InputDecoration(hintText: 'Juan'),
              validator: (v) => (v == null || v.trim().length < 2) ? 'First name is required' : null,
            )),
            const SizedBox(height: 16),
            _labeledField(theme, 'Middle Initial', TextFormField(
              controller: _middleInitialController,
              maxLength: 2,
              decoration: const InputDecoration(hintText: 'D  (optional)', counterText: ''),
            )),
            const SizedBox(height: 16),
            _labeledField(theme, 'Sex', DropdownButtonFormField<String>(
              initialValue: _sex,
              decoration: const InputDecoration(hintText: 'Select sex'),
              items: const [
                DropdownMenuItem(value: 'Male', child: Text('Male')),
                DropdownMenuItem(value: 'Female', child: Text('Female')),
              ],
              onChanged: (v) => setState(() => _sex = v),
              validator: (v) => v == null ? 'Select sex' : null,
            )),
            const SizedBox(height: 16),
            _labeledField(theme, 'Phone Number', TextFormField(
              controller: _phoneController,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(hintText: '09XXXXXXXXX'),
              validator: (v) {
                final value = v?.trim().replaceAll(RegExp(r'[\s-]'), '') ?? '';
                if (value.isEmpty) return null; // optional here — required at registration only
                if (!_phPhoneRegex.hasMatch(value)) return 'Enter a valid PH number (09XXXXXXXXX)';
                return null;
              },
            )),
            const SizedBox(height: 24),
            Text('Address', style: theme.textTheme.titleMedium),
            const SizedBox(height: 12),
            _labeledField(theme, 'Province', DropdownButtonFormField<String>(
              initialValue: _province,
              decoration: const InputDecoration(hintText: 'Select province'),
              items: phAddressData.keys.map((p) => DropdownMenuItem(value: p, child: Text(p))).toList(),
              onChanged: (v) => setState(() {
                _province = v;
                _municipality = null;
                _barangay = null;
              }),
            )),
            const SizedBox(height: 16),
            _labeledField(theme, 'Municipality / City', DropdownButtonFormField<String>(
              initialValue: _municipality,
              decoration: const InputDecoration(hintText: 'Select municipality'),
              items: municipalities.map((m) => DropdownMenuItem(value: m, child: Text(m))).toList(),
              onChanged: _province == null
                  ? null
                  : (v) => setState(() {
                        _municipality = v;
                        _barangay = null;
                      }),
            )),
            const SizedBox(height: 16),
            _labeledField(theme, 'Barangay', DropdownButtonFormField<String>(
              initialValue: _barangay,
              decoration: const InputDecoration(hintText: 'Select barangay'),
              items: barangays.map((b) => DropdownMenuItem(value: b, child: Text(b))).toList(),
              onChanged: _municipality == null ? null : (v) => setState(() => _barangay = v),
            )),
            const SizedBox(height: 16),
            _labeledField(theme, 'Street / Detailed Address', TextFormField(
              controller: _streetController,
              maxLines: 2,
              decoration: const InputDecoration(hintText: 'House no., street, subdivision'),
            )),
            const SizedBox(height: 32),
            ElevatedButton(
              onPressed: _isSaving ? null : _save,
              child: _isSaving
                  ? const ZefanyaLoader(size: 18, color: AppColors.textOnDark, strokeWidth: 4)
                  : const Text('Save Changes'),
            ),
          ],
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
