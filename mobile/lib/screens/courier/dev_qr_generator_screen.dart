// lib/screens/courier/dev_qr_generator_screen.dart
//
// Dev-only QR generator screen. Renders a QR code for any tracking number
// typed by the user. Hidden from release builds via kDebugMode gating.

import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';

/// Dev-only QR code generator screen.
///
/// Renders a QR code for any tracking number typed by the user. Gated by
/// [kDebugMode] so it is hidden in release builds.
class DevQrGeneratorScreen extends StatelessWidget {
  const DevQrGeneratorScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final controller = TextEditingController(text: 'ZEF-DEV-00000');

    return Scaffold(
      appBar: AppBar(
        title: const Text('Dev QR Generator'),
      ),
      body: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            TextField(
              controller: controller,
              decoration: const InputDecoration(
                hintText: 'Tracking number e.g. ZEF-DEV-00000',
                border: OutlineInputBorder(),
                labelText: 'Tracking number',
              ),
              textCapitalization: TextCapitalization.characters,
            ),
            const SizedBox(height: 16),
            ElevatedButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Done'),
            ),
            const SizedBox(height: 24),
            Text('Rendered QR:', style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: 8),
            QrImageView(
              data: controller.text.trim(),
              version: 1,
              size: 200.0,
              backgroundColor: Colors.white,
            ),
          ],
        ),
      ),
    );
  }
}