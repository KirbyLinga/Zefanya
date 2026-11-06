// lib/data/ph_address_catalog.dart
//
// The app's offline PSGC hierarchy. One JSON asset (assets/data/ph_psgc.json)
// is loaded once and shared by the address book, registration and Edit Profile.
//
// The catalogue is deliberately name-first: the existing forms and saved
// addresses store display names, while the PSGC codes are carried alongside for
// a future backend hand-off. Lookups therefore accept either a code or a name,
// tolerate the old fixtures' labels, and keep an unknown legacy value instead
// of clearing it.

import 'dart:convert';

import 'package:flutter/services.dart';

import 'ph_province_regions.dart';

class PhAddressCatalog {
  PhAddressCatalog._({
    required this.version,
    required this.source,
    required this.regions,
    required this._regionsByCode,
    required this._regionsByNormalizedName,
    required this._provincesByCode,
    required this._provincesByNormalizedName,
    required this._citiesByCode,
    required this._citiesByNormalizedName,
  });

  static const String assetPath = 'assets/data/ph_psgc.json';

  static PhAddressCatalog? _cache;
  static Future<PhAddressCatalog>? _loading;

  final String version;
  final String source;
  final List<PhRegion> regions;

  final Map<String, PhRegion> _regionsByCode;
  final Map<String, PhRegion> _regionsByNormalizedName;
  final Map<String, PhProvince> _provincesByCode;
  final Map<String, PhProvince> _provincesByNormalizedName;
  final Map<String, PhCity> _citiesByCode;
  final Map<String, List<PhCity>> _citiesByNormalizedName;

  /// Loads and caches the asset. Repeated calls share one future, so a screen
  /// can call this from `initState` without paying for a second parse.
  static Future<PhAddressCatalog> load({AssetBundle? bundle}) {
    final cached = _cache;
    if (cached != null) return Future.value(cached);
    return _loading ??= _load(bundle ?? rootBundle);
  }

  static Future<PhAddressCatalog> _load(AssetBundle bundle) async {
    try {
      final raw = await bundle.loadString(assetPath);
      final decoded = jsonDecode(raw);
      if (decoded is! Map<String, dynamic>) {
        throw const FormatException(
          'Philippine address data is not a JSON object.',
        );
      }
      final catalog = PhAddressCatalog.fromJson(decoded);
      _cache = catalog;
      return catalog;
    } catch (_) {
      // A failed load must be retryable. Leaving the rejected future cached
      // would make the Retry button on the form fail forever.
      _loading = null;
      rethrow;
    }
  }

  /// The loaded catalogue, when `main()` or an earlier screen has warmed it.
  static PhAddressCatalog? get maybeLoaded => _cache;

  /// Test hook: lets a test start from a clean load without leaking a parsed
  /// catalogue into the next test.
  static void resetForTest() {
    _cache = null;
    _loading = null;
  }

