// Courier QR scanner screen.
//
// Scans parcel QR codes and returns the tracking number via Navigator.pop.
//
// Caller decides whether the scanned number matches the job's tracking number.
// Uses [mobile_scanner] ^7.4.2 API: a [MobileScanner] widget drives the
// camera, results arrive through the [MobileScannerController.barcodes]
// stream, and the controller starts automatically when the widget mounts.
import 'package:app_settings/app_settings.dart';
import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

class CourierQrScanner extends StatefulWidget {
  /// The value the caller expects to match. The scanner itself does not
  /// validate it; the caller decides whether a returned code is correct.
  final String expectedTrackingNumber;

  /// What the manual fallback calls the value it is asking for. Pickup asks
  /// for a tracking number; the delivery handover asks for a delivery code.
  final String manualEntryLabel;
  final String manualHint;

  const CourierQrScanner({
    super.key,
    this.expectedTrackingNumber = '',
    this.manualEntryLabel = 'tracking number',
    this.manualHint = 'e.g. ZEF-PICK-01',
  });

  @override
  State<CourierQrScanner> createState() => _CourierQrScannerState();
}

class _CourierQrScannerState extends State<CourierQrScanner> {
  final _controller = MobileScannerController(
    // Let MobileScanner request permission and start when it mounts. The
    // previous `autoStart: false` had no caller doing start(), so the camera
    // never came up at all.
    autoStart: true,
    detectionSpeed: DetectionSpeed.normal,
    formats: const [BarcodeFormat.qrCode],
  );
  final _manualController = TextEditingController();
  String? _lastScanned;
  DateTime? _lastScanTime;
  bool _torchOn = false;

  @override
  void initState() {
    super.initState();
    _controller.addListener(_onControllerListener);
  }

  @override
  void dispose() {
    _controller.removeListener(_onControllerListener);
    _controller.dispose();
    _manualController.dispose();
    super.dispose();
  }

  void _onControllerListener() {
    // MobileScannerController notifies on state changes.
    // Barcode captures arrive via MobileScanner's onDetect callback (wired in build()).
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

  Widget _buildScanFrame(BuildContext context, BoxConstraints constraints) {
    final size =
        MediaQuery.of(context).size.width < MediaQuery.of(context).size.height
        ? MediaQuery.of(context).size.width * 0.8
        : MediaQuery.of(context).size.height * 0.8;
    return Container(
      padding: EdgeInsets.all(size * 0.1),
      decoration: BoxDecoration(
        border: Border.all(
          color: _torchOn ? Colors.yellow : Colors.green,
          width: 2,
        ),
        color: Colors.black45,
      ),
      child: Center(
        child: Container(
          width: size * 0.6,
          height: size * 0.6,
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
            style: const TextStyle(color: Colors.white),
            decoration: InputDecoration(
              hintText: widget.manualHint,
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
            onSubmitted: (_) {
              if (!_isInCooldown()) {
                Navigator.pop(context, _manualController.text.trim());
              }
            },
          ),
          const SizedBox(height: 8),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              key: const ValueKey('qr-manual-confirm'),
              onPressed: () {
                if (!_isInCooldown()) {
                  Navigator.pop(context, _manualController.text.trim());
                }
              },
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
    if (capture.barcodes.isEmpty) return;
    final barcode = capture.barcodes.first.rawValue;
    if (barcode == null) return;
    // Debounce: ignore the same code scanned repeatedly
    if (barcode == _lastScanned) return;
    _lastScanned = barcode;
    _lastScanTime = DateTime.now();
    // Return scanned string via Navigator.pop — caller decides validation
    if (!mounted) return;
    Navigator.pop(context, barcode);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: const Text('Scan QR code'),
        leading: IconButton(
          key: const ValueKey('qr-close'),
          tooltip: 'Close scanner',
          icon: const Icon(Icons.close),
          onPressed: () => Navigator.of(context).maybePop(),
        ),
      ),
      body: Column(
        children: [
          Expanded(
            child: MobileScanner(
              controller: _controller,
              onDetect: _onDetect,
              scanWindow: const Rect.fromLTWH(0.1, 0.1, 0.8, 0.8),
              scanWindowUpdateThreshold: 0.1,
              overlayBuilder: (context, constraints) => Stack(
                children: [
                  _buildScanFrame(context, constraints),
                  Align(
                    alignment: Alignment.bottomRight,
                    child: Padding(
                      padding: const EdgeInsets.all(24),
                      child: IconButton(
                        tooltip: _torchOn ? 'Turn torch off' : 'Turn torch on',
                        icon: Icon(_torchOn ? Icons.flash_on : Icons.flash_off),
                        onPressed: _toggleTorch,
                        color: Colors.white,
                      ),
                    ),
                  ),
                ],
              ),
              placeholderBuilder: (context) =>
                  const Center(child: CircularProgressIndicator()),
              errorBuilder: (context, error) =>
                  error.errorCode == MobileScannerErrorCode.permissionDenied
                  ? _buildPermissionError(context)
                  : _buildCameraError(context, error),
              onDetectError: (error, stack) {
                debugPrint('QR scanner detect error: $error');
              },
            ),
          ),
          // The manual fallback lives outside MobileScanner on purpose: the
          // camera can be starting, denied, or absent, and none of those may
          // hide the one path that still works.
          ColoredBox(
            color: Colors.black,
            child: SafeArea(top: false, child: _buildManualEntry()),
          ),
        ],
      ),
    );
  }
}
