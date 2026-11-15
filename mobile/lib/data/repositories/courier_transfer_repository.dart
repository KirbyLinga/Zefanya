// lib/data/repositories/courier_transfer_repository.dart
//
// Read-only Courier (Transfer Courier) data from WebZefanya's Courier API:
//
//   GET /api/courier/profile
//   GET /api/courier/transfers           → {upcoming: [...], current: [...]}
//   GET /api/courier/transfers/history   → {data: [...], meta: {...}}
//
// WebZefanya owns every assignment, dispatch, receipt and custody record. The
// app only shows them: there is no write method here, and none is planned for
// this phase. Nothing here is stored on the device as a source of truth.

import '../api/api_client.dart';

/// One transfer the Courier is or was assigned to.
class CourierTransfer {
  const CourierTransfer({
    required this.type,
    required this.id,
    required this.role,
    this.status,
    this.originName,
    this.destinationName,
    this.destinationProvider,
    this.parcelCount,
    this.vehicleType,
    this.plateNumber,
    this.assignedAt,
    this.replacedAt,
    this.dispatchedAt,
    this.receivedAt,
  });

  /// `manifest` (batch between this company's hubs) or `handoff` (to a partner company).
  final String type;
  final int id;

  /// `assigned`, or `reassigned_away` when Logistics gave it to someone else.
  final String role;
  final String? status;
  final String? originName;
  final String? destinationName;
  final String? destinationProvider;
  final int? parcelCount;
  final String? vehicleType;
  final String? plateNumber;
  final DateTime? assignedAt;
  final DateTime? replacedAt;
  final DateTime? dispatchedAt;
  final DateTime? receivedAt;

  bool get reassignedAway => role == 'reassigned_away';

  /// A stable key: a batch and a handoff may share a numeric id.
  String get key => '$type-$id';

  String get title =>
      type == 'handoff' ? 'Handoff #$id' : 'Transfer batch #$id';

  /// Plain-language state. Only hub scans change it; the app never does.
  String get statusLabel {
    if (reassignedAway) return 'Reassigned to another Courier';
    return switch (status) {
      'DRAFT' => 'Waiting for the dispatch scan',
      'REQUESTED' || 'ACCEPTED' => 'Waiting for the dispatch scan',
      'DISPATCHED' ||
      'IN_TRANSIT' => 'On the road — the receiving hub scans it in',
      'RECEIVED' => 'Received at the destination hub',
      'RECEIVED_WITH_EXCEPTIONS' =>
        'Received, with exceptions recorded by the hub',
      'CANCELLED' => 'Cancelled',
      'REJECTED' => 'Rejected by the receiving company',
      _ => status ?? 'Unknown',
    };
  }

  static DateTime? _date(Object? value) =>
      value == null ? null : DateTime.tryParse(value.toString())?.toLocal();

  static int? _int(Object? value) =>
      value is int ? value : int.tryParse('${value ?? ''}');

  /// Reads one server row. Unknown fields are ignored; a row without a type or
  /// id is not a transfer and is rejected by the caller.
  static CourierTransfer? fromJson(Object? raw) {
    if (raw is! Map) return null;
    final type = raw['type']?.toString();
    final id = _int(raw['id']);
    if ((type != 'manifest' && type != 'handoff') || id == null) return null;
    final origin = raw['origin'] is Map ? raw['origin'] as Map : const {};
    final destination = raw['destination'] is Map
        ? raw['destination'] as Map
        : const {};
    return CourierTransfer(
      type: type!,
      id: id,
      role: raw['role']?.toString() ?? 'assigned',
      status: raw['status']?.toString(),
      originName: origin['name']?.toString(),
      destinationName: destination['name']?.toString(),
      destinationProvider: destination['provider']?.toString(),
      parcelCount: _int(raw['parcel_count']),
      vehicleType: raw['vehicle_type']?.toString(),
      plateNumber: raw['plate_number']?.toString(),
      assignedAt: _date(raw['assigned_at']),
      replacedAt: _date(raw['replaced_at']),
      dispatchedAt: _date(raw['dispatched_at']),
      receivedAt: _date(raw['received_at']),
    );
  }
}

class CourierProfile {
  const CourierProfile({
    required this.accountId,
    required this.loginId,
    required this.name,
    this.vehicleType,
    this.plateNumber,
    this.providerName,
    this.session,
  });

  final int accountId;
  final String loginId;
  final String name;
  final String? vehicleType;
  final String? plateNumber;
  final String? providerName;
  final int? session;

  static CourierProfile fromJson(Map<String, Object?> json) {
    final provider = json['provider'] is Map
        ? json['provider'] as Map
        : const {};
    return CourierProfile(
      accountId: CourierTransfer._int(json['account_id']) ?? 0,
      loginId: json['login_id']?.toString() ?? '',
      name: json['name']?.toString() ?? '',
      vehicleType: json['vehicle_type']?.toString(),
      plateNumber: json['plate_number']?.toString(),
      providerName: provider['name']?.toString(),
      session: CourierTransfer._int(json['session']),
    );
  }
}

class CourierAssignments {
  const CourierAssignments({required this.current, required this.upcoming});
  final List<CourierTransfer> current;
  final List<CourierTransfer> upcoming;
}

class CourierHistoryPage {
  const CourierHistoryPage({
    required this.items,
    required this.currentPage,
    required this.lastPage,
    required this.total,
  });
  final List<CourierTransfer> items;
  final int currentPage;
  final int lastPage;
  final int total;
  bool get hasMore => currentPage < lastPage;
}

/// The Courier API, read-only.
class CourierTransferRepository {
  const CourierTransferRepository(this.client);

  final ApiClient client;

  Future<CourierProfile> profile() async => CourierProfile.fromJson(
    unwrapMap(await client.getJson('/courier/profile')),
  );

  Future<CourierAssignments> assignments() async {
    final data = unwrapMap(await client.getJson('/courier/transfers'));
    List<CourierTransfer> list(Object? raw) => [
      if (raw is List)
        for (final row in raw) ?CourierTransfer.fromJson(row),
    ];
    return CourierAssignments(
      current: list(data['current']),
      upcoming: list(data['upcoming']),
    );
  }

  Future<CourierHistoryPage> history({int page = 1}) async {
    final data = unwrapMap(
      await client.getJson(
        '/courier/transfers/history',
        query: {'page': '${page < 1 ? 1 : page}'},
      ),
    );
    final meta = data['meta'] is Map ? data['meta'] as Map : const {};
    final rows = data['data'];
    return CourierHistoryPage(
      items: [
        if (rows is List)
          for (final row in rows) ?CourierTransfer.fromJson(row),
      ],
      currentPage: CourierTransfer._int(meta['current_page']) ?? 1,
      lastPage: CourierTransfer._int(meta['last_page']) ?? 1,
      total: CourierTransfer._int(meta['total']) ?? 0,
    );
  }
}
