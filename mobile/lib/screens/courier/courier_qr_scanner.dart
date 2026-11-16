// Courier QR scanner screen.
//
// Scans parcel QR codes and returns the tracking number via Navigator.pop.
//
// Caller decides whether the scanned number matches the job's tracking number.
// Uses [mobile_scanner] ^7.4.2 API: a [MobileScanner] widget drives the
// camera, results arrive through the [MobileScannerController.barcodes]
// stream, and the controller starts automatically when the widget mounts.
import 'dart:async';

import 'package:app_settings/app_settings.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../widgets/zefanya_mark.dart';

typedef TripScanFeedback = ({
  String message,
  int confirmed,
  int expected,
  bool accepted,
  bool retryable,
});

class CourierQrScanner extends StatefulWidget {
  /// The value the caller expects to match. The scanner itself does not
  /// validate it; the caller decides whether a returned code is correct.
  final String expectedTrackingNumber;

  /// What the manual fallback calls the value it is asking for. Pickup asks
  /// for a tracking number.
  final String manualEntryLabel;
  final String manualHint;
  final String? riderTaskLabel;
  final String? tripTaskLabel;
  final ValueChanged<String>? onInputMethod;
  final bool allowCode128;
  final Future<TripScanFeedback> Function(String code, String method)?
  onTripScan;
  final int confirmedParcels;
  final int expectedParcels;

  const CourierQrScanner({
    super.key,
    this.expectedTrackingNumber = '',
    this.manualEntryLabel = 'tracking number',
    this.manualHint = 'e.g. ZEF-PICK-01',
    this.riderTaskLabel,
    this.tripTaskLabel,
    this.onInputMethod,
    this.allowCode128 = false,
    this.onTripScan,
    this.confirmedParcels = 0,
    this.expectedParcels = 0,
  });

  @override
  State<CourierQrScanner> createState() => _CourierQrScannerState();
}