  factory PhAddressCatalog.fromJson(Map<String, dynamic> json) {
    final regionJson = json['regions'];
    if (regionJson is! List) {
      throw const FormatException('Philippine address data has no regions.');
    }

    final regions = <PhRegion>[];
    final regionsByCode = <String, PhRegion>{};
    final regionsByNormalizedName = <String, PhRegion>{};
    final provincesByCode = <String, PhProvince>{};
    final provincesByNormalizedName = <String, PhProvince>{};
    final citiesByCode = <String, PhCity>{};
    final citiesByNormalizedName = <String, List<PhCity>>{};

    for (final regionValue in regionJson) {
      final regionMap = _asMap(regionValue);
      final region = PhRegion._(
        code: _requiredString(regionMap, 'code'),
        name: _requiredString(regionMap, 'name'),
        shortName: regionMap['shortName'] as String? ?? '',
        officialName: regionMap['officialName'] as String? ?? '',
      );
      regions.add(region);
      regionsByCode[region.code] = region;
      for (final alias in [
        region.name,
        region.shortName,
        region.officialName,
        if (region.isNcr) kMetroManila,
      ]) {
        final key = _normalize(alias);
        if (key.isNotEmpty) {
          regionsByNormalizedName.putIfAbsent(key, () => region);
        }
      }

      final provinceJson = regionMap['provinces'];
      if (provinceJson is List) {
        for (final provinceValue in provinceJson) {
          final provinceMap = _asMap(provinceValue);
          final province = PhProvince._(
            code: _requiredString(provinceMap, 'code'),
            name: _requiredString(provinceMap, 'name'),
            region: region,
          );
          region.provinces.add(province);
          provincesByCode[province.code] = province;
          final provinceKey = _normalizeProvince(province.name);
          if (provinceKey.isNotEmpty) {
            provincesByNormalizedName[provinceKey] = province;
          }

          final cityJson = provinceMap['cities'];
          if (cityJson is List) {
            for (final cityValue in cityJson) {
              final city = _parseCity(_asMap(cityValue), region, province);
              province.cities.add(city);
              _indexCity(city, citiesByCode, citiesByNormalizedName);
            }
          }
        }
      }

      // NCR is province-less: its cities hang directly off the region.
      final cityJson = regionMap['cities'];
      if (cityJson is List) {
        for (final cityValue in cityJson) {
          final city = _parseCity(_asMap(cityValue), region, null);
          region.cities.add(city);
          _indexCity(city, citiesByCode, citiesByNormalizedName);
        }
      }
    }

    return PhAddressCatalog._(
      version: json['version'] as String? ?? 'PSGC',
      source: json['source'] as String? ?? '',
      regions: regions,
      regionsByCode: regionsByCode,
      regionsByNormalizedName: regionsByNormalizedName,
      provincesByCode: provincesByCode,
      provincesByNormalizedName: provincesByNormalizedName,
      citiesByCode: citiesByCode,
      citiesByNormalizedName: citiesByNormalizedName,
    );
  }

  static PhCity _parseCity(
    Map<String, dynamic> map,
    PhRegion region,
    PhProvince? province,
  ) {
    final city = PhCity._(
      code: _requiredString(map, 'code'),
      name: _requiredString(map, 'name'),
      postalCode: map['postalCode'] as String? ?? '',
      region: region,
      province: province,
    );
    final barangayJson = map['barangays'];
    if (barangayJson is List) {
      for (final value in barangayJson) {
        if (value is List && value.length >= 2) {
          final code = value[0];
          final name = value[1];
          if (code is String && name is String) {
            city.barangays.add(PhBarangay(code: code, name: name));
          }
        }
      }
    }
    return city;
  }

  static void _indexCity(
    PhCity city,
    Map<String, PhCity> byCode,
    Map<String, List<PhCity>> byName,
  ) {
    byCode[city.code] = city;
    final keys = {_normalize(city.name), _normalizeCity(city.name)};
    for (final key in keys) {
      if (key.isEmpty) continue;
      byName.putIfAbsent(key, () => <PhCity>[]).add(city);
    }
  }

  PhRegion? regionByName(String? name) {
    final raw = _clean(name);
    if (raw == null) return null;
    final byCode = _regionsByCode[raw];
    if (byCode != null) return byCode;

    final key = _normalize(raw);
    final exact = _regionsByNormalizedName[key];
    if (exact != null) return exact;

    // "CALABARZON (Region IV-A)" -> "calabarzon" for callers that only kept
    // the leading label.
    final withoutParenthetical = _normalize(
      raw.replaceAll(RegExp(r'\([^)]*\)'), ''),
    );
    return _regionsByNormalizedName[withoutParenthetical];
  }

  PhProvince? provinceByName(String? name, {PhRegion? region}) {
    final raw = _clean(name);
    if (raw == null) return null;
    final byCode = _provincesByCode[raw];
    if (byCode != null) return byCode;

    final key = _normalizeProvince(raw);
    final match = _provincesByNormalizedName[key];
    if (match != null) {
      if (region == null || match.region == region) return match;
    }

    if (region != null) {
      for (final province in region.provinces) {
        if (_normalizeProvince(province.name) == key) return province;
      }
    }
    return null;
  }

