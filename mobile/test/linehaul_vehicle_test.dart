import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/linehaul_vehicle_repository.dart';
import 'package:zefanya/screens/courier/linehaul_vehicle_screen.dart';
import 'package:zefanya/services/courier_document_picker.dart';

const _png =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';

Map<String, Object?> _profile({String status = 'pending'}) => {
  'version': 1,
  'plate_number': 'NAB4521',
  'license_no': 'N02-15-345678',
  'license_expiry': '2035-07-15',
  'license_codes': ['B', 'B2', 'C'],
  'vehicle_type': '4-Wheeler Closed Van Truck',
  'capacity_kg': '3000.00',
  'review_status': status,
  'review_note': null,
  'documents': {
    for (final key in ['drivers_license', 'or_cr', 'nbi_clearance'])
      key: {'mime': 'image/png', 'size': 68},
  },
};

class _Picker implements CourierDocumentPicker {
  bool deny = false;
  final cameras = <bool>[];
  @override
  Future<CourierDocument?> pick({required bool camera}) async {
    cameras.add(camera);
    if (deny) throw Exception('Permission denied');
    return CourierDocument('selected.png', base64Decode(_png));
  }
}

class _Backend {
  Map<String, Object?>? profile;
  List<String>? vehicleTypes;
  bool setup = false;
  bool editable = true;
  bool refuse = false;
  String? upload;
  final requests = <http.Request>[];
  Future<http.Response> handle(http.Request request) async {
    requests.add(request);
    if (request.url.path.contains('/documents/')) {
      return http.Response.bytes(
        base64Decode(_png),
        200,
        headers: {'content-type': 'image/png'},
      );
    }
    if (request.method == 'POST') {
      upload = latin1.decode(request.bodyBytes);
      if (refuse) {
        return http.Response(
          jsonEncode({
            'message': 'Validation failed',
            'errors': {
              'plate_number': ['This plate number is already registered.'],
            },
          }),
          422,
          headers: {'content-type': 'application/json'},
        );
      }
      profile = _profile();
    }
    return http.Response(
      jsonEncode({
        'data': {
          'setup_required': setup,
          'editable': editable,
          'profile': profile,
          if (vehicleTypes != null) 'vehicle_types': vehicleTypes,
        },
      }),
      200,
      headers: {'content-type': 'application/json'},
    );
  }
}

Future<void> _mount(
  WidgetTester tester,
  _Backend backend,
  _Picker picker, {
  bool route = false,
}) async {
  tester.view.physicalSize = const Size(360, 640);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final tokens = InMemoryTokenStore();
  await tokens.write('private-token');
  final repository = LinehaulVehicleRepository(
    ApiClient(
      baseUrl: 'https://example.test/api',
      tokenStore: tokens,
      httpClient: MockClient(backend.handle),
    ),
  );
  final screen = LinehaulVehicleScreen(repository: repository, picker: picker);
  await tester.pumpWidget(
    MaterialApp(
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(
          context,
        ).copyWith(textScaler: const TextScaler.linear(1.3)),
        child: child!,
      ),
      home: route
          ? Builder(
              builder: (context) => Scaffold(
                body: TextButton(
                  onPressed: () => Navigator.push(
                    context,
                    MaterialPageRoute<void>(builder: (_) => screen),
                  ),
                  child: const Text('Open records'),
                ),
              ),
            )
          : screen,
    ),
  );
  if (route) await tester.tap(find.text('Open records'));
  await tester.pumpAndSettle();
}

