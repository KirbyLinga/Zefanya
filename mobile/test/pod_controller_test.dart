// test/pod_controller_test.dart
//
// The Proof of Delivery state machine. Everything here is mocked: the service
// keeps submissions in memory and the location provider is a fake. No network is
// used and no parcel can be marked delivered. Evidence files are real PNGs so
// the validator runs for real.

import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/services/pod/pod_location.dart';
import 'package:zefanya/services/pod/pod_models.dart';
import 'package:zefanya/services/pod/proof_of_delivery_service.dart';
import 'package:zefanya/state/proof_of_delivery_controller.dart';

import 'support/pod_fixtures.dart';

class _FakeLocation implements PodLocationProvider {
  _FakeLocation(this.results);

  final List<PodGpsResult> results;
  int calls = 0;

  @override
  Future<PodGpsResult> currentFix() async =>
      results[calls < results.length ? calls++ : results.length - 1];
}

class _ThrowingLocation implements PodLocationProvider {
  @override
  Future<PodGpsResult> currentFix() => throw StateError('boom');
}

final _fix = PodGpsFix(
  latitude: 14.167,
  longitude: 121.243,
  accuracyMeters: 8,
  capturedAt: DateTime(2026, 11, 14, 10),
);

late Directory _dir;
int _fileCounter = 0;

Future<PodEvidence> _evidence(PodEvidenceMethod method) async {
  final file = File('${_dir.path}/e${_fileCounter++}.png')
    ..writeAsBytesSync(await makePng());
  return PodEvidence(method: method, path: file.path);
}

class _Rig {
  _Rig({
    PodLocationProvider? location,
    int failNext = 0,
    Duration latency = Duration.zero,
  }) : service = MockProofOfDeliveryService(
         latency: latency,
         clock: () => DateTime(2026, 11, 14, 10, 31),
         failNextSubmissions: failNext,
       ) {
    var n = 0;
    controller = ProofOfDeliveryController(
      jobId: '42',
      trackingNumber: 'TRK-42',
      courierLabel: 'rider@example.test',
      service: service,
      location: location ?? _FakeLocation([_fix]),
      onEvidenceReleased: (e) async => released.add(e),
      clock: () => DateTime(2026, 11, 14, 10, 30),
      idGenerator: () => 'key-${++n}',
    );
  }

  final MockProofOfDeliveryService service;
  late final ProofOfDeliveryController controller;
  final List<PodEvidence> released = [];
}

