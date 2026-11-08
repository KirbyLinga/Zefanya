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

class DeviceCourierLocationSource implements CourierLocationSource {
  const DeviceCourierLocationSource();
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
