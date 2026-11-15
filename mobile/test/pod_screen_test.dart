// test/pod_screen_test.dart
//
// The Proof of Delivery screen: photo evidence (normalized and preview-owned),
// signature evidence, GPS unavailable, failed upload and retry, duplicate
// submission, and evidence-file lifetime. The service is the in-memory mock; the
// camera and GPS are fakes, and the file store writes to a temp folder.

import 'dart:async';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:zefanya/data/mock_data.dart' show DeliveryJob;
import 'package:zefanya/screens/courier/pod/pod_sources.dart';
import 'package:zefanya/screens/courier/pod/proof_of_delivery_screen.dart';
import 'package:zefanya/screens/courier/pod/signature_pad.dart';
import 'package:zefanya/services/pod/pod_evidence.dart';
import 'package:zefanya/services/pod/pod_location.dart';
import 'package:zefanya/services/pod/pod_models.dart';
import 'package:zefanya/services/pod/proof_of_delivery_service.dart';
import 'package:zefanya/theme/app_theme.dart';

import 'support/pod_fixtures.dart';

class _Location implements PodLocationProvider {
  _Location(this.results);

  final List<PodGpsResult> results;
  int calls = 0;

  @override
  Future<PodGpsResult> currentFix() async =>
      results[calls < results.length ? calls++ : results.length - 1];
}

/// A camera fake. [completer] lets a test finish the capture later.
class _Photos implements PodPhotoSource {
  _Photos(this.path, {this.deleteAfterUse = false, this.completer});

  final String path;
  final bool deleteAfterUse;
  final Completer<void>? completer;
  int captures = 0;

  @override
  Future<PodRawCapture?> capture(
    BuildContext context, {
    required String jobId,
  }) async {
    captures++;
    await completer?.future;
    return PodRawCapture(path, deleteAfterUse: deleteAfterUse);
  }
}

final _fix = PodGpsFix(
  latitude: 14.167123,
  longitude: 121.243456,
  accuracyMeters: 7.6,
  capturedAt: DateTime(2026, 11, 14, 10),
);

const _job = DeliveryJob(
  id: '42',
  shopName: 'Shop',
  pickupAddress: 'Seller address',
  buyerName: 'Buyer Name',
  deliveryArea: 'Bay, Laguna',
  packageSize: 'Small',
  fee: 30,
  trackingNumber: 'TRK-42',
);

Future<void> _pump(
  WidgetTester tester, {
  required MockProofOfDeliveryService service,
  required PodLocationProvider location,
  required PodPhotoSource photos,
  required PodFileStore files,
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(420, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    MaterialApp(
      theme: AppTheme.light,
      home: ProofOfDeliveryScreen(
        job: _job,
        courierLabel: 'rider@example.test',
        service: service,
        location: location,
        photoSource: photos,
        files: files,
        clock: () => DateTime(2026, 11, 14, 10, 30, 5),
        idGenerator: () => 'key-1',
      ),
    ),
  );
  await tester.pumpAndSettle();
}

MockProofOfDeliveryService _service({int failNext = 0}) =>
    MockProofOfDeliveryService(
      latency: Duration.zero,
      failNextSubmissions: failNext,
    );

/// Pumps (with real time between pumps) until [finder] matches or [seconds]
/// pass. Evidence handling does real file IO and image decoding, so a fixed
/// delay is a race under load; polling is not.
Future<void> _pumpUntil(
  WidgetTester tester,
  Finder finder, {
  int seconds = 10,
}) async {
  final deadline = DateTime.now().add(Duration(seconds: seconds));
  while (DateTime.now().isBefore(deadline)) {
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 25)),
    );
    await tester.pump();
    if (finder.evaluate().isNotEmpty) return;
  }
}

/// Polls (in real time) until [condition] holds, for file-system effects that
/// complete outside the widget tree.
Future<void> _waitUntil(
  WidgetTester tester,
  bool Function() condition, {
  int seconds = 10,
}) async {
  final deadline = DateTime.now().add(Duration(seconds: seconds));
  while (!condition() && DateTime.now().isBefore(deadline)) {
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 25)),
    );
    await tester.pump();
  }
}

/// Taps the camera button and waits for the capture to resolve (a preview or
/// an error).
Future<void> _takePhoto(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('pod-take-photo')));
  await tester.pump();
  await _pumpUntil(
    tester,
    find.byWidgetPredicate(
      (w) =>
          w.key == const ValueKey('pod-evidence-preview') ||
          w.key == const ValueKey('pod-capture-error'),
    ),
  );
  await tester.pumpAndSettle();
}