Future<void> _show(WidgetTester tester, Finder finder) async {
  await tester.scrollUntilVisible(
    finder,
    180,
    scrollable: find.byType(Scrollable).first,
  );
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('Van is selectable only when advertised by the server', (
    tester,
  ) async {
    final backend = _Backend();
    await _mount(tester, backend, _Picker());
    await _show(tester, find.text('Add vehicle records'));
    await tester.tap(find.text('Add vehicle records'));
    await tester.pumpAndSettle();
    final dropdown = find.byType(DropdownButtonFormField<String>);
    await _show(tester, dropdown);
    await tester.tap(dropdown);
    await tester.pumpAndSettle();
    expect(find.text('Van'), findsNothing);
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pumpAndSettle();

    backend.vehicleTypes = [
      'Van',
      '4-Wheeler Closed Van Truck',
      '6-Wheeler Truck',
      '10-Wheeler Truck',
    ];
    await _mount(tester, backend, _Picker());
    await _show(tester, find.text('Add vehicle records'));
    await tester.tap(find.text('Add vehicle records'));
    await tester.pumpAndSettle();
    await _show(tester, dropdown);
    await tester.tap(dropdown);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Van'));
    await tester.pumpAndSettle();
    expect(find.text('Van'), findsOneWidget);
    expect(backend.upload, isNull);
    expect(tester.takeException(), isNull);
  });

  test('malformed vehicle records fail closed', () {
    final valid = {
      'setup_required': false,
      'editable': true,
      'profile': _profile(),
    };
    expect(
      LinehaulVehicle.fromJson(valid).profile?['review_status'],
      'pending',
    );
    for (final bad in [
      {'version': '1'},
      {'vehicle_type': 'Imaginary truck'},
      {
        'license_codes': ['A'],
      },
      {'review_status': 'verified'},
      {'documents': {}},
      {'capacity_kg': 'NaN'},
    ]) {
      expect(
        () => LinehaulVehicle.fromJson({
          ...valid,
          'profile': {..._profile(), ...bad},
        }),
        throwsA(isA<MalformedResponseException>()),
      );
    }
  });

  testWidgets('setup pending never offers a fake upload', (tester) async {
    final backend = _Backend()
      ..setup = true
      ..editable = false;
    final picker = _Picker();
    await _mount(tester, backend, picker);
    expect(find.text('Setup pending'), findsOneWidget);
    expect(find.text('Add vehicle records'), findsNothing);
    expect(find.text('Submit for review'), findsNothing);
    expect(picker.cameras, isEmpty);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'saved records and private image preview fit narrow large-text screen',
    (tester) async {
      final backend = _Backend()
        ..profile = _profile(status: 'approved')
        ..editable = false;
      await _mount(tester, backend, _Picker());
      expect(find.text('Approved'), findsOneWidget);
      expect(find.text('Edit records'), findsNothing);
      await _show(tester, find.byKey(const ValueKey('view-drivers_license')));
      await tester.tap(find.byKey(const ValueKey('view-drivers_license')));
      await tester.pumpAndSettle();
      expect(find.byType(InteractiveViewer), findsOneWidget);
      expect(
        backend.requests.last.url.path,
        '/api/courier/vehicle/documents/drivers_license',
      );
      expect(
        backend.requests.last.headers['authorization'],
        'Bearer private-token',
      );
      await tester.tap(find.text('Close'));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'dirty text requires explicit discard and keep editing preserves it',
    (tester) async {
      final backend = _Backend()..profile = _profile();
      await _mount(tester, backend, _Picker(), route: true);
      await tester.tap(find.text('Edit records'));
      await _show(tester, find.byKey(const ValueKey('Plate number')));
      await tester.enterText(
        find.byKey(const ValueKey('Plate number')),
        'NEW1234',
      );
      await tester.pumpAndSettle();
      await tester.pageBack();
      await tester.pumpAndSettle();
      expect(find.text('Discard changes?'), findsOneWidget);
      await tester.tap(find.text('Keep editing'));
      await tester.pumpAndSettle();
      expect(find.text('NEW1234'), findsOneWidget);
      await tester.pageBack();
      await tester.pumpAndSettle();
      await tester.tap(find.text('Discard'));
      await tester.pumpAndSettle();
      expect(find.text('Open records'), findsOneWidget);
      expect(
        backend.requests.where((request) => request.method == 'POST'),
        isEmpty,
      );
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'camera permission failure stays editable and shows a recovery message',
    (tester) async {
      final backend = _Backend()..profile = _profile();
      final picker = _Picker()..deny = true;
      await _mount(tester, backend, picker);
      await tester.tap(find.text('Edit records'));
      await _show(tester, find.byKey(const ValueKey('camera-drivers_license')));
      await tester.tap(find.byKey(const ValueKey('camera-drivers_license')));
      await tester.pumpAndSettle();
      await tester.drag(find.byType(ListView), const Offset(0, 2400));
      await tester.pumpAndSettle();
      expect(find.textContaining('Check permission'), findsOneWidget);
      expect(picker.cameras, [true]);
      expect(
        backend.requests.where((request) => request.method == 'POST'),
        isEmpty,
      );
      expect(tester.takeException(), isNull);
    },
  );

  for (final refuse in [false, true]) {
    testWidgets(
      'capacity and document edit sends multipart bytes; refusal=$refuse',
      (tester) async {
        final backend = _Backend()
          ..profile = _profile()
          ..refuse = refuse;
        final picker = _Picker();
        await _mount(tester, backend, picker);
        await tester.tap(find.text('Edit records'));
        await _show(tester, find.byKey(const ValueKey('Load capacity (kg)')));
        await tester.enterText(
          find.byKey(const ValueKey('Load capacity (kg)')),
          '2500',
        );
        await _show(tester, find.byKey(const ValueKey('file-or_cr')));
        await tester.tap(find.byKey(const ValueKey('file-or_cr')));
        await tester.pumpAndSettle();
        await _show(tester, find.text('Submit for review'));
        await tester.tap(find.text('Submit for review'));
        await tester.pumpAndSettle();
        expect(picker.cameras, [false]);
        expect(backend.upload, contains('name="capacity_kg"\r\n\r\n2500'));
        expect(backend.upload, contains('name="version"\r\n\r\n1'));
        expect(
          backend.upload,
          contains('name="or_cr"; filename="selected.png"'),
        );
        expect(
          backend.requests.last.headers['authorization'],
          'Bearer private-token',
        );
        expect(
          backend.requests.last.headers['content-type'],
          startsWith('multipart/form-data; boundary='),
        );
        await tester.drag(find.byType(ListView), const Offset(0, 3000));
        await tester.pumpAndSettle();
        if (refuse) {
          expect(
            find.text('This plate number is already registered.'),
            findsOneWidget,
          );
        } else {
          expect(find.text('Pending review'), findsOneWidget);
          expect(find.text('Edit records'), findsOneWidget);
        }
        expect(tester.takeException(), isNull);
      },
    );
  }
}
