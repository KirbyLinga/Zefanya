// Live check: the app's own repositories against the real WebZefanya server.
//
// Skipped unless credentials are supplied, so the normal suite never touches
// production. Uses exactly what a live build wires up in main():
// Repositories.forMode (WebZefanya profile, read-only contract installed),
// LiveAuthRepository and CourierTransferRepository.
//
//   LIVE_API_BASE_URL=https://zefanya.shop/api \
//   LIVE_COURIER_LOGIN_ID=... LIVE_COURIER_CODE=... \
//   flutter test test/live/webzefanya_courier_live_test.dart
//
// The Courier must be freshly issued by Logistics (pending activation); the
// test activates it, so revoke that Courier's access afterwards.
import 'dart:io';
import 'dart:math';

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/config/backend_profile.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/courier_transfer_repository.dart';
import 'package:zefanya/data/repositories/auth_repository.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/state/auth_state.dart';

void main() {
  final env = Platform.environment;
  final baseUrl = env['LIVE_API_BASE_URL'] ?? 'https://zefanya.shop/api';
  final loginId = env['LIVE_COURIER_LOGIN_ID'] ?? '';
  final code = env['LIVE_COURIER_CODE'] ?? '';
  final skip = loginId.isEmpty || code.isEmpty
      ? 'Set LIVE_COURIER_LOGIN_ID and LIVE_COURIER_CODE to run'
      : null;

  test(
    'app repositories reach live WebZefanya as a Courier',
    // flutter_test answers every HTTP request with 400; run the body under
    // default (real) overrides so requests reach the server.
    () => HttpOverrides.runWithHttpOverrides(() async {
      final repos = Repositories.forMode(
        useLive: true,
        baseUrl: baseUrl,
        tokenStore: InMemoryTokenStore(),
        profile: BackendProfile.webzefanya,
      );
      final client = repos.client!;
      final transfers = CourierTransferRepository(client);

      // Public read the app's connection watcher uses.
      await client.getJson('/buyer/products', authenticated: false);

      final rnd = Random.secure();
      final password =
          'Lv-${List.generate(12, (_) => rnd.nextInt(36).toRadixString(36)).join()}';

      final activated = await (repos.auth as LiveAuthRepository)
          .activateCourier(
            loginId: loginId,
            activationCode: code,
            password: password,
          );
      expect(activated.role, UserRole.linehaul);

      final profile = await transfers.profile();
      expect(profile.loginId, loginId);
      await transfers.assignments();
      await transfers.history();

      expect(await repos.auth.refresh(role: UserRole.linehaul), isNotNull);
      await transfers.profile();

      await repos.auth.logout(role: UserRole.linehaul);
      await expectLater(transfers.profile(), throwsA(isA<ApiException>()));

      final again = await repos.auth.login(
        role: UserRole.linehaul,
        email: loginId,
        password: password,
      );
      expect(again.role, UserRole.linehaul);
      await transfers.profile();
      await repos.auth.logout(role: UserRole.linehaul);
    }, _RealNetwork()),
    skip: skip,
    timeout: const Timeout(Duration(minutes: 2)),
  );
}

/// Default overrides: `createHttpClient` builds a real client.
class _RealNetwork extends HttpOverrides {}
