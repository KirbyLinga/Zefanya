// lib/data/dto/registration_payload.dart
//
// The registration form's answers, and the one place that knows what the
// server calls them.
//
// ## Why this file exists
//
// The form speaks this app's vocabulary: `_lastNameController.text`, `_sex` as
// 'Male'/'Female', `_dob`, `_address.province`. The server speaks
// `StoreBuyerRegistrationRequest`'s: `last_name`, `sex` as `male|female`,
// `birthday` as a date, `contact_no`, `address_mode`. Those are two different
// vocabularies, and the seam between them belongs in exactly one file —
// otherwise the wire spelling leaks into a 1000-line widget tree and a
// server-side rename becomes a screen-wide edit.
//
// So `AuthRepository.register()` takes a [RegistrationProfile] (app vocabulary)
// and only the live implementation converts it, via [toBuyerFields]. No screen
// in this app spells `middle_initial`, and no wire key is typed twice.
//
// ## What is deliberately *not* here
//
// 1. **The ID document.** The server validates it as
//    `file|mimes:jpg,jpeg,png,pdf|max:5120` — a *multipart part*, not a field.
//    That is why the mapping method is called [toBuyerFields] and not something
//    like `toBody`: a request wired up from its output alone would silently
//    omit the upload. The profile carries the image's *path* separately
//    ([RegistrationProfile.govIdPath]) so the name says which half you hold.
//
// 2. **The courier request.** `StoreLogisticsRegistrationRequest` does exist
//    server-side, and it does *not* match this form: it requires
//    `business_name` and `dti_permit_upload` (which the form never collects) and
//    it has no vehicle field at all (the form collects two — a type and a
//    plate). A mapping that could only produce a 422 is worse than no mapping,
//    so the courier half waits for the pass that settles the *form*; and until
//    then [RegistrationProfile] models the buyer's request only, rather than
//    growing optional fields that nothing reads. A courier's vehicle and
//    document answers stay in the form's own state meanwhile, and the mismatch
//    is recorded as a flag in `memory-bank/progress.md`.
//
// ## Where the rules come from
//
// `web/app/Http/Requests/Buyer/StoreBuyerRegistrationRequest.php` and
// `RegisterBuyerController` — read, not guessed. The server is the authority
// on every rule; the copies below exist so the *form* can refuse a
// 7-character password without spending a round-trip to find out.

/// The minimum registration password, mirroring the server's
/// `['required', 'string', 'min:8', 'confirmed']`.
///
/// A copy rather than a fetched value because it is checked *before* anything
/// is sent: the alternative is letting someone fill in a two-step form, submit,
/// and learn about a one-character problem from a 422 afterwards.
const int kMinPasswordLength = 8;

/// The `sex` values the server accepts — `Rule::in(['male', 'female'])`.
///
/// Lowercase on the wire; the form shows `'Male'`/`'Female'`. See
/// [RegistrationProfile.sexWire].
const List<String> kSexWireValues = ['male', 'female'];

/// Everything the registration form collects, in this app's vocabulary.
///
/// Address parts are *names* (`'Cebu'`, `'Cebu City'`) because those are what
/// the server's `*_name` fields store and what the rest of the app displays.
/// The offline PSGC catalogue also supplies codes, but they stay local for now
/// — see [toBuyerFields] for why the wire still uses `manual` mode.
class RegistrationProfile {
  const RegistrationProfile({
    required this.lastName,
    required this.firstName,
    required this.sex,
    required this.phone,
    required this.birthday,
    this.region,
    required this.province,
    required this.municipality,
    required this.barangay,
    required this.street,
    this.postalCode,
    this.middleInitial,
    this.govIdPath,
    this.riderCompany,
    this.riderHub,
    this.riderVehicleType,
    this.riderPlate,
    this.riderLicenseNumber,
    this.riderLicenseExpiry,
    this.riderLicensePath,
    this.riderOrCrPath,
    this.riderProviderId,
    this.provinceCode,
    this.municipalityCode,
    this.barangayCode,
    this.houseNumber,
    this.addressDetail,
  });

  /// The chosen logistics company's id, from `GET /api/rider/providers`.
  /// WebZefanya's `logistics_provider_id` must name an approved company, so
  /// the free-text [riderCompany] alone cannot register a rider live.
  final int? riderProviderId;

  /// PSGC codes from the offline address catalogue. Rider registration on
  /// WebZefanya requires `address_mode=api`, which takes the codes alongside
  /// the `*_name` labels.
  final String? provinceCode;
  final String? municipalityCode;
  final String? barangayCode;

  /// The web rider form's two optional address lines (`house_number`,
  /// `address_detail`), collected by the rider form only.
  final String? houseNumber;
  final String? addressDetail;

  final String lastName;
  final String firstName;

