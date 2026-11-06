// lib/utils/open_maps.dart
//
// "Open in Maps" for the courier delivery screen (Tier 2, task 8).
//
// Hands a delivery address to the device's maps app via url_launcher.
// Address text only — no buyer phone number is shown, passed, or
// launched-with anywhere here (call-buyer privacy, see OPEN QUESTIONS).

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import 'timed_snackbar.dart';

/// Opens [address] in the device's maps app.
///
/// Uses the universal https maps URL so it works without platform setup
/// (geo: URIs need per-platform handling; the https form degrades to the
/// browser when no maps app is present). Shows a snackbar when nothing
/// can handle it rather than failing silently.
Future<void> openAddressInMaps(BuildContext context, String address) async {
  final query = address.trim();
  if (query.isEmpty) return;
  final uri = Uri.https('www.google.com', '/maps/search/', {'api': '1', 'query': query});
  if (await canLaunchUrl(uri)) {
    await launchUrl(uri, mode: LaunchMode.externalApplication);
    return;
  }
  if (!context.mounted) return;
  showTimedSnackBar(
    context,
    const SnackBar(content: Text('Could not open Maps for this address')),
  );
}
