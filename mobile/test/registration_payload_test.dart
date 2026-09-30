// test/registration_payload_test.dart
//
// The registration form's vocabulary against the server's, asserted without a
// server.
//
// This is the part of registration that can be wrong *silently*. A birthday
// sent as `1998-04-07T00:00:00.000` instead of `1998-04-07`, a phone number
// sent with the spaces the form tolerates, a `middle_initial` sent as `''`
// where the server says `nullable` — every one of those is a 422 that names a
// field and explains nothing. The mapping was written by reading
// `StoreBuyerRegistrationRequest`; these tests are how it stays honest when the
// app changes around it.

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/data/dto/registration_payload.dart';

/// The form as a Buyer filled it in, with two deliberate shapes: the optional
/// middle initial is present, and the phone number is typed with a space, the
/// way someone reading it off a card would type it.
RegistrationProfile _profile({
  String? middleInitial = 'D',
  String phone = '0917 123 4567',
  String sex = 'Male',
  DateTime? birthday,
  String street = '12 Mabini Street',
}) =>
    RegistrationProfile(
      lastName: 'Dela Cruz',
      firstName: 'Juan',
      middleInitial: middleInitial,
      sex: sex,
      phone: phone,
      birthday: birthday ?? DateTime(1998, 4, 7),
      province: 'Cebu',
      municipality: 'Cebu City',
      barangay: 'Lahug',
      street: street,
      govIdPath: '/data/user/0/ph.zefanya/files/gov_id_01.jpg',
    );

Map<String, Object?> _fields({
  RegistrationProfile? profile,
  String password = 'hunter2hunter2',
}) =>
    (profile ?? _profile()).toBuyerFields(
      email: 'juan@example.ph',
      password: password,
    );

void main() {
  test('the wire keys are the server\'s, and nothing else', () {
    // Asserted as an exact set rather than a series of `contains`, because the
    // failure that matters is not only a missing key: an *extra* one
    // (`upload_id` as a string, or a client-computed `age`) is the same kind of
    // guess about the server's shape, and it would pass a `contains` check.
    expect(_fields().keys.toSet(), {
      'last_name',
      'first_name',
      'middle_initial',
      'sex',
      'email',
      'password',
      'password_confirmation',
      'contact_no',
      'birthday',
      'address_mode',
      'province_name',
      'municipality_name',
      'barangay_name',
      'street',
    });
  });

  test('sex is lowercased for the wire, and an already-lowercase value survives',
      () {
    // `Rule::in(['male', 'female'])` — the form's 'Male' is the server's
    // 'male', and the difference is a rejected registration with no useful
    // message attached.
    expect(_fields(profile: _profile(sex: 'Male'))['sex'], 'male');
    expect(_fields(profile: _profile(sex: 'Female'))['sex'], 'female');
    // Tolerant rather than a two-literal switch, so a value that is already in
    // the server's spelling passes through instead of being mangled.
    expect(_fields(profile: _profile(sex: 'female'))['sex'], 'female');
  });

  test('birthday is a date, not a timestamp', () {
    expect(_fields(profile: _profile(birthday: DateTime(1998, 4, 7)))['birthday'],
        '1998-04-07');
    // Single-digit months and days are padded — '1998-4-7' is not what the
    // server's `date` rule reads.
    expect(_fields(profile: _profile(birthday: DateTime(2001, 12, 31)))['birthday'],
        '2001-12-31');
    // The specific thing being ruled out: toIso8601String() would append a time,
    // and a birthday has no time in it to send.
    expect(_fields()['birthday'], isNot(contains('T')));
  });

  test('the address goes as names in manual mode, never as codes', () {
    final fields = _fields();

    expect(fields['address_mode'], 'manual');
    // The code slots are absent. This is the assertion the file exists for: the
    // app's address picker is a bundled sample of names, so putting 'Cebu' in
    // `province` — which the controller writes to `province_code` — would be a
    // stored lie no later screen could detect.
    expect(fields.containsKey('province'), isFalse);
    expect(fields.containsKey('municipality'), isFalse);
    expect(fields.containsKey('barangay'), isFalse);
    // The name slots are not merely allowed in manual mode, they mean what they
    // say — and dropping them would leave a reviewer approving an account with
    // no address on it.
    expect(fields['province_name'], 'Cebu');
    expect(fields['municipality_name'], 'Cebu City');
    expect(fields['barangay_name'], 'Lahug');
    expect(fields['street'], '12 Mabini Street');
  });

  test('an absent optional is omitted, not sent blank', () {
    // 'not given' and 'given as nothing' are different facts, and the server
    // accepts only the first (`nullable`, not `string`).
    expect(_fields(profile: _profile(middleInitial: ''))
        .containsKey('middle_initial'), isFalse);
    expect(_fields(profile: _profile(middleInitial: '   '))
        .containsKey('middle_initial'), isFalse);
    expect(_fields(profile: _profile(middleInitial: null))
        .containsKey('middle_initial'), isFalse);
    // …and a real one is still sent, trimmed.
    expect(_fields(profile: _profile(middleInitial: ' D '))['middle_initial'], 'D');
  });

  test('the phone number is stripped to the digits the server\'s regex matches',
      () {
    final contact = _fields()['contact_no']! as String;

    // The form's own validator strips spaces and dashes before matching, so it
    // accepts '0917 123 4567' — sending it verbatim would hand the server a
    // value this app has already called valid and get a 422 for the trouble.
    expect(contact, '09171234567');
    // Asserted against the server's rule itself, so this fails if the
    // normalisation drifts even slightly.
    expect(RegExp(r'^09\d{9}$').hasMatch(contact), isTrue);
  });

  test('the password is sent twice, and cannot be sent inconsistently', () {
    final fields = _fields(password: 'hunter2hunter2');

    expect(fields['password'], 'hunter2hunter2');
    expect(fields['password_confirmation'], fields['password']);
    // Not a copy-paste accident: the server's rule is `confirmed`, and this
    // class has no confirmation parameter to pass — the form proves the two
    // match before it ever calls, so a mismatch is unrepresentable here rather
    // than merely unlikely.
  });

  test('the minimum length is the server\'s 8, written down once', () {
    // Pinned to the literal so that changing it has to be a deliberate edit in
    // two places rather than a quiet drift away from `min:8` — which would
    // surface as a 422 on a password this app called fine.
    expect(kMinPasswordLength, 8);
    expect(kSexWireValues, ['male', 'female']);
  });

  test('the ID image is not a field — it is a part, and it stays out of the map',
      () {
    // 5MB jpg/png/pdf, validated with `file|mimes:...`, so it cannot be a form
    // field. If this ever becomes true, the class is lying about the request it
    // maps.
    expect(_fields().containsKey('upload_id'), isFalse);
    // The path is still carried, which is where the multipart part will read it
    // from once the transport exists.
    expect(_profile().govIdPath, isNotNull);
  });

  test('a date is formatted the way the wire wants it, wherever it is called from',
      () {
    expect(formatWireDate(DateTime(2026, 9, 30)), '2026-09-30');
    expect(formatWireDate(DateTime(2026, 1, 1)), '2026-01-01');
  });
}