class _CourierQrScannerState extends State<CourierQrScanner>
    with WidgetsBindingObserver {
  late final MobileScannerController _controller;
  MobileScannerController _createController() => MobileScannerController(
    // Let MobileScanner request permission and start when it mounts. The
    // previous `autoStart: false` had no caller doing start(), so the camera
    // never came up at all.
    autoStart: true,
    detectionSpeed: DetectionSpeed.normal,
    formats: [
      BarcodeFormat.qrCode,
      if (widget.allowCode128) BarcodeFormat.code128,
    ],
  );
  final _manualController = TextEditingController();
  final _manualFocus = FocusNode();
  bool _manualRequired = false;
  String? _lastScanned;
  DateTime? _lastScanTime;
  bool _torchOn = false;
  bool _sending = false;
  TripScanFeedback? _feedback;
  ({String code, String method})? _retryScan;
  Future<void> _cameraLifecycle = Future<void>.value();

  @override
  void initState() {
    super.initState();
    _controller = _createController();
    // MobileScanner delegates lifecycle management to the caller when it
    // receives an external controller.
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _controller.dispose();
    _manualController.dispose();
    _manualFocus.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed &&
        state != AppLifecycleState.inactive) {
      return;
    }
    _cameraLifecycle = _cameraLifecycle.then((_) async {
      if (!mounted || !_controller.value.hasCameraPermission) return;
      if (state == AppLifecycleState.resumed) {
        await _retryCamera();
      } else {
        try {
          await _controller.stop();
        } on MobileScannerException {
          // The platform may already have released the camera.
        }
        if (mounted && _torchOn) setState(() => _torchOn = false);
      }
    });
    unawaited(_cameraLifecycle);
  }

  bool _isInCooldown() {
    if (_lastScanTime == null) return false;
    final diff = DateTime.now().difference(_lastScanTime!);
    return diff.inMilliseconds < 1500;
  }

  Future<void> _retryCamera() async {
    try {
      await _controller.start();
    } catch (_) {
      // The errorBuilder shows the permission state; a failed retry stays on
      // that screen rather than throwing out of the button handler.
    }
  }

  Future<void> _openSettings() async {
    try {
      await AppSettings.openAppSettings();
    } catch (_) {
      // Not every platform can open settings; the manual-entry fallback below
      // remains available, so this is not a dead end.
    }
  }

  Widget _buildScanFrame(double side) {
    return Container(
      decoration: BoxDecoration(
        border: Border.all(
          color: _torchOn ? Colors.yellow : Colors.green,
          width: 2,
        ),
        color: widget.riderTaskLabel == null
            ? Colors.black45
            : Colors.transparent,
      ),
      child: Center(
        child: Container(
          width: side,
          height: side,
          decoration: BoxDecoration(
            color: Colors.transparent,
            border: Border.all(
              color: _torchOn ? Colors.yellow : Colors.green,
              width: 1,
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildManualEntry() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 0, 12, 4),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            'Enter ${widget.manualEntryLabel} manually:',
            style: const TextStyle(fontSize: 12, color: Colors.white70),
          ),
          const SizedBox(height: 6),
          TextField(
            key: const ValueKey('qr-manual-entry'),
            controller: _manualController,
            focusNode: _manualFocus,
            textInputAction: TextInputAction.done,
            onChanged: (_) {
              if (_manualRequired) setState(() => _manualRequired = false);
            },
            style: const TextStyle(color: Colors.white),
            decoration: InputDecoration(
              hintText: widget.manualHint,
              errorText: _manualRequired
                  ? 'Enter the ${widget.manualEntryLabel}.'
                  : null,
              hintStyle: const TextStyle(color: Colors.white54),
              filled: true,
              fillColor: Colors.white10,
              enabledBorder: const OutlineInputBorder(
                borderSide: BorderSide(color: Colors.white38),
              ),
              focusedBorder: const OutlineInputBorder(
                borderSide: BorderSide(color: Colors.white70),
              ),
              prefixIcon: const Icon(
                Icons.qr_code_scanner,
                color: Colors.white70,
              ),
            ),
            onSubmitted: (_) => _submitManual(),
          ),
          const SizedBox(height: 8),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              key: const ValueKey('qr-manual-confirm'),
              onPressed: _sending ? null : _submitManual,
              child: const Text('Confirm'),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildPermissionError(BuildContext context) {
    return ColoredBox(
      color: Colors.black,
      child: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(
                  Icons.no_photography_outlined,
                  color: Colors.white70,
                  size: 48,
                ),
                const SizedBox(height: 16),
                const Text(
                  'Camera access is blocked',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 20,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 8),
                const Text(
                  'Allow Zefanya to use the camera in Settings, then come back '
                  'and try again. You can still enter the tracking number '
                  'below.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.white70),
                ),
                const SizedBox(height: 16),
                Wrap(
                  spacing: 12,
                  runSpacing: 8,
                  alignment: WrapAlignment.center,
                  children: [
                    ElevatedButton.icon(
                      key: const ValueKey('qr-open-settings'),
                      onPressed: _openSettings,
                      icon: const Icon(Icons.settings_outlined),
                      label: const Text('Open Settings'),
                    ),
                    TextButton(
                      key: const ValueKey('qr-retry-camera'),
                      onPressed: _retryCamera,
                      child: const Text('Try again'),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildCameraError(BuildContext context, MobileScannerException error) {
    return ColoredBox(
      color: Colors.black,
      child: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(
                  Icons.error_outline,
                  color: Colors.white70,
                  size: 48,
                ),
                const SizedBox(height: 12),
                Text(
                  error.errorCode == MobileScannerErrorCode.unsupported
                      ? 'This device cannot scan QR codes.'
                      : 'The camera could not start.',
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: Colors.white, fontSize: 18),
                ),
                const SizedBox(height: 12),
                TextButton(
                  onPressed: _retryCamera,
                  child: const Text('Try again'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  void _toggleTorch() async {
    try {
      await _controller.toggleTorch();
      if (!mounted) return;
      setState(() => _torchOn = !_torchOn);
    } catch (_) {
      // Torch toggle failed (unlikely); ignore
    }
  }

  void _onDetect(BarcodeCapture capture) {
    if (_sending || capture.barcodes.isEmpty) return;
    if ((widget.riderTaskLabel != null || widget.onTripScan != null) &&
        _manualFocus.hasFocus &&
        MediaQuery.viewInsetsOf(context).bottom > 0) {
      return;
    }
    final barcode = capture.barcodes.first.rawValue;
    if (barcode == null) return;
    // Debounce: ignore the same code scanned repeatedly
    if (barcode == _lastScanned) return;
    _lastScanned = barcode;
    _lastScanTime = DateTime.now();
    _submit(barcode, 'camera');
  }

  void _submitManual() {
    if (widget.riderTaskLabel != null &&
        _manualController.text.trim().isEmpty) {
      setState(() => _manualRequired = true);
      return;
    }
    if (widget.onTripScan != null || !_isInCooldown()) {
      _submit(_manualController.text, 'manual');
    }
  }

  Future<void> _submit(String value, String method) async {
    if (!mounted || _sending) return;
    final code = value.trim();
    final onTripScan = widget.onTripScan;
    if (onTripScan == null) {
      widget.onInputMethod?.call(method);
      Navigator.pop(context, code);
      return;
    }
    setState(() {
      _sending = true;
      _retryScan = null;
    });
    try {
      final feedback = await onTripScan(code, method);
      if (!mounted) return;
      setState(() {
        _feedback = feedback;
        if (feedback.retryable) _retryScan = (code: code, method: method);
      });
      if (feedback.accepted) {
        unawaited(HapticFeedback.selectionClick());
        if (method == 'manual') _manualController.clear();
      }
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rider = widget.riderTaskLabel != null;
    final transport = widget.onTripScan != null;
    final compactControls = rider || transport;
    final keyboard =
        compactControls && MediaQuery.viewInsetsOf(context).bottom > 0;
    final confirmed = _feedback?.confirmed ?? widget.confirmedParcels;
    final expected = _feedback?.expected ?? widget.expectedParcels;
    final progress = Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Semantics(
        liveRegion: true,
        child: Text(
          '$confirmed of $expected parcels confirmed'
          '${_sending
              ? '\nChecking parcel…'
              : _feedback == null
              ? ''
              : '\n${_feedback!.message}'}',
          textAlign: TextAlign.center,
          style: const TextStyle(color: Colors.white),
        ),
      ),
    );
    final manualEntry = ColoredBox(
      color: Colors.black,
      child: SafeArea(top: false, child: _buildManualEntry()),
    );
    return PopScope(
      canPop: !_sending,
      child: Scaffold(
        backgroundColor: Colors.black,
        appBar: AppBar(
          backgroundColor: Colors.black,
          foregroundColor: Colors.white,
          iconTheme: transport
              ? const IconThemeData(color: Colors.white)
              : null,
          actionsIconTheme: transport
              ? const IconThemeData(color: Colors.white)
              : null,
          title: Text(
            widget.tripTaskLabel ?? (rider ? 'Parcel scanner' : 'Scan QR code'),
            style: transport
                ? Theme.of(
                    context,
                  ).textTheme.titleSmall?.copyWith(color: Colors.white)
                : null,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          leading: IconButton(
            key: const ValueKey('qr-close'),
            tooltip: 'Close scanner',
            icon: const Icon(Icons.close),
            onPressed: _sending ? null : () => Navigator.of(context).maybePop(),
          ),
          actions: [
            if (compactControls)
              IconButton(
                tooltip: _torchOn ? 'Turn torch off' : 'Turn torch on',
                onPressed: _toggleTorch,
                icon: Icon(_torchOn ? Icons.flash_on : Icons.flash_off),
              ),
          ],
        ),
        body: Column(
          children: [
            if (rider && !keyboard)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
                child: Text(
                  '${widget.riderTaskLabel}\nJob reference: ${widget.expectedTrackingNumber}',
                  style: const TextStyle(color: Colors.white),
                  textAlign: TextAlign.center,
                ),
              ),
            if (transport && !keyboard) ...[
              progress,
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Text(
                  '${expected - confirmed} remaining',
                  style: const TextStyle(color: Colors.white70),
                ),
              ),
            ],
            if (_retryScan case final retry?)
              TextButton(
                style: TextButton.styleFrom(foregroundColor: Colors.white),
                onPressed: () => _submit(retry.code, retry.method),
                child: const Text('Retry scan'),
              ),
            Expanded(
              flex: compactControls ? (keyboard ? 1 : 4) : 1,
              child: LayoutBuilder(
                builder: (context, constraints) {
                  final preview = constraints.biggest;
                  final side = preview.shortestSide * 0.72;
                  return MobileScanner(
                    controller: _controller,
                    onDetect: _onDetect,
                    scanWindow: Rect.fromCenter(
                      center: preview.center(Offset.zero),
                      width: side,
                      height: side,
                    ),
                    overlayBuilder: (context, constraints) => Stack(
                      children: [
                        _buildScanFrame(side),
                        if (!compactControls)
                          Align(
                            alignment: Alignment.bottomRight,
                            child: Padding(
                              padding: const EdgeInsets.all(24),
                              child: IconButton(
                                tooltip: _torchOn
                                    ? 'Turn torch off'
                                    : 'Turn torch on',
                                icon: Icon(
                                  _torchOn ? Icons.flash_on : Icons.flash_off,
                                ),
                                onPressed: _toggleTorch,
                                color: Colors.white,
                              ),
                            ),
                          ),
                      ],
                    ),
                    placeholderBuilder: (context) => Stack(
                      fit: StackFit.expand,
                      children: [
                        if (compactControls) _buildScanFrame(side),
                        const Center(child: ZefanyaLoader()),
                      ],
                    ),
                    errorBuilder: (context, error) =>
                        error.errorCode ==
                            MobileScannerErrorCode.permissionDenied
                        ? _buildPermissionError(context)
                        : _buildCameraError(context, error),
                    onDetectError: (error, stack) {
                      debugPrint('QR scanner detect error: $error');
                    },
                  );
                },
              ),
            ),
            // The manual fallback lives outside MobileScanner on purpose: the
            // camera can be starting, denied, or absent, and none of those may
            // hide the one path that still works.
            if (compactControls)
              Flexible(
                flex: keyboard ? 5 : 4,
                child: Align(
                  alignment: Alignment.bottomCenter,
                  child: SingleChildScrollView(
                    child: transport && keyboard
                        ? Column(children: [manualEntry, progress])
                        : manualEntry,
                  ),
                ),
              )
            else
              manualEntry,
          ],
        ),
      ),
    );
  }
}