  PhCity? cityByName(String? name, {PhProvince? province, PhRegion? region}) {
    final raw = _clean(name);
    if (raw == null) return null;
    final byCode = _citiesByCode[raw];
    if (byCode != null) return byCode;

    final rawKey = _normalize(raw);
    final keys = {rawKey, _normalizeCity(raw)};
    for (final key in keys) {
      final candidates = _citiesByNormalizedName[key];
      if (candidates == null) continue;
      final filtered = [
        for (final city in candidates)
          if ((province == null || city.province == province) &&
              (region == null || city.region == region))
            city,
      ];
      if (filtered.isEmpty) continue;
      for (final city in filtered) {
        if (_normalize(city.name) == rawKey) return city;
      }
      return filtered.first;
    }
    return null;
  }

  PhBarangay? barangayByName(String? name, PhCity city) {
    final raw = _clean(name);
    if (raw == null) return null;
    for (final barangay in city.barangays) {
      if (barangay.code == raw) return barangay;
    }
    final key = _normalize(raw);
    for (final barangay in city.barangays) {
      if (_normalize(barangay.name) == key) return barangay;
    }
    return null;
  }

  /// Rebuilds a selection from saved names/codes. Unresolved legacy names are
  /// kept verbatim: an old address should remain editable rather than being
  /// silently blanked because its fixture used a name the PSGC does not.
  PhAddressSelection resolveSelection({
    String? region,
    String? province,
    String? city,
    String? barangay,
    String? postalCode,
    String? regionCode,
    String? provinceCode,
    String? cityCode,
    String? barangayCode,
  }) {
    var resolvedRegion = _clean(regionCode) == null
        ? null
        : _regionsByCode[_clean(regionCode)!];
    resolvedRegion ??= regionByName(region);

    var resolvedProvince = _clean(provinceCode) == null
        ? null
        : _provincesByCode[_clean(provinceCode)!];
    resolvedProvince ??= provinceByName(province, region: resolvedRegion);

    var resolvedCity = _clean(cityCode) == null
        ? null
        : _citiesByCode[_clean(cityCode)!];
    resolvedCity ??= cityByName(
      city,
      province: resolvedProvince,
      region: resolvedRegion,
    );
    if (resolvedCity != null) {
      resolvedRegion ??= resolvedCity.region;
      resolvedProvince ??= resolvedCity.province;
    }
    if (resolvedProvince != null) {
      resolvedRegion ??= resolvedProvince.region;
    }

    final resolvedBarangay = resolvedCity == null
        ? null
        : barangayByName(barangay, resolvedCity);
    final ncr = resolvedRegion?.isNcr ?? _looksLikeNcr(region);

    return PhAddressSelection(
      region: resolvedRegion?.name ?? _clean(region),
      province:
          resolvedProvince?.name ?? (ncr ? kMetroManila : _clean(province)),
      city: resolvedCity?.name ?? _clean(city),
      barangay: resolvedBarangay?.name ?? _clean(barangay),
      postalCode: _clean(postalCode) ?? resolvedCity?.postalCode ?? '',
      regionCode: resolvedRegion?.code ?? _clean(regionCode),
      provinceCode: resolvedProvince?.code ?? _clean(provinceCode),
      cityCode: resolvedCity?.code ?? _clean(cityCode),
      barangayCode: resolvedBarangay?.code ?? _clean(barangayCode),
    );
  }
}

class PhRegion {
  PhRegion._({
    required this.code,
    required this.name,
    required this.shortName,
    required this.officialName,
  });

  final String code;
  final String name;
  final String shortName;
  final String officialName;

  /// Province-less region. Its [cities] are the top-level localities.
  final List<PhProvince> provinces = [];
  final List<PhCity> cities = [];

  bool get isNcr => code == '1300000000' || shortName.toUpperCase() == 'NCR';

  List<PhCity> get allCities => [
    ...cities,
    for (final province in provinces) ...province.cities,
  ];

  @override
  String toString() => name;
}

class PhProvince {
  PhProvince._({required this.code, required this.name, required this.region});

  final String code;
  final String name;
  final PhRegion region;
  final List<PhCity> cities = [];

  @override
  String toString() => name;
}

class PhCity {
  PhCity._({
    required this.code,
    required this.name,
    required this.postalCode,
    required this.region,
    this.province,
  });

  final String code;
  final String name;
  final String postalCode;
  final PhRegion region;