  /// Optional: the server takes `nullable|string|max:2`.
  final String? middleInitial;

  /// As the form spells it — `'Male'` / `'Female'`.
  final String sex;

  /// `09XXXXXXXXX`, the shape the form already validates against the server's
  /// own `regex:/^09\d{9}$/`.
  final String phone;

  /// Date of birth. Only the date half is meaningful — the form has no time to
  /// send from.
  final DateTime birthday;

  /// Local profile fields, not part of the buyer registration request. The
  /// offline catalogue supplies them so the address book can seed a complete
  /// address (including the postal code) after approval. They are not sent.
  final String? region;
  final String? postalCode;

  final String province;
  final String municipality;
  final String barangay;

  /// The form's "Street / Detailed Address" field.
  final String street;

  /// Where the picked government ID image was saved (`pickAndSaveImage`).
  ///
  /// A path, not a value: this is the multipart part described in the file
  /// header, and it is kept out of [toBuyerFields] on purpose.
  final String? govIdPath;

  /// The courier's rider record. Optional because the same profile class
  /// carries buyer registrations, where every one of these is null.
  ///
  /// These are not in [toBuyerFields] and have no live wire mapping yet:
  /// `StoreLogisticsRegistrationRequest` still describes the Logistics web
  /// form (business name + DTI permit), not this app's rider sign-up. The
  /// fields are carried here so `AuthState.submitForApproval` can record what
  /// was actually submitted, and so the eventual courier mapping has one
  /// object to read rather than reaching into a disposed screen.
  final String? riderCompany;
  final String? riderHub;
  final String? riderVehicleType;
  final String? riderPlate;
  final String? riderLicenseNumber;
  final DateTime? riderLicenseExpiry;
  final String? riderLicensePath;
  final String? riderOrCrPath;

  /// The wire value for sex: the form's `'Male'` is the server's `'male'`.
  ///
  /// Lowercased rather than switched over two literals, so an already-correct
  /// value passes through unchanged — the same tolerance `RemoteStatus.parse`
  /// shows, and for the same reason: capitalisation carries no meaning and
  /// must not be able to fail a registration.
  String get sexWire => sex.trim().toLowerCase();

  /// Date of birth as `YYYY-MM-DD`.
  ///
  /// Date-only, not `DateTime.toIso8601String()`: that would send
  /// `1998-04-07T00:00:00.000`, a timestamp the server's `date` rule would then
  /// have to read in some timezone it was never told. A birthday is a date.
  String get birthdayWire => formatWireDate(birthday);

  /// The phone number as the server's `regex:/^09\d{9}$/` wants it.
  ///
  /// Spaces and dashes are stripped because the form's own validator already
  /// strips them before matching — it accepts `0917 123 4567`, so sending it
  /// verbatim would hand the server a value this app has already declared valid
  /// and get a 422 for the trouble. The form's leniency and the server's
  /// strictness disagree; the seam is where that gets settled.
  String get phoneWire => phone.replaceAll(RegExp(r'[\s-]'), '');

  /// The scalar half of `StoreBuyerRegistrationRequest`, ready to send.
  ///
  /// See the file header for the part that is *not* in here (`upload_id` is a
  /// multipart file, fed from [govIdPath]).
  ///
  /// ## `address_mode: 'manual'`, and why that is the accurate answer
  ///
  /// The `api` mode is for a client that picked from PSGC **codes** and can
  /// send them as `province`/`municipality`/`barangay` alongside their
  /// `*_name` labels. The app now has those codes from its offline catalogue,
  /// but the live registration contract has not been exercised with them yet;
  /// `manual` is still what this request promises. The names are sent because
  /// those mean what they say — `RegisterBuyerController` writes them straight
  /// to `province_name` and friends. Moving to `api` is a backend-coordinated
  /// change, not something this screen can decide on its own.
  ///
  /// ## Empty optionals are omitted, never blank
  ///
  /// A missing `middle_initial` is "not given"; `''` is "given as nothing".
  /// The server's rule treats only the first as valid (`nullable`, not
  /// `string`), which is the same distinction `minorFromJson` draws between an
  /// absent price and a zero one.
  ///
  /// ## The password, sent twice
  ///
  /// `confirmed` requires `password_confirmation` to be present and equal, and
  /// it is not a parameter anywhere in this app: the form will not submit until
  /// the two entries match, so by the time a password reaches here, "they
  /// differ" is unrepresentable. Sending the same verified value twice
  /// satisfies the rule without inventing anything — and because there is no
  /// second parameter to pass, no caller can get it wrong.
  Map<String, Object?> toBuyerFields({
    required String email,
    required String password,
  }) => {
    ...toProfileFields(),
    'email': email.trim(),
    'password': password,
    'password_confirmation': password,
  };

