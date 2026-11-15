// lib/screens/buyer/address_book_screen.dart
//
// Saved delivery addresses, reached from Profile and from Checkout. The book
// lives on AuthState because it belongs to the account and has to be available
// before a checkout screen exists; this screen only renders and edits it.
//
// The default marker is deliberately strict: deleting the current default while
// other rows exist is refused, and setting a new default clears the old marker
// in the same write. Checkout reads exactly one default, so there is no screen
// that has to guess what "default" means.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/address_entry.dart';
import '../../data/ph_address_catalog.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/pressable_scale.dart';
import 'address_form_screen.dart';

class AddressBookScreen extends StatefulWidget {
  const AddressBookScreen({super.key});

  @override
  State<AddressBookScreen> createState() => _AddressBookScreenState();
}

class _AddressBookScreenState extends State<AddressBookScreen> {
  @override
  void initState() {
    super.initState();
    // Seeding notifies listeners, so it waits until the first frame is done
    // rather than running inside this screen's build. The catalogue is warmed
    // first so a legacy profile's province/city names can also resolve their
    // postal code; a failed load still falls back to the synchronous map.
    WidgetsBinding.instance.addPostFrameCallback((_) => _seed());
  }

  Future<void> _seed() async {
    try {
      await PhAddressCatalog.load();
    } catch (_) {
      // Seeding can still use the province -> region fallback.
    }
    if (!mounted) return;
    context.read<AuthState>().ensureAddressBookSeeded();
  }

  Future<void> _openForm([AddressEntry? address]) async {
    await Navigator.of(context).push<bool>(
      AppPageRoute(builder: (_) => AddressFormScreen(address: address)),
    );
  }

  Future<void> _delete(AddressEntry address) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Delete address?'),
        content: Text(
          '${address.recipient}\n${address.summary}\n\n'
          'Orders already placed keep the address they were sent to.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: const Text('Keep'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    final error = context.read<AuthState>().deleteAddress(address.id);
    if (!mounted) return;
    ScaffoldMessenger.of(context)
      ..clearSnackBars()
      ..showSnackBar(SnackBar(content: Text(error ?? 'Address deleted')));
  }

  void _setDefault(AddressEntry address) {
    final changed = context.read<AuthState>().setDefaultAddress(address.id);
    if (!mounted || !changed) return;
    ScaffoldMessenger.of(context)
      ..clearSnackBars()
      ..showSnackBar(
        SnackBar(content: Text('${address.recipient} is now the default')),
      );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final auth = context.watch<AuthState>();
    final addresses = auth.addresses;

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
          children: [
            const EditorialHeader(
              eyebrow: 'DELIVERY',
              title: 'Address Book',
              padding: EdgeInsets.fromLTRB(0, 0, 0, 16),
            ),
            Text(
              '${addresses.length} of ${AuthState.addressBookLimit} saved',
              style: theme.textTheme.labelSmall?.copyWith(
                color: AppColors.textSecondary,
              ),
            ),
            const SizedBox(height: 16),
            if (addresses.isEmpty)
              _EmptyBook(onAdd: _openForm)
            else
              for (final address in addresses)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: _AddressCard(
                    address: address,
                    onEdit: () => _openForm(address),
                    onSetDefault: () => _setDefault(address),
                    onDelete: () => _delete(address),
                  ),
                ),
            const SizedBox(height: 8),
            ElevatedButton.icon(
              key: const ValueKey('add-address'),
              onPressed: () => _openForm(),
              icon: const Icon(Icons.add, size: 18),
              label: const Text('Add Address'),
            ),
            const SizedBox(height: 12),
            Text(
              'Addresses are saved on this device. Each order keeps the address '
              'it was placed with, even if you edit or delete the address later.',
              style: theme.textTheme.labelSmall?.copyWith(
                color: AppColors.textSecondary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _EmptyBook extends StatelessWidget {
  const _EmptyBook({required this.onAdd});

  final VoidCallback onAdd;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(ext.cardRadius),
        border: Border.all(color: AppColors.divider),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('No saved addresses yet', style: theme.textTheme.titleMedium),
          const SizedBox(height: 6),
          Text(
            'Add one here, or add your profile address and it will appear as '
            'the first default.',
            style: theme.textTheme.bodySmall?.copyWith(
              color: AppColors.textSecondary,
            ),
          ),
          const SizedBox(height: 14),
          OutlinedButton(
            onPressed: onAdd,
            child: const Text('Add your first address'),
          ),
        ],
      ),
    );
  }
}

