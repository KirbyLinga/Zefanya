import '../api/api_exception.dart';
import 'linehaul_repository.dart';

class LinehaulHistoryPage {
  LinehaulHistoryPage(Map<String, Object?> data) {
    if (data['page'] is! int ||
        (data['page'] as int) < 1 ||
        data['last_page'] is! int ||
        (data['last_page'] as int) < 1 ||
        data['total'] is! int ||
        (data['total'] as int) < 0 ||
        data['timezone'] != 'Asia/Manila' ||
        data['trips'] is! List) {
      throw const MalformedResponseException();
    }
    page = data['page'] as int;
    lastPage = data['last_page'] as int;
    total = data['total'] as int;
    rows = [for (final row in data['trips'] as List) LinehaulHistoryEntry(row)];
    if (rows.length > 20 ||
        rows.length > total ||
        rows.map((r) => r.key).toSet().length != rows.length) {
      throw const MalformedResponseException();
    }
  }
  late final int page, lastPage, total;
  late final List<LinehaulHistoryEntry> rows;
}

class LinehaulHistoryEntry {
  LinehaulHistoryEntry(Object? value) {
    if (value is! Map ||
        value['history_key'] is! String ||
        (value['history_key'] as String).isEmpty ||
        value['recorded_at'] is! String ||
        !RegExp(
          r'(Z|[+-]\d{2}:\d{2})$',
        ).hasMatch(value['recorded_at'] as String) ||
        DateTime.tryParse(value['recorded_at'] as String) == null) {
      throw const MalformedResponseException();
    }
    key = value['history_key'] as String;
    recordedAt = DateTime.parse(value['recorded_at'] as String);
    trip = LinehaulTrip.fromJson(value.cast<String, Object?>());
    if (!const {
      'Completed',
      'Received with Issues',
      'Cancelled',
      'Declined',
      'Reassigned',
    }.contains(trip.status)) {
      throw const MalformedResponseException();
    }
  }
  late final String key;
  late final DateTime recordedAt;
  late final LinehaulTrip trip;
}
