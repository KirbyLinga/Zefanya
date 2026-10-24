// test/address_book_test.dart
//
// The address book is account state, not just a form. These tests pin the rules
// the workbook checks directly: one default, a hard cap of ten, both accepted
// phone formats, the region → city → barangay cascade, and the refusal to delete
// the default before another one is chosen.

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/address_entry.dart';
import 'package:zefanya/screens/buyer/address_book_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/theme/app_theme.dart';

AddressEntry _draft({
  String id = 'ADDR-1',
  String recipient = 'Marielle Santos',
  String phone = '09171234567',
  String region = 'National Capital Region (NCR)',
  String city = 'Quezon City',
  String barangay = 'Commonwealth',
  String street = '12 Mabini Street',
  bool isDefault = false,
}) => AddressEntry(
  id: id,
  recipient: recipient,
  phone: phone,
  region: region,
  city: city,
  barangay: barangay,
  street: street,
  isDefault: isDefault,
);

AuthState _seededAuth() {
  final auth = AuthState();
  auth.updateProfile(
    firstName: 'Marielle',
    lastName: 'Santos',
    phone: '09171234567',
    province: 'Metro Manila',
    municipality: 'Manila',
    barangay: 'Poblacion',
    street: '12 Mabini Street',
  );
  auth.ensureAddressBookSeeded();
  return auth;
}

