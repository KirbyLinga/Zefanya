import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../data/api/api_exception.dart';
import '../../data/repositories/linehaul_repository.dart';
import '../../widgets/dirty_guard.dart';

class LinehaulDelayDialog extends StatefulWidget {
  const LinehaulDelayDialog({
    super.key,
    required this.trip,
    required this.repository,
  });
  final LinehaulTrip trip;
  final LinehaulRepository repository;
  @override
  State<LinehaulDelayDialog> createState() => _LinehaulDelayDialogState();
}

class _LinehaulDelayDialogState extends State<LinehaulDelayDialog> {
  final _reason = TextEditingController();
  final _etaText = TextEditingController();
  final _form = GlobalKey<FormState>();
  DateTime? _eta;
  String? _error;
  bool _busy = false;
  bool _saved = false;
  bool _allowLeave = false;
  bool _discardPrompt = false;
  bool get _dirty => _reason.text.isNotEmpty || _eta != null;

  @override
  void initState() {
    super.initState();
    _reason.addListener(_reasonChanged);
  }

  void _reasonChanged() => setState(() {});

  Future<void> _leave() async {
    if (_busy || _saved || _discardPrompt) return;
    _discardPrompt = true;
    final discard =
        !_dirty ||
        await DirtyGuard.confirmDiscard(
          context,
          message:
              'Your unsent delay reason and ETA will be discarded. Your trip stays unchanged.',
        );
    _discardPrompt = false;
    if (!discard || !mounted) return;
    setState(() => _allowLeave = true);
    await WidgetsBinding.instance.endOfFrame;
    if (mounted) Navigator.pop(context, false);
  }

  @override
  void dispose() {
    _reason.removeListener(_reasonChanged);
    _reason.dispose();
    _etaText.dispose();
    super.dispose();
  }

  void _setEta(DateTime eta) {
    setState(() {
      _eta = eta;
      _etaText.text = DateFormat.yMMMd().add_jm().format(eta);
    });
  }

  Future<void> _chooseEta() async {
    final now = DateTime.now();
    final suggestion = _eta?.isAfter(now) == true
        ? _eta!
        : now.add(const Duration(hours: 1));
    final day = await showDatePicker(
      context: context,
      initialDate: suggestion,
      firstDate: DateTime(now.year, now.month, now.day),
      lastDate: now.add(const Duration(days: 365)),
    );
    if (day == null || !mounted) return;
    final time = await showTimePicker(
      context: context,
      initialTime: TimeOfDay.fromDateTime(suggestion),
    );
    if (time == null || !mounted) return;
    _setEta(DateTime(day.year, day.month, day.day, time.hour, time.minute));
  }

  Future<void> _submit() async {
    if (_busy || _saved || !_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.repository.act(
        widget.trip.id,
        'delay',
        body: {
          'reason': _reason.text.trim(),
          'new_eta': _eta!.toUtc().toIso8601String(),
          'offer_version': widget.trip.offerVersion,
        },
      );
      if (!mounted) return;
      setState(() {
        _saved = true;
        _busy = false;
      });
      await WidgetsBinding.instance.endOfFrame;
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (mounted) {
        setState(
          () => _error =
              error is ValidationException && error.fieldErrors.isNotEmpty
              ? error.fieldErrors.values.join('\n')
              : error.message,
        );
      }
    } catch (_) {
      if (mounted) {
        setState(
          () => _error = 'Could not send this report. Please try again.',
        );
      }
    } finally {
      if (mounted && !_saved) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !_busy && (_saved || _allowLeave || !_dirty),
    onPopInvokedWithResult: (didPop, _) {
      if (!didPop) _leave();
    },
    child: AlertDialog(
      title: const Text('Report a delay'),
      content: SingleChildScrollView(
        child: Form(
          key: _form,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Tell Logistics what happened and when you now expect to reach the destination hub. Your trip stays In Transit.',
              ),
              const SizedBox(height: 16),
              if (_busy) const LinearProgressIndicator(),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Text(_error!),
                ),
              DropdownButtonFormField<String>(
                isExpanded: true,
                decoration: const InputDecoration(
                  labelText: 'Suggested reason',
                ),
                items: [
                  for (final reason in [
                    'Heavy traffic',
                    'Road closure',
                    'Vehicle problem',
                    'Other',
                  ])
                    DropdownMenuItem(value: reason, child: Text(reason)),
                ],
                onChanged: _busy || _saved
                    ? null
                    : (value) =>
                          _reason.text = value == 'Other' ? '' : value ?? '',
              ),
              const SizedBox(height: 16),
              TextFormField(
                key: const ValueKey('delay-reason'),
                controller: _reason,
                enabled: !_busy && !_saved,
                minLines: 2,
                maxLines: 4,
                maxLength: 500,
                decoration: const InputDecoration(labelText: 'Reason'),
                validator: (value) => value == null || value.trim().isEmpty
                    ? 'Enter a delay reason'
                    : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                key: const ValueKey('delay-eta'),
                controller: _etaText,
                readOnly: true,
                enabled: !_busy && !_saved,
                onTap: _busy || _saved ? null : _chooseEta,
                decoration: const InputDecoration(
                  labelText: 'New ETA (your local time)',
                  suffixIcon: Icon(Icons.schedule),
                ),
                validator: (_) => _eta == null || !_eta!.isAfter(DateTime.now())
                    ? 'Choose a future ETA'
                    : null,
              ),
              TextButton(
                onPressed: _busy || _saved
                    ? null
                    : () =>
                          _setEta(DateTime.now().add(const Duration(hours: 1))),
                child: const Text('1 hour from now'),
              ),
              const Text(
                'This is your reported estimate. Logistics will see the reason and updated ETA.',
              ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _busy || _saved ? null : _leave,
          child: const Text('Keep trip unchanged'),
        ),
        FilledButton(
          onPressed: _busy || _saved ? null : _submit,
          child: Text(_busy ? 'Sending…' : 'Send delay report'),
        ),
      ],
    ),
  );
}
