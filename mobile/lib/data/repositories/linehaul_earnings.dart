import '../api/api_exception.dart';
import 'linehaul_repository.dart';

class LinehaulEarnings {
  LinehaulEarnings(Map<String, Object?> data) {
    int integer(String key) {
      final value = data[key];
      if (value is! int || value < 0) throw const MalformedResponseException();
      return value;
    }

    earnedMinor = integer('earned_minor');
    earnedTrips = integer('earned_trips');
    unconfirmedTrips = integer('unconfirmed_trips');
    completedTrips = integer('completed_trips');
    page = integer('page');
    lastPage = integer('last_page');
    if (page < 1 ||
        lastPage < 1 ||
        completedTrips != earnedTrips + unconfirmedTrips ||
        data['timezone'] != 'Asia/Manila' ||
        data['trips'] is! List) {
      throw const MalformedResponseException();
    }
    final payoutAvailability = data['payout_recording_available'];
    if (payoutAvailability != null && payoutAvailability is! bool) {
      throw const MalformedResponseException();
    }
    payoutRecordingAvailable = payoutAvailability == true;
    trips = [for (final row in data['trips'] as List) LinehaulEarningRow(row)];
    if (trips.length > 20) throw const MalformedResponseException();
  }
  late final int earnedMinor,
      earnedTrips,
      unconfirmedTrips,
      completedTrips,
      page,
      lastPage;
  late final List<LinehaulEarningRow> trips;
  late final bool payoutRecordingAvailable;
}

class LinehaulEarningRow {
  LinehaulEarningRow(Object? value) {
    if (value is! Map ||
        value['trip_id'] is! int ||
        value['completed_at'] is! String ||
        !RegExp(
          r'(Z|[+-]\d{2}:\d{2})$',
        ).hasMatch(value['completed_at'] as String) ||
        DateTime.tryParse(value['completed_at'] as String) == null ||
        !const {
          'Completed',
          'Received with Issues',
        }.contains(value['result'])) {
      throw const MalformedResponseException();
    }
    tripId = value['trip_id'] as int;
    completedAt = DateTime.parse(value['completed_at'] as String);
    result = value['result'] as String;
    pay = LinehaulTripPay.fromJson(value['trip_pay']);
    final paidDate = value['paid_on'];
    if (paidDate == null) {
      paidOn = null;
    } else {
      if (paidDate is! String ||
          !RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(paidDate) ||
          pay.status != 'earned') {
        throw const MalformedResponseException();
      }
      final parsed = DateTime.tryParse('${paidDate}T00:00:00Z');
      if (parsed == null || !parsed.toIso8601String().startsWith(paidDate)) {
        throw const MalformedResponseException();
      }
      paidOn = parsed;
    }
  }
  late final int tripId;
  late final DateTime completedAt;
  late final String result;
  late final LinehaulTripPay pay;
  late final DateTime? paidOn;
}
