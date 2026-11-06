// Buyer's side of the handover: a QR and a 6-digit code the rider can
// scan or read at the door.
//
// The screen only renders this while a rider is carrying the parcel (see
// CourierTrackerScreen). The code is the same delivery OTP the courier
// validates; the QR is a faster way to hand it over than reading six
// digits over street noise.

import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../../data/mock_data.dart';
import '../../../theme/app_theme.dart';
import '../../../utils/delivery_pass.dart';
import '../../../widgets/editorial_shapes.dart';

class DeliveryPassCard extends StatelessWidget {
  const DeliveryPassCard({super.key, required this.job});

  final DeliveryJob job;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final otp = job.deliveryOtp.trim();
    final payload = DeliveryPassPayload(jobId: job.id, otp: otp).encode();

    return Container(
      key: const ValueKey('delivery-pass-card'),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(0, ShapeTier.card),
        border: Border.all(color: AppColors.divider),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(Icons.qr_code_2, size: 16, color: AppColors.primaryDark),
              const SizedBox(width: 8),
              Text('DELIVERY PASS', style: AppType.eyebrow),
            ],
          ),
          const SizedBox(height: 10),
          Text('Show this to your rider', style: theme.textTheme.titleMedium),
          const SizedBox(height: 4),
          Text(
            'Scan the QR or read the code aloud when your parcel arrives.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: AppColors.textSecondary,
              height: 1.4,
            ),
          ),
          const SizedBox(height: 16),
          Center(
            child: Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                // The QR must stay black-on-white in both themes; a tinted
                // module colour would lower the contrast a camera needs.
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
              ),
              child: QrImageView(
                key: const ValueKey('delivery-pass-qr'),
                data: payload,
                version: QrVersions.auto,
                size: 156,
                backgroundColor: Colors.white,
                eyeStyle: const QrEyeStyle(
                  eyeShape: QrEyeShape.square,
                  color: Colors.black,
                ),
                dataModuleStyle: const QrDataModuleStyle(
                  dataModuleShape: QrDataModuleShape.square,
                  color: Colors.black,
                ),
                errorCorrectionLevel: QrErrorCorrectLevel.M,
                semanticsLabel: 'Delivery pass QR code',
              ),
            ),
          ),
          const SizedBox(height: 16),
          Center(child: Text('DELIVERY CODE', style: AppType.eyebrow)),
          const SizedBox(height: 6),
          Center(
            child: Semantics(
              label: 'Delivery code ${otp.split('').join(' ')}',
              excludeSemantics: true,
              child: Text(
                _grouped(otp),
                key: const ValueKey('delivery-pass-code'),
                style: AppType.priceNumeral(
                  34,
                  color: AppColors.textPrimary,
                ).copyWith(letterSpacing: 5),
              ),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'Only share this when you are handing the parcel over.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall?.copyWith(
              color: AppColors.textSecondary,
            ),
          ),
        ],
      ),
    );
  }

  /// "482913" -> "482 913". A single unbroken run is harder to read aloud
  /// and easier to mistype; the space also makes the number feel like a
  /// code rather than part of the order id.
  String _grouped(String otp) {
    if (otp.length != 6) return otp;
    return '${otp.substring(0, 3)} ${otp.substring(3)}';
  }
}
