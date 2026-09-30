// lib/state/auth_state.dart
//
// Global session state, exposed via Provider (see main.dart). Every
// screen that needs to know "who's logged in / what's their status"
// reads this instead of holding its own local flag.
//
// Persisted via shared_preferences (see load()/_save() below), so the
// session survives an app restart — call `await authState.load()`
// once before runApp (see main.dart) so AuthGate's first build already
// has the right answer instead of flashing the login screen.
//
// Per the updated ERP-Components spec: BOTH Buyer and Courier
// registrations go through admin/logistics review before becoming
// active — there's no more auto-activated role. `login()` is used for
// the login form (an already-approved account signing back in);
// `submitForApproval()` is used by registration for both roles.
//
// TODO: profile edits (updateProfile) are still local-only — name, address and
// avatar have no endpoint yet, which is the line this class holds deliberately
// (see below). Everything else auth-shaped now goes through AuthRepository:
// login, registration, and sign-out. The token, when there is one, belongs in
// SecureTokenStore rather than here.

// ## Where the answer comes from now
//
// Signing in and signing out go through an [AuthRepository]. That matters for
// one specific reason: with no repository, a *live* build's login would still
// mint a local "active" session, so the app would claim someone was signed in
// against a server that had never heard of them. The status now comes back from
// the repository — the mock returns exactly the session this class used to set
// itself, so nothing about the flow changes in mock mode, and in live mode the
// fake is gone.
//
// Registration goes through it as well, now that the form collects a password:
// `submitForApproval()` hands the repository the form's answers and takes back a
// status. That is also what makes the two pending gates distinguishable —
// `pending_verification` (an email the person can go and confirm) and
// `pending_approval` (a reviewer they can only wait for). Collapsing them would
// tell someone to wait for a person who was never going to be asked.
//
// What deliberately stays here: the profile fields (name, address, avatar) and
// their prefs storage. Those are display data the app already owns; moving them
// to the network would make every profile edit a request that can fail, which
// changes Edit Profile's behaviour. See auth_repository.dart for the same
// boundary stated from the other side.

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../data/dto/registration_payload.dart';
// Prefixed because this class has a getter of the same name
// (`awaitsEmailVerification`); the two are the same decision, and the prefix is
// what keeps the getter delegating to the helper instead of shadowing it.
import '../data/dto/remote_status.dart' as remote;
import '../data/repositories/auth_repository.dart';

enum UserRole { buyer, courier }

enum AccountStatus {
  /// No session — show the login screen.
  loggedOut,

  /// Registration submitted, waiting on review — the administrator
  /// for Buyers/Sellers, the Logistics/Sorting Center for Couriers
  /// (see PendingApprovalScreen, which picks the right copy by role).
  pendingApproval,

  /// Logged in and cleared to use the app.
  active,
}

class AuthState extends ChangeNotifier {
  /// [repository] defaults to the mock implementation so existing call sites
  /// (and tests) keep working unchanged; `main.dart` passes the resolved
  /// bundle's, which is what makes the live switch possible.
  AuthState({AuthRepository? repository})
      : _repository = repository ?? const MockAuthRepository();

  final AuthRepository _repository;

  UserRole? role;
  AccountStatus status = AccountStatus.loggedOut;

  /// The server's own registration value for this session, when it sent one.
  ///
  /// Kept *beside* [status] rather than folded into it because both pending
  /// gates collapse into one [AccountStatus] while the copy still differs — see
  /// [awaitsEmailVerification]. Nullable, and null is a real answer: it means
  /// the repository said nothing about a registration gate (which is what a
  /// plain login of an approved account does).
  ///
  /// Assigned on every repository answer rather than merged, so a value left
  /// over from an earlier session cannot end up describing this one.
  remote.RemoteRegistrationStatus? registrationStatus;

  String? lastName;
  String? firstName;
  String? middleInitial;
  String? sex;
  String? name; // composed display name — see _composeName()
  String? email;
  String? phone;
  String? province;
  String? municipality;
  String? barangay;
  String? street;
  String? avatarPath;

  /// When this account first entered the app (login or registration
  /// submit, whichever came first). Set once and never overwritten —
  /// Profile reads it for the "member since" stat. Cleared on logout
  /// along with everything else, because the mock has no real account
  /// to re-attach it to; once there's a Laravel API this should come
  /// back from the server rather than being minted on-device.
  DateTime? memberSince;

  static const _roleKey = 'auth_role';
  static const _statusKey = 'auth_status';
  // Additive: an existing install has no value here, `load()` reads that as
  // null, and null renders as the admin-gate copy — which is what that build
  // was already showing. No migration needed and nothing changes on upgrade.
  static const _registrationStatusKey = 'auth_registration_status';
  static const _lastNameKey = 'auth_last_name';
  static const _firstNameKey = 'auth_first_name';
  static const _middleInitialKey = 'auth_middle_initial';
  static const _sexKey = 'auth_sex';
  static const _nameKey = 'auth_name';
  static const _emailKey = 'auth_email';
  static const _phoneKey = 'auth_phone';
  static const _provinceKey = 'auth_province';
  static const _municipalityKey = 'auth_municipality';
  static const _barangayKey = 'auth_barangay';
  static const _streetKey = 'auth_street';
  static const _avatarPathKey = 'auth_avatar_path';
  static const _memberSinceKey = 'auth_member_since';

