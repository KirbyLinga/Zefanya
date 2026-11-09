import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

class CourierPosition {
  const CourierPosition(this.latitude, this.longitude, this.accuracy);
  final double latitude;
  final double longitude;
  final double accuracy;
  Map<String, Object?> toJson() => {
    'latitude': latitude,
    'longitude': longitude,
    'accuracy': accuracy,
  };
}

class CourierLocationException implements Exception {
  const CourierLocationException(this.message);
  final String message;
}

abstract class CourierLocationSource {
  Future<CourierPosition> current({bool requestPermission = true});
}

abstract class CourierTripLocationSource {
  bool get supported;
  Stream<CourierPosition> watchTrip({required bool Function() canStart});
}

class DeviceCourierLocationSource
    implements CourierLocationSource, CourierTripLocationSource {
  const DeviceCourierLocationSource();

  @override
  bool get supported =>
      !kIsWeb && defaultTargetPlatform == TargetPlatform.android;

  @override
  Stream<CourierPosition> watchTrip({
    required bool Function() canStart,
  }) async* {
    try {
      if (!supported || !await Geolocator.isLocationServiceEnabled()) {
        throw const CourierLocationException(
          'Turn on Android location services to share your trip location.',
        );
      }
      final permission = await Geolocator.checkPermission();
      if (permission != LocationPermission.always &&
          permission != LocationPermission.whileInUse) {
        throw const CourierLocationException(
          'Enable precise location in Settings before sharing your trip.',
        );
      }
      if (!canStart()) {
        throw const CourierLocationException(
          'Open the app to start sharing trip location.',
        );
      }
      final positions = Geolocator.getPositionStream(
        locationSettings: AndroidSettings(
          accuracy: LocationAccuracy.high,
          intervalDuration: const Duration(minutes: 1),
          foregroundNotificationConfig: const ForegroundNotificationConfig(
            notificationTitle: 'Zefanya Courier trip',
            notificationText: 'Sharing location with your Logistics company.',
            notificationChannelName: 'Courier trip location',
            enableWakeLock: true,
            setOngoing: true,
          ),
        ),
      );
      await for (final position in positions) {
        final age = DateTime.now().difference(position.timestamp);
        if (!position.latitude.isFinite ||
            position.latitude.abs() > 90 ||
            !position.longitude.isFinite ||
            position.longitude.abs() > 180 ||
            !position.accuracy.isFinite ||
            position.accuracy < 0 ||
            position.accuracy > 100 ||
            age.isNegative ||
            age > const Duration(minutes: 1)) {
          continue;
        }
        yield CourierPosition(
          position.latitude,
          position.longitude,
          position.accuracy,
        );
      }
    } on CourierLocationException {
      rethrow;
    } catch (_) {
      throw const CourierLocationException(
        'Trip location sharing stopped. Check location access and try again.',
      );
    }
  }

  @override
  Future<CourierPosition> current({bool requestPermission = true}) async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        throw const CourierLocationException(
          'Turn on location services before going On Duty.',
        );
      }
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied && requestPermission) {
        permission = await Geolocator.requestPermission();
      }
      if (permission != LocationPermission.always &&
          permission != LocationPermission.whileInUse) {
        throw const CourierLocationException(
          'Location access is needed for Courier duty. Enable it in Settings and try again.',
        );
      }
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 20),
        ),
      );
      if (position.accuracy > 100) {
        throw const CourierLocationException(
          'Location is not accurate enough yet. Move to a clearer area and try again.',
        );
      }
      return CourierPosition(
        position.latitude,
        position.longitude,
        position.accuracy,
      );
    } on CourierLocationException {
      rethrow;
    } catch (_) {
      throw const CourierLocationException(
        'Could not update your location. Check location access and try again.',
      );
    }
  }
}