class _AddressCard extends StatelessWidget {
  const _AddressCard({
    required this.address,
    required this.onEdit,
    required this.onSetDefault,
    required this.onDelete,
  });

  final AddressEntry address;
  final VoidCallback onEdit;
  final VoidCallback onSetDefault;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    return PressableScale(
      onTap: onEdit,
      child: Container(
        padding: const EdgeInsets.fromLTRB(16, 14, 8, 14),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(ext.cardRadius),
          border: Border.all(
            color: address.isDefault
                ? AppColors.primaryDark
                : AppColors.divider,
            width: address.isDefault ? 1.4 : 1,
          ),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Wrap(
                          crossAxisAlignment: WrapCrossAlignment.center,
                          spacing: 8,
                          runSpacing: 4,
                          children: [
                            Text(
                              address.recipient,
                              style: theme.textTheme.titleSmall,
                            ),
                            _AddressPill(
                              key: ValueKey('label-${address.id}'),
                              text: address.label.toUpperCase(),
                              background: AppColors.surfaceMuted,
                              foreground: AppColors.textSecondary,
                              border: AppColors.divider,
                            ),
                            if (address.isDefault)
                              _AddressPill(
                                key: ValueKey('default-${address.id}'),
                                text: 'DEFAULT',
                                background: AppColors.primaryDark,
                                foreground: AppColors.textOnDark,
                              ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 3),
                  Text(
                    address.phone,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: AppColors.textSecondary,
                    ),
                  ),
                  const SizedBox(height: 7),
                  Text(address.summary, style: theme.textTheme.bodyMedium),
                  if (address.hasLocation) ...[
                    const SizedBox(height: 6),
                    Row(
                      key: ValueKey('pinned-${address.id}'),
                      children: [
                        Icon(
                          Icons.location_on_rounded,
                          size: 14,
                          color: AppColors.primaryDark,
                        ),
                        const SizedBox(width: 4),
                        Text(
                          'Pinned location',
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: AppColors.textSecondary,
                          ),
                        ),
                      ],
                    ),
                  ],
                  const SizedBox(height: 10),
                  if (!address.isDefault)
                    TextButton.icon(
                      onPressed: onSetDefault,
                      style: TextButton.styleFrom(
                        padding: EdgeInsets.zero,
                        minimumSize: const Size(0, 32),
                        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      ),
                      icon: const Icon(Icons.check_circle_outline, size: 16),
                      label: const Text('Set as default'),
                    ),
                ],
              ),
            ),
            PopupMenuButton<String>(
              key: ValueKey('address-menu-${address.id}'),
              tooltip: 'Address actions',
              onSelected: (value) {
                switch (value) {
                  case 'edit':
                    onEdit();
                  case 'default':
                    onSetDefault();
                  case 'delete':
                    onDelete();
                }
              },
              itemBuilder: (_) => [
                const PopupMenuItem(value: 'edit', child: Text('Edit')),
                if (!address.isDefault)
                  const PopupMenuItem(
                    value: 'default',
                    child: Text('Set as default'),
                  ),
                const PopupMenuItem(value: 'delete', child: Text('Delete')),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _AddressPill extends StatelessWidget {
  const _AddressPill({
    super.key,
    required this.text,
    required this.background,
    required this.foreground,
    this.border,
  });

  final String text;
  final Color background;
  final Color foreground;
  final Color? border;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(20),
        border: border == null ? null : Border.all(color: border!),
      ),
      child: Text(
        text,
        style: theme.textTheme.labelSmall?.copyWith(
          color: foreground,
          fontWeight: FontWeight.w700,
          letterSpacing: 0.6,
          fontSize: 12,
        ),
      ),
    );
  }
}
