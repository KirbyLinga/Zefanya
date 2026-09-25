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
// TODO: this still only reflects local UI actions (login form submit,
// registration submit, profile edits) rather than a real Laravel API —
// swap the bodies of login()/submitForApproval()/updateProfile() for
// real API calls, and consider flutter_secure_storage instead of
// shared_preferences once there's a real auth token to protect.

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

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
  UserRole? role;
  AccountStatus status = AccountStatus.loggedOut;
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
  void login({required UserRole role, String? name, String? email}) {
    this.role = role;
    status = AccountStatus.active;
    this.name = name;
    this.email = email;
    memberSince ??= DateTime.now();
    notifyListeners();
    _save();
  }

  /// Call on registration submit for BOTH Buyer and Courier — every
  /// role now waits on review before becoming active, per the updated
  /// spec. PendingApprovalScreen reads `role` to show the right
  /// reviewer (administrator vs Logistics/Sorting Center).
  void submitForApproval({required UserRole role, String? name, String? email}) {
    this.role = role;
    status = AccountStatus.pendingApproval;
    this.name = name;
    this.email = email;
    memberSince ??= DateTime.now();
    notifyListeners();
    _save();
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

  void logout() {
    role = null;
    status = AccountStatus.loggedOut;
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
    _save();
  }
}
