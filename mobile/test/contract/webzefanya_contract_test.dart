// test/contract/webzefanya_contract_test.dart
//
// The WebZefanya route contract: the checked-in snapshot, the Dart constants
// the runtime guard uses, and a source scan that stops the app from growing a
// call to an endpoint WebZefanya does not have.
//
// WebZefanya is never contacted. The snapshot was produced from a read-only
// `artisan route:list`, and is only compared to here.

import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/webzefanya_contract.dart';

/// Endpoints the app (courier-demo profile) calls that WebZefanya has no route
/// for. Each is intentionally gated in the WebZefanya profile (see
/// `BackendCapabilities.webzefanya`). A path appearing in `lib/` that is in
/// neither the contract nor this list fails the scan below, so adding a call
/// forces an explicit decision.
const Set<String> knownUnsupportedInWebZefanya = {
  // Rider
  '/rider/cod',
  '/rider/payouts',
  '/rider/waybills/x',
  '/rider/proofs/x',
  '/rider/deliveries/x/completion-status',
  '/rider/pickups/x/failed',
  '/rider/pickups/stops/x/finish',
  '/rider/return-pickups',
  '/rider/return-pickups/x/collect',
  // Buyer
  '/buyer/checkout/reconcile',
  '/buyer/reviews/mine',
  '/buyer/shipments/x/products/x/review',
  '/buyer/shipments/x/delivery-dispute',
  '/v1/buyer/checkout/quote', // WebZefanya profile uses /buyer/checkout/quote
  // Courier (linehaul): no Courier authentication exists on WebZefanya (C-AUTH).
  '/courier/duty',
  '/courier/duty/x',
  '/courier/earnings',
  '/courier/history',
  '/courier/profile',
  '/courier/trips',
  '/courier/trips/x/x',
  '/courier/trips/x/breakdown',
  '/courier/vehicle',
  '/courier/vehicle/documents/x',
  '/courier/login',
  '/courier/logout',
  '/courier/push-registration',
};

List<Map<String, Object?>> _snapshotRoutes() {
  final file = File('test/contract/webzefanya_api_snapshot.json');
  final doc = jsonDecode(file.readAsStringSync()) as Map<String, Object?>;
  return (doc['routes'] as List).cast<Map<String, Object?>>();
}