/// Taps Submit and waits for [until] (default: the success panel). Submission
/// validates the evidence file with real IO first.
Future<void> _submit(WidgetTester tester, {Finder? until}) async {
  await tester.tap(find.byKey(const ValueKey('pod-submit')));
  await tester.pump();
  await _pumpUntil(
    tester,
    until ??
        find.byWidgetPredicate(
          (w) =>
              w.key == const ValueKey('pod-success') ||
              w.key == const ValueKey('pod-failure') ||
              w.key == const ValueKey('pod-evidence-problem') ||
              w.key == const ValueKey('pod-blocker'),
        ),
  );
  await tester.pumpAndSettle();
}

void main() {
  late Directory dir;
  late Directory previewDir; // where the preview store writes
  late File capture; // a raw capture fixture the camera fake returns
  late TempPodFileStore files;

  List<File> previewFiles() => previewDir.existsSync()
      ? previewDir.listSync().whereType<File>().toList()
      : const [];

  setUp(() {
    dir = Directory.systemTemp.createTempSync('pod_screen_test');
    previewDir = Directory('${dir.path}/pod_preview');
    files = TempPodFileStore(directory: () async => dir);
    capture = File('${dir.path}/capture.png');
  });

  tearDown(() {
    try {
      if (dir.existsSync()) dir.deleteSync(recursive: true);
    } on FileSystemException {
      // Windows can briefly hold a just-displayed image open; the OS cleans up.
    }
  });

  Future<void> writeCapture() async => capture.writeAsBytesSync(
    withTextChunk(await makePng(), 'GPS', '14N 121E'),
  );

  testWidgets('shows the parcel, courier identity, timestamp and GPS', (
    tester,
  ) async {
    await _pump(
      tester,
      service: _service(),
      location: _Location([_fix]),
      photos: _Photos(capture.path),
      files: files,
    );

    expect(find.byKey(const ValueKey('pod-preview-banner')), findsOneWidget);
    expect(find.text('TRK-42'), findsOneWidget);
    expect(find.text('Order reference: 42'), findsOneWidget);
    expect(find.text('Courier: rider@example.test'), findsOneWidget);
    expect(find.textContaining('Nov 14, 2026 10:30:05'), findsOneWidget);
    expect(find.text('14.167123, 121.243456'), findsOneWidget);
    expect(find.text('Accuracy ±8 m'), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(find.byKey(const ValueKey('pod-submit')))
          .onPressed,
      isNull,
    );
    expect(find.byKey(const ValueKey('pod-blocker')), findsOneWidget);
  });

  testWidgets('photo evidence is normalized into a preview-owned file', (
    tester,
  ) async {
    final service = _service();
    late Uint8List stored;
    await tester.runAsync(() async {
      await writeCapture();
    });
    final photos = _Photos(capture.path, deleteAfterUse: true);
    await _pump(
      tester,
      service: service,
      location: _Location([_fix]),
      photos: photos,
      files: files,
    );

    await _takePhoto(tester);

    expect(photos.captures, 1);
    expect(find.byKey(const ValueKey('pod-evidence-preview')), findsOneWidget);
    expect(find.text('Photo ready to submit'), findsOneWidget);

    // The raw capture (carrying a metadata chunk) was consumed and deleted; the
    // evidence is a fresh PNG the preview owns, without that metadata.
    expect(capture.existsSync(), isFalse);
    final stored0 = previewFiles();
    expect(stored0, hasLength(1));
    stored = stored0.single.readAsBytesSync();
    expect(pngChunks(stored), isNot(contains('tEXt')));
    expect(String.fromCharCodes(stored), isNot(contains('14N 121E')));

    await _submit(tester);
    expect(find.byKey(const ValueKey('pod-success')), findsOneWidget);
    expect(find.text('Reference: PREVIEW-0001'), findsOneWidget);
    expect(service.accepted.single.evidence.method, PodEvidenceMethod.photo);
  });

  testWidgets('a fixture capture is never deleted, even after normalizing', (
    tester,
  ) async {
    await tester.runAsync(() async {
      await writeCapture();
    });
    await _pump(
      tester,
      service: _service(),
      location: _Location([_fix]),
      photos: _Photos(capture.path), // deleteAfterUse: false
      files: files,
    );
    await _takePhoto(tester);
    expect(capture.existsSync(), isTrue);
  });

  testWidgets('replacing the photo deletes the previous preview file', (
    tester,
  ) async {
    await tester.runAsync(() async {
      await writeCapture();
    });
    await _pump(
      tester,
      service: _service(),
      location: _Location([_fix]),
      photos: _Photos(capture.path),
      files: files,
    );
    await _takePhoto(tester);
    final first = previewFiles().single.path;

    // Tapped in real time so the file deletion it triggers can finish; the
    // condition is polled, not slept on.
    await tester.runAsync(() async {
      await tester.tap(find.byKey(const ValueKey('pod-replace-evidence')));
      final deadline = DateTime.now().add(const Duration(seconds: 10));
      while (previewFiles().isNotEmpty && DateTime.now().isBefore(deadline)) {
        await Future<void>.delayed(const Duration(milliseconds: 25));
      }
    });
    await tester.pumpAndSettle();
    expect(previewFiles(), isEmpty);
    expect(File(first).existsSync(), isFalse);
    expect(find.byKey(const ValueKey('pod-take-photo')), findsOneWidget);
  });

  testWidgets(
    'a capture that is not an image shows an error and keeps nothing',
    (tester) async {
      await tester.runAsync(() async {
        capture.writeAsStringSync('definitely not a picture');
      });
      await _pump(
        tester,
        service: _service(),
        location: _Location([_fix]),
        photos: _Photos(capture.path),
        files: files,
      );
      await _takePhoto(tester);

      expect(find.byKey(const ValueKey('pod-capture-error')), findsOneWidget);
      expect(find.byKey(const ValueKey('pod-evidence-preview')), findsNothing);
      expect(previewFiles(), isEmpty);
    },
  );

  testWidgets('signature evidence: draw, save, preview, submit', (
    tester,
  ) async {
    final service = _service();
    await _pump(
      tester,
      service: service,
      location: _Location([_fix]),
      photos: _Photos(capture.path),
      files: files,
    );

    await tester.tap(find.text('Signature'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('signature-pad-surface')), findsOneWidget);

    // Empty pad: asked to sign first, nothing written.
    await tester.tap(find.byKey(const ValueKey('pod-use-signature')));
    await tester.pumpAndSettle();
    expect(find.text('Ask the recipient to sign first.'), findsOneWidget);
    expect(previewFiles(), isEmpty);

    await tester.drag(
      find.byKey(const ValueKey('signature-pad-surface')),
      const Offset(120, 30),
    );
    await tester.pump();

    await tester.tap(find.byKey(const ValueKey('pod-use-signature')));
    await _pumpUntil(tester, find.text('Signature ready to submit'));
    await tester.pumpAndSettle();

    expect(find.text('Signature ready to submit'), findsOneWidget);
    expect(previewFiles(), hasLength(1));

    await _submit(tester);
    expect(find.byKey(const ValueKey('pod-success')), findsOneWidget);
    expect(
      service.accepted.single.evidence.method,
      PodEvidenceMethod.signature,
    );
  });

  testWidgets(
    'GPS unavailable shows the unresolved exception and blocks completion',
    (tester) async {
      await tester.runAsync(() async {
        await writeCapture();
      });
      final service = _service();
      await _pump(
        tester,
        service: service,
        location: _Location([
          const PodGpsUnavailable(GpsProblem.permissionDenied),
          _fix,
        ]),
        photos: _Photos(capture.path),
        files: files,
      );

      expect(find.byKey(const ValueKey('pod-gps-unavailable')), findsOneWidget);
      expect(find.textContaining('Unresolved exception'), findsOneWidget);
      expect(find.textContaining('will not complete it'), findsOneWidget);

      await _takePhoto(tester);
      expect(
        tester
            .widget<FilledButton>(find.byKey(const ValueKey('pod-submit')))
            .onPressed,
        isNull,
      );
      expect(find.byKey(const ValueKey('pod-success')), findsNothing);
      expect(service.attempts, 0);

      await tester.tap(find.byKey(const ValueKey('pod-gps-retry')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('pod-gps-fix')), findsOneWidget);
      expect(
        tester
            .widget<FilledButton>(find.byKey(const ValueKey('pod-submit')))
            .onPressed,
        isNotNull,
      );
    },
  );

  testWidgets('a failed upload keeps one evidence file and retries once', (
    tester,
  ) async {
    await tester.runAsync(() async {
      await writeCapture();
    });
    final service = _service(failNext: 1);
    await _pump(
      tester,
      service: service,
      location: _Location([_fix]),
      photos: _Photos(capture.path),
      files: files,
    );
    await _takePhoto(tester);
    expect(previewFiles(), hasLength(1));

    await _submit(tester);
    expect(find.byKey(const ValueKey('pod-failure')), findsOneWidget);
    expect(find.text('Retry submission'), findsOneWidget);
    expect(find.byKey(const ValueKey('pod-evidence-preview')), findsOneWidget);
    expect(service.accepted, isEmpty);

    await _submit(tester, until: find.byKey(const ValueKey('pod-success')));
    expect(find.byKey(const ValueKey('pod-success')), findsOneWidget);
    expect(service.attempts, 2);
    expect(service.accepted, hasLength(1));
    expect(service.accepted.single.submissionId, 'key-1');
    // No leak and no duplicate: still exactly one evidence file.
    expect(previewFiles(), hasLength(1));
  });

  testWidgets('submission progress is shown and a double tap submits once', (
    tester,
  ) async {
    await tester.runAsync(() async {
      await writeCapture();
    });
    final service = MockProofOfDeliveryService(
      latency: const Duration(milliseconds: 900),
    );
    await _pump(
      tester,
      service: service,
      location: _Location([_fix]),
      photos: _Photos(capture.path),
      files: files,
    );
    await _takePhoto(tester);

    await tester.tap(find.byKey(const ValueKey('pod-submit')));
    await tester.pump();
    await _pumpUntil(tester, find.byKey(const ValueKey('pod-progress')));
    expect(find.byKey(const ValueKey('pod-progress')), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(find.byKey(const ValueKey('pod-submit')))
          .onPressed,
      isNull,
    );

    await _pumpUntil(tester, find.byKey(const ValueKey('pod-success')));
    await tester.pumpAndSettle();
    expect(service.attempts, 1);
    expect(find.byKey(const ValueKey('pod-success')), findsOneWidget);
  });

  testWidgets(
    'evidence deleted after capture shows a problem and blocks submit',
    (tester) async {
      await tester.runAsync(() async {
        await writeCapture();
      });
      final service = _service();
      await _pump(
        tester,
        service: service,
        location: _Location([_fix]),
        photos: _Photos(capture.path),
        files: files,
      );
      await _takePhoto(tester);

      previewFiles().single.deleteSync(); // the OS cleared the temp file

      await _submit(
        tester,
        until: find.byKey(const ValueKey('pod-evidence-problem')),
      );

      expect(
        find.byKey(const ValueKey('pod-evidence-problem')),
        findsOneWidget,
      );
      expect(find.textContaining('missing'), findsWidgets);
      expect(find.byKey(const ValueKey('pod-success')), findsNothing);
      expect(service.attempts, 0);
      // Offered a way out: Replace.
      expect(
        find.byKey(const ValueKey('pod-replace-evidence')),
        findsOneWidget,
      );
    },
  );

  testWidgets('leaving the screen deletes the preview evidence', (
    tester,
  ) async {
    await tester.runAsync(() async {
      await writeCapture();
    });
    await _pump(
      tester,
      service: _service(),
      location: _Location([_fix]),
      photos: _Photos(capture.path),
      files: files,
    );
    await _takePhoto(tester);
    expect(previewFiles(), hasLength(1));

    // Leave the screen.
    await tester.pumpWidget(const MaterialApp(home: SizedBox()));
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 200)),
    );
    expect(previewFiles(), isEmpty);
  });

  testWidgets('a capture finishing after the screen was left leaves no file', (
    tester,
  ) async {
    await tester.runAsync(() async {
      await writeCapture();
    });
    final gate = Completer<void>();
    await _pump(
      tester,
      service: _service(),
      location: _Location([_fix]),
      photos: _Photos(capture.path, completer: gate),
      files: files,
    );
    await tester.tap(find.byKey(const ValueKey('pod-take-photo')));
    await tester.pump();

    // The rider leaves while the camera is still open...
    await tester.pumpWidget(const MaterialApp(home: SizedBox()));
    // ...and the capture completes afterwards.
    await tester.runAsync(() async {
      gate.complete();
      await Future<void>.delayed(const Duration(milliseconds: 500));
    });
    expect(previewFiles(), isEmpty);
  });

  test('SignatureController: empty until drawn, clearable', () async {
    final controller = SignatureController();
    expect(controller.isEmpty, isTrue);
    expect(await controller.toPngBytes(size: const Size(100, 50)), isNull);

    controller
      ..startStroke(const Offset(5, 5))
      ..extendStroke(const Offset(40, 30))
      ..extendStroke(const Offset(80, 10));
    expect(controller.isEmpty, isFalse);

    controller.clear();
    expect(controller.isEmpty, isTrue);
  });

  testWidgets('the signature PNG export is a valid image of the drawn size', (
    tester,
  ) async {
    final controller = SignatureController()
      ..startStroke(const Offset(5, 5))
      ..extendStroke(const Offset(60, 30));
    final bytes = await tester.runAsync(
      () => controller.toPngBytes(size: const Size(100, 50), pixelRatio: 2),
    );
    expect(sniffImageFormat(bytes!), PodImageFormat.png);
    expect(pngSize(bytes), (200, 100));
  });
}
