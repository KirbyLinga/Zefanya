// test/auth_flow_test.dart
//
// The sign-in path, asserted as logic rather than pixels.
//
// The gap these cover: sign-in had *no* test at all, and it was the one place
// the mock/live decision was still made inside the state class —
// `AuthState.login()` set `status = active` itself, so a live build would have
// claimed a session the server had never issued. These tests pin the two halves
// of the fix: the status is the repository's answer (so a not-yet-approved
// account cannot be signed in by this app), and in mock mode nothing about the
// flow changed.

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/dto/registration_payload.dart';
import 'package:zefanya/data/repositories/auth_repository.dart';
import 'package:zefanya/state/auth_state.dart';

/// A repository that answers with whatever the test tells it to, and records
/// what it was asked — the point being that [AuthState] must be *told* the
/// status rather than deciding it.
class _ScriptedAuthRepository implements AuthRepository {
  _ScriptedAuthRepository({required this.answer});

  final AuthResult Function(UserRole role, String email) answer;
  String? passwordSeen;
  bool logoutCalled = false;

  @override
  Future<AuthResult> login({
    required UserRole role,
    required String email,
    required String password,
  }) async {
    passwordSeen = password;
    return answer(role, email);
  }

  @override
  Future<AuthResult> register({
    required UserRole role,
    required String email,
    required String password,
    required RegistrationProfile profile,
  }) async =>
      answer(role, email);

  @override
  Future<void> logout() async => logoutCalled = true;

  @override
  Future<AuthResult?> refresh() async => null;
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('the signed-in status is the repository\'s answer, not this app\'s',
      () async {
    // An account whose credentials are fine but which is still awaiting
    // approval. `AuthState` used to be unable to express this — it set
    // `active` unconditionally — which is the whole reason `AuthResult`
    // carries a status instead of login() returning a bool.
    final repository = _ScriptedAuthRepository(
      answer: (role, email) =>
          AuthResult(role: role, status: AccountStatus.pendingApproval, email: email),
    );
    final auth = AuthState(repository: repository);

    await auth.login(
      role: UserRole.courier,
      email: 'rider@example.ph',
      password: 'hunter2',
      name: 'Arnel P.',
    );

    expect(auth.status, AccountStatus.pendingApproval);
    expect(auth.isLoggedIn, isFalse);
    expect(auth.isPendingApproval, isTrue);
    expect(auth.role, UserRole.courier);
    // The password has to actually reach the repository — if the screen kept
    // it local, a real sign-in would be attempted with an empty one.
    expect(repository.passwordSeen, 'hunter2');
  });

  test('mock mode signs in exactly as it always did', () async {
    final auth = AuthState();

    await auth.login(
      role: UserRole.buyer,
      email: 'tristan@example.ph',
      password: 'anything',
      name: 'Tristan Araneta',
    );

    expect(auth.status, AccountStatus.active);
    expect(auth.isLoggedIn, isTrue);
    expect(auth.role, UserRole.buyer);
    // The email is the form's, and the display name is kept even though the
    // mock has no name to send back — the server echoing a name is not
    // something a caller should have to depend on.
    expect(auth.email, 'tristan@example.ph');
    expect(auth.name, 'Tristan Araneta');
    expect(auth.memberSince, isNotNull);
  });

  test('the signed-in session survives a restart', () async {
    await AuthState().login(
      role: UserRole.buyer,
      email: 'tristan@example.ph',
      password: 'anything',
      name: 'Tristan Araneta',
    );

    // A cold start: a brand-new state reading what the previous one saved.
    final restarted = AuthState();
    await restarted.load();

    expect(restarted.status, AccountStatus.active);
    expect(restarted.role, UserRole.buyer);
    expect(restarted.email, 'tristan@example.ph');
  });

  test('signing out clears the session and asks the repository to revoke it',
      () async {
    final repository = _ScriptedAuthRepository(
      answer: (role, email) =>
          AuthResult(role: role, status: AccountStatus.active, email: email),
    );
    final auth = AuthState(repository: repository);
    await auth.login(
      role: UserRole.buyer,
      email: 'tristan@example.ph',
      password: 'anything',
      name: 'Tristan Araneta',
    );

    await auth.logout();

    expect(auth.status, AccountStatus.loggedOut);
    expect(auth.isLoggedIn, isFalse);
    expect(auth.role, isNull);
    expect(auth.memberSince, isNull);
    // A token left valid after sign-out is the one outcome worse than an
    // error, so the revoke is not optional — see AuthState.logout.
    expect(repository.logoutCalled, isTrue);
  });
}