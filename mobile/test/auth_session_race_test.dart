// test/auth_session_race_test.dart
//
// F1: an authentication operation that finishes late must never overwrite,
// renew or erase a newer session. Every race below is forced deterministically
// with Completers (held server replies) and a token store whose writes can be
// held, using the real ApiClient, LiveAuthRepository and AuthState.

import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/api/webzefanya_contract.dart';
import 'package:zefanya/data/repositories/auth_repository.dart';
import 'package:zefanya/data/repositories/google_auth_repository.dart';
import 'package:zefanya/state/auth_state.dart';

/// A token store whose next write or clear can be held open.
class _GatedTokenStore implements TokenStore {
  String? value;
  final List<String> log = [];
  Completer<void>? holdNextWrite;
  Completer<void>? holdNextRead;
  Completer<void>? holdNextClear;
  final writeEntered = StreamController<void>.broadcast(sync: true);
  final readEntered = StreamController<void>.broadcast(sync: true);
  final clearEntered = StreamController<void>.broadcast(sync: true);

  @override
  Future<String?> read() async {
    final gate = holdNextRead;
    holdNextRead = null;
    if (gate != null) {
      readEntered.add(null);
      await gate.future;
    }
    return value;
  }

  @override
  Future<void> write(String token) async {
    final gate = holdNextWrite;
    holdNextWrite = null;
    if (gate != null) {
      writeEntered.add(null);
      await gate.future;
    }
    value = token;
    log.add('write $token');
  }

  @override
  Future<void> clear() async {
    final gate = holdNextClear;
    holdNextClear = null;
    if (gate != null) {
      clearEntered.add(null);
      await gate.future;
    }
    value = null;
    log.add('clear');
  }
}

