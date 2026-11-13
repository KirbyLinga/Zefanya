import 'package:intl/intl.dart';

import '../api/api_client.dart';
import '../api/api_exception.dart';
import 'linehaul_vehicle_repository.dart';

enum LinehaulLocationRequirement { off, required, setupRequired }

class LinehaulTrip {
  const LinehaulTrip({
    required this.id,
    required this.status,
    required this.origin,
    required this.destination,
    required this.items,
    this.seal,
    this.originAddress,
    this.destinationAddress,
    this.offerVersion = 1,
    this.canDecline = false,
    this.declineReason,
    this.declinedAt,
    this.parcelCount,
    this.canReportDelay = false,
    this.canReportBreakdown = false,
    this.needsRescan = false,
    this.departureLocation = LinehaulLocationRequirement.off,
    this.arrivalLocation = LinehaulLocationRequirement.off,
    this.reassignmentReason,
    this.reassignedAt,
    this.delay,
    this.recordedWeightGrams,
    this.acceptedAt,
    this.departedAt,
    this.arrivedAt,
    this.scheduledDepartureAt,
    this.tripPay,
  });

  final String id;
  final String status;
  final String origin;
  final String destination;
  final String? seal;
  final String? originAddress;
  final String? destinationAddress;
  final int offerVersion;
  final bool canDecline;
  final String? declineReason;
  final String? declinedAt;
  final int? parcelCount;
  final bool canReportDelay;
  final bool canReportBreakdown;
  final bool needsRescan;
  final LinehaulLocationRequirement departureLocation;
  final LinehaulLocationRequirement arrivalLocation;
  final String? reassignmentReason;
  final DateTime? reassignedAt;
  final LinehaulDelay? delay;
  final int? recordedWeightGrams;
  final DateTime? acceptedAt;
  final DateTime? departedAt;
  final DateTime? arrivedAt;
  final DateTime? scheduledDepartureAt;
  final LinehaulTripPay? tripPay;
  final List<LinehaulParcel> items;

