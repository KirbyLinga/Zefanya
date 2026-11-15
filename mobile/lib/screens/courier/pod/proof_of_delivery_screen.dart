// lib/screens/courier/pod/proof_of_delivery_screen.dart
//
// The Proof of Delivery experience (Phase 2, **mocked submission**).
//
// Approved policy: a delivery photograph **or** a recipient signature (one is
// enough), the courier's identity, a timestamp and GPS coordinates. This
// screen collects and previews all of it, but its submission goes to
// [MockProofOfDeliveryService]: nothing is sent to WebZefanya and no real
// parcel is marked delivered. The server-side OTP requirement is untouched;
// replacing it is the separately approved C-POD backend change.

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../../data/mock_data.dart' show DeliveryJob;
import '../../../services/pod/pod_evidence.dart';
import '../../../services/pod/pod_location.dart';
import '../../../services/pod/pod_models.dart';
import '../../../services/pod/proof_of_delivery_service.dart';
import '../../../state/proof_of_delivery_controller.dart';
import '../../../theme/app_theme.dart';
import 'pod_sources.dart';
import 'signature_pad.dart';

class ProofOfDeliveryScreen extends StatefulWidget {
  const ProofOfDeliveryScreen({
    super.key,
    required this.job,
    required this.courierLabel,
    required this.service,
    this.location = const DevicePodLocationProvider(),
    this.photoSource = const CameraPodPhotoSource(),
    this.files,
    this.clock,
    this.idGenerator,
  });

  final DeliveryJob job;

  /// Display-only identity of the signed-in courier.
  final String courierLabel;

  final ProofOfDeliveryService service;
  final PodLocationProvider location;
  final PodPhotoSource photoSource;

  /// The preview's own file store. When null the screen creates one and owns
  /// its lifetime; its files are deleted when evidence is replaced or the
  /// screen closes.
  final PodFileStore? files;
  final DateTime Function()? clock;
  final String Function()? idGenerator;

  @override
  State<ProofOfDeliveryScreen> createState() => _ProofOfDeliveryScreenState();
}

class _ProofOfDeliveryScreenState extends State<ProofOfDeliveryScreen> {
  late final PodFileStore _files = widget.files ?? TempPodFileStore();
  late final ProofOfDeliveryController _controller;

  @override
  void initState() {
    super.initState();
    _controller = ProofOfDeliveryController(
      jobId: widget.job.id,
      trackingNumber: widget.job.trackingNumber ?? widget.job.id,
      courierLabel: widget.courierLabel,
      service: widget.service,
      location: widget.location,
      onEvidenceReleased: (evidence) => _files.delete(evidence.path),
      clock: widget.clock,
      idGenerator: widget.idGenerator,
    )..refreshGps();
  }

  @override
  void dispose() {
    // Remove everything the preview owns first (synchronously: it must not
    // depend on the event loop), then let the controller hand back whatever it
    // held, which the store already knows is gone.
    _files.deleteAllSync();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) =>
      ChangeNotifierProvider<ProofOfDeliveryController>.value(
        value: _controller,
        child: _PodBody(
          job: widget.job,
          photoSource: widget.photoSource,
          files: _files,
        ),
      );
}

class _PodBody extends StatefulWidget {
  const _PodBody({
    required this.job,
    required this.photoSource,
    required this.files,
  });

  final DeliveryJob job;
  final PodPhotoSource photoSource;
  final PodFileStore files;

  @override
  State<_PodBody> createState() => _PodBodyState();
}

class _PodBodyState extends State<_PodBody> {
  PodEvidenceMethod _method = PodEvidenceMethod.photo;
  final SignatureController _signature = SignatureController();
  final GlobalKey _padKey = GlobalKey();
  bool _capturing = false;
  String? _captureError;

  @override
  void dispose() {
    _signature.dispose();
    super.dispose();
  }