http.Response _json(Object? body, [int status = 200]) => http.Response.bytes(
  utf8.encode(jsonEncode(body)),
  status,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

Map<String, Object?> _ok(Object? data) => {
  'success': true,
  'message': 'ok',
  'data': data,
  'errors': null,
};

/// A scripted WebZefanya: each account's sign-in mints one token; profiles are
/// answered by token. Any request can be held with [hold].
class _Server {
  final Map<String, Completer<void>> holds = {};
  final List<String> calls = [];
  final List<String?> logoutTokens = [];
  final Set<String> rejectedLogins = {};
  final Set<String> unverifiedLogins = {};
  final Set<String> rejectedProfiles = {};
  final Map<String, Completer<void>> arrivals = {};

  Future<void> arrived(String key) =>
      (arrivals[key] ??= Completer<void>()).future;

  /// Rider tokens expire this soon, so the next authenticated call renews.
  Duration riderLifetime = const Duration(hours: 1);
  int _renewals = 0;

  static const accounts = {'tok-A': 'Account A', 'tok-B': 'Account B'};

  Completer<void> hold(String key) => holds[key] = Completer<void>();

  Future<void> _maybeHold(String key) async {
    final arrival = arrivals[key] ??= Completer<void>();
    if (!arrival.isCompleted) arrival.complete();
    final gate = holds.remove(key);
    if (gate != null) await gate.future;
  }

  String? _bearer(http.Request r) =>
      r.headers['Authorization']?.replaceFirst('Bearer ', '');

  Future<http.Response> handle(http.Request r) async {
    final path = r.url.path.replaceFirst('/api', '');
    calls.add('${r.method} $path');
    switch (path) {
      case '/buyer/login':
      case '/rider/login':
      case '/courier/login':
        final fields = jsonDecode(r.body) as Map;
        final who = (fields['email'] ?? fields['login_id']) as String;
        await _maybeHold('login $who');
        if (unverifiedLogins.contains(who)) {
          return _json({
            'code': 'pending_approval',
            'registration_status': 'pending_verification',
            'verification_id': 'old-A',
          }, 403);
        }
        if (rejectedLogins.contains(who)) {
          return _json({'message': 'Invalid credentials.'}, 422);
        }
        return _json(
          _ok({
            'token': 'tok-$who',
            'expires_at': DateTime.now()
                .add(const Duration(hours: 1))
                .toUtc()
                .toIso8601String(),
          }),
        );
      case '/courier/activate':
        final who = (jsonDecode(r.body) as Map)['login_id'] as String;
        await _maybeHold('activate $who');
        return _json(
          _ok({
            'token': 'tok-$who',
            'expires_at': DateTime.now()
                .add(const Duration(hours: 1))
                .toUtc()
                .toIso8601String(),
          }),
        );
      case '/buyer/profile':
      case '/rider/profile':
      case '/courier/profile':
        final token = _bearer(r);
        await _maybeHold('profile $token');
        if (rejectedProfiles.contains(token)) {
          return _json({'message': 'Unauthenticated.'}, 401);
        }
        // A renewed token belongs to the same account as the one it replaced.
        final name = token == null
            ? null
            : accounts[token.split('-renewed').first];
        if (name == null) return _json({'message': 'Unauthenticated.'}, 401);
        return _json(
          _ok({
            'name': name,
            'status': path.startsWith('/buyer') ? 'approved' : 'active',
          }),
        );
      case '/rider/token/refresh':
      case '/courier/token/refresh':
        final token = _bearer(r);
        await _maybeHold('renew $token');
        _renewals++;
        return _json(
          _ok({
            'token': '$token-renewed$_renewals',
            'expires_at': DateTime.now()
                .add(const Duration(hours: 1))
                .toUtc()
                .toIso8601String(),
          }),
        );
      case '/buyer/logout':
      case '/rider/logout':
      case '/courier/logout':
        logoutTokens.add(_bearer(r));
        await _maybeHold('logout ${_bearer(r)}');
        return _json(_ok(null));
      case '/auth/google':
        await _maybeHold('google');
        return _json(
          _ok({
            'token': 'tok-A',
            'role': 'buyer',
            'name': 'Account A',
            'registration_status': 'approved',
          }),
        );
    }
    return _json({'message': 'unexpected $path'}, 404);
  }
}

class _Rig {
  _Rig({bool webZefanya = true}) {
    client = ApiClient(
      baseUrl: 'https://wz.test/api',
      tokenStore: tokens,
      httpClient: MockClient(server.handle),
      routePolicy: webZefanya
          ? const WebZefanyaContract(
              routes: [...kWebZefanyaRoutes, ...kWebZefanyaCourierRoutes],
            )
          : null,
    );
    auth = AuthState(
      repository: LiveAuthRepository(client),
      googleAuth: LiveGoogleAuthRepository(client),
    );
  }

  final server = _Server();
  final tokens = _GatedTokenStore();
  late final ApiClient client;
  late final AuthState auth;

  Future<void> signIn(String who, {UserRole role = UserRole.buyer}) async {
    await auth.login(role: role, email: who, password: 'pw-$who');
    if (role != UserRole.buyer &&
        server.riderLifetime < ApiClient.renewWindow) {
      // Age the session only after login, independently of whether the login
      // itself performs a profile read/renewal.
      await client.adoptToken(
        tokens.value!,
        expiresAt: DateTime.now().add(server.riderLifetime),
        epoch: client.sessionEpoch,
      );
    }
  }

  /// Lets queued microtasks and the fake HTTP round trips run.
  Future<void> settle() =>
      Future<void>.delayed(const Duration(milliseconds: 20));

  Future<void> expectSignedInAs(String who, {String? token}) async {
    expect(auth.status, AccountStatus.active, reason: 'signed in');
    expect(auth.name, 'Account $who');
    expect(tokens.value, token ?? 'tok-$who');
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getString('auth_name'), 'Account $who');
    expect(prefs.getString('auth_status'), 'active');
    expect(prefs.getString('auth_role'), auth.role!.name);
  }
}

class _DelayedDemoRepository extends MockAuthRepository {
  final entered = Completer<void>();
  final release = Completer<void>();

