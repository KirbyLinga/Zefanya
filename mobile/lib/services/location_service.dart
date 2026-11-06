// lib/services/location_service.dart
//
// The optional "Use my location" pin on the address form.
//
// Deliberately coordinates-only: the app has no map tiles, no Places API and no
// reverse geocoder, so this service never pretends to know a street address. It
// returns a point, the form previews it with a stylized pin, and the buyer still
// picks Region → Province → City → Barangay from the offline PSGC catalogue.
//
// Permission and service-off errors are turned into messages a person can act
// on rather than platform exception strings. The device implementation is the
// default; tests inject [MockLocationService] through AddressFormScreen.

import 'package:geolocator/geolocator.dart';

class GeoPoint {
  const GeoPoint({required this.latitude, required this.longitude});

  final double latitude;
  final double longitude;

  /// Six decimals is roughly 11 cm — more than enough for a delivery pin, and
  /// short enough to show in the preview without wrapping.
  String get display =>
      '${latitude.toStringAsFixed(5)}, ${longitude.toStringAsFixed(5)}';
}

class LocationException implements Exception {
  const LocationException(this.message);

  final String message;

  @override
  String toString() => message;
}

abstract class LocationService {
  Future<GeoPoint> currentPosition();
}

/// Real device location. Used everywhere except widget tests.
class DeviceLocationService implements LocationService {
  const DeviceLocationService();

  @override
  Future<GeoPoint> currentPosition() async {
    final serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) {
      throw const LocationException(
        'Location services are off. Turn them on in your device settings, or '
        'set the pin later.',
      );
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied) {
      throw const LocationException(
        'Location permission was not granted. You can still save the address '
        'without a pin.',
      );
    }
    if (permission == LocationPermission.deniedForever) {
      throw const LocationException(
        'Location permission is blocked. Enable it in your device settings, or '
        'save the address without a pin.',
      );
    }

    try {
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.medium,
          timeLimit: Duration(seconds: 20),
        ),
      );
      return GeoPoint(
        latitude: position.latitude,
        longitude: position.longitude,
      );
    } on LocationException {
      rethrow;
    } catch (_) {
      throw const LocationException(
        'Could not read your location. Move somewhere with a clearer signal '
        'and try again, or save the address without a pin.',
      );
    }
  }
}

/// Deterministic stand-in for widget tests. Not selected in production.
class MockLocationService implements LocationService {
  const MockLocationService({this.point, this.error});

  final GeoPoint? point;
  final LocationException? error;

  @override
  Future<GeoPoint> currentPosition() async {
    final failure = error;
    if (failure != null) throw failure;
    return point ?? const GeoPoint(latitude: 14.59950, longitude: 120.98420);
  }
}
