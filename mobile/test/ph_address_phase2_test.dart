// test/ph_address_phase2_test.dart
//
// Phase 2: the offline PSGC hierarchy, postal codes, Home/Work/Other labels and
// the optional location pin.
//
// The catalogue is read through rootBundle, which uses real file I/O; widget
// tests warm it with tester.runAsync so the fake test clock cannot strand the
// load. Once warmed, PhAddressCatalog caches it for every later test.

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/address_entry.dart';
import 'package:zefanya/data/ph_address_catalog.dart';
import 'package:zefanya/data/ph_regions.dart';
import 'package:zefanya/screens/buyer/address_form_screen.dart';
import 'package:zefanya/services/location_service.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/widgets/searchable_picker_field.dart';

AddressEntry _ncrAddress({
  String id = '',
  String recipient = 'Ana Reyes',
  String phone = '09987654321',
  String city = 'Quezon City',
  String barangay = 'Commonwealth',
  String street = '88 Sampaguita St',
  String postalCode = '1100',
  String label = 'Home',
  bool isDefault = false,
}) => AddressEntry(
  id: id,
  recipient: recipient,
  phone: phone,
  region: 'National Capital Region (NCR)',
  city: city,
  barangay: barangay,
  street: street,
  postalCode: postalCode,
  label: label,
  isDefault: isDefault,
);

Future<void> _pumpForm(
  WidgetTester tester, {
  required AuthState auth,
  AddressEntry? address,
  LocationService locationService = const MockLocationService(),
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 1700);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.runAsync(() => PhAddressCatalog.load());

  await tester.pumpWidget(
    ChangeNotifierProvider<AuthState>.value(
      value: auth,
      child: MaterialApp(
        theme: AppTheme.light,
        home: Builder(
          builder: (context) => Scaffold(
            body: Center(
              child: ElevatedButton(
                key: const ValueKey('open-address-form'),
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => AddressFormScreen(
                      address: address,
                      locationService: locationService,
                    ),
                  ),
                ),
                child: const Text('Open'),
              ),
            ),
          ),
        ),
      ),
    ),
  );
  await tester.tap(find.byKey(const ValueKey('open-address-form')));
  await tester.pumpAndSettle();
}

Future<void> _pick(WidgetTester tester, String fieldKey, String value) async {
  await tester.tap(find.byKey(ValueKey(fieldKey)));
  await tester.pumpAndSettle();
  await tester.enterText(find.byKey(const ValueKey('picker-search')), value);
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(ValueKey('picker-option-$value')));
  await tester.pumpAndSettle();
}

Future<void> _fillRequiredNcr(WidgetTester tester) async {
  await tester.enterText(
    find.byKey(const ValueKey('address-recipient')),
    'Ana Reyes',
  );
  await tester.enterText(
    find.byKey(const ValueKey('address-phone')),
    '09987654321',
  );
  await _pick(tester, 'address-region', 'National Capital Region (NCR)');
  await _pick(tester, 'address-city', 'Quezon City');
  await _pick(tester, 'address-barangay', 'Commonwealth');
  await tester.enterText(
    find.byKey(const ValueKey('address-street')),
    '88 Sampaguita St',
  );
  await tester.pumpAndSettle();
}