  bool get isLoggedIn => status == AccountStatus.active && role != null;
  bool get isPendingApproval => status == AccountStatus.pendingApproval;

  /// True when the pending gate is the *email* one rather than the admin one.
  ///
  /// Delegates to the DTO's helper instead of re-deriving the answer here, so
  /// the screen that shows the copy cannot drift from the wire values the
  /// parser accepts.
  ///
  /// Null answers false — the admin gate — and that default is the opposite of
  /// the one `remote_status.dart` picks for an *unrecognised* value, on purpose.
  /// `unknown` is the server having said something this build doesn't
  /// understand, which might be a gate with an action in it; null is the server
  /// having said nothing at all. "Wait for a reviewer" is the safer thing to
  /// tell someone when nothing is known, because it does not send them to check
  /// an inbox that may never receive anything.
  bool get awaitsEmailVerification {
    final value = registrationStatus;
    return value != null && remote.awaitsEmailVerification(value);
  }

  /// One-line summary for display — empty if nothing's been filled in yet.
  String get addressSummary {
    final parts = [street, barangay, municipality, province].where((p) => p != null && p.isNotEmpty);
    return parts.join(', ');
  }

  /// PH naming convention: "First MI. Last". Falls back to whatever
  /// parts are actually available (e.g. the login stub only sets a
  /// pre-composed `name`, not the split fields).
  String? _composeName() {
    if (firstName == null && lastName == null) return name;
    final mi = (middleInitial ?? '').trim();
    return [firstName, if (mi.isNotEmpty) '$mi.', lastName].where((p) => p != null && p.isNotEmpty).join(' ');
  }

