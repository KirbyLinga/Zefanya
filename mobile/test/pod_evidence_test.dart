// test/pod_evidence_test.dart
//
// Evidence validation and normalization (review finding F5/F6): a missing,
// empty, corrupt or unsupported file is refused, a photo is re-encoded from
// pixels so capture metadata does not survive, and the preview-owned file store
// only ever deletes its own files.

import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/screens/courier/pod/pod_sources.dart';
import 'package:zefanya/services/pod/pod_evidence.dart';
import 'package:zefanya/services/pod/pod_models.dart';

import 'support/pod_fixtures.dart';

void main() {
  late Directory dir;

  setUp(() => dir = Directory.systemTemp.createTempSync('pod_evidence_test'));
  tearDown(() {
    if (dir.existsSync()) dir.deleteSync(recursive: true);
  });

  PodEvidence ev(String path, [PodEvidenceMethod m = PodEvidenceMethod.photo]) =>
      PodEvidence(method: m, path: path);

  group('FilePodEvidenceValidator', () {
    const validator = FilePodEvidenceValidator();

    test('accepts a real PNG as a photo and as a signature', () async {
      final file = File('${dir.path}/a.png')..writeAsBytesSync(await makePng());
      expect(await validator.validate(ev(file.path)), isNull);
      expect(
        await validator.validate(ev(file.path, PodEvidenceMethod.signature)),
        isNull,
      );
    });

    test('a missing file is refused', () async {
      expect(
        await validator.validate(ev('${dir.path}/gone.png')),
        EvidenceProblem.missing,
      );
    });

    test('a deleted file is refused (evidence removed after capture)', () async {
      final file = File('${dir.path}/b.png')..writeAsBytesSync(await makePng());
      expect(await validator.validate(ev(file.path)), isNull);
      file.deleteSync();
      expect(await validator.validate(ev(file.path)), EvidenceProblem.missing);
    });

    test('an empty file is refused', () async {
      final file = File('${dir.path}/empty.png')..writeAsBytesSync([]);
      expect(await validator.validate(ev(file.path)), EvidenceProblem.empty);
    });

    test('text and unknown formats are refused', () async {
      final file = File('${dir.path}/note.png')
        ..writeAsStringSync('not an image at all');
      expect(
        await validator.validate(ev(file.path)),
        EvidenceProblem.unsupportedFormat,
      );
    });

    test('a corrupt image with a valid header is refused', () async {
      final bytes = await makePng();
      final corrupt = Uint8List.fromList([
        ...bytes.sublist(0, 24),
        ...List.filled(16, 0x7F),
      ]);
      final file = File('${dir.path}/corrupt.png')..writeAsBytesSync(corrupt);
      expect(await validator.validate(ev(file.path)), EvidenceProblem.unreadable);
    });

    test('a JPEG header with garbage is refused as unreadable', () async {
      final file = File('${dir.path}/fake.jpg')
        ..writeAsBytesSync([0xFF, 0xD8, 0xFF, 0xE0, 1, 2, 3, 4, 5, 6, 7, 8]);
      expect(await validator.validate(ev(file.path)), EvidenceProblem.unreadable);
    });

    test('a signature must be a PNG', () async {
      final file = File('${dir.path}/sig.jpg')
        ..writeAsBytesSync([0xFF, 0xD8, 0xFF, 0xE0, 1, 2, 3, 4, 5, 6, 7, 8]);
      expect(
        await validator.validate(ev(file.path, PodEvidenceMethod.signature)),
        EvidenceProblem.unsupportedFormat,
      );
    });

    test('a file over 5 MB is refused', () async {
      final big = File('${dir.path}/big.png')
        ..writeAsBytesSync(Uint8List(kPodMaxEvidenceBytes + 1));
      expect(await validator.validate(ev(big.path)), EvidenceProblem.tooLarge);
    });
  });

  group('sniffImageFormat', () {
    test('recognises the three supported formats', () {
      expect(
        sniffImageFormat(Uint8List.fromList([0xFF, 0xD8, 0xFF, 0xE0])),
        PodImageFormat.jpeg,
      );
      expect(
        sniffImageFormat(
          Uint8List.fromList([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]),
        ),
        PodImageFormat.png,
      );
      expect(
        sniffImageFormat(
          Uint8List.fromList([
            0x52, 0x49, 0x46, 0x46, 0, 0, 0, 0, 0x57, 0x45, 0x42, 0x50, //
          ]),
        ),
        PodImageFormat.webp,
      );
      expect(sniffImageFormat(Uint8List.fromList([1, 2, 3])), isNull);
    });
  });

  group('normalizeToPng', () {
    test('drops capture metadata: only pixel chunks survive', () async {
      final original = withTextChunk(
        await makePng(),
        'GPSLatitude',
        '14.167 N, 121.243 E',
      );
      expect(pngChunks(original), contains('tEXt'));

      final normalized = await normalizeToPng(original);

      expect(sniffImageFormat(normalized), PodImageFormat.png);
      expect(pngChunks(normalized), isNot(contains('tEXt')));
      expect(String.fromCharCodes(normalized), isNot(contains('GPSLatitude')));
      expect(pngChunks(normalized), containsAll(['IHDR', 'IDAT', 'IEND']));
    });

    test('bounds the longest edge and keeps the aspect ratio', () async {
      final wide = await makePng(width: 400, height: 200);
      final (w, h) = pngSize(await normalizeToPng(wide, maxSide: 100));
      expect(w, 100);
      expect(h, 50);

      final tall = await makePng(width: 200, height: 400);
      final (tw, th) = pngSize(await normalizeToPng(tall, maxSide: 100));
      expect(th, 100);
      expect(tw, 50);

      final small = await makePng(width: 40, height: 30);
      expect(pngSize(await normalizeToPng(small, maxSide: 100)), (40, 30));
    });

    test('refuses empty, unsupported and undecodable input', () async {
      expect(
        () => normalizeToPng(Uint8List(0)),
        throwsA(
          isA<PodEvidenceException>().having(
            (e) => e.problem,
            'problem',
            EvidenceProblem.empty,
          ),
        ),
      );
      expect(
        () => normalizeToPng(Uint8List.fromList([1, 2, 3, 4])),
        throwsA(isA<PodEvidenceException>()),
      );
      expect(
        () => normalizeToPng(
          Uint8List.fromList([0xFF, 0xD8, 0xFF, 0xE0, 9, 9, 9, 9, 9, 9, 9, 9]),
        ),
        throwsA(
          isA<PodEvidenceException>().having(
            (e) => e.problem,
            'problem',
            EvidenceProblem.unreadable,
          ),
        ),
      );
    });
  });

  group('TempPodFileStore (preview-owned files)', () {
    test('deletes only files it created', () async {
      final store = TempPodFileStore(directory: () async => dir);
      final foreign = File('${dir.path}/foreign.jpg')..writeAsBytesSync([1, 2]);

      final owned = await store.savePhotoPng(await makePng(), jobId: '9');
      expect(File(owned).existsSync(), isTrue);
      expect(store.owns(owned), isTrue);
      expect(store.owns(foreign.path), isFalse);

      await store.delete(foreign.path); // not ours: untouched
      expect(foreign.existsSync(), isTrue);

      await store.delete(owned);
      expect(File(owned).existsSync(), isFalse);
      expect(store.owns(owned), isFalse);
      await store.delete(owned); // safe twice
    });

    test('deleteAll removes everything it still owns and nothing else', () async {
      final store = TempPodFileStore(directory: () async => dir);
      final other = File('${dir.path}/pod_preview/not_ours.png')
        ..createSync(recursive: true);
      final a = await store.savePhotoPng(await makePng(), jobId: '1');
      final b = await store.saveSignaturePng(await makePng(), jobId: '1');

      await store.deleteAll();

      expect(File(a).existsSync(), isFalse);
      expect(File(b).existsSync(), isFalse);
      expect(other.existsSync(), isTrue);
    });
  });
}
