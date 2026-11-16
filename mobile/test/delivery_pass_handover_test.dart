// test/delivery_pass_handover_test.dart
//
// The courier half of the receiving flow. The handover sheet is reached
// through the real tracker screen (including its proof-photo gate), then
// it requires recorded handover and recipient acceptance without a Buyer OTP.

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
  // The picker uses real file I/O, so wait for the rendered result.
  final attached = find.text('Photo attached');
  final deadline = DateTime.now().add(const Duration(seconds: 10));
  while (attached.evaluate().isEmpty && DateTime.now().isBefore(deadline)) {
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 25)),
    );
    await tester.pump();
  }
  expect(attached, findsOneWidget);

  await tester.tap(find.text('Confirm Order Delivered'));
  await tester.pumpAndSettle();
  expect(find.text('CONFIRM HANDOVER'), findsOneWidget);
  expect(find.byKey(const ValueKey('delivery-pass-scan')), findsNothing);
  expect(find.byKey(const ValueKey('delivery-otp-field')), findsNothing);
}

Future<void> _tap(WidgetTester tester, String key) async {
  final target = find.byKey(ValueKey(key));
  await tester.ensureVisible(target);
  await tester.tap(target);
  await tester.pumpAndSettle();
}

bool _canSubmit(WidgetTester tester) =>
    tester
        .widget<ElevatedButton>(
          find.byKey(const ValueKey('confirm-delivery-submit')),
        )
        .onPressed !=
    null;

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

  testWidgets('a proof photo and Buyer acknowledgement complete handover', (
    tester,
  ) async {
    final (courier, jobId) = await _atTheDoor();
    await _pump(tester, courier, jobId);
    await _attachProofAndOpenHandover(tester);

    await _tap(tester, 'physical-handover');
    await _tap(tester, 'recipient-accepted');
    expect(_canSubmit(tester), isTrue);
    await _tap(tester, 'confirm-delivery-submit');

    expect(courier.isCompleted(jobId), isTrue);
    expect(find.text('Delivered'), findsOneWidget);
    expect(find.textContaining('added to your earnings'), findsOneWidget);
  });

  testWidgets('recipient acknowledgement alone cannot complete handover', (
    tester,
  ) async {
    final (courier, jobId) = await _atTheDoor();
    await _pump(tester, courier, jobId);
    await _attachProofAndOpenHandover(tester);

    await _tap(tester, 'recipient-accepted');
    expect(_canSubmit(tester), isFalse);
    expect(courier.isCompleted(jobId), isFalse);
  });

  testWidgets('a representative requires both recorded identity fields', (
    tester,
  ) async {
    final (courier, jobId) = await _atTheDoor();
    await _pump(tester, courier, jobId);
    await _attachProofAndOpenHandover(tester);

    await _tap(tester, 'physical-handover');
    await _tap(tester, 'recipient-category');
    await tester.tap(find.text('Authorized representative').last);
    await tester.pumpAndSettle();
    await _tap(tester, 'recipient-accepted');
    await tester.enterText(
      find.byKey(const ValueKey('recipient-name')),
      'Anna',
    );
    await tester.pumpAndSettle();
    expect(_canSubmit(tester), isFalse);
    expect(courier.isCompleted(jobId), isFalse);
    await tester.enterText(
      find.byKey(const ValueKey('recipient-relationship')),
      'Household member',
    );
    await tester.pumpAndSettle();
    expect(_canSubmit(tester), isTrue);
  });
}
