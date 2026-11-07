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
            )
          else
            throw const MalformedResponseException(),
      ],
    );
  }
}

class LinehaulParcel {
  const LinehaulParcel(this.tracking, this.loaded, this.received);
  final String tracking;
  final bool loaded;
  final bool received;
}

class LinehaulRepository {
  const LinehaulRepository(this.client);
  final ApiClient client;

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
