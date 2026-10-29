// lib/screens/courier/rider_vehicle_screen.dart
//
// Editing a rider's vehicle and documents after sign-up.
//
// The rules are RID-PROF-002/003/004/005: a changed plate replaces the old one,
// a plate registered to someone else is refused, an expiry of yesterday is
// refused while today is accepted, and a rejected document leaves the previous
// one in place. The same validators run here as in registration — see
// utils/rider_validators.dart for why they are shared.

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../data/mock_data.dart' show courierVehicleTypes;
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/image_picker_helper.dart';
import '../../utils/rider_validators.dart';
import '../../utils/timed_snackbar.dart';
import '../../widgets/editorial_header.dart';

class RiderVehicleScreen extends StatefulWidget {
  const RiderVehicleScreen({super.key});

  @override
  State<RiderVehicleScreen> createState() => _RiderVehicleScreenState();
}

class _RiderVehicleScreenState extends State<RiderVehicleScreen> {
  late final TextEditingController _companyController;
  late final TextEditingController _plateController;
  late final TextEditingController _licenseController;
  late String? _vehicleType;
  late DateTime? _licenseExpiry;
  String? _licensePath;
  String? _orCrPath;
  String? _expiryError;

  @override
  void initState() {
    super.initState();
    final auth = context.read<AuthState>();
    _companyController = TextEditingController(text: auth.riderCompany);
    _plateController = TextEditingController(text: auth.riderPlate);
    _licenseController = TextEditingController(
      text: auth.riderLicenseNumber,
    );
    _vehicleType = auth.riderVehicleType;
    _licenseExpiry = auth.riderLicenseExpiry;
    _licensePath = auth.riderLicensePath;
    _orCrPath = auth.riderOrCrPath;
  }

  @override
  void dispose() {
    _companyController.dispose();
    _plateController.dispose();
    _licenseController.dispose();
    super.dispose();
  }

  Future<void> _pickExpiry() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _licenseExpiry ?? DateTime(now.year + 1, now.month, now.day),
      firstDate: DateTime(now.year - 10),
      lastDate: DateTime(now.year + 30),
    );
    if (picked == null) return;
    setState(() {
      _licenseExpiry = picked;
      _expiryError = validateLicenseExpiry(picked);
    });
  }

  Future<void> _pickDocument({required bool license}) async {
    final picked = await pickAndSaveImage(
      context,
      filenamePrefix: license ? 'license' : 'or_cr',
    );
    if (picked == null) return;
    final problem = validateRiderDocumentPath(picked.path);
    if (problem != null) {
      if (!mounted) return;
      showTimedSnackBar(context, SnackBar(content: Text(problem)));
      return;
    }
    setState(() {
      if (license) {
        _licensePath = picked.path;
      } else {
        _orCrPath = picked.path;
      }
    });
  }

  Future<void> _save() async {
    final auth = context.read<AuthState>();
    final expiryProblem = validateLicenseExpiry(_licenseExpiry);
    if (expiryProblem != null) {
      setState(() => _expiryError = expiryProblem);
      return;
    }
    if (_vehicleType == null) {
      showTimedSnackBar(
        context,
        const SnackBar(content: Text('Select a vehicle type.')),
      );
      return;
    }

    final problem = auth.updateRiderVehicle(
      vehicleType: _vehicleType!,
      plate: _plateController.text,
      licenseNumber: _licenseController.text,
      licenseExpiry: _licenseExpiry,
      company: _companyController.text,
      licensePath: _licensePath == auth.riderLicensePath ? null : _licensePath,
      orCrPath: _orCrPath == auth.riderOrCrPath ? null : _orCrPath,
    );
    if (problem != null) {
      showTimedSnackBar(context, SnackBar(content: Text(problem)));
      return;
    }
    if (!mounted) return;
    Navigator.of(context).pop();
    showTimedSnackBar(
      context,
      const SnackBar(
        content: Text(
          'Vehicle updated. Logistics will re-verify the new details.',
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final auth = context.watch<AuthState>();

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(
        title: Text('Vehicle & Documents', style: theme.textTheme.titleSmall),
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
        children: [
          const EditorialHeader(
            eyebrow: 'RIDER RECORD',
            title: 'Vehicle',
            padding: EdgeInsets.fromLTRB(0, 12, 0, 16),
          ),
          if (auth.riderReverificationRequired)
            Padding(
              padding: const EdgeInsets.only(bottom: 16),
              child: Text(
                'Your last change is waiting for Logistics to re-verify it.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: AppColors.warning,
                ),
              ),
            ),
          TextField(
            controller: _companyController,
            textCapitalization: TextCapitalization.words,
            decoration: const InputDecoration(labelText: 'Company'),
          ),
          const SizedBox(height: 16),
          DropdownButtonFormField<String>(
            initialValue: _vehicleType,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Vehicle type'),
            items: courierVehicleTypes
                .map((v) => DropdownMenuItem(value: v, child: Text(v)))
                .toList(),
            onChanged: (value) => setState(() => _vehicleType = value),
          ),
          const SizedBox(height: 16),
          TextField(
            key: const ValueKey('rider-plate-field'),
            controller: _plateController,
            textCapitalization: TextCapitalization.characters,
            decoration: const InputDecoration(labelText: 'Plate number'),
          ),
          const SizedBox(height: 16),
          TextField(
            key: const ValueKey('rider-license-field'),
            controller: _licenseController,
            textCapitalization: TextCapitalization.characters,
            decoration: const InputDecoration(labelText: 'Licence number'),
          ),
          const SizedBox(height: 16),
          InkWell(
            key: const ValueKey('rider-expiry-field'),
            onTap: _pickExpiry,
            child: InputDecorator(
              decoration: InputDecoration(
                labelText: 'Licence expiry',
                errorText: _expiryError,
              ),
              child: Text(
                _licenseExpiry == null
                    ? 'Select expiry'
                    : '${_licenseExpiry!.month.toString().padLeft(2, '0')}/'
                          '${_licenseExpiry!.day.toString().padLeft(2, '0')}/'
                          '${_licenseExpiry!.year}',
              ),
            ),
          ),
          const SizedBox(height: 24),
          Text('Documents', style: AppType.eyebrow),
          const SizedBox(height: 10),
          _documentRow(
            key: const ValueKey('rider-license-upload'),
            label: 'Licence document',
            path: _licensePath,
            onPick: () => _pickDocument(license: true),
          ),
          const SizedBox(height: 10),
          _documentRow(
            key: const ValueKey('rider-orcr-upload'),
            label: 'OR/CR document',
            path: _orCrPath,
            onPick: () => _pickDocument(license: false),
          ),
          const SizedBox(height: 24),
          ElevatedButton(
            key: const ValueKey('rider-save-vehicle'),
            onPressed: _save,
            child: const Text('Save and Send for Re-verification'),
          ),
        ],
      ),
    );
  }

  Widget _documentRow({
    required Key key,
    required String label,
    required String? path,
    required VoidCallback onPick,
  }) {
    final present = path != null && File(path).existsSync();
    return OutlinedButton.icon(
      key: key,
      onPressed: onPick,
      icon: Icon(
        present ? Icons.check_circle_outline : Icons.upload_file_outlined,
        size: 18,
      ),
      label: Text(present ? '$label · attached' : 'Attach $label'),
    );
  }
}
