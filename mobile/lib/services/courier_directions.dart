import 'package:url_launcher/url_launcher.dart';

abstract interface class CourierDirections {
  Future<bool> open(String hubName, String address);
}

class DeviceCourierDirections implements CourierDirections {
  const DeviceCourierDirections();

  static Uri? destinationUri(String hubName, String address) {
    if (hubName.trim().isEmpty || address.trim().isEmpty) return null;
    final uri = Uri.https('www.google.com', '/maps/dir/', {
      'api': '1',
      'destination': '${hubName.trim()}, ${address.trim()}',
      'travelmode': 'driving',
    });
    return uri.toString().length <= 2048 ? uri : null;
  }

  @override
  Future<bool> open(String hubName, String address) async {
    final uri = destinationUri(hubName, address);
    if (uri == null) return false;
    return launchUrl(
      uri,
      mode: LaunchMode.externalApplication,
      webOnlyWindowName: '_blank',
    );
  }
}