  /// Null only for NCR's province-less localities.
  final PhProvince? province;
  final List<PhBarangay> barangays = [];

  /// The label the rest of the app should show as the province. NCR has no
  /// province in the PSGC, but "Metro Manila" is the useful mailing label.
  String get provinceLabel =>
      province?.name ?? (region.isNcr ? kMetroManila : region.name);

  bool get isNcr => region.isNcr;

  @override
  String toString() => name;
}

class PhBarangay {
  const PhBarangay({required this.code, required this.name});

  final String code;
  final String name;

  @override
  String toString() => name;
}

/// The four address levels plus the postal code and their PSGC codes.
///
/// Kept separate from [AddressEntry] because the form and the catalogue work
/// with a selection that may be incomplete while the buyer is still choosing.
class PhAddressSelection {
  const PhAddressSelection({
    this.region,
    this.province,
    this.city,
    this.barangay,
    this.postalCode = '',
    this.regionCode,
    this.provinceCode,
    this.cityCode,
    this.barangayCode,
  });

  final String? region;
  final String? province;
  final String? city;
  final String? barangay;
  final String postalCode;
  final String? regionCode;
  final String? provinceCode;
  final String? cityCode;
  final String? barangayCode;

  bool get isEmpty =>
      (region ?? '').isEmpty &&
      (province ?? '').isEmpty &&
      (city ?? '').isEmpty &&
      (barangay ?? '').isEmpty;

  PhAddressSelection withRegion(PhRegion region) => PhAddressSelection(
    region: region.name,
    regionCode: region.code,
    province: region.isNcr ? kMetroManila : null,
  );

  PhAddressSelection withProvince(PhProvince province) => PhAddressSelection(
    region: region ?? province.region.name,
    regionCode: regionCode ?? province.region.code,
    province: province.name,
    provinceCode: province.code,
  );

  PhAddressSelection withCity(PhCity city) => PhAddressSelection(
    region: city.region.name,
    regionCode: city.region.code,
    province: city.province?.name ?? (city.region.isNcr ? kMetroManila : null),
    provinceCode: city.province?.code,
    city: city.name,
    cityCode: city.code,
    postalCode: city.postalCode,
  );

  PhAddressSelection withBarangay(PhBarangay barangay) => PhAddressSelection(
    region: region,
    regionCode: regionCode,
    province: province,
    provinceCode: provinceCode,
    city: city,
    cityCode: cityCode,
    barangay: barangay.name,
    barangayCode: barangay.code,
    postalCode: postalCode,
  );

  PhAddressSelection withPostalCode(String value) => PhAddressSelection(
    region: region,
    regionCode: regionCode,
    province: province,
    provinceCode: provinceCode,
    city: city,
    cityCode: cityCode,
    barangay: barangay,
    barangayCode: barangayCode,
    postalCode: value,
  );
}

Map<String, dynamic> _asMap(Object? value) {
  if (value is Map<String, dynamic>) return value;
  if (value is Map) {
    return value.map((key, value) => MapEntry(key.toString(), value));
  }
  throw const FormatException('Philippine address data has a malformed row.');
}

String _requiredString(Map<String, dynamic> map, String key) {
  final value = map[key];
  if (value is! String || value.isEmpty) {
    throw FormatException('Philippine address data is missing "$key".');
  }
  return value;
}

String? _clean(String? value) {
  final trimmed = value?.trim();
  return trimmed == null || trimmed.isEmpty ? null : trimmed;
}

String _normalize(String? value) =>
    (value ?? '').toLowerCase().replaceAll(RegExp(r'[^a-z0-9]+'), ' ').trim();

String _normalizeCity(String? value) =>
    _normalize(value).replaceAll(RegExp(r'\bcity\b'), '').trim();

String _normalizeProvince(String? value) {
  var key = _normalize(value);
  key = key.replaceFirst(RegExp(r'^province of '), '');
  key = key.replaceFirst(RegExp(r' province$'), '');
  return key.trim();
}

bool _looksLikeNcr(String? value) {
  final key = _normalize(value);
  return key.contains('ncr') ||
      key.contains('national capital') ||
      key.contains('metro manila');
}