TextField _textField(WidgetTester tester, String key) =>
    tester.widget<TextField>(
      find.descendant(
        of: find.byKey(ValueKey(key)),
        matching: find.byType(TextField),
      ),
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('offline PSGC catalogue', () {
    testWidgets('bundles the full hierarchy with unique codes', (tester) async {
      final catalog = await tester.runAsync(() => PhAddressCatalog.load());
      expect(catalog, isNotNull);

      final regions = catalog!.regions;
      final provinces = [for (final region in regions) ...region.provinces];
      final cities = [
        for (final region in regions) ...[
          ...region.cities,
          for (final province in region.provinces) ...province.cities,
        ],
      ];
      final barangays = [for (final city in cities) ...city.barangays];

      expect(regions, hasLength(18));
      expect(provinces, hasLength(83));
      expect(cities, hasLength(1642));
      expect(barangays, hasLength(42010));

      expect({for (final region in regions) region.code}, hasLength(18));
      expect({for (final city in cities) city.code}, hasLength(cities.length));
      expect({
        for (final barangay in barangays) barangay.code,
      }, hasLength(barangays.length));

      // Every city carries a postal code, and every code is the four digits
      // validatePostalCode accepts.
      expect(cities.every((city) => city.postalCode.isNotEmpty), isTrue);
      expect(
        cities.every((city) => RegExp(r'^\d{4}$').hasMatch(city.postalCode)),
        isTrue,
      );
    });

    testWidgets('NCR is province-less and labels its localities Metro Manila', (
      tester,
    ) async {
      final catalog = await tester.runAsync(() => PhAddressCatalog.load());
      final ncr = catalog!.regionByName('National Capital Region (NCR)');

      expect(ncr, isNotNull);
      expect(ncr!.isNcr, isTrue);
      expect(ncr.provinces, isEmpty);
      expect(ncr.cities, hasLength(17));
      expect(catalog.regionByName('Metro Manila'), same(ncr));

      final quezonCity = catalog.cityByName('Quezon City', region: ncr);
      expect(quezonCity, isNotNull);
      expect(quezonCity!.province, isNull);
      expect(quezonCity.provinceLabel, 'Metro Manila');
      expect(quezonCity.postalCode, '1100');
    });

    testWidgets('Manila is merged and special areas keep their parents', (
      tester,
    ) async {
      final catalog = await tester.runAsync(() => PhAddressCatalog.load());
      final ncr = catalog!.regionByName('National Capital Region (NCR)')!;
      final manila = catalog.cityByName('Manila City', region: ncr);

      expect(manila, isNotNull);
      expect(manila!.barangays, hasLength(897));
      expect(catalog.cityByName('Manila', region: ncr), isNotNull);
      expect(ncr.cities.where((city) => city.name == 'Manila'), isEmpty);

      final barmm = catalog.regionByName(
        'Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)',
      )!;
      final sga = barmm.provinces.firstWhere(
        (province) => province.name == 'Special Geographic Area',
      );
      expect(sga.cities, hasLength(8));
      expect(sga.cities.every((city) => city.province == sga), isTrue);

      final basilan = barmm.provinces.firstWhere(
        (province) => province.name == 'Basilan',
      );
      expect(basilan.cities.any((city) => city.name == 'Isabela City'), isTrue);

      final region9 = catalog.regionByName('Zamboanga Peninsula (Region IX)')!;
      final sulu = region9.provinces.firstWhere(
        (province) => province.name == 'Sulu',
      );
      expect(sulu.cities, isNotEmpty);
    });
  });

  group('lookups and legacy rows', () {
    testWidgets('legacy labels resolve to canonical catalogue rows', (
      tester,
    ) async {
      final catalog = await tester.runAsync(() => PhAddressCatalog.load());

      final ncr = catalog!.resolveSelection(
        region: 'NCR',
        province: 'Metro Manila',
        city: 'Quezon City',
        barangay: 'Commonwealth',
      );
      expect(ncr.region, 'National Capital Region (NCR)');
      expect(ncr.province, 'Metro Manila');
      expect(ncr.city, 'Quezon City');
      expect(ncr.barangay, 'Commonwealth');
      expect(ncr.postalCode, '1100');
      expect(ncr.regionCode, isNotNull);
      expect(ncr.cityCode, isNotNull);
      expect(ncr.barangayCode, isNotNull);

      expect(regionForProvince('Cebu'), 'Central Visayas (Region VII)');
      expect(
        regionForProvince('Province of Cebu'),
        'Central Visayas (Region VII)',
      );
      expect(
        regionForProvince('Metro Manila'),
        'National Capital Region (NCR)',
      );
      expect(isNcrRegionName('Metro Manila'), isTrue);
    });

    testWidgets('an unknown legacy address is kept editable, not blanked', (
      tester,
    ) async {
      final catalog = await tester.runAsync(() => PhAddressCatalog.load());

      final selection = catalog!.resolveSelection(
        region: 'National Capital Region (NCR)',
        city: 'Atlantis City',
        barangay: 'Old Barangay',
        postalCode: '9999',
      );
      expect(selection.region, 'National Capital Region (NCR)');
      expect(selection.city, 'Atlantis City');
      expect(selection.barangay, 'Old Barangay');
      expect(selection.postalCode, '9999');
    });

    test('AddressEntry reads pre-Phase-2 JSON with safe defaults', () {
      final old = AddressEntry.fromJson({
        'id': 'ADDR-1',
        'recipient': 'Ana Reyes',
        'phone': '09987654321',
        'region': 'National Capital Region (NCR)',
        'city': 'Quezon City',
        'barangay': 'Commonwealth',
        'street': '88 Sampaguita St',
      });

      expect(old, isNotNull);
      expect(old!.province, '');
      expect(old.postalCode, '');
      expect(old.label, 'Home');
      expect(old.regionCode, isNull);
      expect(old.hasLocation, isFalse);
      expect(old.isDefault, isFalse);
      // NCR has no PSGC province, so the old row still reads sensibly.
      expect(
        old.summary,
        '88 Sampaguita St, Commonwealth, Quezon City, Metro Manila',
      );
    });

    test('AddressEntry round-trips the Phase 2 fields and display format', () {
      const entry = AddressEntry(
        id: 'ADDR-2',
        recipient: 'Ana Reyes',
        phone: '09987654321',
        region: 'Central Visayas (Region VII)',
        province: 'Cebu',
        city: 'Cebu City',
        barangay: 'Lahug',
        street: '12 Mabini Street',
        postalCode: '6000',
        label: 'Work',
        regionCode: '0700000000',
        provinceCode: '0722000000',
        cityCode: '0730600000',
        barangayCode: '0730600001',
        latitude: 10.3157,
        longitude: 123.8854,
        isDefault: true,
      );

      final restored = AddressEntry.fromJson(
        jsonDecode(jsonEncode(entry.toJson())) as Map<String, dynamic>,
      );
      expect(restored, isNotNull);
      expect(restored!.province, 'Cebu');
      expect(restored.postalCode, '6000');
      expect(restored.label, 'Work');
      expect(restored.regionCode, '0700000000');
      expect(restored.provinceCode, '0722000000');
      expect(restored.cityCode, '0730600000');
      expect(restored.barangayCode, '0730600001');
      expect(restored.latitude, closeTo(10.3157, 1e-9));
      expect(restored.longitude, closeTo(123.8854, 1e-9));
      expect(restored.isDefault, isTrue);
      expect(
        restored.summary,
        '12 Mabini Street, Lahug, Cebu City, Cebu, 6000',
      );
    });

    test(
      'stored pre-Phase-2 addresses load and still become the default',
      () async {
        SharedPreferences.setMockInitialValues({
          'auth_addresses': jsonEncode([
            {
              'id': 'ADDR-1',
              'recipient': 'Ana Reyes',
              'phone': '09987654321',
              'region': 'National Capital Region (NCR)',
              'city': 'Quezon City',
              'barangay': 'Commonwealth',
              'street': '88 Sampaguita St',
            },
          ]),
        });

        final auth = AuthState();
        await auth.load();
        expect(auth.addresses, hasLength(1));
        expect(auth.addresses.single.isDefault, isTrue);
        expect(auth.addresses.single.label, 'Home');
        expect(
          auth.defaultAddress!.summary,
          '88 Sampaguita St, Commonwealth, Quezon City, Metro Manila',
        );
      },
    );
  });

  group('account state', () {
    test('region and postal code survive a fresh load', () async {
      final auth = AuthState();
      auth.updateProfile(
        firstName: 'Marielle',
        lastName: 'Santos',
        phone: '09171234567',
        region: 'Central Visayas (Region VII)',
        province: 'Cebu',
        municipality: 'Cebu City',
        barangay: 'Lahug',
        street: '12 Mabini Street',
        postalCode: '6000',
      );
      await pumpEventQueue();

      final restored = AuthState();
      await restored.load();
      expect(restored.region, 'Central Visayas (Region VII)');
      expect(restored.postalCode, '6000');
      expect(restored.province, 'Cebu');
      expect(restored.municipality, 'Cebu City');
    });

    test('NCR saves without a province; other regions require one', () {
      final auth = AuthState();
      expect(auth.addAddress(_ncrAddress()), isNull);
      expect(auth.addresses, hasLength(1));

      final missingProvince = AddressEntry(
        id: '',
        recipient: 'Ana Reyes',
        phone: '09987654321',
        region: 'Central Visayas (Region VII)',
        city: 'Cebu City',
        barangay: 'Lahug',
        street: '12 Mabini Street',
        postalCode: '6000',
      );
      expect(auth.addAddress(missingProvince), 'Select a province.');
      expect(auth.addresses, hasLength(1));

      final badPostal = _ncrAddress(postalCode: '11');
      expect(auth.addAddress(badPostal), contains('4-digit postal code'));
      expect(auth.addresses, hasLength(1));
    });

    testWidgets('seeding resolves the profile through the loaded catalogue', (
      tester,
    ) async {
      await tester.runAsync(() => PhAddressCatalog.load());
      final auth = AuthState();
      auth.updateProfile(
        firstName: 'Marielle',
        lastName: 'Santos',
        phone: '09171234567',
        province: 'Cebu',
        municipality: 'Cebu City',
        barangay: 'Lahug',
        street: '12 Mabini Street',
      );

      auth.ensureAddressBookSeeded();
      final seeded = auth.addresses.single;
      expect(seeded.region, 'Central Visayas (Region VII)');
      expect(seeded.province, 'Cebu');
      expect(seeded.city, 'Cebu City');
      expect(seeded.barangay, 'Lahug');
      expect(seeded.postalCode, '6000');
      expect(seeded.regionCode, isNotNull);
      expect(seeded.cityCode, isNotNull);
    });
  });

  group('address form', () {
    testWidgets(
      'selecting a city fills the postal code and keeps it editable',
      (tester) async {
        final auth = AuthState();
        await _pumpForm(tester, auth: auth);

        await _pick(tester, 'address-region', 'National Capital Region (NCR)');
        await _pick(tester, 'address-city', 'Quezon City');
        expect(_textField(tester, 'address-postal').controller!.text, '1100');

        await tester.enterText(
          find.byKey(const ValueKey('address-postal')),
          '1101',
        );
        await tester.pumpAndSettle();
        expect(_textField(tester, 'address-postal').controller!.text, '1101');

        // A new city replaces the old default; the buyer can then edit again.
        await _pick(tester, 'address-city', 'Makati City');
        expect(_textField(tester, 'address-postal').controller!.text, '1200');
        await tester.enterText(
          find.byKey(const ValueKey('address-postal')),
          '1201',
        );

        await tester.enterText(
          find.byKey(const ValueKey('address-recipient')),
          'Ana Reyes',
        );
        await tester.enterText(
          find.byKey(const ValueKey('address-phone')),
          '09987654321',
        );
        await _pick(tester, 'address-barangay', 'Poblacion');
        await tester.enterText(
          find.byKey(const ValueKey('address-street')),
          '88 Sampaguita St',
        );
        await tester.pumpAndSettle();

        await tester.tap(find.byKey(const ValueKey('save-address')));
        await tester.pumpAndSettle();

        expect(auth.addresses, hasLength(1));
        expect(auth.addresses.single.city, 'Makati City');
        expect(auth.addresses.single.postalCode, '1201');
      },
    );

    testWidgets('a new address can be labelled Work and made the default', (
      tester,
    ) async {
      final auth = AuthState();
      auth.updateProfile(
        firstName: 'Marielle',
        lastName: 'Santos',
        phone: '09171234567',
        province: 'Metro Manila',
        municipality: 'Manila',
        barangay: 'Poblacion',
        street: '1 Profile Street',
      );
      auth.ensureAddressBookSeeded();

      await _pumpForm(tester, auth: auth);
      await _fillRequiredNcr(tester);

      await tester.tap(find.byKey(const ValueKey('address-label-Work')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('address-default')));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('save-address')));
      await tester.pumpAndSettle();

      expect(auth.addresses, hasLength(2));
      expect(auth.addresses.last.label, 'Work');
      expect(auth.addresses.last.isDefault, isTrue);
      expect(auth.addresses.first.isDefault, isFalse);
    });

    testWidgets(
      'Use my location pins coordinates, and the pin can be cleared',
      (tester) async {
        final auth = AuthState();
        const point = GeoPoint(latitude: 14.59950, longitude: 120.98420);
        await _pumpForm(
          tester,
          auth: auth,
          locationService: const MockLocationService(point: point),
        );
        await _fillRequiredNcr(tester);

        await tester.tap(find.byKey(const ValueKey('address-use-location')));
        await tester.pumpAndSettle();
        expect(
          find.byKey(const ValueKey('address-location-pin')),
          findsOneWidget,
        );
        expect(find.text('14.59950, 120.98420'), findsOneWidget);

        await tester.tap(find.byKey(const ValueKey('address-location-clear')));
        await tester.pumpAndSettle();
        expect(
          find.byKey(const ValueKey('address-location-pin')),
          findsNothing,
        );
        expect(
          find.byKey(const ValueKey('address-use-location')),
          findsOneWidget,
        );

        await tester.tap(find.byKey(const ValueKey('address-use-location')));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const ValueKey('save-address')));
        await tester.pumpAndSettle();

        expect(auth.addresses, hasLength(1));
        expect(auth.addresses.single.latitude, closeTo(14.59950, 1e-9));
        expect(auth.addresses.single.longitude, closeTo(120.98420, 1e-9));
      },
    );

    testWidgets(
      'a denied location explains itself and the address still saves',
      (tester) async {
        final auth = AuthState();
        await _pumpForm(
          tester,
          auth: auth,
          locationService: const MockLocationService(
            error: LocationException(
              'Location permission was not granted. You can still save the '
              'address without a pin.',
            ),
          ),
        );
        await _fillRequiredNcr(tester);

        await tester.tap(find.byKey(const ValueKey('address-use-location')));
        await tester.pumpAndSettle();

        final error = tester.widget<Text>(
          find.byKey(const ValueKey('address-location-error')),
        );
        expect(error.data, contains('permission was not granted'));
        expect(
          find.byKey(const ValueKey('address-location-pin')),
          findsNothing,
        );

        await tester.tap(find.byKey(const ValueKey('save-address')));
        await tester.pumpAndSettle();
        expect(auth.addresses, hasLength(1));
        expect(auth.addresses.single.hasLocation, isFalse);
      },
    );

    testWidgets('editing pre-fills the cascade, label and pin', (tester) async {
      final auth = AuthState();
      const draft = AddressEntry(
        id: '',
        recipient: 'Ana Reyes',
        phone: '09987654321',
        region: 'National Capital Region (NCR)',
        city: 'Quezon City',
        barangay: 'Commonwealth',
        street: '88 Sampaguita St',
        postalCode: '1100',
        label: 'Work',
        latitude: 14.6760,
        longitude: 121.0437,
      );
      expect(auth.addAddress(draft), isNull);
      final saved = auth.addresses.single;

      await _pumpForm(tester, auth: auth, address: saved);

      expect(find.text('Edit Address'), findsOneWidget);
      expect(find.text('National Capital Region (NCR)'), findsOneWidget);
      expect(find.text('Quezon City'), findsOneWidget);
      expect(find.text('Commonwealth'), findsOneWidget);
      expect(_textField(tester, 'address-postal').controller!.text, '1100');
      expect(
        tester
            .widget<ChoiceChip>(
              find.byKey(const ValueKey('address-label-Work')),
            )
            .selected,
        isTrue,
      );
      expect(
        find.byKey(const ValueKey('address-location-pin')),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const ValueKey('save-address')));
      await tester.pumpAndSettle();
      expect(auth.addresses, hasLength(1));
      expect(auth.addresses.single.label, 'Work');
      expect(auth.addresses.single.latitude, closeTo(14.6760, 1e-9));
      expect(auth.addresses.single.longitude, closeTo(121.0437, 1e-9));
    });
  });

  group('searchable picker', () {
    testWidgets('filters accents, shows an empty state, and returns a choice', (
      tester,
    ) async {
      AppColors.apply(Brightness.light);
      GoogleFonts.config.allowRuntimeFetching = false;
      String? picked;

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.light,
          home: Scaffold(
            body: Builder(
              builder: (context) => Center(
                child: ElevatedButton(
                  key: const ValueKey('open-picker'),
                  onPressed: () async {
                    picked = await showSearchablePickerSheet(
                      context: context,
                      title: 'City / Municipality',
                      searchHint: 'Search city',
                      options: const [
                        PickerOption(value: 'Paranaque', label: 'Parañaque'),
                        PickerOption(value: 'Pasig', label: 'Pasig'),
                      ],
                    );
                  },
                  child: const Text('Open'),
                ),
              ),
            ),
          ),
        ),
      );

      await tester.tap(find.byKey(const ValueKey('open-picker')));
      await tester.pumpAndSettle();
      expect(find.text('Parañaque'), findsOneWidget);

      await tester.enterText(
        find.byKey(const ValueKey('picker-search')),
        'paranaque',
      );
      await tester.pumpAndSettle();
      expect(find.text('Parañaque'), findsOneWidget);
      expect(find.text('Pasig'), findsNothing);

      await tester.enterText(
        find.byKey(const ValueKey('picker-search')),
        'zzz',
      );
      await tester.pumpAndSettle();
      expect(find.text('No matches for "zzz"'), findsOneWidget);

      await tester.enterText(find.byKey(const ValueKey('picker-search')), '');
      await tester.pumpAndSettle();
      await tester.tap(find.text('Pasig'));
      await tester.pumpAndSettle();
      expect(picked, 'Pasig');
    });
  });
}