  @override
  Future<AuthResult> login({
    required UserRole role,
    required String email,
    required String password,
  }) async {
    entered.complete();
    await release.future;
    return super.login(role: role, email: email, password: password);
  }
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('stale operations never win', () {
    test(
      '1. A signs in, the person signs out, A\'s answer arrives late',
      () async {
        final r = _Rig();
        final gate = r.server.hold('login A');
        final loginA = r.signIn('A');
        await r.settle();

        await r.auth.logout();
        gate.complete();

        await expectLater(loginA, throwsA(isA<SessionSupersededException>()));
        expect(r.auth.status, AccountStatus.loggedOut);
        expect(r.auth.name, isNull);
        expect(
          r.tokens.value,
          isNull,
          reason: 'the late token was not adopted',
        );
        expect(r.tokens.log, isNot(contains('write tok-A')));
      },
    );

    test(
      '2. A signs in, B signs in and succeeds, A\'s answer arrives late',
      () async {
        final r = _Rig();
        final gate = r.server.hold('login A');
        final loginA = r.signIn('A');
        await r.settle();

        await r.signIn('B');
        await r.expectSignedInAs('B');
        gate.complete();

        await expectLater(loginA, throwsA(isA<SessionSupersededException>()));
        await r.expectSignedInAs('B');
        await r.settle();
        final prefs = await SharedPreferences.getInstance();
        expect(
          prefs.getString('auth_name'),
          'Account B',
          reason: 'the persisted session is B',
        );
        expect(prefs.getString('auth_status'), AccountStatus.active.name);
      },
    );

    test(
      '3. A activates, the person signs out, activation completes late',
      () async {
        final r = _Rig();
        final gate = r.server.hold('activate A');
        final activation = r.auth.activateCourier(
          loginId: 'A',
          activationCode: 'CODE',
          password: 'pw-A-12345',
        );
        await r.settle();

        await r.auth.logout();
        gate.complete();

        await expectLater(
          activation,
          throwsA(isA<SessionSupersededException>()),
        );
        expect(r.auth.status, AccountStatus.loggedOut);
        expect(r.tokens.value, isNull);
      },
    );

    test(
      '4a. A refreshes its profile, B signs in, A\'s refresh completes late',
      () async {
        final r = _Rig();
        await r.signIn('A');
        final gate = r.server.hold('profile tok-A');
        final refreshA = r.auth.refresh();
        await r.settle();

        await r.signIn('B');
        gate.complete();
        await refreshA; // stale: completes without applying anything

        await r.expectSignedInAs('B');
      },
    );

    test(
      '4b. A\'s token renewal is in flight, B signs in, the renewed A token arrives late',
      () async {
        final r = _Rig();
        r.server.riderLifetime = const Duration(
          minutes: 1,
        ); // inside the renew window
        await r.signIn('A', role: UserRole.courier);
        final gate = r.server.hold('renew tok-A');
        final refreshA = r.auth.refresh(); // triggers the renewal first
        await r.settle();
        expect(r.server.calls, contains('POST /rider/token/refresh'));

        await r.signIn('B');
        gate.complete();
        await refreshA;
        await r.settle();

        await r.expectSignedInAs('B');
        expect(
          r.tokens.log.where((l) => l.contains('renewed')),
          isEmpty,
          reason: 'the stale renewal wrote nothing',
        );
      },
    );

    test('5. A signs out, B signs in, A\'s sign-out finishes late', () async {
      final r = _Rig();
      await r.signIn('A');
      final gate = r.server.hold('logout tok-A');
      final logoutA = r.auth.logout();
      await r.settle();

      await r.signIn('B');
      gate.complete();
      await logoutA;
      await r.settle();

      await r.expectSignedInAs('B');
      expect(r.server.logoutTokens, [
        'tok-A',
      ], reason: 'only A\'s token was revoked, never B\'s');
      expect(
        r.tokens.log.last,
        'write tok-B',
        reason: 'nothing cleared B afterwards',
      );
    });

    test(
      '5b. a slow credential write cannot finish after a newer one (serialized)',
      () async {
        final r = _Rig();
        // A's token write is held in the store itself, after the session check.
        final slowWrite = Completer<void>();
        r.tokens.holdNextWrite = slowWrite;
        final loginA = r.signIn('A');
        await r.settle();

        final loginB = r.signIn('B'); // B's write is queued behind A's
        await r.settle();
        slowWrite.complete();

        await expectLater(loginA, throwsA(isA<SessionSupersededException>()));
        await loginB;
        await r.expectSignedInAs('B');
        expect(r.tokens.log, [
          'write tok-A',
          'clear',
          'write tok-B',
        ], reason: 'the stale write is cleared before the newer write');
      },
    );

    test(
      '6. two concurrent refreshes cannot overwrite a newer session',
      () async {
        final r = _Rig();
        r.server.riderLifetime = const Duration(minutes: 1);
        await r.signIn('A', role: UserRole.courier);
        final renew = r.server.hold('renew tok-A');
        final first = r.auth.refresh();
        final second = r.auth.refresh();
        await r.settle();
        expect(
          r.server.calls.where((c) => c == 'POST /rider/token/refresh'),
          hasLength(1),
          reason: 'one shared renewal',
        );

        await r.signIn('B');
        renew.complete();
        await Future.wait([first, second]);
        await r.settle();

        await r.expectSignedInAs('B');
      },
    );
  });

