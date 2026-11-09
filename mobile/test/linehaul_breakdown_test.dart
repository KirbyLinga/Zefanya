import 'dart:convert';
import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/linehaul_repository.dart';
import 'package:zefanya/data/repositories/linehaul_vehicle_repository.dart';
import 'package:zefanya/screens/courier/linehaul_delay_dialog.dart';
import 'package:zefanya/services/courier_document_picker.dart';

class _Picker implements CourierDocumentPicker {
  CourierDocument? photo = CourierDocument(
    'engine.jpg',
    Uint8List.fromList([255, 216, 255, 217]),
  );
  @override
  Future<CourierDocument?> pick({required bool camera}) async => photo;
}

Future<void> _mount(
  WidgetTester tester,
  _Picker picker,
  List<String> bodies,
) async {
  tester.view.physicalSize = const Size(360, 640);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final repository = LinehaulRepository(
    ApiClient(
      baseUrl: 'https://example.test/api',
      tokenStore: InMemoryTokenStore(),
      httpClient: MockClient((request) async {
        expectSync(request.method, 'POST');
        expectSync(request.url.path, '/api/courier/trips/12/breakdown');
        expectSync(
          request.headers['content-type'],
          contains('multipart/form-data'),
        );
        bodies.add(utf8.decode(request.bodyBytes, allowMalformed: true));
        return http.Response(
          jsonEncode(
            bodies.length == 1
                ? {'message': 'Upload interrupted. Try again.'}
                : {
                    'data': {
                      'id': 1,
                      'type': 'vehicle_breakdown',
                      'photo_received': true,
                      'delay': {
                        'reason': 'Vehicle breakdown: Engine stopped',
                        'new_eta': DateTime.now()
                            .add(const Duration(hours: 1))
                            .toUtc()
                            .toIso8601String(),
                        'reported_at': DateTime.now().toUtc().toIso8601String(),
                      },
                    },
                  },
          ),
          bodies.length == 1 ? 503 : 200,
          headers: {'content-type': 'application/json'},
        );
      }),
    ),
  );
  await tester.pumpWidget(
    MaterialApp(
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(
          context,
        ).copyWith(textScaler: const TextScaler.linear(1.3)),
        child: child!,
      ),
      home: Builder(
        builder: (context) => Scaffold(
          body: TextButton(
            onPressed: () => showDialog<bool>(
              context: context,
              barrierDismissible: false,
              builder: (_) => LinehaulDelayDialog(
                trip: const LinehaulTrip(
                  id: '12',
                  status: 'In Transit',
                  origin: 'Pila',
                  destination: 'Calamba',
                  items: [],
                  offerVersion: 3,
                ),
                repository: repository,
                breakdown: true,
                photoPicker: picker,
              ),
            ),
            child: const Text('Open breakdown'),
          ),
        ),
      ),
    ),
  );
  await tester.tap(find.text('Open breakdown'));
  await tester.pumpAndSettle();
}

Future<void> _tapContent(WidgetTester tester, String label) async {
  await tester.scrollUntilVisible(
    find.text(label),
    100,
    scrollable: find
        .descendant(
          of: find.byType(AlertDialog),
          matching: find.byType(Scrollable),
        )
        .first,
  );
  await tester.pumpAndSettle();
  await tester.tap(find.text(label));
  await tester.pumpAndSettle();
}

void main() {
  test(
    'breakdown capability defaults hidden and rejects malformed or non-transit flags',
    () {
      final trip = <String, Object?>{
        'id': 12,
        'status': 'In Transit',
        'items': [],
      };
      expect(LinehaulTrip.fromJson(trip).canReportBreakdown, false);
      expect(
        LinehaulTrip.fromJson({
          ...trip,
          'can_report_breakdown': true,
        }).canReportBreakdown,
        true,
      );
      for (final bad in [
        {'can_report_breakdown': 'true'},
        {'can_report_breakdown': true, 'status': 'Completed'},
      ]) {
        expect(
          () => LinehaulTrip.fromJson({...trip, ...bad}),
          throwsA(isA<MalformedResponseException>()),
        );
      }
    },
  );

  testWidgets(
    'breakdown requires photo and retains exact fields/file for multipart retry',
    (tester) async {
      final bodies = <String>[];
      await _mount(tester, _Picker(), bodies);
      await tester.enterText(
        find.byKey(const ValueKey('delay-reason')),
        'Engine stopped',
      );
      await _tapContent(tester, '1 hour from now');
      await tester.tap(find.text('Send breakdown report'));
      await tester.pumpAndSettle();
      expect(
        find.text('Add a photo of the vehicle breakdown.'),
        findsOneWidget,
      );
      expect(bodies, isEmpty);
      await _tapContent(tester, 'Choose photo');
      expect(find.text('Photo selected: engine.jpg'), findsOneWidget);
      await tester.tap(find.text('Send breakdown report'));
      await tester.pumpAndSettle();
      expect(find.text('Report a breakdown'), findsOneWidget);
      expect(find.text('Upload interrupted. Try again.'), findsOneWidget);
      await tester.tap(find.text('Send breakdown report'));
      await tester.pumpAndSettle();
      expect(find.text('Report a breakdown'), findsNothing);
      expect(bodies.length, 2);
      for (final body in bodies) {
        expect(body, contains('name="photo"; filename="engine.jpg"'));
        expect(body, contains('name="reason"\r\n\r\nEngine stopped'));
        expect(body, contains('name="offer_version"\r\n\r\n3'));
      }
      String eta(String body) =>
          body.split('name="new_eta"\r\n\r\n').last.split('\r\n').first;
      expect(eta(bodies.first), eta(bodies.last));
      expect(DateTime.parse(eta(bodies.first)).isUtc, true);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'PDF is refused and selected photo alone receives discard protection',
    (tester) async {
      final picker = _Picker()
        ..photo = CourierDocument('paper.pdf', Uint8List.fromList([1, 2]));
      final bodies = <String>[];
      await _mount(tester, picker, bodies);
      await _tapContent(tester, 'Choose photo');
      expect(
        find.text('Choose a JPG or PNG photo up to 5 MB.'),
        findsOneWidget,
      );
      picker.photo = CourierDocument('engine.png', Uint8List.fromList([1, 2]));
      await _tapContent(tester, 'Take photo');
      await tester.tap(find.text('Keep trip unchanged'));
      await tester.pumpAndSettle();
      expect(find.text('Discard changes?'), findsOneWidget);
      await tester.tap(find.text('Stay'));
      await tester.pumpAndSettle();
      expect(find.text('Photo selected: engine.png'), findsOneWidget);
      expect(bodies, isEmpty);
      expect(tester.takeException(), isNull);
    },
  );
}
