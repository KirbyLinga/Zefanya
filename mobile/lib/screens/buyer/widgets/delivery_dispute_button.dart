import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import '../../../data/api/api_exception.dart';
import '../../../state/order_state.dart';

class DeliveryDisputeButton extends StatelessWidget {
  const DeliveryDisputeButton({super.key, required this.shipmentId});

  final String shipmentId;

  @override
  Widget build(BuildContext context) => TextButton.icon(
    icon: const Icon(Icons.help_outline),
    label: const Text('Parcel not received'),
    onPressed: () {
      final orders = context.read<OrderState>();
      showModalBottomSheet<void>(
        context: context,
        useSafeArea: true,
        isScrollControlled: true,
        builder: (_) => _DisputeSheet(
          read: () => orders.deliveryDispute(shipmentId),
          submit: (text, evidence) => orders.deliveryDispute(
            shipmentId,
            explanation: text,
            evidence: evidence,
          ),
        ),
      );
    },
  );
}

class _DisputeSheet extends StatefulWidget {
  const _DisputeSheet({required this.read, required this.submit});

  final Future<Map<String, Object?>> Function() read;
  final Future<Map<String, Object?>> Function(
    String,
    ({List<int> bytes, String filename})?,
  )
  submit;

  @override
  State<_DisputeSheet> createState() => _DisputeSheetState();
}

class _DisputeSheetState extends State<_DisputeSheet> {
  Map<String, Object?>? _data;
  String _explanation = '';
  String? _error;
  bool _pending = false;
  bool _checkBeforeRetry = false;
  bool _picking = false;
  ({List<int> bytes, String filename})? _evidence;

  @override
  void initState() {
    super.initState();
    _request();
  }

  Future<void> _request({bool submit = false}) async {
    if (_pending) return;
    setState(() {
      _pending = true;
      _error = null;
    });
    try {
      final data = submit
          ? await widget.submit(_explanation.trim(), _evidence)
          : await widget.read();
      if (mounted) {
        setState(() {
          _data = data;
          _checkBeforeRetry = false;
        });
      }
    } on ApiException catch (error) {
      if (mounted) {
        setState(() {
          if (submit) _checkBeforeRetry = error is! ValidationException;
          _error = error is ValidationException && error.fieldErrors.isNotEmpty
              ? error.fieldErrors.values.first
              : error.message;
        });
      }
    } finally {
      if (mounted) setState(() => _pending = false);
    }
  }

  Future<void> _attach() async {
    setState(() => _picking = true);
    try {
      final photo = await ImagePicker().pickImage(
        source: ImageSource.gallery,
        maxWidth: 1280,
        imageQuality: 80,
      );
      if (photo == null || !mounted) return;
      if (await photo.length() > 5 * 1024 * 1024) {
        if (mounted) setState(() => _error = 'Choose an image up to 5 MB.');
        return;
      }
      final bytes = await photo.readAsBytes();
      if (mounted) {
        setState(() => _evidence = (bytes: bytes, filename: photo.name));
      }
    } on Exception {
      if (mounted) setState(() => _error = 'The photo could not be attached.');
    } finally {
      if (mounted) setState(() => _picking = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final record = _data?['case'] as Map?;
    return SafeArea(
      top: false,
      child: SingleChildScrollView(
        padding: EdgeInsets.fromLTRB(
          20,
          20,
          20,
          20 + MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Parcel not received',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 12),
            if (record != null)
              Text('Case #${record['id']} · ${record['status']}'),
            if (record?['outcome'] != null)
              Text('Decision: ${record?['outcome']}'),
            if (_data?['can_report'] == true) ...[
              const Text(
                'Tell Logistics what happened so they can investigate.',
              ),
              TextField(
                maxLength: 1000,
                minLines: 2,
                maxLines: 4,
                enabled: !_pending,
                decoration: const InputDecoration(labelText: 'What happened?'),
                onChanged: (value) => setState(() => _explanation = value),
              ),
              TextButton(
                onPressed: _pending || _picking ? null : _attach,
                child: Text(
                  _evidence == null
                      ? 'Attach supporting photo (optional)'
                      : 'Replace supporting photo',
                ),
              ),
              if (_evidence != null)
                TextButton(
                  onPressed: _pending || _picking
                      ? null
                      : () => setState(() => _evidence = null),
                  child: const Text('Remove photo'),
                ),
              FilledButton(
                onPressed:
                    _pending ||
                        _picking ||
                        _checkBeforeRetry ||
                        _explanation.trim().isEmpty
                    ? null
                    : () => _request(submit: true),
                child: const Text('Send for investigation'),
              ),
            ] else if (_data != null && record == null)
              const Text(
                'Online reporting is unavailable for this delivery. Contact support for review.',
              ),
            if (_pending) const LinearProgressIndicator(),
            if (_error != null) ...[
              Semantics(liveRegion: true, child: Text(_error!)),
              TextButton(
                onPressed: _pending ? null : () => _request(),
                child: const Text('Check case status'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