  group('handoff review gaps', () {
    test(
      'a stale verification error cannot reopen A registration over B',
      () async {
        final r = _Rig();
        r.server.unverifiedLogins.add('A');
        final gate = r.server.hold('login A');
        // Match the login screen's existing recovery callback.
        final login = r.signIn('A').onError<PendingApprovalException>((
          error,
          stack,
        ) async {
          await r.auth.beginEmailVerification(
            email: 'A',
            verificationId: error.verificationId!,
          );
        });
        final rejected = expectLater(
          login,
          throwsA(isA<SessionSupersededException>()),
        );
        await r.server.arrived('login A');
        await r.signIn('B');
        gate.complete();
        await rejected;
        await r.expectSignedInAs('B');
      },
    );
    test('logout still takes A when B starts immediately and fails', () async {
      final r = _Rig();
      await r.signIn('A');
      r.server.rejectedLogins.add('B');
      final logout = r.auth.logout();
      final failedLogin = expectLater(
        r.signIn('B'),
        throwsA(isA<ValidationException>()),
      );
      await Future.wait([logout, failedLogin]);
      expect(r.tokens.value, isNull);
      expect(r.auth.status, AccountStatus.loggedOut);
      expect(r.server.logoutTokens, ['tok-A']);
      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString('auth_status'), 'loggedOut');
      expect(prefs.getString('auth_name'), isNull);
    });

    test('a delayed clear finishes before B credentials are written', () async {
      final r = _Rig();
      await r.signIn('A');
      final gate = Completer<void>();
      r.tokens.holdNextClear = gate;
      final entered = r.tokens.clearEntered.stream.first;
      final logout = r.auth.logout();
      await entered;
      final login = r.signIn('B');
      await r.server.arrived('login B');
      expect(r.tokens.log, isNot(contains('write tok-B')));
      gate.complete();
      await Future.wait([logout, login]);
      await r.expectSignedInAs('B');
      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString('auth_name'), 'Account B');
      expect(prefs.getString('auth_status'), 'active');
    });

    test('a stale storage read cannot send B to A renewal endpoint', () async {
      final r = _Rig();
      r.server.riderLifetime = const Duration(minutes: 1);
      await r.signIn('A', role: UserRole.courier);
      final gate = Completer<void>();
      r.tokens.holdNextRead = gate;
      final entered = r.tokens.readEntered.stream.first;
      final refresh = r.auth.refresh();
      await entered;
      final login = r.signIn('B');
      await r.server.arrived('login B');
      // Release even when reads are serialized with writes: B may be queued.
      gate.complete();
      await Future.wait([refresh, login]);
      await r.expectSignedInAs('B');
      expect(r.server.calls, isNot(contains('POST /rider/token/refresh')));
    });

    test('renewal rechecks ownership after awaited token comparison', () async {
      final r = _Rig();
      r.server.riderLifetime = const Duration(minutes: 1);
      await r.signIn('A', role: UserRole.courier);
      final response = r.server.hold('renew tok-A');
      final refresh = r.auth.refresh();
      await r.server.arrived('renew tok-A');
      final read = Completer<void>();
      r.tokens.holdNextRead = read;
      final entered = r.tokens.readEntered.stream.first;
      response.complete();
      await entered;
      final login = r.signIn('B');
      await r.server.arrived('login B');
      read.complete();
      await Future.wait([refresh, login]);
      await r.expectSignedInAs('B');
      expect(r.tokens.log.where((e) => e.contains('renewed')), isEmpty);
    });