  Future<void> _takePhoto(ProofOfDeliveryController pod) async {
    if (_capturing) return;
    setState(() {
      _capturing = true;
      _captureError = null;
    });
    PodRawCapture? raw;
    try {
      raw = await widget.photoSource.capture(context, jobId: widget.job.id);
      // The screen may have been left while the camera was open: write nothing.
      if (raw == null || !mounted) return;
      // Re-encode from pixels: drops the capture's EXIF / GPS metadata, bounds
      // the size, and gives the preview a file it owns (and can delete).
      final png = await normalizeToPng(await File(raw.path).readAsBytes());
      if (!mounted) return;
      final path = await widget.files.savePhotoPng(png, jobId: widget.job.id);
      if (!mounted) {
        await widget.files.delete(path);
        return;
      }
      final accepted = await pod.setEvidence(
        PodEvidence(method: PodEvidenceMethod.photo, path: path),
      );
      if (!accepted && mounted) {
        setState(() => _captureError = pod.errorMessage);
      }
    } on PodEvidenceException catch (error) {
      if (mounted) setState(() => _captureError = error.problem.message);
    } on Object {
      if (mounted) {
        setState(
          () => _captureError =
              'The photo could not be captured. Check camera permission and try again.',
        );
      }
    } finally {
      // The picker's own cache copy is only deleted when the capture says it
      // created it for this use; a supplied file is never removed.
      if (raw != null && raw.deleteAfterUse) {
        try {
          final file = File(raw.path);
          if (await file.exists()) await file.delete();
        } on FileSystemException {
          // Best effort.
        }
      }
      if (mounted) setState(() => _capturing = false);
    }
  }

