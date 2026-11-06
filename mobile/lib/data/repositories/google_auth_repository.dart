// lib/data/repositories/google_auth_repository.dart
//
// The second half of "Continue with Google": exchange the identity the native
// SDK proved for a Zefanya session, or learn that there is no account yet.
//
// ## Why this is a separate interface from AuthRepository
//
// Email/password sign-in asks a role-specific question (`POST /login/buyer` vs
// `/login/courier`), because the app has already drawn two doors. Google does
// not: the person has not said which door they belong to, and the whole point
// of the flow is to let the server answer that from the account it already
// holds. A `login(role: ...)` signature would force the screen to guess the
// very thing this flow exists to discover.
//
// ## The two answers
//
// [GoogleAuthOutcome.result] is a real session when the Google address belongs
// to an existing account. A null result means `needs_role`: the identity is
// verified, but Zefanya has no account for it, so the app must ask which role
// the person wants before opening registration. That is a *successful* answer,
// not a failure — the token is good, there is just nothing to sign in to yet.
//
// ## The live contract
//
// `POST /auth/google` with `{id_token}` for sign-in, and the same path with
// the registration fields plus `role` when completing a new account. The
// response is the same shape as `/login/buyer` plus `needs_role` for the
// no-account case. The full contract is written down for the backend teammate
// in `mobile/docs/google_sign_in_contract.md`; no `web/` file was changed.

import '../../state/auth_state.dart';
import '../api/api_client.dart';
import '../api/api_exception.dart';
import '../dto/registration_payload.dart';
import '../dto/remote_status.dart';
import '../google_identity.dart';
import 'auth_repository.dart';
import 'live_stub.dart';

/// What exchanging a Google identity produced.
///
/// [result] null means the identity is verified but has no Zefanya account
/// yet — see the file header.
class GoogleAuthOutcome {
  const GoogleAuthOutcome({required this.identity, this.result});

  final GoogleIdentity identity;
  final AuthResult? result;

  bool get needsRole => result == null;
}

abstract class GoogleAuthRepository {
  /// Asks the backend whether this Google identity already has an account.
  Future<GoogleAuthOutcome> signIn(GoogleIdentity identity);

  /// Completes a new account after the person has chosen a role.
  ///
  /// The password is deliberately absent: Google is the credential. The
  /// profile still carries every field the reviewer needs (name, birthday,
  /// phone, address, ID document).
  Future<AuthResult> register({
    required GoogleIdentity identity,
    required UserRole role,
    required RegistrationProfile profile,
  });
}

// ---------------------------------------------------------------------------
// Mock
// ---------------------------------------------------------------------------

class _MockGoogleAccount {
  const _MockGoogleAccount({
    required this.role,
    required this.name,
    required this.status,
    required this.registrationStatus,
  });

  final UserRole role;
  final String name;
  final AccountStatus status;
  final RemoteRegistrationStatus registrationStatus;
}

/// The accounts a mock Google sign-in can land on.
///
/// Addresses are the demo vocabulary, not a security boundary: the mock has no
/// Google project to verify against, so it answers from this table and treats
/// everything else as a new account. That is enough to exercise all three
/// branches the real flow has — existing buyer, existing courier, and
/// `needs_role` — without a network or a Google Cloud setup.
class MockGoogleAuthRepository implements GoogleAuthRepository {
  const MockGoogleAuthRepository();

  static const Map<String, _MockGoogleAccount> _accounts = {
    'tristan.araneta@gmail.com': _MockGoogleAccount(
      role: UserRole.buyer,
      name: 'Tristan Araneta',
      status: AccountStatus.active,
      registrationStatus: RemoteRegistrationStatus.approved,
    ),
    'miguel.santos@gmail.com': _MockGoogleAccount(
      role: UserRole.courier,
      name: 'Miguel Santos',
      status: AccountStatus.active,
      registrationStatus: RemoteRegistrationStatus.approved,
    ),
    'pending.buyer@gmail.com': _MockGoogleAccount(
      role: UserRole.buyer,
      name: 'Pending Buyer',
      status: AccountStatus.pendingApproval,
      registrationStatus: RemoteRegistrationStatus.pendingApproval,
    ),
  };

  @override
  Future<GoogleAuthOutcome> signIn(GoogleIdentity identity) async {
    final account = _accounts[identity.email.trim().toLowerCase()];
    if (account == null) {
      return GoogleAuthOutcome(identity: identity);
    }

    return GoogleAuthOutcome(
      identity: identity,
      result: AuthResult(
        role: account.role,
        status: account.status,
        name: account.name,
        email: identity.email,
        registrationStatus: account.registrationStatus,
      ),
    );
  }

