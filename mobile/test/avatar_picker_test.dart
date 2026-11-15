// test/avatar_picker_test.dart
//
// S5.1: one avatar picker with camera/gallery/remove, initials fallback, and
// a path that is only kept when the change succeeds.

import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/services/media_picker.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/widgets/avatar_picker.dart';

// 1x1 transparent PNG: enough for Image.file to decode in a widget test.
final Uint8List _pngBytes = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
);

late Directory _tempDir;

File _avatarFile() {
  final file = File('${_tempDir.path}/avatar.png');
  file.writeAsBytesSync(_pngBytes);
  return file;
}

Widget _wrap(Widget child, AuthState auth) {
  return ChangeNotifierProvider<AuthState>.value(
    value: auth,
    child: MaterialApp(
      theme: AppTheme.light,
      home: Scaffold(body: Center(child: child)),
    ),
  );
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    _tempDir = Directory.systemTemp.createTempSync('zefanya_avatar_test_');
  });

  tearDown(() {
    try {
      if (_tempDir.existsSync()) _tempDir.deleteSync(recursive: true);
    } catch (_) {
      // Best-effort on Windows, where an open image handle can outlive the
      // test. The assertions above already passed.
    }
  });

  testWidgets('shows initials when no avatar is set', (tester) async {
    final auth = AuthState();
    auth.updateProfile(firstName: 'Maria', lastName: 'Clara');

    await tester.pumpWidget(_wrap(const AvatarPicker(), auth));
    await tester.pump();

    expect(find.text('M'), findsOneWidget);
    expect(
      find.byWidgetPredicate(
        (widget) =>
            widget is Semantics &&
            widget.properties.label == 'Add profile photo',
      ),
      findsOneWidget,
    );
  });

  testWidgets('menu offers camera and gallery, and remove only with a photo',
      (tester) async {
    final auth = AuthState();
    await tester.pumpWidget(
      _wrap(
        AvatarPicker(pickImage: (context, source) async => null),
        auth,
      ),
    );
    await tester.pump();

    await tester.tap(find.byType(AvatarPicker));
    await tester.pumpAndSettle();
    expect(find.text('Take photo'), findsOneWidget);
    expect(find.text('Choose from gallery'), findsOneWidget);
    expect(find.text('Remove photo'), findsNothing);

    await tester.tap(find.text('Choose from gallery'));
    await tester.pumpAndSettle();
    // Nothing was picked, so the sheet closed without changing anything.
    expect(auth.avatarPath, isNull);
  });

  testWidgets('removing a photo returns to the initials fallback',
      (tester) async {
    final file = _avatarFile();
    final auth = AuthState();
    auth.updateProfile(firstName: 'Maria', avatarPath: file.path);

    await tester.pumpWidget(_wrap(const AvatarPicker(), auth));
    await tester.pump();

    await tester.tap(find.byType(AvatarPicker));
    await tester.pumpAndSettle();
    expect(find.text('Remove photo'), findsOneWidget);

    await tester.tap(find.text('Remove photo'));
    await tester.pumpAndSettle();

    expect(auth.avatarPath, isNull);
    expect(find.text('M'), findsOneWidget);
  });

  testWidgets('a picked gallery image updates the profile', (tester) async {
    final file = _avatarFile();
    final auth = AuthState();
    ImageSource? requestedSource;
    var changed = 0;

    await tester.pumpWidget(
      _wrap(
        AvatarPicker(
          onChanged: () => changed++,
          pickImage: (context, source) async {
            requestedSource = source;
            return MediaResult(
              file: file,
              originalName: 'avatar.png',
              sizeBytes: file.lengthSync(),
              mimeType: 'image/png',
            );
          },
        ),
        auth,
      ),
    );
    await tester.pump();

    await tester.tap(find.byType(AvatarPicker));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Choose from gallery'));
    await tester.pumpAndSettle();

    expect(requestedSource, ImageSource.gallery);
    expect(auth.avatarPath, file.path);
    expect(changed, 1);
  });

  testWidgets('a refused pick leaves the previous photo in place',
      (tester) async {
    final file = _avatarFile();
    final auth = AuthState();
    auth.updateProfile(avatarPath: file.path);

    await tester.pumpWidget(
      _wrap(
        AvatarPicker(
          pickImage: (context, source) async {
            throw MediaRefusal(MediaProblem.tooLarge);
          },
        ),
        auth,
      ),
    );
    await tester.pump();

    await tester.tap(find.byType(AvatarPicker));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Take photo'));
    await tester.pumpAndSettle();

    expect(auth.avatarPath, file.path);
    expect(find.text(mediaProblemMessage(MediaProblem.tooLarge)),
        findsOneWidget);
  });

  testWidgets('AvatarDisplay is display-only but exposes an edit action',
      (tester) async {
    final auth = AuthState();
    var taps = 0;

    await tester.pumpWidget(
      _wrap(AvatarDisplay(onTap: () => taps++), auth),
    );
    await tester.pump();

    expect(find.byIcon(Icons.edit), findsOneWidget);
    expect(find.text('Take photo'), findsNothing);

    await tester.tap(find.byType(AvatarDisplay));
    expect(taps, 1);
  });
}
