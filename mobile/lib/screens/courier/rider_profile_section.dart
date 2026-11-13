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
import 'rider_vehicle_screen.dart';

class RiderProfileSection extends StatelessWidget {
  const RiderProfileSection({super.key});

  static String? _date(DateTime? value) {
    if (value == null) return null;
    return '${value.month.toString().padLeft(2, '0')}/'
        '${value.day.toString().padLeft(2, '0')}/${value.year}';
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final auth = context.watch<AuthState>();
    final courier = context.watch<CourierState>();
    final status = courier.isSuspended ? 'Suspended' : 'Active';

    if (courier.hasServerRecords && courier.coverage == null) {
      // Read once, after this frame; the rows appear when it answers.
      WidgetsBinding.instance.addPostFrameCallback(
        (_) => courier.refreshCoverageOnce(),
      );
    }

    return Column(
      key: const ValueKey('rider-profile-section'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('RIDER PROFILE', style: AppType.eyebrow),
        const SizedBox(height: 12),
        Container(
          decoration: BoxDecoration(
            color: AppColors.surface, // Opaque surface token behind data rows
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: AppColors.divider),
          ),
          child: Column(
            children: [
              _row(
                context,
                label: 'Verification',
                value: auth.riderReverificationRequired
                    ? 'For re-verification'
                    : status,
                valueColor: courier.isSuspended
                    ? AppColors.danger
                    : auth.riderReverificationRequired
                    ? AppColors.warning
                    : AppColors.successText,
              ),
              Divider(height: 1, color: AppColors.divider),
              _row(
                context,
                label: 'Availability',
                value:
                    courier.isOnline &&
                        !courier.isWindingDown &&
                        !courier.isSuspended
                    ? 'Online'
                    : 'Offline',
              ),
              Divider(height: 1, color: AppColors.divider),
              _row(
                context,
                label: 'Company',
                value: auth.riderCompany,
                emptyPrompt: 'Add company',
              ),
              // Live mode: the station and coverage area from WebZefanya
              // (GET /api/rider/profile). Read-only; Logistics assigns them.
              if (courier.coverage?.stationLabel != null) ...[
                Divider(height: 1, thickness: 1, color: AppColors.divider),
                _row(
                  context,
                  label: 'Station hub',
                  value: courier.coverage!.stationLabel,
                ),
              ],
              if (courier.coverage?.areaLabel != null) ...[
                Divider(height: 1, thickness: 1, color: AppColors.divider),
                _row(
                  context,
                  label: 'Coverage',
                  value: courier.coverage!.areaLabel,
                ),
              ],
              Divider(height: 1, thickness: 1, color: AppColors.divider),
              _row(
                context,
                label: 'Vehicle',
                value: auth.riderVehicleType,
                emptyPrompt: 'Add vehicle',
              ),
              Divider(height: 1, thickness: 1, color: AppColors.divider),
              _row(
                context,
                label: 'Plate number',
                value: auth.riderPlate,
                emptyPrompt: 'Add plate number',
              ),
              Divider(height: 1, thickness: 1, color: AppColors.divider),
              _row(
                context,
                label: 'Licence number',
                value: auth.riderLicenseNumber,
                emptyPrompt: 'Add licence number',
              ),
              Divider(height: 1, thickness: 1, color: AppColors.divider),
              _row(
                context,
                label: 'Licence expiry',
                value: _date(auth.riderLicenseExpiry),
                emptyPrompt: 'Add licence expiry',
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
        const SizedBox(height: 14),
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
            onPressed: () => Navigator.of(
              context,
            ).push(AppPageRoute(builder: (_) => const RiderVehicleScreen())),
            icon: const Icon(Icons.two_wheeler_outlined, size: 18),
            label: const Text('Edit Vehicle & Documents'),
          ),
        ),
      ],
    );
  }

  Widget _row(
    BuildContext context, {
    required String label,
    required String? value,
    String? emptyPrompt,
    Color? valueColor,
  }) {
    final val = value;
    final hasValue = val != null && val.trim().isNotEmpty && val != '—';
    final prompt = emptyPrompt ?? 'Add ${label.toLowerCase()}';

    final rowWidget = Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Expanded(
            child: Text(
              label,
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w500,
                color: AppColors.textSecondary, // 12sp muted
              ),
            ),
          ),
          const SizedBox(width: 12),
          Flexible(
            child: Text(
              hasValue ? val : prompt,
              textAlign: TextAlign.right,
              style: TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.w600, // 15sp semi-bold
                color: hasValue
                    ? (valueColor ?? AppColors.textPrimary)
                    : AppColors.accent,
                decoration: hasValue ? null : TextDecoration.underline,
              ),
            ),
          ),
        ],
      ),
    );

    if (!hasValue) {
      return Semantics(
        label: prompt,
        button: true,
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: () => Navigator.of(
            context,
          ).push(AppPageRoute(builder: (_) => const RiderVehicleScreen())),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 48),
            child: rowWidget,
          ),
        ),
      );
    }

    return ConstrainedBox(
      constraints: const BoxConstraints(minHeight: 48),
      child: rowWidget,
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
                    borderRadius: BorderRadius.circular(12),
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
