import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';
import '../../data/api/api_exception.dart';
import '../../data/repositories/linehaul_vehicle_repository.dart';
import '../../services/courier_document_picker.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/zefanya_mark.dart';

class LinehaulVehicleScreen extends StatefulWidget {
  const LinehaulVehicleScreen({
    super.key,
    required this.repository,
    this.picker = const DeviceCourierDocumentPicker(),
  });
  final LinehaulVehicleRepository repository;
  final CourierDocumentPicker picker;
  @override
  State<LinehaulVehicleScreen> createState() => _LinehaulVehicleScreenState();
}

class _LinehaulVehicleScreenState extends State<LinehaulVehicleScreen> {
  static const _labels = {
    'drivers_license': 'Professional driver’s license',
    'or_cr': 'OR/CR',
    'nbi_clearance': 'NBI clearance',
  };
  final _form = GlobalKey<FormState>();
  final _plate = TextEditingController();
  final _license = TextEditingController();
  final _expiry = TextEditingController();
  final _capacity = TextEditingController();
  final _files = <String, CourierDocument>{};
  final _codes = <String>{};
  LinehaulVehicle? _records;
  String? _error;
  String? _vehicle;
  bool _loading = true;
  bool _busy = false;
  bool _editing = false;
  bool _dirty = false;
  bool _discardPrompt = false;