void main() {
  group('snapshot', () {
    test('is version controlled and non-trivial', () {
      final doc =
          jsonDecode(
                File(
                  'test/contract/webzefanya_api_snapshot.json',
                ).readAsStringSync(),
              )
              as Map<String, Object?>;
      expect(doc['route_count'], (doc['routes'] as List).length);
      expect(doc['route_count'], greaterThan(50));
    });

    test('Dart constants match the snapshot exactly', () {
      final fromJson = {
        for (final r in _snapshotRoutes()) '${r['method']} ${r['path']}',
      };
      final fromDart = {for (final r in kWebZefanyaRoutes) '$r'};
      expect(fromDart, fromJson);
      expect(kWebZefanyaRoutes.length, _snapshotRoutes().length);
    });
  });

  group('WebZefanyaContract.supports', () {
    const contract = WebZefanyaContract();

    test('matches templates, ignores query strings and trailing slashes', () {
      expect(contract.supports('GET', '/rider/jobs'), isTrue);
      expect(contract.supports('get', '/rider/jobs/'), isTrue);
      expect(contract.supports('GET', '/rider/history?limit=5'), isTrue);
      expect(contract.supports('POST', '/rider/pickups/42/confirm'), isTrue);
      expect(
        contract.supports('POST', '/buyer/orders/7/shipments/9/return'),
        isTrue,
      );
    });

    test('rejects the methods and paths WebZefanya does not have', () {
      expect(contract.supports('DELETE', '/rider/jobs'), isFalse);
      expect(contract.supports('GET', '/rider/cod'), isFalse);
      expect(
        contract.supports('POST', '/rider/return-pickups/3/collect'),
        isFalse,
      );
      expect(contract.supports('GET', '/courier/trips'), isFalse);
      expect(contract.supports('POST', '/buyer/checkout/reconcile'), isFalse);
      expect(contract.supports('GET', '/rider/jobs/'), isTrue);
      expect(contract.supports('GET', '/rider/pickups//confirm'), isFalse);
    });
  });

  group('WebZefanyaContract.check', () {
    test('an unsupported endpoint throws ApiNotImplementedException', () {
      const contract = WebZefanyaContract(allowWrites: true);
      expect(
        () => contract.check('GET', '/rider/cod'),
        throwsA(isA<ApiNotImplementedException>()),
      );
      expect(
        () => contract.check('POST', '/courier/duty/on'),
        throwsA(isA<ApiNotImplementedException>()),
      );
    });

    test('writes are off by default, except signing in/out and renewal', () {
      const contract = WebZefanyaContract();
      expect(
        () => contract.check('POST', '/rider/availability'),
        throwsA(isA<ApiNotImplementedException>()),
      );
      expect(() => contract.check('GET', '/rider/jobs'), returnsNormally);
      expect(() => contract.check('POST', '/rider/login'), returnsNormally);
      expect(() => contract.check('POST', '/buyer/logout'), returnsNormally);
      expect(
        () => contract.check('POST', '/rider/token/refresh'),
        returnsNormally,
      );
    });

    test('writes inside the contract pass once explicitly enabled', () {
      const contract = WebZefanyaContract(allowWrites: true);
      expect(
        () => contract.check('POST', '/rider/availability'),
        returnsNormally,
      );
    });
  });

  group('source scan: no unclassified endpoint', () {
    // Every `'/buyer/...'`, `'/rider/...'`, `'/courier/...'` or `'/v1/...'`
    // string literal in lib/. `$id` and `${expr}` interpolations become `x`.
    final literal = RegExp(
      r'''['"](/(?:buyer|rider|courier|v1)/[^'"\s]*)['"]''',
    );

    String normalize(String raw) => raw
        .split('?')
        .first
        .replaceAll(RegExp(r'\$\{[^}]*\}'), 'x')
        .replaceAll(RegExp(r'\$\w+'), 'x');

    final found = <String, Set<String>>{};
    for (final entity in Directory('lib').listSync(recursive: true)) {
      if (entity is! File || !entity.path.endsWith('.dart')) continue;
      final text = entity.readAsStringSync();
      for (final match in literal.allMatches(text)) {
        found
            .putIfAbsent(normalize(match.group(1)!), () => {})
            .add(entity.path.replaceAll(r'\', '/'));
      }
    }

    // `any method` check: the scan sees paths, not verbs. A literal that is a
    // whole-segment prefix of a supported route (a base that the code appends
    // `/cancel`, `/return`... to) counts as supported.
    bool supportedByAnyMethod(String path) {
      const contract = WebZefanyaContract();
      if ([
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
      ].any((m) => contract.supports(m, path))) {
        return true;
      }
      final segments = path.split('/');
      return kWebZefanyaRoutes.any((route) {
        final template = route.path.split('/');
        if (template.length <= segments.length) return false;
        for (var i = 0; i < segments.length; i++) {
          final t = template[i];
          if (t.startsWith('{') && t.endsWith('}')) continue;
          if (t != segments[i]) return false;
        }
        return true;
      });
    }

    bool gatedCourierPath(String path) => ['GET', 'POST'].any(
      (m) => const WebZefanyaContract(
        routes: kWebZefanyaCourierRoutes,
      ).supports(m, path),
    );

    test('every literal path is supported or explicitly gated', () {
      final unclassified = <String>[];
      for (final entry in found.entries) {
        if (supportedByAnyMethod(entry.key)) continue;
        if (knownUnsupportedInWebZefanya.contains(entry.key)) continue;
        // Phase 3 Courier API: classified by its own snapshot and only
        // supported in builds with zefanya.webzefanya.courierApp=true.
        if (gatedCourierPath(entry.key)) continue;
        unclassified.add('${entry.key} (${entry.value.join(', ')})');
      }
      expect(
        unclassified,
        isEmpty,
        reason:
            'These paths are neither in the WebZefanya contract nor in '
            'knownUnsupportedInWebZefanya. Classify them (and gate the '
            'feature) before adding the call.',
      );
    });

    test('the known-unsupported list contains no route WebZefanya has', () {
      final wrong = [
        for (final path in knownUnsupportedInWebZefanya)
          if (supportedByAnyMethod(path)) path,
      ];
      expect(wrong, isEmpty);
    });

    test('the scan actually found the app calls it is guarding', () {
      expect(found.keys, contains('/rider/jobs'));
      expect(found.keys, contains('/rider/return-pickups'));
      expect(found.length, greaterThan(40));
    });
  });
}
