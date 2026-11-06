// test/media_picker_test.dart
//
// S2.1 MEDIA: fakes for permission states and refusal handling of MediaPicker.

import 'dart:io';
//
// MediaPicker launches the platform picker, so a real camera/gallery cannot be
// opened in a widget test. Instead these tests cover the refusal mapping and
// the user-facing messages for each MediaProblem, which is what the UI layer
// reacts to when permission is denied or a file is refused.

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/services/media_picker.dart';

void main() {
  group('mediaProblemMessage', () {
    test('returns a non-empty, user-facing message for every problem', () {
      for (final problem in MediaProblem.values) {
        final message = mediaProblemMessage(problem);
        expect(message, isNotEmpty);
        expect(message, isA<String>());
      }
    });

    test('permission denial suggests opening settings', () {
      expect(
        mediaProblemMessage(MediaProblem.permissionDenied),
        contains('Open settings'),
      );
    });

    test('size refusal mentions the 5 MB limit', () {
      expect(
        mediaProblemMessage(MediaProblem.tooLarge),
        contains('5 MB'),
      );
    });
  });

  group('refusal mapping', () {
    test('isMediaRefusal is true only for _MediaRefusal', () {
      expect(isMediaRefusal(MediaRefusal(MediaProblem.permissionDenied)), isTrue);
      expect(isMediaRefusal(Exception('boom')), isFalse);
      expect(isMediaRefusal(null), isFalse);
    });

    test('mediaProblemOf extracts the problem from a refusal', () {
      expect(
        mediaProblemOf(MediaRefusal(MediaProblem.notAnImage)),
        MediaProblem.notAnImage,
      );
    });

    test('mediaProblemOf defaults to platformError for non-refusals', () {
      expect(
        mediaProblemOf(Exception('platform failure')),
        MediaProblem.platformError,
      );
    });
  });

  group('MediaResult', () {
    test('carries file, name, size and mime', () {
      final file = File.fromUri(Uri.file('avatar.jpg'));
      final result = MediaResult(
        file: file,
        originalName: 'avatar.jpg',
        sizeBytes: 1234,
        mimeType: 'image/jpeg',
      );
      expect(result.mimeType, 'image/jpeg');
      expect(result.sizeBytes, 1234);
      expect(result.originalName, 'avatar.jpg');
    });
  });
}