Future<void> _pumpBook(WidgetTester tester, AuthState auth) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    ChangeNotifierProvider<AuthState>.value(
      value: auth,
      child: MaterialApp(
        theme: AppTheme.light,
        home: const AddressBookScreen(),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _choose(WidgetTester tester, int fieldIndex, String value) async {
  await tester.tap(find.byType(DropdownButtonFormField<String>).at(fieldIndex));
  await tester.pumpAndSettle();
  await tester.tap(find.text(value).last);
  await tester.pumpAndSettle();
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('address state', () {
    test('seeds ADDR-1 from the profile and keeps it the only default', () {
      final auth = _seededAuth();
      expect(auth.addresses, hasLength(1));
      expect(auth.addresses.single.id, 'ADDR-1');
      expect(auth.addresses.single.isDefault, isTrue);
      expect(auth.defaultAddress!.recipient, 'Marielle Santos');
      expect(auth.defaultAddress!.region, 'National Capital Region (NCR)');
    });

    test('a second address gets ADDR-2 and leaves ADDR-1 default', () {
      final auth = _seededAuth();
      final error = auth.addAddress(_draft());
      expect(error, isNull);
      expect(auth.addresses.map((a) => a.id), ['ADDR-1', 'ADDR-2']);
      expect(auth.addresses.last.recipient, 'Marielle Santos');
      expect(auth.addresses.last.isDefault, isFalse);
      expect(auth.defaultAddress!.id, 'ADDR-1');
    });

    test('exactly one address is default after moving the marker', () {
      final auth = _seededAuth();
      auth.addAddress(_draft());
      expect(auth.setDefaultAddress('ADDR-2'), isTrue);
      expect(auth.addresses.where((a) => a.isDefault), hasLength(1));
      expect(auth.defaultAddress!.id, 'ADDR-2');
      expect(auth.addresses.first.isDefault, isFalse);
    });

    test('both phone formats save and malformed ones do not', () {
      final auth = _seededAuth();
      for (final phone in ['09912345678', '+639171234567']) {
        expect(auth.addAddress(_draft(phone: phone)), isNull);
      }
      final before = auth.addresses.length;
      for (final phone in ['0917123456', '+63917123456', '0999', '']) {
        expect(auth.addAddress(_draft(phone: phone)), isNotNull);
      }
      expect(auth.addresses, hasLength(before));
    });

    test('the tenth address saves and the eleventh is refused', () {
      final auth = _seededAuth();
      for (var i = 2; i <= 10; i++) {
        expect(auth.addAddress(_draft(street: '$i Mabini Street')), isNull);
      }
      expect(auth.addresses, hasLength(10));
      final error = auth.addAddress(_draft(street: '11 Mabini Street'));
      expect(error, contains('up to 10'));
      expect(auth.addresses, hasLength(10));
    });

    test('the default cannot be deleted until another is chosen', () {
      final auth = _seededAuth();
      auth.addAddress(_draft());
      expect(auth.deleteAddress('ADDR-1'), contains('default'));
      expect(auth.addresses, hasLength(2));
      expect(auth.setDefaultAddress('ADDR-2'), isTrue);
      expect(auth.deleteAddress('ADDR-1'), isNull);
      expect(auth.addresses.single.id, 'ADDR-2');
      expect(auth.addresses.single.isDefault, isTrue);
    });

    test('stored addresses survive a fresh load', () async {
      SharedPreferences.setMockInitialValues({
        'auth_addresses': jsonEncode([
          _draft(id: 'ADDR-1', street: '1 Saved Street', isDefault: false)
              .toJson(),
          _draft(id: 'ADDR-2', street: '2 Saved Street', isDefault: true)
              .toJson(),
        ]),
      });
      final auth = AuthState();
      await auth.load();
      expect(auth.addresses, hasLength(2));
      expect(auth.defaultAddress!.street, '2 Saved Street');
      expect(auth.addresses.first.id, 'ADDR-1');
    });
  });

  group('address book screen', () {
    testWidgets('adds a second address with the values entered', (tester) async {
      final auth = _seededAuth();
      await _pumpBook(tester, auth);

      await tester.tap(find.byKey(const ValueKey('add-address')));
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const ValueKey('address-recipient')),
        'Ana Reyes',
      );
      await tester.enterText(
        find.byKey(const ValueKey('address-phone')),
        '09987654321',
      );
      await _choose(tester, 0, 'National Capital Region (NCR)');
      await _choose(tester, 1, 'Quezon City');
      await _choose(tester, 2, 'Commonwealth');
      await tester.enterText(
        find.byKey(const ValueKey('address-street')),
        '88 Sampaguita St',
      );
      await tester.scrollUntilVisible(
        find.byKey(const ValueKey('save-address')),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('save-address')));
      await tester.pumpAndSettle();

      expect(find.text('Ana Reyes'), findsOneWidget);
      expect(find.text('09987654321'), findsOneWidget);
      expect(
        find.text(
          '88 Sampaguita St, Commonwealth, Quezon City, '
          'National Capital Region (NCR)',
        ),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('default-ADDR-2')), findsNothing);
      expect(find.byKey(const ValueKey('default-ADDR-1')), findsOneWidget);
      expect(auth.addresses.last.isDefault, isFalse);
    });

    testWidgets('changing region clears city and barangay', (tester) async {
      final auth = _seededAuth();
      await _pumpBook(tester, auth);
      await tester.tap(find.byKey(const ValueKey('add-address')));
      await tester.pumpAndSettle();

      await _choose(tester, 0, 'National Capital Region (NCR)');
      await _choose(tester, 1, 'Quezon City');
      await _choose(tester, 2, 'Commonwealth');

      // The NCR city list must not leak a Visayas city.
      await tester.tap(find.byType(DropdownButtonFormField<String>).at(1));
      await tester.pumpAndSettle();
      expect(find.text('Cebu City'), findsNothing);
      expect(find.text('Quezon City'), findsWidgets);
      await tester.tapAt(const Offset(10, 10));
      await tester.pumpAndSettle();

      await _choose(tester, 0, 'Central Visayas (Region VII)');
      expect(find.text('Select city'), findsOneWidget);
      expect(find.text('Select barangay'), findsOneWidget);

      await tester.tap(find.byType(DropdownButtonFormField<String>).at(1));
      await tester.pumpAndSettle();
      expect(find.text('Cebu City'), findsWidgets);
      expect(find.text('Quezon City'), findsNothing);
    });

    testWidgets('blank fields keep the form open with field messages',
        (tester) async {
      final auth = _seededAuth();
      await _pumpBook(tester, auth);
      await tester.tap(find.byKey(const ValueKey('add-address')));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.byKey(const ValueKey('save-address')),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('save-address')));
      await tester.pumpAndSettle();

      expect(find.text('Recipient name is required'), findsOneWidget);
      expect(find.text('Phone number is required'), findsOneWidget);
      expect(find.text('Select a region'), findsOneWidget);
      expect(find.text('Street address is required'), findsOneWidget);
      expect(auth.addresses, hasLength(1));
    });
  });
}