    test('B renewal is independent of an unfinished A renewal', () async {
      final r = _Rig();
      r.server.riderLifetime = const Duration(minutes: 1);
      await r.signIn('A', role: UserRole.courier);
      final a = r.server.hold('renew tok-A');
      final refreshA = r.auth.refresh();
      await r.server.arrived('renew tok-A');
      await r.signIn('B', role: UserRole.courier);
      final refreshB = r.auth.refresh();
      try {
        await r.server
            .arrived('renew tok-B')
            .timeout(const Duration(seconds: 2));
        await refreshB;
        await r.expectSignedInAs('B', token: 'tok-B-renewed1');
      } finally {
        a.complete();
        await Future.wait([refreshA, refreshB]);
      }
      await r.expectSignedInAs('B', token: 'tok-B-renewed1');
    });

    test('a late Google exchange cannot replace a password login', () async {
      final r = _Rig(webZefanya: false);
      final gate = r.server.hold('google');
      final google = r.auth.signInWithGoogle();
      await r.server.arrived('google');
      await r.signIn('B');
      final rejected = expectLater(
        google,
        throwsA(isA<SessionSupersededException>()),
      );
      gate.complete();
      await rejected;
      await r.expectSignedInAs('B');
    });

    test('activation completing after B login cannot overwrite B', () async {
      final r = _Rig();
      final gate = r.server.hold('activate A');
      final activation = r.auth.activateCourier(
        loginId: 'A',
        activationCode: 'CODE',
        password: 'secret-123',
      );
      final rejected = expectLater(
        activation,
        throwsA(isA<SessionSupersededException>()),
      );
      await r.server.arrived('activate A');
      await r.signIn('B');
      gate.complete();
      await rejected;
      await r.expectSignedInAs('B');
    });

