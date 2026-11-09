import 'dart:typed_data';
import '../api/api_client.dart';
import '../api/api_exception.dart';

class CourierDocument {
  const CourierDocument(this.name, this.bytes);
  final String name;
  final Uint8List bytes;
}

class LinehaulVehicle {
  const LinehaulVehicle(
    this.setupRequired,
    this.editable,
    this.profile, {
    this.vehicleTypes = _legacyVehicleTypes,
  });
  static const _legacyVehicleTypes = [
    '4-Wheeler Closed Van Truck',
    '6-Wheeler Truck',
    '10-Wheeler Truck',
  ];
  final bool setupRequired;
  final bool editable;
  final Map<String, Object?>? profile;
  final List<String> vehicleTypes;

  factory LinehaulVehicle.fromJson(Map<String, Object?> data) {
    final profile = data['profile'];
    final types = data['vehicle_types'] ?? _legacyVehicleTypes;
    if (types is! List ||
        types.isEmpty ||
        types.toSet().length != types.length ||
        types.any(
          (type) => type != 'Van' && !_legacyVehicleTypes.contains(type),
        )) {
      throw const MalformedResponseException();
    }
    if (data['setup_required'] is! bool ||
        data['editable'] is! bool ||
        (profile != null &&
            (profile is! Map ||
                profile['version'] is! int ||
                (profile['version'] as int) < 1 ||
                profile['documents'] is! Map ||
                profile['license_codes'] is! List ||
                !const [
                  'pending',
                  'approved',
                  'rejected',
                ].contains(profile['review_status'])))) {
      throw const MalformedResponseException(
        message: 'Could not read vehicle records.',
      );
    }
    if (profile is Map) {
      for (final field in [
        'plate_number',
        'license_no',
        'license_expiry',
        'vehicle_type',
        'capacity_kg',
      ]) {
        if (profile[field] is! String ||
            (profile[field] as String).trim().isEmpty) {
          throw const MalformedResponseException();
        }
      }
      if (!types.contains(profile['vehicle_type']) ||
          (profile['license_codes'] as List).any(
            (code) =>
                !const ['B', 'B1', 'B2', 'C', 'D', 'BE', 'CE'].contains(code),
          ) ||
          DateTime.tryParse(profile['license_expiry'] as String) == null ||
          double.tryParse(profile['capacity_kg'] as String)?.isFinite != true) {
        throw const MalformedResponseException();
      }
      final documents = profile['documents'] as Map;
      for (final key in ['drivers_license', 'or_cr', 'nbi_clearance']) {
        final document = documents[key];
        if (document is! Map ||
            !const [
              'image/jpeg',
              'image/png',
              'application/pdf',
            ].contains(document['mime'])) {
          throw const MalformedResponseException();
        }
      }
    }
    return LinehaulVehicle(
      data['setup_required'] as bool,
      data['editable'] as bool,
      profile == null ? null : (profile as Map).cast<String, Object?>(),
      vehicleTypes: List<String>.unmodifiable(types),
    );
  }
}

class LinehaulVehicleRepository {
  const LinehaulVehicleRepository(this.client);
  final ApiClient client;

  Future<LinehaulVehicle> load() async => LinehaulVehicle.fromJson(
    unwrapMap(await client.getJson('/courier/vehicle')),
  );

  Future<LinehaulVehicle> save(
    Map<String, String> fields,
    Map<String, CourierDocument> documents,
  ) async => LinehaulVehicle.fromJson(
    unwrapMap(
      await client.postMultipart(
        '/courier/vehicle',
        fields: fields,
        byteFiles: {
          for (final entry in documents.entries)
            entry.key: (bytes: entry.value.bytes, filename: entry.value.name),
        },
      ),
    ),
  );

  Future<Uint8List> download(String document) {
    if (!const [
      'drivers_license',
      'or_cr',
      'nbi_clearance',
    ].contains(document)) {
      throw const MalformedResponseException();
    }
    return client.getBytes('/courier/vehicle/documents/$document');
  }
}
