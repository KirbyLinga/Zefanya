import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/config/backend_profile.dart';
import 'package:zefanya/config/dev_flags.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/api/webzefanya_contract.dart';

// Run with --dart-define-from-file=env.webzefanya.production.json.
// Every request uses a fake transport; the production server is never called.
void main() {
  test(
    'production defines select guarded WebZefanya APIs and verified writes',
    () async {
      final defines =
          jsonDecode(File('env.webzefanya.production.json').readAsStringSync())
              as Map<String, dynamic>;
      expect(defines, {
        'USE_LIVE_API': true,
        'zefanya.backend': 'webzefanya',
        'zefanya.webzefanya.apiBaseUrl': 'https://zefanya.shop/api',
        'zefanya.webzefanya.courierApp': true,
        'zefanya.webzefanya.allowWrites': false,
        'zefanya.webzefanya.allowVerifiedWrites': true,
        'zefanya.apiBaseUrl': '',
        'zefanya.devTools': false,
      });
      // The normal demo test run checks the file and explicit contract.
      // The documented production-define run also checks compiled selection.
      final productionBuild =
          kActiveApiBaseUrl == defines['zefanya.webzefanya.apiBaseUrl'];
      if (productionBuild) {
        expect(kUseLiveApi, isTrue);
        expect(kBackendProfile, BackendProfile.webzefanya);
        expect(kApiBaseUrl, isEmpty);
        expect(kWebZefanyaCourierApp, isTrue);
        expect(kWebZefanyaAllowWrites, isFalse);
        expect(kWebZefanyaAllowVerifiedWrites, isTrue);
        expect(kShowDevTools, isFalse);
      }
      var requests = 0;
      final client = ApiClient(
        baseUrl: defines['zefanya.webzefanya.apiBaseUrl'] as String,
        tokenStore: InMemoryTokenStore(),
        routePolicy: WebZefanyaContract(
          allowVerifiedWrites: true,
          routes: productionBuild
              ? kWebZefanyaBuildRoutes
              : [...kWebZefanyaRoutes, ...kWebZefanyaCourierRoutes],
        ),
        httpClient: MockClient((request) async {
          requests++;
          expect(request.url.scheme, 'https');
          expect(request.url.host, 'zefanya.shop');
          expect(
            request.url.path,
            anyOf(startsWith('/api/courier/'), '/api/buyer/checkout/quote'),
          );
          expect(request.followRedirects, isFalse);
          return http.Response(jsonEncode({'success': true, 'data': {}}), 200);
        }),
      );
      for (final path in [
        '/courier/profile',
        '/courier/transfers',
        '/courier/transfers/history',
      ]) {
        await client.getJson(path);
      }
      for (final path in [
        '/courier/activate',
        '/courier/login',
        '/courier/token/refresh',
        '/courier/logout',
      ]) {
        await client.postJson(path, body: {});
      }
      expect(requests, 7);
      await client.postJson('/buyer/checkout/quote', body: {});
      await expectLater(
        client.postJson('/buyer/cart', body: {}),
        throwsA(isA<ApiNotImplementedException>()),
      );
      await expectLater(
        client.postJson('/courier/transfers/1/receive', body: {}),
        throwsA(isA<ApiNotImplementedException>()),
      );
      expect(requests, 8);
    },
  );

  test(
    'protected requests reject HTTP and URL credentials before transport',
    () async {
      var requests = 0;
      for (final base in [
        'http://wz.test/api',
        'https://user:password@wz.test/api',
      ]) {
        final client = guardedWebZefanyaClient(
          baseUrl: base,
          tokenStore: InMemoryTokenStore(),
          httpClient: MockClient((_) async {
            requests++;
            return http.Response('{}', 200);
          }),
        );
        await expectLater(
          client.postJson('/courier/login', body: {}),
          throwsA(isA<ApiNotImplementedException>()),
        );
      }
      expect(requests, 0);
    },
  );

  test('demo transport behavior remains unchanged', () async {
    final client = ApiClient(
      baseUrl: 'http://demo.test/api',
      tokenStore: InMemoryTokenStore(),
      httpClient: MockClient((request) async {
        expect(request.url.scheme, 'http');
        expect(request.followRedirects, isTrue);
        return http.Response('{}', 200);
      }),
    );
    await client.getJson('/demo');
  });
}