  Future<void> _useSignature(ProofOfDeliveryController pod) async {
    if (_capturing) return;
    final box = _padKey.currentContext?.findRenderObject() as RenderBox?;
    final size = box?.size ?? const Size(320, 180);
    setState(() {
      _capturing = true;
      _captureError = null;
    });
    try {
      final png = await _signature.toPngBytes(size: size);
      if (png == null) {
        setState(() => _captureError = 'Ask the recipient to sign first.');
        return;
      }
      final path = await widget.files.saveSignaturePng(
        png,
        jobId: widget.job.id,
      );
      final accepted = await pod.setEvidence(
        PodEvidence(method: PodEvidenceMethod.signature, path: path),
      );
      if (!accepted && mounted) {
        setState(() => _captureError = pod.errorMessage);
      }
    } on Object {
      if (mounted) {
        setState(
          () => _captureError = 'The signature could not be saved. Try again.',
        );
      }
    } finally {
      if (mounted) setState(() => _capturing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final pod = context.watch<ProofOfDeliveryController>();
    final theme = Theme.of(context);
    final job = widget.job;

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(title: const Text('Proof of delivery')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
        children: [
          _Notice(
            key: const ValueKey('pod-preview-banner'),
            color: AppColors.warning,
            icon: Icons.science_outlined,
            text:
                'Preview only. Nothing is sent to WebZefanya and no parcel is '
                'marked delivered. The buyer OTP is still required on the server.',
          ),
          const SizedBox(height: 16),
          _Card(
            key: const ValueKey('pod-parcel'),
            title: 'PARCEL',
            children: [
              Text(
                job.trackingNumber ?? job.id,
                key: const ValueKey('pod-tracking'),
                style: theme.textTheme.titleMedium,
              ),
              Text('Order reference: ${job.id}'),
              Text(job.buyerName),
              Text(job.deliveryArea, style: theme.textTheme.bodySmall),
            ],
          ),
          const SizedBox(height: 12),
          _Card(
            key: const ValueKey('pod-identity'),
            title: 'COURIER AND TIME',
            children: [
              Text(
                'Courier: ${pod.courierLabel}',
                key: const ValueKey('pod-courier'),
              ),
              Text(
                'Device time: ${DateFormat('MMM d, y h:mm:ss a').format(pod.now)}',
                key: const ValueKey('pod-timestamp'),
              ),
              Text(
                'The server records the official time and your verified '
                'identity when it accepts the delivery.',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
          const SizedBox(height: 12),
          _GpsCard(pod: pod),
          const SizedBox(height: 12),
          _evidenceCard(pod, theme),
          const SizedBox(height: 16),
          _submitArea(pod, theme),
        ],
      ),
    );
  }

  Widget _evidenceCard(ProofOfDeliveryController pod, ThemeData theme) {
    final evidence = pod.evidence;
    final locked = pod.isLocked;

    return _Card(
      key: const ValueKey('pod-evidence'),
      title: 'EVIDENCE — PHOTO OR SIGNATURE',
      children: [
        Text(
          'One is enough. Choosing the other replaces what you have.',
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: 8),
        SegmentedButton<PodEvidenceMethod>(
          segments: const [
            ButtonSegment(
              value: PodEvidenceMethod.photo,
              icon: Icon(Icons.photo_camera_outlined),
              label: Text('Photo'),
            ),
            ButtonSegment(
              value: PodEvidenceMethod.signature,
              icon: Icon(Icons.draw_outlined),
              label: Text('Signature'),
            ),
          ],
          selected: {_method},
          onSelectionChanged: locked
              ? null
              : (selection) => setState(() => _method = selection.first),
        ),
        const SizedBox(height: 12),
        if (evidence != null)
          ..._preview(pod, evidence, theme)
        else if (_method == PodEvidenceMethod.photo)
          FilledButton.icon(
            key: const ValueKey('pod-take-photo'),
            onPressed: locked || _capturing ? null : () => _takePhoto(pod),
            icon: const Icon(Icons.photo_camera_outlined),
            label: Text(_capturing ? 'Opening camera…' : 'Take delivery photo'),
          )
        else ...[
          SignaturePad(key: _padKey, controller: _signature, enabled: !locked),
          const SizedBox(height: 8),
          Row(
            children: [
              OutlinedButton(
                key: const ValueKey('pod-clear-signature'),
                onPressed: locked ? null : _signature.clear,
                child: const Text('Clear'),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: FilledButton(
                  key: const ValueKey('pod-use-signature'),
                  onPressed: locked || _capturing
                      ? null
                      : () => _useSignature(pod),
                  child: const Text('Use this signature'),
                ),
              ),
            ],
          ),
        ],
        if (_captureError != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              _captureError!,
              key: const ValueKey('pod-capture-error'),
              style: TextStyle(color: AppColors.danger),
            ),
          ),
      ],
    );
  }

  List<Widget> _preview(
    ProofOfDeliveryController pod,
    PodEvidence evidence,
    ThemeData theme,
  ) => [
    ClipRRect(
      borderRadius: BorderRadius.circular(12),
      child: Container(
        color: Colors.white,
        constraints: const BoxConstraints(maxHeight: 220),
        width: double.infinity,
        child: Image.file(
          File(evidence.path),
          key: const ValueKey('pod-evidence-preview'),
          fit: BoxFit.contain,
          errorBuilder: (_, _, _) => const Padding(
            padding: EdgeInsets.all(24),
            child: Center(child: Text('Preview unavailable')),
          ),
        ),
      ),
    ),
    const SizedBox(height: 8),
    if (pod.evidenceProblem != null)
      _Notice(
        key: const ValueKey('pod-evidence-problem'),
        color: AppColors.danger,
        icon: Icons.broken_image_outlined,
        text: pod.evidenceProblem!.message,
      )
    else
      Text(
        '${evidence.method.label} ready to submit',
        key: const ValueKey('pod-evidence-label'),
      ),
    const SizedBox(height: 8),
    OutlinedButton(
      key: const ValueKey('pod-replace-evidence'),
      onPressed: pod.isLocked
          ? null
          : () {
              _signature.clear();
              pod.clearEvidence();
            },
      child: Text('Replace ${evidence.method.label.toLowerCase()}'),
    ),
  ];

  Widget _submitArea(ProofOfDeliveryController pod, ThemeData theme) {
    if (pod.isSucceeded) {
      final receipt = pod.receipt!;
      return _Card(
        key: const ValueKey('pod-success'),
        title: 'PROOF RECORDED (PREVIEW)',
        children: [
          Text(
            'Reference: ${receipt.reference}',
            key: const ValueKey('pod-reference'),
          ),
          const Text(
            'This was a preview. Nothing was sent to WebZefanya and the parcel '
            'has not been marked delivered.',
          ),
          const SizedBox(height: 8),
          FilledButton(
            key: const ValueKey('pod-done'),
            onPressed: () => Navigator.of(context).maybePop(),
            child: const Text('Done'),
          ),
        ],
      );
    }

    final blocker = pod.submitBlocker;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (pod.isFailed && pod.errorMessage != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: _Notice(
              key: const ValueKey('pod-failure'),
              color: AppColors.danger,
              icon: Icons.error_outline,
              text:
                  '${pod.errorMessage} Your proof is kept. Retrying sends the '
                  'same submission, so it cannot be recorded twice.',
            ),
          ),
        FilledButton(
          key: const ValueKey('pod-submit'),
          onPressed: pod.canSubmit ? pod.submit : null,
          child: pod.isSubmitting
              ? const Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    SizedBox(
                      key: ValueKey('pod-progress'),
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                    SizedBox(width: 10),
                    Text('Submitting…'),
                  ],
                )
              : Text(
                  pod.isFailed ? 'Retry submission' : 'Submit proof (preview)',
                ),
        ),
        if (blocker != null && !pod.isSubmitting)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              blocker,
              key: const ValueKey('pod-blocker'),
              style: theme.textTheme.bodySmall,
            ),
          ),
      ],
    );
  }
}

