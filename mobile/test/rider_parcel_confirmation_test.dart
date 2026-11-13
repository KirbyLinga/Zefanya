import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/courier_repository.dart';
import 'package:zefanya/state/courier_state.dart';

class _ParcelServer {
  _ParcelServer({required this.pickup})
    : status = pickup ? 'READY_FOR_PICKUP' : 'OUT_FOR_DELIVERY';

  final bool pickup;
  String status;
  bool assigned = true;
  bool jobsUnavailable = false;
  bool wrongParcel = false;
  int submissions = 0;
  int completionPosts = 0;
  int completionReads = 0;
  Map<String, Object?>? requestBody;
  final requestArrived = Completer<void>();
  final response = Completer<http.Response>();

  String get confirmedStatus => pickup ? 'PICKED_UP' : 'DELIVERY_FAILED';

  Map<String, Object?> get parcel => {
    'id': 12,
    'status': status,
    'tracking_number': 'TRK-12',
  };

  http.Response ok(Object data) => http.Response(
    jsonEncode({'success': true, 'data': data}),
    200,
    headers: {'content-type': 'application/json'},
  );

  http.Response error(int status, String message) =>
      http.Response(jsonEncode({'message': message}), status);

  CourierState createState({TokenStore? completionStore}) {
    final client = MockClient((request) async {
      switch (request.url.path) {
        case '/rider/jobs':
          if (jobsUnavailable) return error(503, 'Could not refresh jobs.');
          return ok({
            'pickups': pickup && assigned ? [parcel] : [],
            'deliveries': !pickup && assigned ? [parcel] : [],
          });
        case '/rider/scan/verify':
          return ok({
            'parcel': {'id': wrongParcel ? 99 : 12},
            'actions': [
              {
                'key': pickup ? 'confirm_pickup' : 'mark_failed',
                'verification_token': 'verified',
              },
              {'key': 'proof_of_delivery', 'verification_token': 'verified'},
            ],
          });
        case '/rider/pickups/12/confirm':
        case '/rider/deliveries/12/failed':
          submissions++;
          requestBody = (jsonDecode(request.body) as Map)
              .cast<String, Object?>();
          if (!requestArrived.isCompleted) requestArrived.complete();
          return response.future;
        case '/rider/deliveries/12/deliver':
          completionPosts++;
          requestArrived.complete();
          return response.future;
        case '/rider/deliveries/12/completion-status':
          completionReads++;
          return ok({'state': status == 'DELIVERED' ? 'completed' : 'open'});
        default:
          return ok(<String, Object?>{});
      }
    });
    final courier = CourierState(
      deliveryCompletionStore: completionStore,
      repository: LiveCourierRepository(
        ApiClient(
          baseUrl: 'https://api.zefanya.test',
          tokenStore: InMemoryTokenStore(),
          httpClient: client,
        ),
      ),
    );
    addTearDown(courier.dispose);
    return courier;
  }
}

