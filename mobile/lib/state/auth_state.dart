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

import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../config/dev_flags.dart';
import '../data/address_entry.dart';
import '../data/api/api_exception.dart';
import '../data/dto/registration_payload.dart';
// Prefixed because this class has a getter of the same name
// (`awaitsEmailVerification`); the two are the same decision, and the prefix is
// what keeps the getter delegating to the helper instead of shadowing it.
import '../data/dto/remote_status.dart' as remote;
import '../data/ph_regions.dart';
import '../data/repositories/account_security_repository.dart';
import '../data/repositories/auth_repository.dart';
import '../utils/order_tab_memory.dart';
import '../utils/validators.dart';

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
  AuthState({AuthRepository? repository, AccountSecurityRepository? accountSecurity})
    : _repository = repository ?? const MockAuthRepository(),
      _accountSecurity = accountSecurity ?? MockAccountSecurityRepository();

  final AuthRepository _repository;
  final AccountSecurityRepository _accountSecurity;

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

  /// The server's handle for confirming this registration's email, when it gave
  /// one. Opaque — see [AuthResult.verificationId] for why this app never parses
  /// it or builds one of its own.
  ///
  /// Persisted, because the email gate outlives a lunch break: someone registers,
  /// the mail arrives an hour later, and iOS may have killed the app in between.
  /// Without this the pending screen could be rebuilt but the confirmation could
  /// not, because there would be nothing to address the code *to*.
  String? verificationId;

  /// When the current email code was last sent, for the OTP screen's
  /// expiry countdown. Persisted with the rest of the pending session so
  /// switching to the mail app and back (or a cold start) does not reset
  /// the clock to five minutes.
  ///
  /// The server still decides whether a code is valid; this is the app's
  /// copy of the lifetime it tells the buyer, not a replacement for the
  /// server's expiry check.
  DateTime? otpSentAt;

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

  /// Last few distinct delivery addresses, most-recent-first, capped.
  /// Recorded on every successful order placement; the checkout
  /// section offers them as one-tap shortcuts. Display strings only
  /// (the same summary the section already renders) — not structured
  /// address parts, so there is nothing new to validate or merge.
  List<String> recentAddresses = const [];
  static const int recentAddressesCap = 3;

  /// Structured saved addresses. Unlike [recentAddresses] these have a
  /// recipient, their own phone number and a default marker, so checkout can
  /// name the person receiving the parcel rather than the account holder every
  /// time. Persisted with the rest of the profile.
  List<AddressEntry> addresses = const [];
  static const int addressBookLimit = 10;

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
  // Additive as well, and for the same reason: an install from before the OTP
  // screen has no value here, `load()` reads that as null, and null means "no
  // registration to confirm" — which is what that build was already showing.
  static const _verificationIdKey = 'auth_verification_id';
  static const _otpSentAtKey = 'auth_otp_sent_at';
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
  static const _recentAddressesKey = 'auth_recent_addresses';
  static const _addressesKey = 'auth_addresses';
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

  /// True when the email gate is open *and* there is a handle to confirm against.
  ///
  /// Both halves are required, and the second one is the half a screen would
  /// forget: `pending_verification` alone says there is a code to type, but not
  /// which registration it belongs to. Rendering a field whose submit could only
  /// fail with "we don't know what to send it against" is worse than rendering
  /// nothing, so this is the getter the pending screen gates its button on.
  bool get canConfirmEmail => awaitsEmailVerification && verificationId != null;

  /// One-line summary for display — empty if nothing's been filled in yet.
  String get addressSummary {
    final parts = [
      street,
      barangay,
      municipality,
      province,
    ].where((p) => p != null && p.isNotEmpty);
    return parts.join(', ');
  }

  /// The address checkout pre-selects. Falls back to the first saved row when
  /// an older/corrupt prefs payload has no default marker, so the book never
  /// renders without an answer while it is non-empty.
  AddressEntry? get defaultAddress {
    if (addresses.isEmpty) return null;
    for (final address in addresses) {
      if (address.isDefault) return address;
    }
    return addresses.first;
  }

  /// PH naming convention: "First MI. Last". Falls back to whatever
  /// parts are actually available (e.g. the login stub only sets a
  /// pre-composed `name`, not the split fields).
  String? _composeName() {
    if (firstName == null && lastName == null) return name;
    final mi = (middleInitial ?? '').trim();
    return [
      firstName,
      if (mi.isNotEmpty) '$mi.',
      lastName,
    ].where((p) => p != null && p.isNotEmpty).join(' ');
  }

  /// Call once before runApp (see main.dart) to restore the last
  /// session from disk.
  Future<void> load() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final roleStr = prefs.getString(_roleKey);
      final statusStr = prefs.getString(_statusKey);
      role = roleStr == null ? null : UserRole.values.byName(roleStr);
      status = statusStr == null
          ? AccountStatus.loggedOut
          : AccountStatus.values.byName(statusStr);
      // Read through the DTO's forgiving parser rather than `values.byName`,
      // because this one is the *server's* own string: a build meeting a status
      // it has never heard of should render "unrecognised" rather than throw on
      // startup — and the catch below answers a throw by dropping the whole
      // session, which is a lot to lose to one unknown word.
      final registrationRaw = prefs.getString(_registrationStatusKey);
      registrationStatus = registrationRaw == null
          ? null
          : remote.RemoteRegistrationStatus.parse(registrationRaw);
      verificationId = prefs.getString(_verificationIdKey);
      final otpSentAtRaw = prefs.getString(_otpSentAtKey);
      otpSentAt = otpSentAtRaw == null ? null : DateTime.tryParse(otpSentAtRaw);
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
      final recentRaw = prefs.getStringList(_recentAddressesKey);
      recentAddresses = recentRaw == null ? const [] : List.of(recentRaw);
      final addressesRaw = prefs.getString(_addressesKey);
      addresses = _decodeAddresses(addressesRaw);
      avatarPath = prefs.getString(_avatarPathKey);
      final memberSinceRaw = prefs.getString(_memberSinceKey);
      memberSince = memberSinceRaw == null
          ? null
          : DateTime.tryParse(memberSinceRaw);
    } catch (_) {
      // Corrupt/old prefs shape — fall back to logged-out rather than crash.
      role = null;
      status = AccountStatus.loggedOut;
    }
  }

  List<AddressEntry> _decodeAddresses(String? raw) {
    if (raw == null || raw.isEmpty) return const [];
    try {
      final decoded = jsonDecode(raw);
      if (decoded is! List) return const [];
      final entries = <AddressEntry>[];
      for (final row in decoded) {
        if (row is! Map) continue;
        final entry = AddressEntry.fromJson(
          row.map((key, value) => MapEntry(key.toString(), value)),
        );
        if (entry != null) entries.add(entry);
      }
      if (entries.isNotEmpty && !entries.any((a) => a.isDefault)) {
        entries[0] = entries[0].copyWith(isDefault: true);
      }
      if (entries.length > addressBookLimit) {
        return entries.sublist(0, addressBookLimit);
      }
      return entries;
    } catch (_) {
      // A malformed book costs the book, not the whole signed-in profile.
      return const [];
    }
  }

  Future<void> _setOrRemove(
    SharedPreferences prefs,
    String key,
    String? value,
  ) async {
    value == null || value.isEmpty
        ? await prefs.remove(key)
        : await prefs.setString(key, value);
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_statusKey, status.name);
    // Stored as the *wire* string rather than the enum's name, so what sits on
    // disk is what the server said and a later reorganisation of the enum
    // cannot turn a saved session into an unreadable one.
    await _setOrRemove(prefs, _registrationStatusKey, registrationStatus?.wire);
    role == null
        ? await prefs.remove(_roleKey)
        : await prefs.setString(_roleKey, role!.name);
    await _setOrRemove(prefs, _verificationIdKey, verificationId);
    await _setOrRemove(prefs, _otpSentAtKey, otpSentAt?.toIso8601String());
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
    final recent = recentAddresses;
    recent.isEmpty
        ? await prefs.remove(_recentAddressesKey)
        : await prefs.setStringList(_recentAddressesKey, recent);
    final savedAddresses = addresses;
    savedAddresses.isEmpty
        ? await prefs.remove(_addressesKey)
        : await prefs.setString(
            _addressesKey,
            jsonEncode([for (final address in savedAddresses) address.toJson()]),
          );
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

  /// Changes the password after re-proving the current one.
  ///
  /// The repository/server does the checking; this method exists so screens do
  /// not reach into a data source directly and so a successful change can notify
  /// anything watching the session. Other phones are signed out by the server.
  Future<void> changePassword({
    required String currentPassword,
    required String newPassword,
  }) async {
    await _accountSecurity.changePassword(
      currentPassword: currentPassword,
      newPassword: newPassword,
    );
    notifyListeners();
  }

  /// Asks for a code at the new address. Deliberately does not touch [email]:
  /// until the code comes back, the account still belongs to the old address,
  /// and a profile that showed the pending one would claim a change that has
  /// not happened.
  Future<void> requestEmailChange(String newEmail) async {
    final value = newEmail.trim();
    if (email != null && value.toLowerCase() == email!.toLowerCase()) {
      throw const ValidationException(
        fieldErrors: {'email': 'This is already your email address.'},
      );
    }

    await _accountSecurity.requestEmailChange(value);
  }

  /// Confirms the emailed code and moves the session onto the new address.
  Future<void> confirmEmailChange(String code) async {
    final changed = await _accountSecurity.confirmEmailChange(code);
    email = changed;
    notifyListeners();
    await _save();
  }

  /// Same two-step shape as email. The number is validated and normalized here
  /// as well as server-side: an invalid number should be answered at the field
  /// without spending an OTP request, and the server still re-checks.
  Future<void> requestPhoneChange(String newPhone) async {
    final value = normalizePhMobile(newPhone);
    final problem = validatePhMobile(value);
    if (problem != null) {
      throw ValidationException(fieldErrors: {'contact_no': problem});
    }
    if (phone != null && value == normalizePhMobile(phone)) {
      throw const ValidationException(
        fieldErrors: {'contact_no': 'This is already your phone number.'},
      );
    }

    await _accountSecurity.requestPhoneChange(value);
  }

  Future<void> confirmPhoneChange(String code) async {
    final changed = await _accountSecurity.confirmPhoneChange(code);
    phone = changed;
    notifyListeners();
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

  /// Submits the emailed code that closes the first of the two registration
  /// gates.
  ///
  /// What happens next is the *repository's* answer, exactly as in [login] and
  /// [submitForApproval] — and it matters more here than anywhere else in this
  /// class, because a wrong code and a right one arrive from the same button
  /// press and only one of them moves the session on.
  ///
  /// Does nothing when [verificationId] or [role] is null. That is unreachable
  /// through the UI, which only offers the field when [canConfirmEmail] is true,
  /// and it answers by returning rather than by sending a request with a null
  /// handle: "there is no registration to confirm" is not something a repository
  /// call can express, and inventing a handle to send would be worse than the
  /// silence.
  Future<void> confirmEmail(String code) async {
    final id = verificationId;
    final currentRole = role;
    if (id == null || currentRole == null) return;

    final result = await _repository.verifyOtp(
      role: currentRole,
      verificationId: id,
      code: code,
    );
    _applyResult(result);
    await _save();
  }

  /// Asks for a fresh code. A failure propagates so the screen can say so: a
  /// resend that quietly failed leaves someone watching an inbox for mail that
  /// is not coming, which is the worse of the two outcomes available here.
  Future<void> resendConfirmationCode() async {
    final id = verificationId;
    final currentRole = role;
    if (id == null || currentRole == null) return;

    await _repository.resendOtp(role: currentRole, verificationId: id);
    otpSentAt = DateTime.now();
    notifyListeners();
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
    // Assigned unconditionally for the same reason, and the consequence is the
    // useful one: a successful confirmation comes back with no handle (there is
    // nothing left to confirm), so the id clears itself the moment the email gate
    // closes. Nothing has to remember to tidy it up.
    verificationId = result.verificationId;
    // A fresh pending-verification answer means a fresh code. A confirmed
    // answer carries no handle, so the timestamp clears with it.
    otpSentAt = result.verificationId != null && awaitsEmailVerification
        ? DateTime.now()
        : null;
    name = result.name ?? fallbackName ?? name;
    email = result.email ?? email;
    memberSince ??= DateTime.now();
    notifyListeners();
  }

  /// Moves this session onto the email gate after a sign-in the server
  /// *refused* because the address is not confirmed yet.
  ///
  /// This is the one refusal that is not an end state. The credentials were
  /// accepted — the account exists and the password matched — and the only thing
  /// between this person and the app is a code that was already sent to them.
  /// The server says as much, and hands over the handle that code can be
  /// confirmed against, so refusing the sign-in and merely saying "confirm your
  /// email" would leave someone with nothing to confirm *with*: the
  /// registration form would open a second account, and the OTP screen needs a
  /// handle the app would otherwise have thrown away.
  ///
  /// Deliberately not reached from the repository. The repository's contract is
  /// to *throw* for a shut gate — that is what `ApiClient` maps a 403 to, and a
  /// test pins it — so the transition belongs to the caller, which is the login
  /// screen: it is the only layer that both sees the refusal and still has the
  /// address the person typed.
  ///
  /// Going through [_applyResult] rather than assigning fields is what keeps a
  /// session entered this way indistinguishable from one that never left the
  /// registration form — including `canConfirmEmail`, which comes out true for
  /// the same reason it does there.
  Future<void> beginEmailVerification({
    required UserRole role,
    required String email,
    required String verificationId,
  }) async {
    _applyResult(
      AuthResult(
        role: role,
        status: AccountStatus.pendingApproval,
        email: email,
        registrationStatus: remote.RemoteRegistrationStatus.pendingVerification,
        verificationId: verificationId,
      ),
    );
    await _save();
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

  /// TEST-ONLY, the mirror of [debugApprovePendingAccount]: puts this session into
  /// the *email* gate with a handle the mock accepts, so the OTP screen is
  /// reachable by hand in a dev-tools build.
  ///
  /// It exists because the mock's `register()` cannot honestly answer
  /// `pending_verification` — there is no mail, so claiming a code was sent would
  /// send someone to check an inbox that will never receive anything, which is
  /// the exact lie the pending screen was rewritten to stop telling. Opened
  /// explicitly as a testing affordance instead of faked as a registration
  /// answer. An unreachable screen, on the other hand, is one nobody ever looks
  /// at, and a screen nobody looks at is a screen that is wrong on the day it is
  /// finally reached.
  ///
  /// Refuses to run outside the pending state, so it cannot be used to skip a
  /// registration that never happened. Remove along with the other dev-tools
  /// affordances once a real registration answers `pending_verification`.
  void debugStartEmailVerification() {
    if (status != AccountStatus.pendingApproval || role == null) return;
    registrationStatus = remote.RemoteRegistrationStatus.pendingVerification;
    verificationId = kMockVerificationId;
    otpSentAt = DateTime.now();
    notifyListeners();
    _save();
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

  /// Records the just-used delivery address, most-recent-first,
  /// deduped, capped. Called on successful order placement; empty
  /// summaries are ignored, so a profile with no address on file
  /// never pollutes the list.
  void recordRecentAddress(String summary) {
    final trimmed = summary.trim();
    if (trimmed.isEmpty) return;
    final next = [trimmed];
    for (final existing in recentAddresses) {
      if (existing.toLowerCase() != trimmed.toLowerCase()) next.add(existing);
      if (next.length >= recentAddressesCap) break;
    }
    recentAddresses = next;
    notifyListeners();
    _save();
  }

  /// Seeds the book from the profile address the first time the address book is
  /// opened for an account made before the book existed. This is why a buyer
  /// who never adds anything still has one default and checkout keeps working.
  ///
  /// The seeded phone is not re-validated here: it came from the profile, and
  /// dropping the only address because an old profile number is in a format the
  /// new form would reject is worse than showing it so the buyer can correct it.
  void ensureAddressBookSeeded() {
    if (addresses.isNotEmpty || addressSummary.trim().isEmpty) return;
    final seeded = AddressEntry(
      id: 'ADDR-1',
      recipient: (name ?? '').trim().isEmpty ? 'Buyer' : name!.trim(),
      phone: (phone ?? '').trim(),
      region: regionForProvince(province),
      city: (municipality ?? '').trim(),
      barangay: (barangay ?? '').trim(),
      street: (street ?? '').trim(),
      isDefault: true,
    );
    addresses = [seeded];
    notifyListeners();
    _save();
  }

  /// Adds [draft], assigning it the next stable `ADDR-n` id. Returns a
  /// user-facing error, or null on success.
  ///
  /// The first address is always the default; later ones default only when the
  /// form asked for it. When it does, every other row loses the marker in the
  /// same write, so there is never more than one.
  String? addAddress(AddressEntry draft) {
    if (addresses.length >= addressBookLimit) {
      return 'You can save up to $addressBookLimit addresses.';
    }
    final error = _validateAddress(draft);
    if (error != null) return error;

    final makeDefault = draft.isDefault || addresses.isEmpty;
    if (makeDefault) {
      addresses = [
        for (final address in addresses) address.copyWith(isDefault: false),
      ];
    }
    addresses = [
      ...addresses,
      AddressEntry(
        id: _nextAddressId(),
        recipient: draft.recipient.trim(),
        phone: draft.phone.trim(),
        region: draft.region.trim(),
        city: draft.city.trim(),
        barangay: draft.barangay.trim(),
        street: draft.street.trim(),
        isDefault: makeDefault,
      ),
    ];
    notifyListeners();
    _save();
    return null;
  }

  /// Replaces the row with the same id. Returns a user-facing error or null.
  String? updateAddress(AddressEntry draft) {
    final index = addresses.indexWhere((address) => address.id == draft.id);
    if (index < 0) return 'That address no longer exists.';
    final error = _validateAddress(draft);
    if (error != null) return error;

    final existing = addresses[index];
    if (existing.isDefault &&
        !draft.isDefault &&
        addresses.length > 1) {
      return 'Choose another default address first.';
    }

    final next = [
      for (final address in addresses) address.copyWith(isDefault: false),
    ];
    final makeDefault = draft.isDefault || existing.isDefault || addresses.length == 1;
    next[index] = AddressEntry(
      id: draft.id,
      recipient: draft.recipient.trim(),
      phone: draft.phone.trim(),
      region: draft.region.trim(),
      city: draft.city.trim(),
      barangay: draft.barangay.trim(),
      street: draft.street.trim(),
      isDefault: makeDefault,
    );
    addresses = next;
    notifyListeners();
    _save();
    return null;
  }

  /// Deletes one address. The default is protected while alternatives exist,
  /// because checkout and the address book both rely on there being one.
  String? deleteAddress(String id) {
    final matches = addresses.where((address) => address.id == id);
    if (matches.isEmpty) return 'That address no longer exists.';
    final target = matches.first;
    if (target.isDefault && addresses.length > 1) {
      return 'Choose another default address first.';
    }
    final next = [
      for (final address in addresses)
        if (address.id != id) address,
    ];
    if (next.isNotEmpty && !next.any((address) => address.isDefault)) {
      next[0] = next[0].copyWith(isDefault: true);
    }
    addresses = next;
    notifyListeners();
    _save();
    return null;
  }

  /// Moves the default marker to [id]. Returns false when the address is gone.
  bool setDefaultAddress(String id) {
    if (!addresses.any((address) => address.id == id)) return false;
    addresses = [
      for (final address in addresses)
        address.copyWith(isDefault: address.id == id),
    ];
    notifyListeners();
    _save();
    return true;
  }

  String? _validateAddress(AddressEntry address) {
    final recipient = address.recipient.trim();
    if (recipient.isEmpty) return 'Recipient name is required.';
    if (recipient.length < 2) {
      return 'Recipient name must be at least 2 characters.';
    }
    final phoneError = validatePhMobile(address.phone);
    if (phoneError != null) return '$phoneError.';
    if (address.region.trim().isEmpty) return 'Select a region.';
    if (address.city.trim().isEmpty) return 'Select a city.';
    if (address.barangay.trim().isEmpty) return 'Select a barangay.';
    if (address.street.trim().isEmpty) return 'Street address is required.';
    return null;
  }

  String _nextAddressId() {
    var highest = 0;
    final pattern = RegExp(r'^ADDR-(\d+)$');
    for (final address in addresses) {
      final match = pattern.firstMatch(address.id);
      if (match == null) continue;
      final number = int.tryParse(match.group(1)!) ?? 0;
      if (number > highest) highest = number;
    }
    return 'ADDR-${highest + 1}';
  }

  /// Re-asks the server what this account's current status is.
  ///
  /// Called on resume from `PendingApprovalScreen` and by "Check Status".
  /// If the account has since been approved, `AuthGate` rebuilds to the
  /// shell automatically because `_applyResult` calls `notifyListeners`.
  ///
  /// Silently returns if there is no role or no configured client — the
  /// mock repository's `refresh()` is a no-op, so in mock mode this is
  /// always a no-op and `debugApprovePendingAccount` is the test path.
  Future<void> refresh() async {
    final currentRole = role;
    if (currentRole == null) return;
    final result = await _repository.refresh(role: currentRole);
    if (result != null) {
      _applyResult(result);
      await _save();
    }
  }

  /// Signs out: this device's session is cleared, then the repository is told  /// to revoke the server session.
  ///
  /// In that order on purpose. The person asked to be signed out of *this*
  /// device and that part cannot fail, so it happens first; a revoke that fails
  /// then propagates to the caller instead of being swallowed, because a live
  /// token outliving the sign-out is the one outcome worse than an error. In
  /// mock mode the repository's `logout()` is a no-op, so nothing about signing
  /// out changes — see `docs/api-contract.md` §3.3.
  Future<void> logout() async {
    // The role is captured before being cleared: the repository needs it to call
    // the correct revocation path (/logout for buyers, /logout/courier for
    // couriers), and `_repository.logout()` is called after the local state is
    // cleared — so we read it now.
    final sessionRole = role ?? UserRole.buyer;

    role = null;
    status = AccountStatus.loggedOut;
    registrationStatus = null;
    verificationId = null;
    otpSentAt = null;
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
    recentAddresses = const [];
    addresses = const [];
    avatarPath = null;
    memberSince = null;
    // Navigation memory belongs to the account, not the device — same
    // reason recentAddresses clears above.
    await clearOrdersTab();
    notifyListeners();
    await _save();
    await _repository.logout(role: sessionRole);
  }
}