    test('logout clears a credential write already in progress', () async {
      final r = _Rig();
      final gate = Completer<void>();
      r.tokens.holdNextWrite = gate;
      final entered = r.tokens.writeEntered.stream.first;
      final rejected = expectLater(
        r.signIn('A'),
        throwsA(isA<SessionSupersededException>()),
      );
      await entered;
      final logout = r.auth.logout();
      expect(r.auth.status, AccountStatus.loggedOut);
      gate.complete();
      await Future.wait([logout, rejected]);
      expect(r.tokens.value, isNull);
      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString('auth_status'), 'loggedOut');
    });

    test('a rejected stale refresh cannot clear B credentials', () async {
      final r = _Rig();
      await r.signIn('A');
      r.server.rejectedProfiles.add('tok-A');
      final gate = r.server.hold('profile tok-A');
      final refresh = r.auth.refresh();
      await r.settle();
      await r.signIn('B');
      gate.complete();
      await refresh;
      await r.expectSignedInAs('B');
    });

    test('a stale saved-session check cannot clear B state', () async {
      final r = _Rig();
      await r.signIn('A');
      r.server.rejectedProfiles.add('tok-A');
      final gate = r.server.hold('profile tok-A');
      final restore = r.auth.load();
      await r.settle();
      await r.signIn('B');
      gate.complete();
      await restore;
      await r.expectSignedInAs('B');
    });

    test(
      'same-session concurrent refreshes share one successful renewal',
      () async {
        final r = _Rig();
        r.server.riderLifetime = const Duration(minutes: 1);
        await r.signIn('A', role: UserRole.courier);
        final gate = r.server.hold('renew tok-A');
        final one = r.auth.refresh();
        final two = r.auth.refresh();
        await r.server.arrived('renew tok-A');
        gate.complete();
        await Future.wait([one, two]);
        await r.expectSignedInAs('A', token: 'tok-A-renewed1');
        expect(
          r.server.calls.where((c) => c == 'POST /rider/token/refresh'),
          hasLength(1),
        );
      },
    );
  });

  group('normal flows still work', () {
    test(
      'WebZefanya Courier login, renewal and logout keep their own role',
      () async {
        final r = _Rig();
        r.server.riderLifetime = const Duration(minutes: 1);
        await r.signIn('A', role: UserRole.linehaul);
        await r.auth.refresh();
        await r.expectSignedInAs('A', token: 'tok-A-renewed1');
        expect(r.auth.role, UserRole.linehaul);
        expect(r.server.calls, contains('POST /courier/token/refresh'));
        await r.auth.logout();
        expect(r.server.logoutTokens, ['tok-A-renewed1']);
        expect(r.tokens.value, isNull);
        expect(r.auth.status, AccountStatus.loggedOut);
      },
    );

    test('a late WebZefanya Courier renewal cannot replace Buyer B', () async {
      final r = _Rig();
      r.server.riderLifetime = const Duration(minutes: 1);
      await r.signIn('A', role: UserRole.linehaul);
      final gate = r.server.hold('renew tok-A');
      final refresh = r.auth.refresh();
      await r.server.arrived('renew tok-A');
      await r.signIn('B');
      gate.complete();
      await refresh;
      await r.expectSignedInAs('B');
    });

    test('concurrent WebZefanya Courier refreshes share one renewal', () async {
      final r = _Rig();
      r.server.riderLifetime = const Duration(minutes: 1);
      await r.signIn('A', role: UserRole.linehaul);
      final gate = r.server.hold('renew tok-A');
      final first = r.auth.refresh();
      final second = r.auth.refresh();
      await r.server.arrived('renew tok-A');
      gate.complete();
      await Future.wait([first, second]);
      await r.expectSignedInAs('A', token: 'tok-A-renewed1');
      expect(
        r.server.calls.where((c) => c == 'POST /courier/token/refresh'),
        hasLength(1),
      );
    });

    test('a late Google token cannot restore a signed-out session', () async {
      final r = _Rig(webZefanya: false);
      final gate = r.server.hold('google');
      final rejected = expectLater(
        r.auth.signInWithGoogle(),
        throwsA(isA<SessionSupersededException>()),
      );
      await r.server.arrived('google');
      await r.auth.logout();
      gate.complete();
      await rejected;
      expect(r.tokens.value, isNull);
      expect(r.auth.status, AccountStatus.loggedOut);
      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString('auth_status'), 'loggedOut');
    });

    test('a delayed mock demo sign-in cannot undo logout', () async {
      final repository = _DelayedDemoRepository();
      final auth = AuthState(repository: repository);
      final demo = auth.startDemo(UserRole.linehaul);
      await repository.entered.future;
      await auth.logout();
      repository.release.complete();
      await demo;
      expect(auth.status, AccountStatus.loggedOut);
      expect(auth.role, isNull);
      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString('auth_status'), 'loggedOut');
    });

    test('normal mock demo sign-in stays in memory and signs out', () async {
      final auth = AuthState();
      await auth.startDemo(UserRole.linehaul);
      expect(auth.status, AccountStatus.active);
      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString('auth_status'), isNull);
      await auth.logout();
      expect(auth.status, AccountStatus.loggedOut);
      expect(prefs.getString('auth_status'), isNull);
    });

    test('7. sign-in', () async {
      final r = _Rig();
      await r.signIn('A');
      await r.expectSignedInAs('A');
      expect(r.server.calls, ['POST /buyer/login', 'GET /buyer/profile']);
    });

    test('8. Courier activation', () async {
      final r = _Rig();
      await r.auth.activateCourier(
        loginId: 'A',
        activationCode: 'CODE',
        password: 'pw-A-12345',
      );
      expect(r.auth.role, UserRole.linehaul);
      expect(r.auth.status, AccountStatus.active);
      expect(r.tokens.value, 'tok-A');
    });

    test('9. refresh, including a token renewal', () async {
      final r = _Rig();
      r.server.riderLifetime = const Duration(minutes: 1);
      await r.signIn('A', role: UserRole.courier);
      await r.auth.refresh();
      expect(r.tokens.value, 'tok-A-renewed1');
      await r.expectSignedInAs('A', token: 'tok-A-renewed1');
    });

    test(
      '10. sign-out revokes the token on the server and clears it locally',
      () async {
        final r = _Rig();
        await r.signIn('A');
        await r.auth.logout();
        expect(r.auth.status, AccountStatus.loggedOut);
        expect(r.tokens.value, isNull);
        expect(r.server.logoutTokens, ['tok-A']);
      },
    );

    test(
      'a failed server sign-out still clears local credentials and reports the error',
      () async {
        final r = _Rig();
        await r.signIn('A');
        final failing = ApiClient(
          baseUrl: 'https://wz.test/api',
          tokenStore: r.tokens,
          httpClient: MockClient(
            (request) async => throw http.ClientException('offline'),
          ),
        );
        final auth = AuthState(repository: LiveAuthRepository(failing));
        expect(r.tokens.value, 'tok-A');
        await expectLater(auth.logout(), throwsA(isA<NetworkException>()));
        expect(r.tokens.value, isNull);
      },
    );
  });
}
