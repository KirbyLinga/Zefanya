import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:image_picker_platform_interface/image_picker_platform_interface.dart';
import 'package:path_provider_platform_interface/path_provider_platform_interface.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/screens/courier/delivery_tracker_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/theme/app_theme.dart';

const _order = Order(
  id: 'ORD-HANDOVER-PASS-1',
  shopName: 'Zefanya Studio',
  status: OrderStatus.inTransit,
  eta: 'Today, 4â€“6 PM',
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

class _ControlledDeliveryRider extends CourierState {
  int submissions = 0;
  Completer<void> response = Completer<void>();

  @override
  bool get isServerAuthoritative => true;

  @override
  Future<void> submitDelivery(
    String jobId, {
    required String proofPhotoPath,
    required String recipientCategory,
    String? receivedBy,
    String? recipientRelationship,
    double? codAmount,
  }) {
    submissions++;
    return response.future;
  }
}

Future<(CourierState, String)> _atTheDoor({
  bool cod = false,
  CourierState? state,
}) async {
  final courier = state ?? CourierState();
  await courier.load();
  courier.dispatchForOrder(
    order: _order,
    buyerName: 'Marielle Santos',
    deliveryArea: 'Sta. Cruz, Laguna',
    fee: 120,
    trackingNumber: 'ZEF-PASS-1',
    prepaid: !cod,
    codAmount: cod ? 549 : 0,
  );
  final jobId = courier.jobForOrder(_order.id)!.id;
  if (courier.isServerAuthoritative) {
    courier.updateMilestoneFromServer(jobId, DeliveryMilestone.inTransit);
  } else {
    courier.advanceMilestone(jobId, DeliveryMilestone.inTransit);
  }
  return (courier, jobId);
}

Future<void> _pump(
  WidgetTester tester,
  CourierState courier,
  String jobId, {
  bool disableAnimations = true,
}) async {
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
          data: MediaQueryData(
            size: const Size(390, 844),
            disableAnimations: disableAnimations,
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
  // The picker copies the file through real file I/O. Wait for its visible
  // completion instead of assuming it always finishes within 150 ms.
  final attached = find.text('Photo attached');
  final timeout = Stopwatch()..start();
  while (attached.evaluate().isEmpty &&
      timeout.elapsed < const Duration(seconds: 5)) {
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 25)),
    );
    await tester.pump();
  }
  await tester.pumpAndSettle();
  expect(attached, findsOneWidget);

  await tester.tap(find.text('Confirm Order Delivered'));
  await tester.pumpAndSettle();
  expect(find.text('CONFIRM HANDOVER'), findsOneWidget);
  expect(find.byKey(const ValueKey('delivery-otp-field')), findsNothing);
  expect(find.byKey(const ValueKey('delivery-code-send')), findsNothing);
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

  final submit = find.byKey(const ValueKey('confirm-delivery-submit'));

  Future<void> check(WidgetTester tester, String key) async {
    final field = find.byKey(ValueKey(key));
    await tester.ensureVisible(field);
    await tester.tap(field);
    await tester.pumpAndSettle();
  }

  testWidgets('Buyer handover requires both confirmations and a proof photo', (
    tester,
  ) async {
    final (courier, jobId) = await _atTheDoor();
    await _pump(tester, courier, jobId);
    expect(
      tester
          .widget<ElevatedButton>(
            find.widgetWithText(ElevatedButton, 'Confirm Order Delivered'),
          )
          .onPressed,
      isNull,
    );
    await _attachProofAndOpenHandover(tester);
    expect(tester.widget<ElevatedButton>(submit).onPressed, isNull);
    await check(tester, 'physical-handover');
    expect(tester.widget<ElevatedButton>(submit).onPressed, isNull);
    await check(tester, 'recipient-accepted');
    expect(find.byKey(const ValueKey('cod-collected')), findsNothing);
    await tester.ensureVisible(submit);
    await tester.tap(submit);
    await tester.pumpAndSettle();
    expect(courier.completedJobs, hasLength(1));
  });

  testWidgets(
    'Representative requires name, relationship and exact COD confirmation',
    (tester) async {
      final (courier, jobId) = await _atTheDoor(cod: true);
      await _pump(tester, courier, jobId);
      await _attachProofAndOpenHandover(tester);
      await check(tester, 'physical-handover');
      await tester.tap(find.byKey(const ValueKey('recipient-category')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Authorized representative').last);
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('recipient-name')),
        'Maria Cruz',
      );
      await check(tester, 'recipient-accepted');
      await check(tester, 'cod-collected');
      expect(find.textContaining('549'), findsWidgets);
      expect(tester.widget<ElevatedButton>(submit).onPressed, isNull);
      final relation = find.byKey(const ValueKey('recipient-relationship'));
      await tester.ensureVisible(relation);
      await tester.enterText(relation, 'Parent');
      await tester.pumpAndSettle();
      await tester.ensureVisible(submit);
      await tester.tap(submit);
      await tester.pumpAndSettle();
      expect(courier.completedJobs, hasLength(1));
    },
  );

  testWidgets('Live submission waits and rejects without local completion', (
    tester,
  ) async {
    final courier = _ControlledDeliveryRider();
    final (_, jobId) = await _atTheDoor(state: courier);
    await _pump(tester, courier, jobId);
    await _attachProofAndOpenHandover(tester);
    await check(tester, 'physical-handover');
    await check(tester, 'recipient-accepted');
    await tester.tap(submit);
    await tester.tap(submit, warnIfMissed: false);
    await tester.pumpAndSettle();
    expect(courier.submissions, 1);
    expect(find.text('Submitting delivery…'), findsOneWidget);
    expect(courier.completedJobs, isEmpty);
    courier.response.completeError(
      const UnauthorizedException(message: 'Sign in again.'),
    );
    await tester.pumpAndSettle();
    expect(find.text('Sign in again.'), findsOneWidget);
    expect(courier.completedJobs, isEmpty);
  });
}
