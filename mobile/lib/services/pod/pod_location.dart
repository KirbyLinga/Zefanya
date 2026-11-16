// lib/services/pod/pod_location.dart
//
// One GPS fix for a delivery event. This is deliberately *not* the continuous
// courier tracking service (that is a later, separately approved stage): it
// asks once, when the rider opens the proof screen or taps Retry GPS.

import 'dart:async';

import 'package:geolocator/geolocator.dart';

import 'pod_models.dart';

abstract class PodLocationProvider {
  Future<PodGpsResult> currentFix();
}

/// Reads the device location with explicit failure reasons.
class DevicePodLocationProvider implements PodLocationProvider {
  const DevicePodLocationProvider({this.timeout = const Duration(seconds: 12)});

  final Duration timeout;

  @override
  Future<PodGpsResult> currentFix() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        return const PodGpsUnavailable(GpsProblem.servicesDisabled);
      }
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        return const PodGpsUnavailable(GpsProblem.permissionDenied);
      }
      final position = await Geolocator.getCurrentPosition(
        locationSettings: LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: timeout,
        ),
      );
      final fix = PodGpsFix(
        latitude: position.latitude,
        longitude: position.longitude,
        accuracyMeters: position.accuracy,
        capturedAt: position.timestamp,
        isMocked: position.isMocked,
      );
      return fix.isStructurallyValid
          ? fix
          : const PodGpsUnavailable(GpsProblem.unavailable);
    } on TimeoutException {
      return const PodGpsUnavailable(GpsProblem.timeout);
    } on Object {
      return const PodGpsUnavailable(GpsProblem.unavailable);
    }
  }
}