void main() {
  test(
    'delivery reference survives restart and is reconciled without resubmission',
    () async {
      final store = InMemoryTokenStore();
      final server = _ParcelServer(pickup: false);
      final beforeRestart = server.createState(completionStore: store);
      beforeRestart.setRiderSession('first@zefanya.test');
      await beforeRestart.refreshJobs();
      final folder = Directory.systemTemp.createTempSync('zefanya-proof-test');
      final photo = File('${folder.path}/proof.jpg')
        ..writeAsBytesSync([255, 216, 255, 217]);
      addTearDown(() => folder.deleteSync(recursive: true));
      final submission = beforeRestart.submitDelivery(
        '12',
        proofPhotoPath: photo.path,
        recipientCategory: 'buyer',
      );
      await server.requestArrived.future;
      expect(jsonDecode((await store.read())!), {
        'account': 'first@zefanya.test',
        'parcels': ['12'],
      });
      server.status = 'DELIVERED';
      server.assigned = false;
      server.response.completeError(const SocketException('Response lost'));
      await expectLater(submission, throwsA(isA<NetworkException>()));

      final restarted = server.createState(completionStore: store);
      restarted.setRiderSession('first@zefanya.test');
      await restarted.refreshJobs();
      expect(restarted.unconfirmedDeliveryIds, ['12']);
      expect(restarted.jobById('12'), isNull);
      await expectLater(
        restarted.submitDelivery(
          '12',
          proofPhotoPath: photo.path,
          recipientCategory: 'buyer',
        ),
        throwsA(isA<ValidationException>()),
      );
      expect(await restarted.reconcileDeliveryCompletion('12'), isTrue);
      expect(restarted.unconfirmedDeliveryIds, isEmpty);
      expect(await store.read(), isNull);
      expect(server.completionPosts, 1);
      expect(server.completionReads, 1);
    },
  );

  test(
    'changing Rider accounts clears a previous unresolved reference',
    () async {
      final store = InMemoryTokenStore();
      await store.write(
        jsonEncode({
          'account': 'first@zefanya.test',
          'parcels': ['12'],
        }),
      );
      final courier = _ParcelServer(
        pickup: false,
      ).createState(completionStore: store);
      courier.setRiderSession('second@zefanya.test');
      await courier.refreshJobs();
      expect(courier.unconfirmedDeliveryIds, isEmpty);
      expect(await store.read(), isNull);
    },
  );

  for (final pickup in [true, false]) {
    final action = pickup ? 'pickup' : 'failed delivery';
    final before = pickup
        ? DeliveryMilestone.accepted
        : DeliveryMilestone.inTransit;
    final after = pickup
        ? DeliveryMilestone.pickedUp
        : DeliveryMilestone.failedDelivery;

    test('$action waits for the server and suppresses a duplicate', () async {
      final server = _ParcelServer(pickup: pickup);
      final courier = server.createState();
      await courier.refreshJobs();
      final result = courier.confirmParcelAction(
        '12',
        pickup: pickup,
        trackingNumber: 'TRK-12',
        failureReason: pickup ? null : 'Buyer refused',
        failureNotes: pickup ? null : 'Buyer declined at the door.',
      );
      await server.requestArrived.future;
      expect(courier.isParcelActionPending('12'), isTrue);
      expect(courier.milestoneFor('12'), before);
      expect(await courier.confirmParcelAction('12', pickup: pickup), isFalse);
      expect(server.submissions, 1);
      server.status = server.confirmedStatus;
      server.response.complete(server.ok(server.parcel));
      expect(await result, isTrue);
      expect(courier.isParcelActionPending('12'), isFalse);
      expect(courier.milestoneFor('12'), after);
      if (!pickup) {
        expect(server.requestBody, {
          'verification_token': 'verified',
          'failure_reason': 'refused_by_recipient',
          'failure_notes': 'Buyer declined at the door.',
        });
      }
    });

    test('$action rejection never advances the local milestone', () async {
      final server = _ParcelServer(pickup: pickup);
      final courier = server.createState();
      await courier.refreshJobs();
      server.jobsUnavailable = true;
      server.response.complete(server.error(409, 'The assignment changed.'));
      await expectLater(
        courier.confirmParcelAction('12', pickup: pickup),
        throwsA(isA<RequestRejectedException>()),
      );
      expect(courier.milestoneFor('12'), before);
      expect(courier.isParcelActionUnconfirmed('12'), isFalse);
    });

    test('$action with a lost response adopts the confirmed job', () async {
      final server = _ParcelServer(pickup: pickup);
      final courier = server.createState();
      await courier.refreshJobs();
      final result = courier.confirmParcelAction('12', pickup: pickup);
      await server.requestArrived.future;
      server.status = server.confirmedStatus;
      server.response.completeError(const SocketException('Response lost'));
      expect(await result, isTrue);
      expect(courier.milestoneFor('12'), after);
      expect(server.submissions, 1);
    });

    test('$action remains locked when the request and refresh fail', () async {
      final server = _ParcelServer(pickup: pickup);
      final courier = server.createState();
      await courier.refreshJobs();
      final result = courier.confirmParcelAction('12', pickup: pickup);
      await server.requestArrived.future;
      server.jobsUnavailable = true;
      server.response.completeError(const SocketException('Request not sent'));
      await expectLater(result, throwsA(isA<NetworkException>()));
      expect(courier.milestoneFor('12'), before);
      expect(courier.isParcelActionUnconfirmed('12'), isTrue);
      expect(await courier.confirmParcelAction('12', pickup: pickup), isFalse);
      expect(server.submissions, 1);
      server.jobsUnavailable = false;
      await courier.refreshJobs();
      expect(courier.isParcelActionUnconfirmed('12'), isFalse);
      expect(courier.milestoneFor('12'), before);
    });
  }

  test('a scan for a different parcel never submits pickup', () async {
    final server = _ParcelServer(pickup: true)..wrongParcel = true;
    final courier = server.createState();
    await courier.refreshJobs();
    await expectLater(
      courier.confirmParcelAction('12', pickup: true),
      throwsA(isA<ValidationException>()),
    );
    expect(server.submissions, 0);
    expect(courier.milestoneFor('12'), DeliveryMilestone.accepted);
  });

  test(
    'a revoked pickup assignment disappears when the jobs refresh',
    () async {
      final server = _ParcelServer(pickup: true);
      final courier = server.createState();
      await courier.refreshJobs();
      final result = courier.confirmParcelAction('12', pickup: true);
      await server.requestArrived.future;
      server.assigned = false;
      server.response.complete(server.error(403, 'Pickup was reassigned.'));
      await expectLater(result, throwsA(isA<ForbiddenException>()));
      expect(courier.jobById('12'), isNull);
      expect(server.submissions, 1);
    },
  );
}