void main() {
  setUp(() => _dir = Directory.systemTemp.createTempSync('pod_controller'));
  tearDown(() {
    if (_dir.existsSync()) _dir.deleteSync(recursive: true);
  });

  group('evidence: photo OR signature', () {
    test('nothing can be submitted without evidence', () async {
      final r = _Rig();
      await r.controller.refreshGps();

      expect(r.controller.canSubmit, isFalse);
      expect(
        r.controller.submitBlocker,
        contains('photo or a recipient signature'),
      );
      expect(await r.controller.submit(), isFalse);
      expect(r.service.attempts, 0);
    });

    test('a photo alone is enough', () async {
      final r = _Rig();
      await r.controller.refreshGps();
      expect(
        await r.controller.setEvidence(
          await _evidence(PodEvidenceMethod.photo),
        ),
        isTrue,
      );

      expect(r.controller.canSubmit, isTrue);
      expect(await r.controller.submit(), isTrue);
      expect(
        r.service.accepted.single.evidence.method,
        PodEvidenceMethod.photo,
      );
    });

    test('a signature alone is enough', () async {
      final r = _Rig();
      await r.controller.refreshGps();
      await r.controller.setEvidence(
        await _evidence(PodEvidenceMethod.signature),
      );

      expect(await r.controller.submit(), isTrue);
      expect(
        r.service.accepted.single.evidence.method,
        PodEvidenceMethod.signature,
      );
    });

    test(
      'choosing the other method replaces the evidence; clearing removes it',
      () async {
        final r = _Rig();
        await r.controller.refreshGps();
        final photo = await _evidence(PodEvidenceMethod.photo);
        final signature = await _evidence(PodEvidenceMethod.signature);
        await r.controller.setEvidence(photo);
        await r.controller.setEvidence(signature);
        expect(r.controller.evidence, signature);

        await r.controller.clearEvidence();
        expect(r.controller.evidence, isNull);
        expect(r.controller.canSubmit, isFalse);
      },
    );
  });

  group('evidence validation (review F5)', () {
    test('a missing file is refused and cannot be submitted', () async {
      final r = _Rig();
      await r.controller.refreshGps();
      final ghost = PodEvidence(
        method: PodEvidenceMethod.photo,
        path: '${_dir.path}/nothing.png',
      );

      expect(await r.controller.setEvidence(ghost), isFalse);
      expect(r.controller.evidence, isNull);
      expect(r.controller.errorMessage, contains('missing'));
      expect(await r.controller.submit(), isFalse);
      expect(r.service.attempts, 0);
      expect(r.released, [ghost]); // the refused file is handed back
    });

    test('an empty or corrupt file is refused', () async {
      final r = _Rig();
      await r.controller.refreshGps();
      final empty = File('${_dir.path}/empty.png')..writeAsBytesSync([]);
      final text = File('${_dir.path}/text.png')..writeAsStringSync('hello');

      expect(
        await r.controller.setEvidence(
          PodEvidence(method: PodEvidenceMethod.photo, path: empty.path),
        ),
        isFalse,
      );
      expect(
        await r.controller.setEvidence(
          PodEvidence(method: PodEvidenceMethod.photo, path: text.path),
        ),
        isFalse,
      );
      expect(r.service.attempts, 0);
    });

    test('a refused replacement keeps the earlier valid evidence', () async {
      final r = _Rig();
      await r.controller.refreshGps();
      final good = await _evidence(PodEvidenceMethod.photo);
      await r.controller.setEvidence(good);

      final bad = PodEvidence(
        method: PodEvidenceMethod.photo,
        path: '${_dir.path}/nope.png',
      );
      expect(await r.controller.setEvidence(bad), isFalse);
      expect(r.controller.evidence, good);
      expect(r.controller.canSubmit, isTrue);
    });

    test(
      'evidence deleted after it was accepted blocks the submit and keeps the draft',
      () async {
        final r = _Rig();
        await r.controller.refreshGps();
        final e = await _evidence(PodEvidenceMethod.signature);
        await r.controller.setEvidence(e);
        File(e.path).deleteSync(); // e.g. the OS cleared the temp folder

        expect(await r.controller.submit(), isFalse);
        expect(r.service.attempts, 0);
        expect(r.controller.evidence, e); // kept so the screen can offer Replace
        expect(r.controller.evidenceProblem, isNotNull);
        expect(r.controller.canSubmit, isFalse);

        // Replacing it makes the proof submittable again.
        expect(
          await r.controller.setEvidence(
            await _evidence(PodEvidenceMethod.photo),
          ),
          isTrue,
        );
        expect(r.controller.evidenceProblem, isNull);
        expect(await r.controller.submit(), isTrue);
      },
    );
  });

  group('identity, timestamp, GPS and parcel reference', () {
    test('the submission carries all five approved elements', () async {
      final r = _Rig();
      await r.controller.refreshGps();
      await r.controller.setEvidence(await _evidence(PodEvidenceMethod.photo));
      await r.controller.submit();

      final s = r.service.accepted.single;
      expect(s.courierLabel, 'rider@example.test');
      expect(s.deviceCapturedAt, DateTime(2026, 11, 14, 10, 30));
      expect(s.gps.latitude, 14.167);
      expect(s.gps.longitude, 121.243);
      expect(s.gps.accuracyMeters, 8);
      expect(s.jobId, '42');
      expect(s.trackingNumber, 'TRK-42');
      expect(s.submissionId, 'key-1');
      expect(r.controller.receipt!.isPreview, isTrue);
      expect(r.controller.receipt!.recordedAt, DateTime(2026, 11, 14, 10, 31));
    });

    test('a mocked-location fix is carried through for review', () async {
      final mocked = PodGpsFix(
        latitude: 1,
        longitude: 2,
        accuracyMeters: 5,
        capturedAt: DateTime(2026),
        isMocked: true,
      );
      final r = _Rig(location: _FakeLocation([mocked]));
      await r.controller.refreshGps();
      await r.controller.setEvidence(await _evidence(PodEvidenceMethod.photo));
      expect(await r.controller.submit(), isTrue); // flagged, not blocked
      expect(r.service.accepted.single.gps.isMocked, isTrue);
    });
  });

  group('GPS unavailable or impossible is an unresolved exception', () {
    test('no fix blocks submission and sends nothing', () async {
      final r = _Rig(
        location: _FakeLocation([
          const PodGpsUnavailable(GpsProblem.servicesDisabled),
        ]),
      );
      await r.controller.refreshGps();
      await r.controller.setEvidence(await _evidence(PodEvidenceMethod.photo));

      expect(r.controller.gpsUnavailable, isTrue);
      expect(r.controller.canSubmit, isFalse);
      expect(r.controller.submitBlocker, contains('unresolved exception'));
      expect(await r.controller.submit(), isFalse);
      expect(r.service.attempts, 0);
      expect(r.controller.isSucceeded, isFalse);
    });

    test('a later successful GPS retry unblocks submission', () async {
      final r = _Rig(
        location: _FakeLocation([
          const PodGpsUnavailable(GpsProblem.timeout),
          _fix,
        ]),
      );
      await r.controller.refreshGps();
      await r.controller.setEvidence(await _evidence(PodEvidenceMethod.photo));
      expect(r.controller.canSubmit, isFalse);

      await r.controller.refreshGps();
      expect(r.controller.gpsUnavailable, isFalse);
      expect(await r.controller.submit(), isTrue);
    });

    test('a throwing location provider becomes the unavailable state', () async {
      final r = _Rig(location: _ThrowingLocation());
      await r.controller.refreshGps();
      expect(r.controller.gpsUnavailable, isTrue);
    });

    test('structurally impossible coordinates are treated as no fix', () async {
      final bads = [
        PodGpsFix(
          latitude: 91,
          longitude: 0,
          accuracyMeters: 5,
          capturedAt: DateTime(2026),
        ),
        PodGpsFix(
          latitude: 0,
          longitude: -181,
          accuracyMeters: 5,
          capturedAt: DateTime(2026),
        ),
        PodGpsFix(
          latitude: 0,
          longitude: 0,
          accuracyMeters: -1,
          capturedAt: DateTime(2026),
        ),
        PodGpsFix(
          latitude: double.nan,
          longitude: 0,
          accuracyMeters: 5,
          capturedAt: DateTime(2026),
        ),
        PodGpsFix(
          latitude: 0,
          longitude: 0,
          accuracyMeters: double.infinity,
          capturedAt: DateTime(2026),
        ),
      ];
      for (final bad in bads) {
        expect(bad.isStructurallyValid, isFalse);
        final r = _Rig(location: _FakeLocation([bad]));
        await r.controller.refreshGps();
        expect(r.controller.gpsUnavailable, isTrue);
      }
      expect(_fix.isStructurallyValid, isTrue);
      // No accuracy/freshness policy: a poor-but-valid fix is still a fix.
      final poor = PodGpsFix(
        latitude: 0,
        longitude: 0,
        accuracyMeters: 5000,
        capturedAt: DateTime(2000),
      );
      expect(poor.isStructurallyValid, isTrue);
    });
  });

  group('failed upload, retry and duplicate protection', () {
    test(
      'a failed upload keeps the draft; the retry re-sends the same attempt',
      () async {
        final r = _Rig(failNext: 1);
        await r.controller.refreshGps();
        final e = await _evidence(PodEvidenceMethod.photo);
        await r.controller.setEvidence(e);

        expect(await r.controller.submit(), isFalse);
        expect(r.controller.isFailed, isTrue);
        expect(r.controller.evidence, e); // draft kept
        expect(File(e.path).existsSync(), isTrue);

        expect(await r.controller.submit(), isTrue);
        expect(r.service.attempts, 2);
        expect(r.service.accepted, hasLength(1));
        expect(r.service.accepted.single.submissionId, 'key-1');
        // The retry neither released nor copied the evidence.
        expect(r.released, isEmpty);
        expect(_dir.listSync().whereType<File>(), hasLength(1));
      },
    );

    test('a second submit after success does nothing', () async {
      final r = _Rig();
      await r.controller.refreshGps();
      await r.controller.setEvidence(await _evidence(PodEvidenceMethod.photo));
      await r.controller.submit();

      expect(await r.controller.submit(), isFalse);
      expect(r.service.attempts, 1);
      expect(r.service.accepted, hasLength(1));
    });

    test('tapping twice while sending sends once', () async {
      final r = _Rig(latency: const Duration(milliseconds: 20));
      await r.controller.refreshGps();
      await r.controller.setEvidence(await _evidence(PodEvidenceMethod.photo));

      final first = r.controller.submit();
      final second = r.controller.submit();
      expect(await second, isFalse);
      expect(await first, isTrue);
      expect(r.service.attempts, 1);
      expect(r.service.accepted, hasLength(1));
    });

    test('evidence is locked while sending and after success', () async {
      final r = _Rig(latency: const Duration(milliseconds: 20));
      await r.controller.refreshGps();
      final photo = await _evidence(PodEvidenceMethod.photo);
      await r.controller.setEvidence(photo);

      final sending = r.controller.submit();
      expect(
        await r.controller.setEvidence(
          await _evidence(PodEvidenceMethod.signature),
        ),
        isFalse,
      );
      await r.controller.clearEvidence();
      expect(r.controller.evidence, photo);
      await sending;
      expect(
        await r.controller.setEvidence(
          await _evidence(PodEvidenceMethod.signature),
        ),
        isFalse,
      );
      expect(r.controller.evidence, photo);
    });

    test(
      'changing the evidence after a failure starts a new attempt and releases the old file',
      () async {
        final r = _Rig(failNext: 1);
        await r.controller.refreshGps();
        final first = await _evidence(PodEvidenceMethod.photo);
        await r.controller.setEvidence(first);
        await r.controller.submit(); // fails

        final second = await _evidence(PodEvidenceMethod.signature);
        await r.controller.setEvidence(second);
        expect(r.released, [first]);
        expect(await r.controller.submit(), isTrue);
        expect(r.service.accepted.single.submissionId, 'key-2');
        expect(r.service.accepted.single.evidence, second);
      },
    );

    PodSubmission make(String job, PodEvidence e) => PodSubmission(
      submissionId: 'same',
      jobId: job,
      trackingNumber: 'T',
      evidence: e,
      gps: _fix,
      courierLabel: 'r',
      deviceCapturedAt: DateTime(2026),
    );

    test('the mock service is idempotent per submission id', () async {
      final service = MockProofOfDeliveryService(latency: Duration.zero);
      final e = await _evidence(PodEvidenceMethod.photo);
      final a = await service.submit(make('1', e));
      final b = await service.submit(make('1', e));
      expect(identical(a, b), isTrue);
      expect(service.accepted, hasLength(1));
    });

    test(
      'the same id with a different payload is a conflict, not a silent replay',
      () async {
        final service = MockProofOfDeliveryService(latency: Duration.zero);
        final e1 = await _evidence(PodEvidenceMethod.photo);
        final e2 = await _evidence(PodEvidenceMethod.photo);
        await service.submit(make('1', e1));

        await expectLater(
          service.submit(make('2', e1)),
          throwsA(isA<PodConflict>()),
        );
        await expectLater(
          service.submit(make('1', e2)),
          throwsA(isA<PodConflict>()),
        );
        expect(service.accepted, hasLength(1));
      },
    );

    test(
      'a new service instance has no memory: replay protection is preview-only',
      () async {
        final e = await _evidence(PodEvidenceMethod.photo);
        final first = MockProofOfDeliveryService(latency: Duration.zero);
        final second = MockProofOfDeliveryService(latency: Duration.zero);
        await first.submit(make('1', e));
        await second.submit(make('1', e));
        expect(first.accepted, hasLength(1));
        expect(second.accepted, hasLength(1)); // not durable across instances
      },
    );
  });

  group('evidence files are released (review F6)', () {
    test('replace, clear and closing the screen hand the file back', () async {
      final r = _Rig();
      await r.controller.refreshGps();
      final a = await _evidence(PodEvidenceMethod.photo);
      final b = await _evidence(PodEvidenceMethod.signature);
      final c = await _evidence(PodEvidenceMethod.photo);

      await r.controller.setEvidence(a);
      await r.controller.setEvidence(b); // replace
      expect(r.released, [a]);
      await r.controller.clearEvidence();
      expect(r.released, [a, b]);
      await r.controller.setEvidence(c);
      r.controller.dispose(); // screen closed
      await Future<void>.delayed(Duration.zero);
      expect(r.released, [a, b, c]);
    });

    test('a refused capture is handed back, never left behind', () async {
      final r = _Rig();
      await r.controller.refreshGps();
      final junk = File('${_dir.path}/junk.png')..writeAsStringSync('x');
      final e = PodEvidence(method: PodEvidenceMethod.photo, path: junk.path);
      expect(await r.controller.setEvidence(e), isFalse);
      expect(r.released, [e]);
    });
  });

  group('the preview can never mark a real parcel delivered', () {
    test(
      'the POD service, models, evidence and controller import no network or repository code',
      () {
        final files = [
          'lib/services/pod/proof_of_delivery_service.dart',
          'lib/services/pod/pod_models.dart',
          'lib/services/pod/pod_evidence.dart',
          'lib/state/proof_of_delivery_controller.dart',
        ];
        const forbidden = [
          'package:http',
          'api_client',
          'repositories',
          'courier_repository',
          'courier_state',
          'order_repository',
        ];
        for (final path in files) {
          final imports = File(path)
              .readAsLinesSync()
              .where((line) => line.trimLeft().startsWith('import '))
              .join('\n');
          for (final word in forbidden) {
            expect(
              imports,
              isNot(contains(word)),
              reason: '$path must not import $word',
            );
          }
        }
      },
    );

    test('the live delivery path never imports the POD preview', () {
      for (final path in [
        'lib/data/repositories/courier_repository.dart',
        'lib/state/courier_state.dart',
      ]) {
        expect(
          File(path).readAsStringSync(),
          isNot(contains('services/pod/')),
          reason: '$path must stay disconnected from the preview',
        );
      }
    });

    test('the service reports itself as a preview', () {
      expect(
        MockProofOfDeliveryService(latency: Duration.zero).isPreview,
        isTrue,
      );
    });
  });
}
