// test/delivery_pass_handover_test.dart
//
// The courier half of the receiving flow. The handover sheet is reached
// through the real tracker screen (including its proof-photo gate), then
// the QR scanner's manual fallback supplies a pass. This pins the wiring
// the pure codec tests cannot: that a scan fills the delivery-code field,
// and that a malformed or wrong-order pass never completes a delivery.

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:image_picker_platform_interface/image_picker_platform_interface.dart';
import 'package:path_provider_platform_interface/path_provider_platform_interface.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/courier/delivery_tracker_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/theme/app_theme.dart';

const _order = Order(
  id: 'ORD-HANDOVER-PASS-1',
  shopName: 'Zefanya Studio',
  status: OrderStatus.inTransit,
  eta: 'Today, 4–6 PM',
  itemSummary: 'Linen Blend Wrap Dress',
);

class _FakeImagePicker extends ImagePickerPlatform {
  _FakeImagePicker(this.file);

  final XFile file;

  @override
  Future<XFile?> getImageFromSource({
    required ImageSource source,
    ImagePickerOptions options = const ImagePickerOptions(),
  }) async {
    return file;
  }
}

class _FakePathProvider extends PathProviderPlatform {
  _FakePathProvider(this.documentsPath);

  final String documentsPath;

  @override
  Future<String?> getApplicationDocumentsPath() async => documentsPath;
}

Future<(CourierState, String)> _atTheDoor() async {
  final courier = CourierState();
  await courier.load();
  courier.dispatchForOrder(
    order: _order,
    buyerName: 'Marielle Santos',
    deliveryArea: 'Sta. Cruz, Laguna',
    fee: 120,
    trackingNumber: 'ZEF-PASS-1',
    prepaid: true,
  );
  final jobId = courier.jobForOrder(_order.id)!.id;
  courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
  return (courier, jobId);
}

Future<void> _pump(
  WidgetTester tester,
  CourierState courier,
  String jobId,
) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => AuthState()),
        ChangeNotifierProvider<CourierState>.value(value: courier),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        home: MediaQuery(
          data: const MediaQueryData(
            size: Size(390, 844),
            disableAnimations: true,
          ),
          child: DeliveryTrackerScreen(jobId: jobId),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _attachProofAndOpenHandover(WidgetTester tester) async {
  final proof = find.text('Tap to attach a photo');
  await tester.scrollUntilVisible(proof, 300);
  await tester.tap(proof);
  // The picker copies the file through real file I/O, which fake time does
  // not advance. Give the copy a moment to finish before the next frame.
  await tester.runAsync(
    () => Future<void>.delayed(const Duration(milliseconds: 150)),
  );
  await tester.pumpAndSettle();
  expect(find.text('Photo attached'), findsOneWidget);

  await tester.tap(find.text('Confirm Order Delivered'));
  await tester.pumpAndSettle();
  expect(find.text('CONFIRM HANDOVER'), findsOneWidget);
  expect(find.byKey(const ValueKey('delivery-pass-scan')), findsOneWidget);
}

Future<void> _scanManually(WidgetTester tester, String value) async {
  await tester.tap(find.byKey(const ValueKey('delivery-pass-scan')));
  // The scanner's camera placeholder keeps an indeterminate spinner
  // running, so pumpAndSettle would never finish here.
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 500));

  final field = find.byKey(const ValueKey('qr-manual-entry'));
  expect(field, findsOneWidget);
  await tester.enterText(field, value);
  await tester.tap(find.byKey(const ValueKey('qr-manual-confirm')));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 500));
}

void main() {
  late Directory tempDir;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;

    tempDir = await Directory.systemTemp.createTemp('zefanya_pass_test');
    final source = File('${tempDir.path}/proof.png')
      ..writeAsBytesSync(const [0]);
    PathProviderPlatform.instance = _FakePathProvider(tempDir.path);
    ImagePickerPlatform.instance = _FakeImagePicker(XFile(source.path));
  });

  tearDown(() {
    try {
      if (tempDir.existsSync()) tempDir.deleteSync(recursive: true);
    } catch (_) {
      // On Windows, file locks can prevent temp dir deletion.
      // Clean up best-effort; the OS reclaims temp files on reboot.
    }
  });

  testWidgets('a scanned pass fills the delivery code and completes the '
      'handover', (tester) async {
    final (courier, jobId) = await _atTheDoor();
    await _pump(tester, courier, jobId);
    await _attachProofAndOpenHandover(tester);

    await _scanManually(tester, 'ZEFANYA:DELIVERY:$jobId:482913');

    final otpField = tester.widget<TextField>(
      find.byKey(const ValueKey('delivery-otp-field')),
    );
    expect(otpField.controller!.text, '482913');
    expect(find.byKey(const ValueKey('handover-error')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('confirm-delivery-submit')));
    await tester.pumpAndSettle();

    expect(courier.isCompleted(jobId), isTrue);
    expect(find.text('Delivered'), findsOneWidget);
    expect(find.textContaining('added to your earnings'), findsOneWidget);
  });

  testWidgets('a pass for another order is refused and does not fill the '
      'code', (tester) async {
    final (courier, jobId) = await _atTheDoor();
    await _pump(tester, courier, jobId);
    await _attachProofAndOpenHandover(tester);

    await _scanManually(tester, 'ZEFANYA:DELIVERY:REQ-9999:482913');

    expect(
      find.text('That delivery pass belongs to a different order.'),
      findsOneWidget,
    );
    final otpField = tester.widget<TextField>(
      find.byKey(const ValueKey('delivery-otp-field')),
    );
    expect(otpField.controller!.text, isEmpty);
    expect(courier.isCompleted(jobId), isFalse);
  });

  testWidgets('an unrelated QR code is refused with a clear message', (
    tester,
  ) async {
    final (courier, jobId) = await _atTheDoor();
    await _pump(tester, courier, jobId);
    await _attachProofAndOpenHandover(tester);

    await _scanManually(tester, 'ZEF-5521-01');

    expect(
      find.text('That QR is not a Zefanya delivery pass.'),
      findsOneWidget,
    );
    expect(courier.isCompleted(jobId), isFalse);
  });
}