  @override
  Future<AuthResult> register({
    required GoogleIdentity identity,
    required UserRole role,
    required RegistrationProfile profile,
  }) async => AuthResult(
    role: role,
    status: AccountStatus.pendingApproval,
    name: identity.displayNameOrEmail,
    email: identity.email,
    // No mail is sent in mock mode, so the honest gate is the admin one —
    // the same choice MockAuthRepository.register makes.
    registrationStatus: RemoteRegistrationStatus.pendingApproval,
  );
}

// ---------------------------------------------------------------------------
// Live
// ---------------------------------------------------------------------------

class LiveGoogleAuthRepository extends LiveRepository
    implements GoogleAuthRepository {
  LiveGoogleAuthRepository(super.client);

  @override
  Future<GoogleAuthOutcome> signIn(GoogleIdentity identity) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    final body = await client.postJson(
      '/auth/google',
      authenticated: false,
      body: {'id_token': identity.idToken},
    );
    final payload = unwrapMap(body);

    // The identity is valid but has no account. This is the branch that opens
    // the role chooser; it deliberately does not mint a token, because there
    // is no account for the token to belong to yet.
    if (payload['needs_role'] == true) {
      return GoogleAuthOutcome(identity: identity);
    }

    await _adoptToken(payload);
    return GoogleAuthOutcome(
      identity: identity,
      result: authResultFrom(payload, role: _roleFrom(payload['role'])),
    );
  }

  @override
  Future<AuthResult> register({
    required GoogleIdentity identity,
    required UserRole role,
    required RegistrationProfile profile,
  }) async {
    if (role == UserRole.courier) {
      if (!client.isConfigured) throw const ApiNotConfiguredException();

      final idPath = profile.govIdPath;
      if (idPath == null || idPath.trim().isEmpty) {
        throw const ValidationException(
          fieldErrors: {'upload_id': 'Upload a photo or PDF of your ID.'},
        );
      }

      final files = <String, String>{'upload_id': idPath};
      if (profile.riderLicensePath != null && profile.riderLicensePath!.trim().isNotEmpty) {
        files['license_doc'] = profile.riderLicensePath!.trim();
      }
      if (profile.riderOrCrPath != null && profile.riderOrCrPath!.trim().isNotEmpty) {
        files['or_cr_doc'] = profile.riderOrCrPath!.trim();
      }

      final body = await client.postMultipart(
        '/auth/google',
        authenticated: false,
        fields: {
          'id_token': identity.idToken,
          'role': 'courier',
          ...profile.toGoogleCourierFields().map(
            (key, value) => MapEntry(key, '$value'),
          ),
        },
        files: files,
      );
      final payload = unwrapMap(body);
      await _adoptToken(payload);
      return authResultFrom(payload, role: role);
    }

    if (role != UserRole.buyer) {
      return notWired('POST /auth/google (courier registration)');
    }
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    final idPath = profile.govIdPath;
    if (idPath == null || idPath.trim().isEmpty) {
      throw const ValidationException(
        fieldErrors: {'upload_id': 'Upload a photo or PDF of your ID.'},
      );
    }

    final body = await client.postMultipart(
      '/auth/google',
      authenticated: false,
      fields: {
        'id_token': identity.idToken,
        'role': 'buyer',
        ...profile.toGoogleFields().map(
          (key, value) => MapEntry(key, '$value'),
        ),
      },
      files: {'upload_id': idPath},
    );
    final payload = unwrapMap(body);
    await _adoptToken(payload);
    return authResultFrom(payload, role: role);
  }

  Future<void> _adoptToken(Map<String, Object?> payload) async {
    final token = payload['token'];
    if (token is! String || token.isEmpty) {
      throw const MalformedResponseException(
        message: 'The server accepted the Google sign-in but sent no token.',
      );
    }
    await client.adoptToken(token);
  }

  /// Reads the role the server detected.
  ///
  /// A missing or unrecognised role is malformed, not "buyer by default":
  /// guessing would hand someone a buyer shell when the server may have meant
  /// a courier account, and the next request would 403 with no explanation.
  UserRole _roleFrom(Object? raw) {
    final value = raw?.toString().trim().toLowerCase();
    return switch (value) {
      'buyer' => UserRole.buyer,
      // The Laravel guard is called `logistics`; the app's vocabulary is
      // `courier`. Accept both so the seam cannot break on a spelling.
      'courier' || 'logistics' => UserRole.courier,
      _ => throw const MalformedResponseException(
        message: 'The server did not say which role this Google account has.',
      ),
    };
  }
}