class _GpsCard extends StatelessWidget {
  const _GpsCard({required this.pod});

  final ProofOfDeliveryController pod;

  @override
  Widget build(BuildContext context) {
    final gps = pod.gps;
    final theme = Theme.of(context);

    Widget body;
    if (pod.gpsLoading || gps == null) {
      body = const Row(
        key: ValueKey('pod-gps-loading'),
        children: [
          SizedBox(
            width: 16,
            height: 16,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
          SizedBox(width: 10),
          Text('Getting the delivery location…'),
        ],
      );
    } else if (gps is PodGpsFix) {
      body = Column(
        key: const ValueKey('pod-gps-fix'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '${gps.latitude.toStringAsFixed(6)}, ${gps.longitude.toStringAsFixed(6)}',
            key: const ValueKey('pod-gps-coordinates'),
            style: theme.textTheme.titleSmall,
          ),
          Text(
            'Accuracy ±${gps.accuracyMeters.toStringAsFixed(0)} m',
            key: const ValueKey('pod-gps-accuracy'),
          ),
          if (gps.isMocked)
            Text(
              'The device reports a mock location. This is shown to reviewers.',
              key: const ValueKey('pod-gps-mocked'),
              style: TextStyle(color: AppColors.warning),
            ),
        ],
      );
    } else {
      final problem = (gps as PodGpsUnavailable).problem;
      body = Column(
        key: const ValueKey('pod-gps-unavailable'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _Notice(
            color: AppColors.danger,
            icon: Icons.location_off_outlined,
            text:
                '${problem.message} Unresolved exception: the owner has not '
                'decided whether a delivery may complete without GPS, so the '
                'app will not complete it.',
          ),
        ],
      );
    }

    return _Card(
      key: const ValueKey('pod-gps'),
      title: 'DELIVERY LOCATION (GPS)',
      children: [
        body,
        if (!pod.gpsLoading && !pod.isLocked)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              key: const ValueKey('pod-gps-retry'),
              onPressed: pod.refreshGps,
              child: Text(
                gps is PodGpsUnavailable ? 'Retry GPS' : 'Refresh location',
              ),
            ),
          ),
      ],
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({super.key, required this.title, required this.children});

  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Container(
    width: double.infinity,
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: AppColors.surfaceMuted,
      borderRadius: BorderRadius.circular(14),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: AppType.eyebrow),
        const SizedBox(height: 8),
        ...children,
      ],
    ),
  );
}

class _Notice extends StatelessWidget {
  const _Notice({
    super.key,
    required this.color,
    required this.icon,
    required this.text,
  });

  final Color color;
  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: color.withValues(alpha: 0.12),
      border: Border.all(color: color),
      borderRadius: BorderRadius.circular(12),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, color: color, size: 20),
        const SizedBox(width: 10),
        Expanded(child: Text(text)),
      ],
    ),
  );
}