  factory LinehaulTrip.fromJson(Map<String, Object?> data) {
    LinehaulLocationRequirement locationRequirement(String key) =>
        switch (data[key]) {
          null || 'off' => LinehaulLocationRequirement.off,
          'required' => LinehaulLocationRequirement.required,
          'setup_required' => LinehaulLocationRequirement.setupRequired,
          _ => throw const MalformedResponseException(
            message: 'Could not read the trip location requirements.',
          ),
        };

    DateTime? timestamp(String key) {
      final value = data[key];
      if (value == null) return null;
      if (value is! String ||
          !RegExp(r'(Z|[+-]\d{2}:\d{2})$').hasMatch(value) ||
          DateTime.tryParse(value) == null) {
        throw const MalformedResponseException(
          message: 'Could not read the recorded trip time.',
        );
      }
      return DateTime.parse(value);
    }

    final weight = data['recorded_weight_grams'];
    if (weight != null && (weight is! int || weight <= 0)) {
      throw const MalformedResponseException(
        message: 'Could not read the recorded parcel weight.',
      );
    }
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
          'Declined',
          'Reassigned',
        }.contains(data['status'])) {
      throw const MalformedResponseException(
        message: 'The server sent an incomplete Courier trip.',
      );
    }
    for (final field in [
      'origin_address',
      'destination_address',
      'decline_reason',
      'declined_at',
    ]) {
      if (data[field] != null && data[field] is! String) {
        throw const MalformedResponseException(
          message: 'Could not read the hub address.',
        );
      }
    }
    if ((data['offer_version'] != null &&
            (data['offer_version'] is! int ||
                (data['offer_version'] as int) < 1)) ||
        (data['can_decline'] != null && data['can_decline'] is! bool) ||
        (data['parcel_count'] != null &&
            (data['parcel_count'] is! int ||
                (data['parcel_count'] as int) < 0)) ||
        (data['can_decline'] == true && data['status'] != 'Assigned') ||
        (data['status'] == 'Declined' &&
            (data['decline_reason'] is! String ||
                (data['decline_reason'] as String).trim().isEmpty ||
                data['declined_at'] is! String ||
                DateTime.tryParse(data['declined_at'] as String) == null ||
                data['parcel_count'] is! int ||
                items.isNotEmpty))) {
      throw const MalformedResponseException(
        message: 'Could not read this Courier offer.',
      );
    }
    if ((data['needs_rescan'] != null && data['needs_rescan'] is! bool) ||
        (data['needs_rescan'] == true && data['status'] != 'In Transit') ||
        (data['status'] == 'Reassigned' &&
            (data['reassignment_reason'] is! String ||
                (data['reassignment_reason'] as String).trim().isEmpty ||
                items.isNotEmpty)) ||
        (data['can_report_delay'] != null &&
            data['can_report_delay'] is! bool) ||
        (data['can_report_breakdown'] != null &&
            data['can_report_breakdown'] is! bool) ||
        (data['can_report_breakdown'] == true &&
            data['status'] != 'In Transit') ||
        (data['can_report_delay'] == true && data['status'] != 'In Transit') ||
        (data['delay'] != null && data['delay'] is! Map)) {
      throw const MalformedResponseException(
        message: 'Could not read the trip delay report.',
      );
    }
    return LinehaulTrip(
      id: '${data['id']}',
      status: '${data['status']}',
      origin: '${data['origin'] ?? ''}',
      destination: '${data['destination'] ?? ''}',
      seal: data['seal_number'] as String?,
      originAddress: data['origin_address'] as String?,
      destinationAddress: data['destination_address'] as String?,
      offerVersion: data['offer_version'] as int? ?? 1,
      canDecline: data['can_decline'] == true,
      declineReason: data['decline_reason'] as String?,
      declinedAt: data['declined_at'] as String?,
      parcelCount: data['parcel_count'] as int?,
      recordedWeightGrams: weight as int?,
      acceptedAt: timestamp('accepted_at'),
      departedAt: timestamp('departed_at'),
      arrivedAt: timestamp('arrived_at'),
      scheduledDepartureAt: timestamp('scheduled_departure_at'),
      tripPay: data['trip_pay'] == null
          ? null
          : LinehaulTripPay.fromJson(data['trip_pay']),
      canReportDelay: data['can_report_delay'] == true,
      canReportBreakdown: data['can_report_breakdown'] == true,
      needsRescan: data['needs_rescan'] == true,
      departureLocation: locationRequirement('departure_location'),
      arrivalLocation: locationRequirement('arrival_location'),
      reassignmentReason: data['status'] == 'Reassigned'
          ? data['reassignment_reason'] as String
          : null,
      reassignedAt: data['status'] == 'Reassigned'
          ? timestamp('reassigned_at')
          : null,
      delay: data['delay'] is Map
          ? LinehaulDelay.fromJson(
              (data['delay'] as Map).cast<String, Object?>(),
            )
          : null,
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

class LinehaulTripPay {
  const LinehaulTripPay(this.status, this.amountMinor);
  final String status;
  final int? amountMinor;

  factory LinehaulTripPay.fromJson(Object? value) {
    if (value is! Map ||
        !const {
          'quote',
          'pending',
          'earned',
          'ineligible',
          'not_configured',
        }.contains(value['status'])) {
      throw const MalformedResponseException(
        message: 'Could not read the trip pay.',
      );
    }
    final status = value['status'] as String;
    final amount = value['amount_minor'];
    if ((status == 'not_configured' && amount != null) ||
        (status == 'ineligible' && amount != 0) ||
        (status != 'not_configured' && (amount is! int || amount < 0)) ||
        (const {'quote', 'pending', 'earned'}.contains(status) &&
            amount == 0)) {
      throw const MalformedResponseException(
        message: 'Could not read the trip pay.',
      );
    }
    return LinehaulTripPay(status, amount as int?);
  }
}

class LinehaulDelay {
  const LinehaulDelay({
    required this.reason,
    required this.newEta,
    required this.reportedAt,
  });
  final String reason;
  final DateTime newEta;
  final DateTime reportedAt;

  factory LinehaulDelay.fromJson(Map<String, Object?> data) {
    DateTime? timestamp(Object? value) =>
        value is String && RegExp(r'(Z|[+-]\d{2}:\d{2})$').hasMatch(value)
        ? DateTime.tryParse(value)
        : null;
    final eta = timestamp(data['new_eta']);
    final reported = timestamp(data['reported_at']);
    if (data['reason'] is! String ||
        (data['reason'] as String).trim().isEmpty ||
        (data['reason'] as String).runes.length > 500 ||
        eta == null ||
        reported == null) {
      throw const MalformedResponseException(
        message: 'Could not read the trip delay report.',
      );
    }
    return LinehaulDelay(
      reason: data['reason'] as String,
      newEta: eta,
      reportedAt: reported,
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
  const LinehaulRepository(ApiClient client) : _client = client;
  const LinehaulRepository.demo() : _client = null;

  final ApiClient? _client;
  bool get isDemo => _client == null;
  ApiClient get client => _client ?? (throw const ApiNotConfiguredException());

  Future<LinehaulProfile> profile() async => isDemo
      ? const LinehaulProfile(
          name: 'Demo Transport Courier',
          email: 'transport@demo.invalid',
          status: 'Demo',
          emailVerified: false,
          company: 'Zefanya Demo Logistics',
          homeHub: 'Pila Hub',
        )
      : LinehaulProfile.fromJson(
          unwrapMap(await client.getJson('/courier/profile')),
        );

  static const _demoTrip = {
    'origin': 'Pila Hub',
    'destination': 'Calamba Hub',
    'origin_address': 'Pila, Laguna',
    'destination_address': 'Calamba, Laguna',
  };

  Future<Map<String, Object?>> history(Map<String, String> query) async {
    if (!isDemo) return client.getJson('/courier/history', query: query);
    final (timestamp, included) = _demoReportDate(query);
    return {
      'data': {
        'page': 1,
        'last_page': 1,
        'timezone': 'Asia/Manila',
        'total': included ? 1 : 0,
        'trips': [
          if (included)
            {
              ..._demoTrip,
              'id': 101,
              'status': 'Completed',
              'history_key': 'demo-101',
              'recorded_at': timestamp,
              'items': [
                {
                  'tracking_number': 'DEMO-PARCEL-101',
                  'loaded': true,
                  'received': true,
                },
              ],
            },
        ],
      },
    };
  }

  Future<Map<String, Object?>> earnings(Map<String, String> query) async {
    if (!isDemo) return client.getJson('/courier/earnings', query: query);
    final (timestamp, included) = _demoReportDate(query);
    return {
      'data': {
        'page': 1,
        'last_page': 1,
        'timezone': 'Asia/Manila',
        'earned_minor': included ? 125000 : 0,
        'earned_trips': included ? 1 : 0,
        'unconfirmed_trips': 0,
        'completed_trips': included ? 1 : 0,
        'trips': [
          if (included)
            {
              'trip_id': 101,
              'result': 'Completed',
              'completed_at': timestamp,
              'trip_pay': {'status': 'earned', 'amount_minor': 125000},
            },
        ],
      },
    };
  }

  (String, bool) _demoReportDate(Map<String, String> query) {
    final manila = DateTime.now().toUtc().add(const Duration(hours: 8));
    final day = DateFormat('yyyy-MM-dd').format(manila);
    final included =
        day.compareTo(query['from']!) >= 0 && day.compareTo(query['to']!) <= 0;
    return ('${day}T08:00:00+08:00', included);
  }

  Future<void> reportBreakdown(
    String tripId, {
    required String reason,
    required DateTime eta,
    required int offerVersion,
    required CourierDocument photo,
  }) async {
    final data = unwrapMap(
      await client.postMultipart(
        '/courier/trips/$tripId/breakdown',
        fields: {
          'reason': reason,
          'new_eta': eta.toUtc().toIso8601String(),
          'offer_version': '$offerVersion',
        },
        byteFiles: {'photo': (bytes: photo.bytes, filename: photo.name)},
      ),
    );
    if (data['id'] is! int ||
        (data['id'] as int) < 1 ||
        data['type'] != 'vehicle_breakdown' ||
        data['photo_received'] != true ||
        data['delay'] is! Map) {
      throw const MalformedResponseException();
    }
    LinehaulDelay.fromJson((data['delay'] as Map).cast<String, Object?>());
  }

  Future<LinehaulDuty> duty() async => isDemo
      ? const LinehaulDuty(
          onDuty: false,
          available: false,
          setupRequired: false,
          status: 'Off Duty',
        )
      : LinehaulDuty.fromJson(unwrapMap(await client.getJson('/courier/duty')));

  Future<LinehaulDuty> changeDuty(
    String action,
    Map<String, Object?> body,
  ) async => LinehaulDuty.fromJson(
    unwrapMap(await client.postJson('/courier/duty/$action', body: body)),
  );

  Future<List<LinehaulTrip>> trips() async {
    if (isDemo) {
      return [
        LinehaulTrip.fromJson({
          ..._demoTrip,
          'id': 102,
          'status': 'Loading',
          'items': [
            {
              'tracking_number': 'DEMO-PARCEL-102',
              'loaded': true,
              'received': false,
            },
            {
              'tracking_number': 'DEMO-PARCEL-103',
              'loaded': false,
              'received': false,
            },
          ],
        }),
      ];
    }
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

class LinehaulProfile {
  const LinehaulProfile({
    required this.name,
    required this.email,
    required this.status,
    required this.emailVerified,
    required this.company,
    required this.homeHub,
  });
  final String name;
  final String email;
  final String status;
  final bool emailVerified;
  final String company;
  final String homeHub;

  factory LinehaulProfile.fromJson(Map<String, Object?> data) {
    final company = data['company'];
    final hub = data['home_hub'];
    if (data['name'] is! String ||
        (data['name'] as String).trim().isEmpty ||
        data['email'] is! String ||
        (data['email'] as String).trim().isEmpty ||
        data['email_verified'] is! bool ||
        data['status'] != 'active' ||
        company is! Map ||
        company['name'] is! String ||
        (company['name'] as String).trim().isEmpty ||
        hub is! Map ||
        hub['name'] is! String ||
        (hub['name'] as String).trim().isEmpty) {
      throw const MalformedResponseException(
        message: 'Could not read your Courier profile.',
      );
    }
    return LinehaulProfile(
      name: data['name'] as String,
      email: data['email'] as String,
      status: 'Active',
      emailVerified: data['email_verified'] as bool,
      company: company['name'] as String,
      homeHub: hub['name'] as String,
    );
  }
}

class LinehaulDuty {
  const LinehaulDuty({
    required this.onDuty,
    required this.available,
    required this.setupRequired,
    required this.status,
    this.tripLocationSharing = false,
    this.checkedAt,
    this.lastCheckPassed,
  });
  final bool onDuty;
  final bool available;
  final bool setupRequired;
  final String status;
  final bool tripLocationSharing;
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
      tripLocationSharing: data['trip_location_sharing'] == true,
      checkedAt: data['checked_at'] as String?,
      lastCheckPassed: data['last_check_passed'] as bool?,
    );
  }
}
