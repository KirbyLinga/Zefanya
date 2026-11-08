import '../api/api_client.dart';
import '../api/api_exception.dart';

class LinehaulTrip {
  const LinehaulTrip({
    required this.id,
    required this.status,
    required this.origin,
    required this.destination,
    required this.items,
    this.seal,
  });

  final String id;
  final String status;
  final String origin;
  final String destination;
  final String? seal;
  final List<LinehaulParcel> items;

  factory LinehaulTrip.fromJson(Map<String, Object?> data) {
    final items = data['items'];
    if (data['id'] == null ||
        items is! List ||
        !const {
          'Assigned',
          'Loading',
          'In Transit',
          'Arrived',
          'Completed',
          'Received with Issues',
          'Cancelled',
        }.contains(data['status'])) {
      throw const MalformedResponseException(
        message: 'The server sent an incomplete Courier trip.',
      );
    }
    return LinehaulTrip(
      id: '${data['id']}',
      status: '${data['status']}',
      origin: '${data['origin'] ?? ''}',
      destination: '${data['destination'] ?? ''}',
      seal: data['seal_number'] as String?,
      items: [
        for (final item in items)
          if (item is Map)
            LinehaulParcel(
              '${item['tracking_number'] ?? ''}',
              item['loaded'] == true,
              item['received'] == true,
              issue: switch (item['issue']) {
                null => null,
                'missing' => 'missing',
                'damaged' => 'damaged',
                _ => throw const MalformedResponseException(),
              },
              courierNote: item['courier_note'] as String?,
            )
          else
            throw const MalformedResponseException(),
      ],
    );
  }
}

class LinehaulParcel {
  const LinehaulParcel(
    this.tracking,
    this.loaded,
    this.received, {
    this.issue,
    this.courierNote,
  });
  final String tracking;
  final bool loaded;
  final bool received;
  final String? issue;
  final String? courierNote;
}

class LinehaulRepository {
  const LinehaulRepository(this.client);
  final ApiClient client;

  Future<LinehaulDuty> duty() async =>
      LinehaulDuty.fromJson(unwrapMap(await client.getJson('/courier/duty')));

  Future<LinehaulDuty> changeDuty(
    String action,
    Map<String, Object?> body,
  ) async => LinehaulDuty.fromJson(
    unwrapMap(await client.postJson('/courier/duty/$action', body: body)),
  );

  Future<List<LinehaulTrip>> trips() async {
    final response = await client.getJson('/courier/trips');
    final rows = response['data'];
    if (rows is! List) throw const MalformedResponseException();
    return [
      for (final row in rows)
        if (row is Map)
          LinehaulTrip.fromJson(row.cast<String, Object?>())
        else
          throw const MalformedResponseException(),
    ];
  }

  Future<LinehaulTrip> act(
    String tripId,
    String action, {
    Map<String, Object?> body = const {},
  }) async {
    final response = await client.postJson(
      '/courier/trips/$tripId/$action',
      body: body,
    );
    return LinehaulTrip.fromJson(unwrapMap(response));
  }
}

class LinehaulDuty {
  const LinehaulDuty({
    required this.onDuty,
    required this.available,
    required this.setupRequired,
    required this.status,
    this.checkedAt,
    this.lastCheckPassed,
  });
  final bool onDuty;
  final bool available;
  final bool setupRequired;
  final String status;
  final String? checkedAt;
  final bool? lastCheckPassed;
  factory LinehaulDuty.fromJson(Map<String, Object?> data) {
    if (data['on_duty'] is! bool ||
        data['available'] is! bool ||
        data['setup_required'] is! bool ||
        !const {
          'On Duty',
          'Off Duty',
          'Location unknown',
          'Setup pending',
        }.contains(data['status'])) {
      throw const MalformedResponseException(
        message: 'Could not read Courier duty status.',
      );
    }
    return LinehaulDuty(
      onDuty: data['on_duty'] as bool,
      available: data['available'] as bool,
      setupRequired: data['setup_required'] as bool,
      status: data['status'] as String,
      checkedAt: data['checked_at'] as String?,
      lastCheckPassed: data['last_check_passed'] as bool?,
    );
  }
}
