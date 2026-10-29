// lib/screens/courier/rider_profile_section.dart
//
// The Rider record on the shared Profile screen: company, vehicle, licence,
// verification status and the two documents. Split from profile_screen.dart
// because the buyer's profile has no business importing a licence view, and
// because the same rows are exercised by the RID-PROF cases as one unit.
//
// RID-PROF-001 requires both documents to open, not merely to be named. A path
// to a deleted file would render a tile that opens nothing, so the tap checks
// the file and says so instead of showing a broken image.

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/auth_state.dart';
import '../../state/courier_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/timed_snackbar.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_shapes.dart';
import 'rider_vehicle_screen.dart';

class RiderProfileSection extends StatelessWidget {
  const RiderProfileSection({super.key});

  static String _date(DateTime? value) {
    if (value == null) return '—';
    return '${value.month.toString().padLeft(2, '0')}/'
        '${value.day.toString().padLeft(2, '0')}/${value.year}';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final auth = context.watch<AuthState>();
    final courier = context.watch<CourierState>();
    final status = courier.isSuspended ? 'Suspended' : 'Active';

    return Column(
      key: const ValueKey('rider-profile-section'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('RIDER PROFILE', style: AppType.eyebrow),
        const SizedBox(height: 12),
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: AppColors.surfaceMuted,
            borderRadius: editorialRadius(0, ShapeTier.card),
          ),
          child: Column(
            children: [
              _row(theme, 'Company', auth.riderCompany ?? '—'),
              _row(theme, 'Vehicle', auth.riderVehicleType ?? '—'),
              _row(theme, 'Plate number', auth.riderPlate ?? '—'),
              _row(theme, 'Licence number', auth.riderLicenseNumber ?? '—'),
              _row(theme, 'Licence expiry', _date(auth.riderLicenseExpiry)),
              _row(
                theme,
                'Verification',
                auth.riderReverificationRequired
                    ? 'For re-verification'
                    : status,
                valueColor: courier.isSuspended
                    ? AppColors.danger
                    : auth.riderReverificationRequired
                    ? AppColors.warning
                    : AppColors.tertiary,
              ),
            ],
          ),
        ),
        if (courier.isSuspended) ...[
          const SizedBox(height: 10),
          Text(
            courier.suspensionReason == null
                ? 'Your account is suspended. Contact Logistics before going online.'
                : 'Suspended: ${courier.suspensionReason}',
            style: theme.textTheme.bodySmall?.copyWith(color: AppColors.danger),
          ),
        ],
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(
              child: _documentTile(
                context,
                key: const ValueKey('rider-document-license'),
                label: "Licence",
                path: auth.riderLicensePath,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _documentTile(
                context,
                key: const ValueKey('rider-document-orcr'),
                label: 'OR/CR',
                path: auth.riderOrCrPath,
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        SizedBox(
          width: double.infinity,
          child: OutlinedButton.icon(
            key: const ValueKey('rider-edit-vehicle'),
            onPressed: () => Navigator.of(context).push(
              AppPageRoute(builder: (_) => const RiderVehicleScreen()),
            ),
            icon: const Icon(Icons.two_wheeler_outlined, size: 18),
            label: const Text('Edit Vehicle & Documents'),
          ),
        ),
      ],
    );
  }

  Widget _row(
    ThemeData theme,
    String label,
    String value, {
    Color? valueColor,
  }) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(
                color: AppColors.textSecondary,
              ),
            ),
          ),
          const SizedBox(width: 12),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.right,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w600,
                color: valueColor ?? AppColors.textPrimary,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _documentTile(
    BuildContext context, {
    required Key key,
    required String label,
    required String? path,
  }) {
    final exists = path != null && File(path).existsSync();
    return OutlinedButton.icon(
      key: key,
      onPressed: () {
        if (!exists) {
          showTimedSnackBar(
            context,
            const SnackBar(
              content: Text('That document is not on this phone.'),
            ),
          );
          return;
        }
        showDialog<void>(
          context: context,
          builder: (dialogContext) => Dialog(
            backgroundColor: AppColors.surface,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  ClipRRect(
                    borderRadius:
                        editorialRadius(0, ShapeTier.card),
                    child: Image.file(
                      File(path),
                      key: ValueKey('document-preview-$label'),
                      fit: BoxFit.contain,
                    ),
                  ),
                  const SizedBox(height: 8),
                  TextButton(
                    onPressed: () => Navigator.of(dialogContext).pop(),
                    child: const Text('Close'),
                  ),
                ],
              ),
            ),
          ),
        );
      },
      icon: Icon(
        exists ? Icons.description_outlined : Icons.file_present_outlined,
        size: 16,
      ),
      label: Text(label),
    );
  }
}
