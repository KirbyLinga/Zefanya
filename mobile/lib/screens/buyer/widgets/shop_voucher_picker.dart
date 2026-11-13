import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../state/cart_state.dart';
import '../../../state/order_state.dart';

Future<String?> pickShopVoucher(BuildContext context, List<CartItem> items) {
  final offers = context.read<OrderState>().serverVoucherOffers(items);
  return showModalBottomSheet<String>(
    context: context,
    useSafeArea: true,
    isScrollControlled: true,
    builder: (context) => SafeArea(
      child: SizedBox(
        height: MediaQuery.sizeOf(context).height * 0.55,
        child: FutureBuilder(
          future: offers,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return const Center(
                child: Text(
                  'Could not load shop vouchers. Close and try again.',
                ),
              );
            }
            final available = snapshot.requireData;
            if (available.isEmpty) {
              return const Center(child: Text('No shop vouchers available.'));
            }
            return ListView(
              children: [
                for (final offer in available)
                  ListTile(
                    title: Text(offer.code),
                    subtitle: Text(offer.summary),
                    onTap: () => Navigator.pop(context, offer.code),
                  ),
              ],
            );
          },
        ),
      ),
    ),
  );
}