  /// Call once before runApp (see main.dart) to restore the last
  /// session from disk.
  Future<void> load() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final roleStr = prefs.getString(_roleKey);
      final statusStr = prefs.getString(_statusKey);
      role = roleStr == null ? null : UserRole.values.byName(roleStr);
      status = statusStr == null ? AccountStatus.loggedOut : AccountStatus.values.byName(statusStr);
      // Read through the DTO's forgiving parser rather than `values.byName`,
      // because this one is the *server's* own string: a build meeting a status
      // it has never heard of should render "unrecognised" rather than throw on
      // startup — and the catch below answers a throw by dropping the whole
      // session, which is a lot to lose to one unknown word.
      final registrationRaw = prefs.getString(_registrationStatusKey);
      registrationStatus = registrationRaw == null
          ? null
          : remote.RemoteRegistrationStatus.parse(registrationRaw);
      lastName = prefs.getString(_lastNameKey);
      firstName = prefs.getString(_firstNameKey);
      middleInitial = prefs.getString(_middleInitialKey);
      sex = prefs.getString(_sexKey);
      name = prefs.getString(_nameKey);
      email = prefs.getString(_emailKey);
      phone = prefs.getString(_phoneKey);
      province = prefs.getString(_provinceKey);
      municipality = prefs.getString(_municipalityKey);
      barangay = prefs.getString(_barangayKey);
      street = prefs.getString(_streetKey);
      avatarPath = prefs.getString(_avatarPathKey);
      final memberSinceRaw = prefs.getString(_memberSinceKey);
      memberSince = memberSinceRaw == null ? null : DateTime.tryParse(memberSinceRaw);
    } catch (_) {
      // Corrupt/old prefs shape — fall back to logged-out rather than crash.
      role = null;
      status = AccountStatus.loggedOut;
    }
  }

  Future<void> _setOrRemove(SharedPreferences prefs, String key, String? value) async {
    value == null || value.isEmpty ? await prefs.remove(key) : await prefs.setString(key, value);
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_statusKey, status.name);
    // Stored as the *wire* string rather than the enum's name, so what sits on
    // disk is what the server said and a later reorganisation of the enum
    // cannot turn a saved session into an unreadable one.
    await _setOrRemove(prefs, _registrationStatusKey, registrationStatus?.wire);
    role == null ? await prefs.remove(_roleKey) : await prefs.setString(_roleKey, role!.name);
    await _setOrRemove(prefs, _lastNameKey, lastName);
    await _setOrRemove(prefs, _firstNameKey, firstName);
    await _setOrRemove(prefs, _middleInitialKey, middleInitial);
    await _setOrRemove(prefs, _sexKey, sex);
    await _setOrRemove(prefs, _nameKey, name);
    await _setOrRemove(prefs, _emailKey, email);
    await _setOrRemove(prefs, _phoneKey, phone);
    await _setOrRemove(prefs, _provinceKey, province);
    await _setOrRemove(prefs, _municipalityKey, municipality);
    await _setOrRemove(prefs, _barangayKey, barangay);
    await _setOrRemove(prefs, _streetKey, street);
    await _setOrRemove(prefs, _avatarPathKey, avatarPath);
    await _setOrRemove(prefs, _memberSinceKey, memberSince?.toIso8601String());
  }

  /// Call after a successful login of an already-approved account.
  ///
  /// The role and status are the *repository's* answer, not this method's
  /// assertion — [AuthResult] exists precisely so "your credentials are fine
  /// but your account isn't active yet" is expressible, which a locally-set
  /// `status = active` cannot represent. In mock mode the answer is the same
  /// active session this used to set directly, so routing is unchanged.
  ///
  /// A failure propagates: the login screen reports it rather than appearing to
  /// sign someone in who was never signed in.
  Future<void> login({
    required UserRole role,
    required String email,
    required String password,
    String? name,
  }) async {
    final result = await _repository.login(
      role: role,
      email: email,
      password: password,
    );
    _applyResult(result, fallbackName: name);
    await _save();
  }

  /// Call on registration submit for BOTH Buyer and Courier — every
  /// role now waits on review before becoming active, per the updated
  /// spec. PendingApprovalScreen reads `role` to show the right
  /// reviewer (administrator vs Logistics/Sorting Center) and
  /// [registrationStatus] to pick which *gate* it is describing.
  ///
  /// The status is the repository's answer, exactly as in [login]. It used to be
  /// set here, and the reason is worth keeping: [AuthRepository.register]
  /// requires a password, the form had no password field, and passing an empty
  /// string to satisfy the signature would have been inventing a credential.
  /// Now that the form collects one, the invented part is gone — and with it the
  /// old failure mode where a live build could show a pending screen for a
  /// registration the server had never received.
  ///
  /// A failure propagates rather than being flattened into a local "pending":
  /// the screen reports it and leaves the person on a form they can still fix.
  Future<void> submitForApproval({
    required UserRole role,
    required String email,
    required String password,
    required RegistrationProfile profile,
    String? name,
  }) async {
    final result = await _repository.register(
      role: role,
      email: email,
      password: password,
      profile: profile,
    );
    _applyResult(result, fallbackName: name);
    await _save();
  }

  /// Takes the repository's answer as the session, keeping whatever name the
  /// caller supplied when the server didn't send one (it has no reason to
  /// echo a display name back on a login).
  ///
  /// [registrationStatus] is assigned unconditionally, null included: it
  /// describes the answer just received, so an absent value means "this answer
  /// says nothing about a registration gate", not "keep the previous one".
  void _applyResult(AuthResult result, {String? fallbackName}) {
    role = result.role;
    status = result.status;
    registrationStatus = result.registrationStatus;
    name = result.name ?? fallbackName ?? name;
    email = result.email ?? email;
    memberSince ??= DateTime.now();
    notifyListeners();
  }

  /// TEST-ONLY helper so the approval loop is verifiable without a
  /// real admin/logistics backend. Remove once that's wired up.
  void debugApprovePendingAccount() {
    if (status == AccountStatus.pendingApproval && role != null) {
      status = AccountStatus.active;
      notifyListeners();
      _save();
    }
  }

  /// Called from RegistrationScreen and EditProfileScreen — updates
  /// whichever fields are passed and leaves the rest untouched. Also
  /// recomposes the display `name` whenever any name part changes.
  void updateProfile({
    String? lastName,
    String? firstName,
    String? middleInitial,
    String? sex,
    String? phone,
    String? province,
    String? municipality,
    String? barangay,
    String? street,
    String? avatarPath,
  }) {
    if (lastName != null) this.lastName = lastName;
    if (firstName != null) this.firstName = firstName;
    if (middleInitial != null) this.middleInitial = middleInitial;
    if (lastName != null || firstName != null || middleInitial != null) {
      name = _composeName();
    }
    if (sex != null) this.sex = sex;
    if (phone != null) this.phone = phone;
    if (province != null) this.province = province;
    if (municipality != null) this.municipality = municipality;
    if (barangay != null) this.barangay = barangay;
    if (street != null) this.street = street;
    if (avatarPath != null) this.avatarPath = avatarPath;
    notifyListeners();
    _save();
  }

  /// Signs out: this device's session is cleared, then the repository is told
  /// to revoke the server session.
  ///
  /// In that order on purpose. The person asked to be signed out of *this*
  /// device and that part cannot fail, so it happens first; a revoke that fails
  /// then propagates to the caller instead of being swallowed, because a live
  /// token outliving the sign-out is the one outcome worse than an error. In
  /// mock mode the repository's `logout()` is a no-op, so nothing about signing
  /// out changes — see `docs/api-contract.md` §3.3.
  Future<void> logout() async {
    role = null;
    status = AccountStatus.loggedOut;
    registrationStatus = null;
    lastName = null;
    firstName = null;
    middleInitial = null;
    sex = null;
    name = null;
    email = null;
    phone = null;
    province = null;
    municipality = null;
    barangay = null;
    street = null;
    avatarPath = null;
    memberSince = null;
    notifyListeners();
    await _save();
    await _repository.logout();
  }
}
