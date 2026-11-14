// lib/screens/courier/rider_waybill_screen.dart
//
// The private digital waybill (GET /api/rider/waybills/{id}). The server
// decides what is shown: the seller's contact on a pickup, the buyer's on a
// delivery, and the COD amount to collect. Never cached on the device.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/api/api_exception.dart';
import '../../data/dto/remote_courier.dart';
import '../../state/courier_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/microcopy.dart';
import '../../utils/money_format.dart';

class RiderWaybillScreen extends StatefulWidget {
  const RiderWaybillScreen({super.key, required this.jobId});

  final String jobId;

  @override
  State<RiderWaybillScreen> createState() => _RiderWaybillScreenState();
}

class _RiderWaybillScreenState extends State<RiderWaybillScreen> {
  late Future<RiderWaybill> _waybill;

  @override
  void initState() {
    super.initState();
    _waybill = context.read<CourierState>().waybillFor(widget.jobId);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Waybill')),
      body: FutureBuilder<RiderWaybill>(
        future: _waybill,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          final error = snapshot.error;
          final waybill = snapshot.data;
          if (error != null || waybill == null) {
            return Padding(
              padding: const EdgeInsets.all(24),
              child: Center(
                child: Text(
                  error is ApiException ? Microcopy.calmError(error.message) : 'This waybill could not be loaded.',
                  textAlign: TextAlign.center,
                ),
              ),
            );
          }

          final party = waybill.context == 'pickup' ? waybill.seller : waybill.buyer;
          Widget line(String label, String? value) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 5),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SizedBox(width: 110, child: Text(label, style: theme.textTheme.bodySmall?.copyWith(color: AppColors.textSecondary))),
                    Expanded(child: Text(value == null || value.isEmpty ? '—' : value, style: theme.textTheme.bodyMedium)),
                  ],
                ),
              );

          return ListView(
            key: const ValueKey('rider-waybill'),
            padding: const EdgeInsets.all(20),
            children: [
              Text(waybill.context == 'pickup' ? 'PICKUP WAYBILL' : 'DELIVERY WAYBILL', style: AppType.eyebrow),
              const SizedBox(height: 6),
              Text(waybill.trackingNumber ?? 'No tracking number', style: theme.textTheme.headlineSmall),
              const SizedBox(height: 12),
              line('Status', waybill.statusLabel),
              line('Items', '${waybill.itemCount}'),
              line('Weight', waybill.weight),
              const Divider(height: 24),
              Text(waybill.context == 'pickup' ? 'SELLER' : 'BUYER', style: AppType.eyebrow),
              line('Name', party?.name),
              line('Address', party?.address),
              line('Phone', party?.phone),
              if (waybill.context == 'pickup') line('Destination', waybill.destinationArea),
              const Divider(height: 24),
              Text('PAYMENT', style: AppType.eyebrow),
              line('Method', waybill.paymentLabel),
              if (waybill.collectCod > 0)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                    'Collect ${formatPeso(waybill.collectCod, showDecimals: true)} at the door',
                    key: const ValueKey('waybill-collect-cod'),
                    style: theme.textTheme.titleSmall?.copyWith(color: AppColors.warning),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}