  String _message(ApiException error) => error is ValidationException
      ? error.fieldErrors.values.join('\n')
      : error.message;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final controller in [_plate, _license, _expiry, _capacity]) {
      controller.dispose();
    }
    super.dispose();
  }

  void _accept(LinehaulVehicle records) {
    final profile = records.profile;
    _records = records;
    _plate.text = '${profile?['plate_number'] ?? ''}';
    _license.text = '${profile?['license_no'] ?? ''}';
    _expiry.text = '${profile?['license_expiry'] ?? ''}';
    _capacity.text = '${profile?['capacity_kg'] ?? ''}';
    _vehicle = profile?['vehicle_type'] as String?;
    _codes
      ..clear()
      ..addAll((profile?['license_codes'] as List? ?? []).cast<String>());
    _files.clear();
    _dirty = false;
    _editing = false;
    _error = null;
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final records = await widget.repository.load();
      if (mounted) setState(() => _accept(records));
    } on ApiException catch (error) {
      if (mounted) {
        setState(() {
          _records = null;
          _error = _message(error);
        });
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _leave() async {
    if (_busy || _discardPrompt || !_dirty) return;
    _discardPrompt = true;
    final discard = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Discard changes?'),
        content: const Text(
          'Your unsaved vehicle details and selected documents will be discarded.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Keep editing'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Discard'),
          ),
        ],
      ),
    );
    _discardPrompt = false;
    if (discard == true && mounted) {
      setState(() => _dirty = false);
      await WidgetsBinding.instance.endOfFrame;
      if (mounted) Navigator.pop(context);
    }
  }

  Future<void> _pick(String field, bool camera) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final file = await widget.picker.pick(camera: camera);
      if (file != null && mounted) {
        setState(() {
          _files[field] = file;
          _dirty = true;
        });
      }
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = _message(error));
    } catch (_) {
      if (mounted) {
        setState(
          () => _error =
              'Could not open the camera or Files. Check permission and try again.',
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    if (_codes.isEmpty) {
      setState(() => _error = 'Select the codes on your professional license.');
      return;
    }
    final existing = _records?.profile?['documents'] as Map? ?? {};
    if (_labels.keys.any(
      (key) => !_files.containsKey(key) && !existing.containsKey(key),
    )) {
      setState(() => _error = 'Select all three required documents.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final records = await widget.repository.save({
        'version': '${_records?.profile?['version'] ?? 0}',
        'plate_number': _plate.text.trim().toUpperCase(),
        'license_no': _license.text.trim().toUpperCase(),
        'license_expiry': _expiry.text,
        'vehicle_type': _vehicle!,
        'capacity_kg': _capacity.text.trim(),
        for (final (index, code) in _codes.indexed)
          'license_codes[$index]': code,
      }, _files);
      if (mounted) setState(() => _accept(records));
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = _message(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _open(String field, String mime) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final bytes = await widget.repository.download(field);
      if (!mounted) return;
      if (mime == 'application/pdf') {
        await Share.shareXFiles([
          XFile.fromData(bytes, mimeType: mime, name: 'courier-$field.pdf'),
        ], sharePositionOrigin: const Rect.fromLTWH(0, 0, 100, 100));
      } else {
        await showDialog<void>(
          context: context,
          builder: (context) => Dialog(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Flexible(
                  child: InteractiveViewer(
                    child: Image.memory(
                      bytes,
                      errorBuilder: (_, error, stack) =>
                          const Text('Could not display this image.'),
                    ),
                  ),
                ),
                TextButton(
                  onPressed: () => Navigator.pop(context),
                  child: const Text('Close'),
                ),
              ],
            ),
          ),
        );
      }
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = _message(error));
    } catch (_) {
      if (mounted) {
        setState(
          () => _error = 'Could not open this document. Please try again.',
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _card(String title, List<Widget> children) => Card(
    color: AppColors.surface,
    elevation: 0,
    margin: const EdgeInsets.only(bottom: 16),
    shape: RoundedRectangleBorder(
      borderRadius: editorialRadius(0, ShapeTier.card),
      side: BorderSide(color: AppColors.divider),
    ),
    child: Padding(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(title, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 20),
          ...children,
        ],
      ),
    ),
  );

  Widget _field(
    String label,
    TextEditingController controller, {
    TextInputType? keyboard,
    String? Function(String?)? validator,
  }) => Padding(
    padding: const EdgeInsets.only(bottom: 18),
    child: TextFormField(
      key: ValueKey(label),
      controller: controller,
      readOnly: !_editing || _busy,
      keyboardType: keyboard,
      textCapitalization: TextCapitalization.characters,
      decoration: InputDecoration(labelText: label),
      validator:
          validator ??
          (value) => value == null || value.trim().isEmpty ? 'Required' : null,
      onChanged: (_) => setState(() => _dirty = true),
    ),
  );

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return PopScope(
      canPop: !_dirty && !_busy,
      onPopInvokedWithResult: (popped, result) {
        if (!popped) _leave();
      },
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(title: const Text('Vehicle & documents')),
        body: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 720),
            child: _body(),
          ),
        ),
      ),
    );
  }

  Widget _body() {
    if (_loading) return const Center(child: ZefanyaLoader(size: 48));
    final records = _records;
    if (records == null) {
      return SingleChildScrollView(
        child: EmptyState(
          icon: Icons.cloud_off_outlined,
          title: 'Vehicle records could not be loaded',
          subtitle: _error ?? 'Please try again.',
          action: OutlinedButton(onPressed: _load, child: const Text('Retry')),
        ),
      );
    }
    final profile = records.profile;
    final documents = profile?['documents'] as Map? ?? {};
    return Form(
      key: _form,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
        children: [
          const EditorialHeader(
            eyebrow: 'READY FOR THE ROAD',
            title: 'Your vehicle.\nYour credentials.',
            padding: EdgeInsets.only(bottom: 20),
          ),
          if (_busy) const LinearProgressIndicator(),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 16),
              child: Text(_error!, style: TextStyle(color: AppColors.primary)),
            ),
          _card('Company review', [
            Text(
              records.setupRequired
                  ? 'Setup pending'
                  : profile == null
                  ? 'No records submitted'
                  : switch (profile['review_status']) {
                      'approved' => 'Approved',
                      'rejected' => 'Corrections requested',
                      _ => 'Pending review',
                    },
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 12),
            Text(
              records.setupRequired
                  ? 'Vehicle records are not enabled on this server yet.'
                  : profile == null
                  ? 'Add your actual vehicle and documents. Existing demo access does not mean your documents are verified.'
                  : '${profile['review_note'] ?? 'Logistics must review this submission before you can go On Duty.'}',
            ),
            if (!records.setupRequired && !records.editable)
              const Padding(
                padding: EdgeInsets.only(top: 12),
                child: Text(
                  'Finish your active trip and go Off Duty before editing.',
                ),
              ),
            if (records.editable && !_editing)
              Padding(
                padding: const EdgeInsets.only(top: 16),
                child: FilledButton(
                  onPressed: _busy
                      ? null
                      : () => setState(() => _editing = true),
                  child: Text(
                    profile == null ? 'Add vehicle records' : 'Edit records',
                  ),
                ),
              ),
          ]),
          if (profile != null || _editing) ...[
            _card('Vehicle details', [
              _field('Plate number', _plate),
              DropdownButtonFormField<String>(
                initialValue: _vehicle,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Vehicle type'),
                items: [
                  for (final vehicle in records.vehicleTypes)
                    DropdownMenuItem(
                      value: vehicle,
                      child: Text(vehicle, overflow: TextOverflow.ellipsis),
                    ),
                ],
                onChanged: !_editing || _busy
                    ? null
                    : (value) => setState(() {
                        _vehicle = value;
                        _dirty = true;
                      }),
                validator: (value) =>
                    value == null ? 'Select your vehicle type' : null,
              ),
              const SizedBox(height: 18),
              _field(
                'Load capacity (kg)',
                _capacity,
                keyboard: const TextInputType.numberWithOptions(decimal: true),
                validator: (value) {
                  final capacity = double.tryParse(value ?? '');
                  return capacity == null ||
                          !capacity.isFinite ||
                          capacity < 1 ||
                          capacity > 50000
                      ? 'Enter 1–50,000 kg'
                      : null;
                },
              ),
            ]),
            _card('Professional license', [
              _field('License number', _license),
              _field(
                'Expiry (YYYY-MM-DD)',
                _expiry,
                keyboard: TextInputType.datetime,
                validator: (value) {
                  final date = DateTime.tryParse(value ?? '');
                  final now = DateTime.now();
                  return !RegExp(
                            r'^\d{4}-\d{2}-\d{2}$',
                          ).hasMatch(value ?? '') ||
                          date == null ||
                          date.toIso8601String().substring(0, 10) != value ||
                          date.isBefore(DateTime(now.year, now.month, now.day))
                      ? 'Enter today or a later date'
                      : null;
                },
              ),
              const Text('License codes'),
              Wrap(
                spacing: 8,
                children: [
                  for (final code in ['B', 'B1', 'B2', 'C', 'D', 'BE', 'CE'])
                    FilterChip(
                      label: Text(code),
                      selected: _codes.contains(code),
                      onSelected: !_editing || _busy
                          ? null
                          : (selected) => setState(() {
                              selected ? _codes.add(code) : _codes.remove(code);
                              _dirty = true;
                            }),
                    ),
                ],
              ),
            ]),
            for (final entry in _labels.entries)
              _card(entry.value, [
                Text(
                  _files[entry.key]?.name ??
                      (documents.containsKey(entry.key)
                          ? 'Document saved privately'
                          : 'Required · JPG, PNG or PDF · up to 5 MB'),
                ),
                if (_files[entry.key] case final file?) ...[
                  const SizedBox(height: 12),
                  if (!file.name.toLowerCase().endsWith('.pdf'))
                    Image.memory(
                      file.bytes,
                      height: 140,
                      fit: BoxFit.contain,
                      errorBuilder: (_, error, stack) => const Text(
                        'Preview unavailable; the server will validate this file.',
                      ),
                    ),
                ],
                if (_editing)
                  Wrap(
                    spacing: 8,
                    children: [
                      OutlinedButton.icon(
                        key: ValueKey('camera-${entry.key}'),
                        onPressed: _busy ? null : () => _pick(entry.key, true),
                        icon: const Icon(Icons.camera_alt_outlined),
                        label: const Text('Take photo'),
                      ),
                      OutlinedButton.icon(
                        key: ValueKey('file-${entry.key}'),
                        onPressed: _busy ? null : () => _pick(entry.key, false),
                        icon: const Icon(Icons.folder_open_outlined),
                        label: const Text('Choose file'),
                      ),
                    ],
                  ),
                if (documents[entry.key] case final Map document)
                  OutlinedButton(
                    key: ValueKey('view-${entry.key}'),
                    onPressed: _busy
                        ? null
                        : () => _open(entry.key, '${document['mime']}'),
                    child: Text(
                      document['mime'] == 'application/pdf'
                          ? 'Open / save PDF'
                          : 'View saved document',
                    ),
                  ),
              ]),
            if (_editing) ...[
              const Text(
                'Saving sends these records to your company for review. You will need approval before going On Duty.',
              ),
              const SizedBox(height: 16),
              FilledButton(
                onPressed: _busy ? null : _save,
                child: Text(_busy ? 'Saving…' : 'Submit for review'),
              ),
            ],
          ],
        ],
      ),
    );
  }
}