  /// The profile fields shared by password and Google registration.
  ///
  /// Separated so the Google request cannot drift from the password one: both
  /// send the same names, the same lowercased sex, the same date-only birthday,
  /// and the same manual address mode. The Google path adds its token and role
  /// at the repository seam; the password path adds the credentials.
  Map<String, Object?> toProfileFields() => {
    'last_name': lastName.trim(),
    'first_name': firstName.trim(),
    if (_present(middleInitial)) 'middle_initial': middleInitial!.trim(),
    'sex': sexWire,
    'contact_no': phoneWire,
    'birthday': birthdayWire,
    'address_mode': 'manual',
    if (_present(province)) 'province_name': province.trim(),
    if (_present(municipality)) 'municipality_name': municipality.trim(),
    if (_present(barangay)) 'barangay_name': barangay.trim(),
    'street': street.trim(),
  };

  /// The registration fields sent when Google is the credential.
  ///
  /// No password and no email: the server verifies the email from the ID token,
  /// and Google is the credential. The repository adds `id_token` and the role
  /// the person chose after the server answered `needs_role`.
  Map<String, Object?> toGoogleFields() => toProfileFields();

  /// Courier/Rider registration fields including vehicle and license details.
  Map<String, Object?> toCourierFields({
    required String email,
    required String password,
  }) => {
    ...toCourierProfileFields(),
    'email': email.trim(),
    'password': password,
    'password_confirmation': password,
  };

  /// The courier-specific profile fields shared by password and Google auth.
  Map<String, Object?> toCourierProfileFields() => {
    ...toProfileFields(),
    if (_present(riderCompany)) 'company': riderCompany!.trim(),
    if (_present(riderCompany)) 'business_name': riderCompany!.trim(),
    'vehicle_type': riderVehicleType?.trim() ?? 'Motorcycle',
    'plate_number': riderPlate?.trim() ?? '',
    'license_number': riderLicenseNumber?.trim() ?? '',
    if (riderLicenseExpiry != null)
      'license_expiry': formatWireDate(riderLicenseExpiry!),
    if (_present(riderHub)) 'hub': riderHub!.trim(),
  };

  /// Courier fields sent when registering via Google.
  Map<String, Object?> toGoogleCourierFields() => toCourierProfileFields();

  /// The scalar half of WebZefanya's `StoreRiderRegistrationRequest`
  /// (`POST /api/rider/register`), read from the request, not guessed:
  /// `license_no` (not `license_number`), PSGC codes with `address_mode=api`,
  /// the approved `logistics_provider_id`, and `terms`. The two documents,
  /// `drivers_license` and `or_cr`, are multipart parts sent separately.
  /// No station hub: the server forbids it here; Logistics assigns one at
  /// approval.
  Map<String, Object?> toRiderFields({
    required String email,
    required String password,
  }) => {
    'first_name': firstName.trim(),
    'last_name': lastName.trim(),
    'birthday': birthdayWire,
    'email': email.trim(),
    'contact_no': phoneWire,
    'license_no': (riderLicenseNumber ?? '').trim().toUpperCase(),
    if (riderLicenseExpiry != null)
      'license_expiry': formatWireDate(riderLicenseExpiry!),
    'vehicle_type': riderVehicleType?.trim() ?? '',
    'plate_number': (riderPlate ?? '').trim().toUpperCase(),
    'address_mode': 'api',
    'province': provinceCode ?? '',
    'province_name': province.trim(),
    'municipality': municipalityCode ?? '',
    'municipality_name': municipality.trim(),
    'barangay': barangayCode ?? '',
    'barangay_name': barangay.trim(),
    'street': street.trim(),
    if (_present(houseNumber)) 'house_number': houseNumber!.trim(),
    if (_present(addressDetail)) 'address_detail': addressDetail!.trim(),
    if (_present(postalCode)) 'postal_code': postalCode!.trim(),
    if (riderProviderId != null) 'logistics_provider_id': riderProviderId,
    'password': password,
    // The form only submits after the person ticked the terms checkbox.
    'terms': '1',
  };
}

/// The vehicle a rider may register with in this app: motorcycle only (a
/// product decision). WebZefanya's `RiderValidation::VEHICLES` also accepts
/// Car and Van, so Motorcycle is always valid on the server.
const List<String> kRiderRegistrationVehicleTypes = ['Motorcycle'];

/// `YYYY-MM-DD` — the date-only shape the server's `date` rule reads.
///
/// Public because the courier's registration sends the same field the same way,
/// and a private copy here would have to be duplicated there.
String formatWireDate(DateTime date) =>
    '${date.year.toString().padLeft(4, '0')}-'
    '${date.month.toString().padLeft(2, '0')}-'
    '${date.day.toString().padLeft(2, '0')}';

bool _present(String? value) => value != null && value.trim().isNotEmpty;
