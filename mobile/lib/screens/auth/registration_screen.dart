// lib/screens/auth/registration_screen.dart
//
// Path check: this file lives at lib/screens/auth/registration_screen.dart
//   -> theme      => '../../theme/app_theme.dart'
//   -> auth state => '../../state/auth_state.dart'
//   -> mock data  => '../../data/mock_data.dart' (phAddressData, courierVehicleTypes)
//
// Address dropdowns use data/mock_data.dart's phAddressData sample —
// shared with edit_profile_screen.dart so both forms agree. Swap it
// for a real PSGC API lookup when that's wired up.
//
// Role is UserRole (from auth_state.dart) rather than a local enum,
// so this screen and login_screen.dart always agree on what "Buyer"
// vs "Courier" means.
//
// -------------------------------------------------------------------
// Why this is stepped
//
// The previous version was one long scroll with a single _submit()
// that did:
//
//   if (!formValid || !addressValid || !dobValid || !sexValid || !docsValid)
//     -> one snackbar: "Please complete all required fields and uploads."
//
// That's a form that hides its own errors. Someone fills 90% of it,
// misses Sex or one upload, submits, and gets a generic toast with no
// indication of which field — then has to scroll the whole thing
// hunting for it. The steps are not the fix in themselves; the fix is
// that each step validates only its own fields, so a missing Sex
// surfaces on the step that contains Sex, next to the field, the
// moment you try to leave it.
//
// Three things that follow from that, and that you'd break by
// "simplifying" them:
//
//   1. physics: NeverScrollableScrollPhysics. If the pages could be
//      swiped, you could swipe past a step without ever triggering its
//      validation, which puts the generic-toast problem straight back.
//      Forward movement goes through _next() only.
//   2. _KeepAlive around each page. PageView disposes off-screen
//      children by default; the entered text survives (the controllers
//      live on this State) but each page's FormState — and therefore
//      every red error message already on screen — would be rebuilt
//      from scratch on return. Keeping them alive is what makes Back
//      non-destructive.
//   3. One FormState per step, keyed by _StepId rather than by index.
//      Step *indices* shift when the role changes (Courier has a
//      Vehicle step Buyer doesn't); step identities don't.
//
// DOB and the document uploads aren't FormFields, so validate() can't
// speak for them. They render their own error text, gated on
// _attempted, so nothing is red before the person has tried to
// advance past it.
// -------------------------------------------------------------------

import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../../data/api/api_exception.dart';
import '../../data/dto/registration_payload.dart';
import '../../data/mock_data.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/image_picker_helper.dart';
import '../../utils/motion.dart';
import '../../utils/password_strength.dart';
import '../../utils/rider_validators.dart';
import '../../utils/timed_snackbar.dart';
import '../../utils/validators.dart';
import '../../widgets/dirty_guard.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/zefanya_mark.dart';

// Shared validation patterns — used by the form fields below.
final RegExp _emailRegex = RegExp(r'^[\w.+-]+@[\w-]+\.[\w.-]+$');
final RegExp _phPhoneRegex = RegExp(r'^(09\d{9}|\+639\d{9})$');

/// Stable identity for a step, independent of its position. Buyer skips
/// [vehicle], so position 2 means Documents for a Buyer and Vehicle for
/// a Courier — anything keyed on the index would mix them up the moment
/// someone flips the role toggle.
///
/// [credentials] was inserted between [personal] and [address], which is
/// exactly what this design was built to survive: the enum is ordered for
/// reading, the *page order* comes from [_RegistrationScreenState._steps], and
/// every switch over the enum is exhaustive, so the compiler names every site
/// that needed a new case.
enum _StepId { personal, credentials, address, vehicle, documents }

extension on _StepId {
  String get label => switch (this) {
    _StepId.personal => 'Personal',
    _StepId.credentials => 'Account',
    _StepId.address => 'Address',
    _StepId.vehicle => 'Vehicle',
    _StepId.documents => 'Documents',
  };
}

class RegistrationScreen extends StatefulWidget {
  const RegistrationScreen({super.key, this.initialRole = UserRole.buyer});

  final UserRole initialRole;

  @override
  State<RegistrationScreen> createState() => _RegistrationScreenState();
}

class _RegistrationScreenState extends State<RegistrationScreen> {
  final _pageController = PageController();

  final Map<_StepId, GlobalKey<FormState>> _formKeys = {
    for (final id in _StepId.values) id: GlobalKey<FormState>(),
  };

  /// Steps the person has tried to leave. Only these render errors for
  /// the non-FormField inputs (DOB, uploads) — a form shouldn't be red
  /// before you've done anything wrong.
  final Set<_StepId> _attempted = {};

  int _step = 0;

  late UserRole _role = widget.initialRole;

  final _lastNameController = TextEditingController();
  final _firstNameController = TextEditingController();
  final _middleInitialController = TextEditingController();
  final _emailController = TextEditingController();
  final _phoneController = TextEditingController();
  final _streetController = TextEditingController();
  final _plateController = TextEditingController();
  final _companyController = TextEditingController();
  final _licenseNumberController = TextEditingController();
  final _emailFocus = FocusNode();
  final _phoneFocus = FocusNode();
  final _lastNameFocus = FocusNode();
  final _firstNameFocus = FocusNode();
  final _middleInitialFocus = FocusNode();
  final _passwordFocus = FocusNode();
  final _confirmPasswordFocus = FocusNode();
  final _idNumberFocus = FocusNode();
  final _streetFocus = FocusNode();
  final _plateFocus = FocusNode();

  // The password and its confirmation, with a separate reveal flag each: one
  // shared flag would mean peeking at the first field also reveals the second,
  // which is the opposite of what someone checking a typo asked for.
  final _passwordController = TextEditingController();
  final _confirmPasswordController = TextEditingController();
  bool _obscurePassword = true;
  bool _obscureConfirmation = true;

  String? _sex;

  DateTime? _dob;
  int? _age;
  String? _dobError;

  bool _acceptedTerms = false;

  String? _province;
  String? _municipality;
  String? _barangay;

  String? _vehicleType;
  DateTime? _licenseExpiry;
  String? _licenseExpiryError;

  File? _govIdFile;
  File? _licenseFile;
  File? _orCrFile;

  bool _isSubmitting = false;

  List<_StepId> get _steps => _role == UserRole.buyer
      ? const [
          _StepId.personal,
          _StepId.credentials,
          _StepId.address,
          _StepId.documents,
        ]
      : const [
          _StepId.personal,
          _StepId.credentials,
          _StepId.address,
          _StepId.vehicle,
          _StepId.documents,
        ];

  _StepId get _currentStep => _steps[_step];
  bool get _isLastStep => _step == _steps.length - 1;

  /// True once anything has been typed or selected. Used only on the first
  /// step, where leaving means throwing the whole wizard away.
  bool get _hasUnsavedInput =>
      _lastNameController.text.trim().isNotEmpty ||
      _firstNameController.text.trim().isNotEmpty ||
      _middleInitialController.text.trim().isNotEmpty ||
      _emailController.text.trim().isNotEmpty ||
      _phoneController.text.trim().isNotEmpty ||
      _streetController.text.trim().isNotEmpty ||
      _plateController.text.trim().isNotEmpty ||
      _companyController.text.trim().isNotEmpty ||
      _licenseNumberController.text.trim().isNotEmpty ||
      _passwordController.text.isNotEmpty ||
      _confirmPasswordController.text.isNotEmpty ||
      _sex != null ||
      _dob != null ||
      _province != null ||
      _municipality != null ||
      _barangay != null ||
      _vehicleType != null ||
      _licenseExpiry != null ||
      _govIdFile != null ||
      _licenseFile != null ||
      _orCrFile != null;

  @override
  void dispose() {
    _pageController.dispose();
    _emailFocus.dispose();
    _phoneFocus.dispose();
    _lastNameFocus.dispose();
    _firstNameFocus.dispose();
    _middleInitialFocus.dispose();
    _passwordFocus.dispose();
    _confirmPasswordFocus.dispose();
    _idNumberFocus.dispose();
    _streetFocus.dispose();
    _plateFocus.dispose();
    _lastNameController.dispose();
    _firstNameController.dispose();
    _middleInitialController.dispose();
    _emailController.dispose();
    _phoneController.dispose();
    _streetController.dispose();
    _plateController.dispose();
    _companyController.dispose();
    _licenseNumberController.dispose();
    _passwordController.dispose();
    _confirmPasswordController.dispose();
    super.dispose();
  }

  Future<void> _pickDob() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: DateTime(now.year - 18, now.month, now.day),
      firstDate: DateTime(now.year - 100),
      lastDate: now,
    );
    if (picked == null) return;

    final age = ageOn(picked, today: now);
    setState(() {
      _dob = picked;
      _age = age;
      _dobError = age < kMinimumAge
          ? 'You must be at least $kMinimumAge years old to register.'
          : null;
    });
  }

  Future<void> _pickLicenseExpiry() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _licenseExpiry ?? DateTime(now.year + 1, now.month, now.day),
      firstDate: DateTime(now.year - 10),
      lastDate: DateTime(now.year + 30),
    );
    if (picked == null) return;
    setState(() {
      _licenseExpiry = picked;
      _licenseExpiryError = validateLicenseExpiry(picked);
    });
  }

  // ---- Navigation ------------------------------------------------------

  /// Validates [id]'s own fields and nothing else. This is the whole
  /// point of the rewrite: the answer to "what's wrong?" is always on
  /// the screen you're looking at.
  bool _validateStep(_StepId id) {
    setState(() => _attempted.add(id));

    final formOk = _formKeys[id]!.currentState?.validate() ?? false;

    var extraOk = true;
    if (id == _StepId.personal) {
      extraOk = _dob != null && _dobError == null;
    } else if (id == _StepId.credentials) {
      extraOk = _acceptedTerms;
    } else if (id == _StepId.vehicle) {
      extraOk = _licenseExpiry != null &&
          _licenseExpiryError == null &&
          !isPlateRegisteredElsewhere(
            _plateController.text,
            currentPlate: null,
          );
    } else if (id == _StepId.documents) {
      extraOk = _role == UserRole.buyer
          ? _govIdFile != null
          : (_licenseFile != null && _orCrFile != null);
    }

    return formOk && extraOk;
  }

  void _goTo(int index) {
    setState(() => _step = index);
    if (reduceMotion(context)) {
      _pageController.jumpToPage(index);
    } else {
      _pageController.animateToPage(
        index,
        duration: const Duration(milliseconds: 260),
        curve: Curves.easeOutCubic,
      );
    }
  }

  void _next() {
    if (!_validateStep(_currentStep)) return;
    if (_isLastStep) {
      _submit();
      return;
    }
    FocusScope.of(context).unfocus();
    _goTo(_step + 1);
  }

  void _back() {
    if (_step == 0) {
      Navigator.of(context).maybePop();
      return;
    }
    FocusScope.of(context).unfocus();
    _goTo(_step - 1);
  }

  // ---- Submit ----------------------------------------------------------

  Future<void> _submit() async {
    // Every step has been validated on the way here, and forward motion
    // is only possible through _next(), so there's no whole-form sweep
    // left to do — and no generic "something is missing" snackbar,
    // because there is no longer a way to arrive here with something
    // missing.
    //
    // The artificial 500ms delay that used to wrap this is gone for the reason
    // the 600ms login one went: it stood in for a repository call that now
    // actually happens, and it would otherwise add half a second to every real
    // registration.
    setState(() => _isSubmitting = true);

    final fullName = [
      _firstNameController.text.trim(),
      if (_middleInitialController.text.trim().isNotEmpty)
        '${_middleInitialController.text.trim()}.',
      _lastNameController.text.trim(),
    ].where((p) => p.isNotEmpty).join(' ');

    // Narrowed once instead of banged at each use site. Sex, DOB and the address
    // dropdowns are validated by _validateStep rather than by a FormField, so a
    // `!` here would claim more certainty than this widget can actually see.
    // The early return is the honest fallback for a state that cannot happen:
    // leave the form as it is rather than submit a null-riddled profile.
    final sex = _sex;
    final dob = _dob;
    final province = _province;
    final municipality = _municipality;
    final barangay = _barangay;
    if (sex == null ||
        dob == null ||
        province == null ||
        municipality == null ||
        barangay == null) {
      setState(() => _isSubmitting = false);
      return;
    }

    final auth = context.read<AuthState>();
    try {
      await auth.submitForApproval(
        role: _role,
        email: _emailController.text.trim(),
        password: _passwordController.text,
        name: fullName,
        profile: RegistrationProfile(
          lastName: _lastNameController.text,
          firstName: _firstNameController.text,
          middleInitial: _middleInitialController.text,
          sex: sex,
          phone: _phoneController.text,
          birthday: dob,
          province: province,
          municipality: municipality,
          barangay: barangay,
          street: _streetController.text,
          // Null for a Courier, whose two uploads are a license and an OR/CR —
          // documents `StoreBuyerRegistrationRequest` has no field for. See the
          // courier note in registration_payload.dart.
          govIdPath: _govIdFile?.path,
          riderCompany: _companyController.text,
          riderVehicleType: _vehicleType,
          riderPlate: _plateController.text,
          riderLicenseNumber: _licenseNumberController.text,
          riderLicenseExpiry: _licenseExpiry,
          riderLicensePath: _licenseFile?.path,
          riderOrCrPath: _orCrFile?.path,
        ),
      );
      if (context.mounted) TextInput.finishAutofillContext();
    } on ApiException catch (e) {
      // Reachable in live builds only, where the endpoint does not exist yet.
      // Handled exactly as login handles it — report it and stay on the form,
      // rather than appearing to have submitted something the server never
      // received.
      if (!mounted) return;
      setState(() => _isSubmitting = false);
      showTimedSnackBar(context, SnackBar(content: Text(e.message)));
      return;
    }

    if (!mounted) return;
    setState(() => _isSubmitting = false);

    // After the submit rather than before it: the repository's answer already
    // carried a display name (composed from these same parts when the server
    // sends none), and this recomposes it from them. Both produce the same
    // string today, so the order decides nothing yet — it is written down
    // because the parts are the more specific source, and a future
    // server-supplied name should lose to what the person typed here.
    auth.updateProfile(
      lastName: _lastNameController.text.trim(),
      firstName: _firstNameController.text.trim(),
      middleInitial: _middleInitialController.text.trim(),
      sex: sex,
      phone: _phoneController.text.trim(),
      province: province,
      municipality: municipality,
      barangay: barangay,
      street: _streetController.text.trim(),
    );

    // AuthGate (in main.dart) rebuilds to the right screen once the
    // state changes above — this just unwinds back to that root route.
    Navigator.of(context).popUntil((route) => route.isFirst);
  }

  // ---- Build -----------------------------------------------------------

  /// System back walks the steps rather than throwing away a part-filled
  /// form. On the first step there is no previous step to walk to, so a
  /// dirty form asks before it is discarded (M8) — the same dialog Edit
  /// Profile uses, reused rather than copied.
  Future<void> _handlePop() async {
    if (_step > 0) {
      _back();
      return;
    }
    if (!_hasUnsavedInput) {
      Navigator.of(context).pop();
      return;
    }
    final discard = await DirtyGuard.confirmDiscard(
      context,
      message: 'Your registration details will be lost.',
    );
    if (discard && mounted) Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _handlePop();
      },
      child: Scaffold(
        backgroundColor: Colors
            .transparent, // the app-wide gradient in main.dart paints this
        appBar: AppBar(
          leading: IconButton(
            tooltip: 'Go back',
            icon: const Icon(Icons.arrow_back),
            onPressed: _back,
          ),
        ),
        body: Column(
          children: [
            // Per-step header — the step's accent rule changes as you
            // advance so each step feels like entering a distinct zone,
            // not just scrolling to a new section of the same neutral
            // form.
            //
            // The band is a surface tier and the step hue is the rule
            // along its bottom edge rather than its fill. As a fill the
            // tint had two problems on the dark ground: the first step's
            // blush at 45% put EditorialHeader's 10pt eyebrow at ~1.8:1,
            // and the last step's rose at 8% composited to *below* the
            // surrounding ground — so the very cue this band exists to
            // give disappeared on the most serious step of the form.
            AnimatedContainer(
              duration: const Duration(milliseconds: 300),
              curve: Curves.easeOut,
              decoration: BoxDecoration(
                color: AppColors.surface,
                border: Border(
                  bottom: BorderSide(color: _stepTint(_currentStep), width: 3),
                ),
              ),
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
              child: Column(
                children: [
                  EditorialHeader(
                    eyebrow: _stepEyebrow(_currentStep),
                    title: 'Create Account',
                    padding: const EdgeInsets.fromLTRB(0, 0, 0, 18),
                  ),
                  _StepProgress(
                    steps: _steps,
                    current: _step,
                    onTapStep: (i) => i < _step ? _goTo(i) : null,
                  ),
                  const SizedBox(height: 4),
                ],
              ),
            ),
            Expanded(
              // Just the PageView. This was wrapped in an AnimatedSwitcher keyed
              // on `_step` ("a brief opacity transition over the whole area"),
              // and that wrapper was a bug rather than a flourish: changing the
              // key rebuilt the PageView while the outgoing copy was still
              // fading, so for 200ms two PageViews existed and both built the
              // same `Form(key: _formKeys[id])` widgets — one set of GlobalKeys,
              // two subtrees claiming them. The framework resolved that by
              // reparenting the elements back into the outgoing subtree, which
              // left the incoming PageView without its children: the step band
              // advanced while the form content stayed behind, permanently, from
              // the first Continue onwards. Found by a test that walks the form;
              // nothing previously did.
              //
              // The PageView stays for _KeepAlive to work (off-screen pages stay
              // mounted so entered text and error states survive Back), and the
              // motion the switcher was standing in for is the PageView's own:
              // `_goTo` animates to the page, which slides the content the way
              // the switcher used to fade it.
              child: PageView(
                controller: _pageController,
                physics: const NeverScrollableScrollPhysics(),
                children: [
                  for (final id in _steps)
                    _KeepAlive(
                      child: Form(
                        key: _formKeys[id],
                        child: ListView(
                          padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
                          children: _fieldsFor(id, theme),
                        ),
                      ),
                    ),
                ],
              ),
            ),
            _buildStepControls(theme),
          ],
        ),
      ),
    );
  }

  // Per-step accent — the rule along the bottom of the step band, so
  // entering Address reads as subtly different from entering Personal.
  // Five steps, five brand tokens, cycled rather than any new colour
  // introduced. Routed through ruleOnGround because a rule and a fill need
  // opposite treatment per mode: these hues are audible on a dark band and
  // invisible on a white one until they're darkened.
  //
  // Keyed by step identity rather than by position, like everything else here:
  // the Courier's extra Vehicle step takes its colour wherever it falls,
  // instead of shifting every colour after it.
  Color _stepTint(_StepId id) => AppColors.ruleOnGround(switch (id) {
    _StepId.personal => AppColors.secondary,
    _StepId.credentials => AppColors.accent,
    _StepId.address => AppColors.tertiary,
    _StepId.vehicle => AppColors.primary,
    _StepId.documents => AppColors.primaryDark,
  });

  // Contextual eyebrow copy — tells the user exactly what this step is
  // for rather than repeating "JOIN ZEFANYA" on every page.
  //
  // The numeral comes from the step's *position*, not from the caption, because
  // the position is not fixed: this list is four steps for a Buyer and five for
  // a Courier, and the digits used to be typed into each caption by hand.
  // Inserting the credentials step is precisely what that would have broken —
  // every digit after it would still have rendered, just wrong. The last step
  // says "LAST STEP" instead of a number: on the step where someone is about to
  // hand over documents, "nothing comes after this" is worth more than the
  // count.
  String _stepEyebrow(_StepId id) {
    if (id == _StepId.documents) return 'LAST STEP · VERIFICATION';
    return 'STEP ${_steps.indexOf(id) + 1} · ${_stepCaption(id)}';
  }

  /// What each step is for, without its number — see [_stepEyebrow].
  String _stepCaption(_StepId id) => switch (id) {
    _StepId.personal => 'ABOUT YOU',
    _StepId.credentials => 'SET A PASSWORD',
    _StepId.address => 'WHERE YOU ARE',
    _StepId.vehicle => 'YOUR VEHICLE',
    // Rendered by _stepEyebrow above; listed so this switch stays
    // exhaustive and a new step cannot be added without its caption being
    // decided.
    _StepId.documents => 'VERIFICATION',
  };

  List<Widget> _fieldsFor(_StepId id, ThemeData theme) {
    switch (id) {
      case _StepId.personal:
        return _personalFields(theme);
      case _StepId.credentials:
        return _credentialFields(theme);
      case _StepId.address:
        return _addressFields(theme);
      case _StepId.vehicle:
        return _vehicleFields(theme);
      case _StepId.documents:
        return _documentFields(theme);
    }
  }

  Widget _buildStepControls(ThemeData theme) {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 14, 20, 20),
      decoration: BoxDecoration(
        // A hairline rather than a shadow — the bar is a floor for the
        // scrolling step, not a card sitting on top of it.
        border: Border(top: BorderSide(color: AppColors.divider)),
      ),
      child: Row(
        children: [
          if (_step > 0) ...[
            Expanded(
              child: OutlinedButton(
                onPressed: _isSubmitting ? null : _back,
                child: const Text('Back'),
              ),
            ),
            const SizedBox(width: 12),
          ],
          Expanded(
            flex: 2,
            child: ElevatedButton(
              onPressed: _isSubmitting ? null : _next,
              child: _isSubmitting
                  ? ZefanyaLoader(
                      size: 18,
                      color: AppColors.textOnDark,
                      strokeWidth: 4,
                    )
                  : Text(_isLastStep ? 'Submit Registration' : 'Continue'),
            ),
          ),
        ],
      ),
    );
  }

  // ---- Steps -----------------------------------------------------------

  List<Widget> _personalFields(ThemeData theme) {
    final dobMissing = _attempted.contains(_StepId.personal) && _dob == null;

    return [
      _buildRoleToggle(theme),
      const SizedBox(height: 24),
      _sectionTitle('Personal Information'),
      const SizedBox(height: 12),
      _labeledField(
        theme,
        'Last Name',
        TextFormField(
          controller: _lastNameController,
          focusNode: _lastNameFocus,
          keyboardType: TextInputType.name,
          autofillHints: const [AutofillHints.familyName],
          textInputAction: TextInputAction.next,
          onFieldSubmitted: (_) => _firstNameFocus.requestFocus(),
          decoration: const InputDecoration(hintText: 'Dela Cruz'),
          validator: (v) => validatePersonName(v, label: 'Last name'),
        ),
      ),
      const SizedBox(height: 16),
      _labeledField(
        theme,
        'First Name',
        TextFormField(
          controller: _firstNameController,
          focusNode: _firstNameFocus,
          keyboardType: TextInputType.name,
          autofillHints: const [AutofillHints.givenName],
          textInputAction: TextInputAction.next,
          onFieldSubmitted: (_) => _middleInitialFocus.requestFocus(),
          decoration: const InputDecoration(hintText: 'Juan'),
          validator: (v) => validatePersonName(v, label: 'First name'),
        ),
      ),
      const SizedBox(height: 16),
      _labeledField(
        theme,
        'Middle Initial',
        TextFormField(
          controller: _middleInitialController,
          focusNode: _middleInitialFocus,
          keyboardType: TextInputType.name,
          textInputAction: TextInputAction.next,
          onFieldSubmitted: (_) => FocusScope.of(context).nextFocus(),
          maxLength: 2,
          decoration: const InputDecoration(
            hintText: 'D  (optional)',
            counterText: '',
          ),
        ),
      ),
      const SizedBox(height: 16),
      _labeledField(
        theme,
        'Sex',
        DropdownButtonFormField<String>(
          initialValue: _sex,
          // isExpanded on every dropdown in this file. Without it the button's
          // internal Row is as wide as its *hint*, so a long hint ('Select
          // municipality') or a large text scale pushes the arrow off the field
          // and overflows it. Found by a test that walks the whole form — the
          // address step had never been rendered before, because of the stepping
          // bug the AnimatedSwitcher wrapper was causing.
          isExpanded: true,
          decoration: const InputDecoration(hintText: 'Select sex'),
          items: const [
            DropdownMenuItem(value: 'Male', child: Text('Male')),
            DropdownMenuItem(value: 'Female', child: Text('Female')),
          ],
          onChanged: (v) => setState(() => _sex = v),
          validator: (v) => v == null ? 'Select sex' : null,
        ),
      ),
      const SizedBox(height: 16),
      _labeledField(
        theme,
        'Email',
        TextFormField(
          controller: _emailController,
          keyboardType: TextInputType.emailAddress,
          autofillHints: const [AutofillHints.email],
          textInputAction: TextInputAction.next,
          onFieldSubmitted: (_) => _phoneFocus.requestFocus(),
          decoration: const InputDecoration(hintText: 'you@example.com'),
          validator: (v) {
            final value = v?.trim() ?? '';
            if (value.isEmpty) return 'Email is required';
            if (!_emailRegex.hasMatch(value))
              return 'Enter a valid email address';
            return null;
          },
        ),
      ),
      const SizedBox(height: 16),
      _labeledField(
        theme,
        'Phone Number',
        TextFormField(
          controller: _phoneController,
          focusNode: _phoneFocus,
          keyboardType: TextInputType.phone,
          autofillHints: const [AutofillHints.telephoneNumber],
          textInputAction: TextInputAction.next,
          onFieldSubmitted: (_) => _lastNameFocus.requestFocus(),
          decoration: const InputDecoration(hintText: '09XXXXXXXXX'),
          validator: (v) {
            final value = v?.trim().replaceAll(RegExp(r'[\s-]'), '') ?? '';
            if (value.isEmpty) return 'Phone number is required';
            if (!_phPhoneRegex.hasMatch(value)) {
              return 'Enter a valid PH number (09XXXXXXXXX)';
            }
            return null;
          },
        ),
      ),
      const SizedBox(height: 16),
      _labeledField(
        theme,
        'Date of Birth',
        _buildDobPicker(theme, showError: dobMissing, errorText: _dobError),
      ),
    ];
  }

  // The password step, as its own step rather than two more fields on
  // Personal — the same reason the rewrite exists at all: Personal already
  // holds seven fields, and "what's wrong?" stays answerable only while each
  // step's errors are about that step's subject.
  //
  // Both rules are the server's (`StoreBuyerRegistrationRequest`), mirrored
  // here so a 7-character password is refused by the field rather than by a 422
  // after the whole form has been filled in. The server remains the authority:
  // this is a copy for the person's benefit, not a validation the app is
  // entitled to trust.
  List<Widget> _credentialFields(ThemeData theme) {
    return [
      _sectionTitle('Account Password'),
      const SizedBox(height: 12),
      _labeledField(
        theme,
        'Password',
        TextFormField(
          controller: _passwordController,
          focusNode: _passwordFocus,
          autofillHints: const [AutofillHints.newPassword],
          textInputAction: TextInputAction.next,
          onFieldSubmitted: (_) => _confirmPasswordFocus.requestFocus(),
          obscureText: _obscurePassword,
          onChanged: (_) => setState(() {}),
          decoration: InputDecoration(
            hintText: 'At least $kMinPasswordLength characters',
            prefixIcon: const Icon(Icons.lock_outline),
            suffixIcon: IconButton(
              tooltip: _obscurePassword ? 'Show password' : 'Hide password',
              icon: Icon(
                _obscurePassword
                    ? Icons.visibility_outlined
                    : Icons.visibility_off_outlined,
              ),
              onPressed: () =>
                  setState(() => _obscurePassword = !_obscurePassword),
            ),
          ),
          validator: validatePassword,
        ),
      ),
      if (_passwordController.text.isNotEmpty)
        _passwordStrengthHint(theme, _passwordController.text),
      const SizedBox(height: 16),
      _labeledField(
        theme,
        'Confirm Password',
        TextFormField(
          controller: _confirmPasswordController,
          focusNode: _confirmPasswordFocus,
          autofillHints: const [AutofillHints.newPassword],
          textInputAction: TextInputAction.next,
          onFieldSubmitted: (_) => _idNumberFocus.requestFocus(),
          obscureText: _obscureConfirmation,
          decoration: InputDecoration(
            hintText: 'Type it again',
            prefixIcon: const Icon(Icons.lock_outline),
            suffixIcon: IconButton(
              tooltip: _obscureConfirmation ? 'Show password' : 'Hide password',
              icon: Icon(
                _obscureConfirmation
                    ? Icons.visibility_outlined
                    : Icons.visibility_off_outlined,
              ),
              onPressed: () =>
                  setState(() => _obscureConfirmation = !_obscureConfirmation),
            ),
          ),
          // Compared against the other controller rather than a stored copy: the
          // form is already holding the password, and a second copy in this
          // State would be a second thing that can fall out of step with it.
          validator: (v) {
            if (v == null || v.isEmpty) return 'Confirm your password';
            if (v != _passwordController.text) return "Passwords don't match";
            return null;
          },
        ),
      ),
      const SizedBox(height: 8),
      CheckboxListTile(
        key: const ValueKey('registration-terms'),
        value: _acceptedTerms,
        onChanged: (value) => setState(() => _acceptedTerms = value ?? false),
        controlAffinity: ListTileControlAffinity.leading,
        contentPadding: EdgeInsets.zero,
        title: Text(
          'I accept the Terms and Privacy Policy',
          style: theme.textTheme.bodySmall,
        ),
      ),
      if (_attempted.contains(_StepId.credentials) && !_acceptedTerms)
        _fieldError(
          theme,
          'Please accept the Terms and Privacy Policy to continue.',
        ),
    ];
  }

  /// Inline password-strength hint under the password field.
  ///
  /// A hint, never a gate: the validator above stays the authority.
  /// Caller hides this while the field is empty, so nothing announces
  /// "Weak" before the person has typed anything.
  Widget _passwordStrengthHint(ThemeData theme, String password) {
    final strength = scorePassword(password);
    final label = passwordStrengthLabel(strength)!;
    final color = switch (strength) {
      PasswordStrength.weak => AppColors.danger,
      PasswordStrength.fair => AppColors.warning,
      PasswordStrength.strong => AppColors.successText,
    };
    final progress = switch (strength) {
      PasswordStrength.weak => 0.33,
      PasswordStrength.fair => 0.66,
      PasswordStrength.strong => 1.0,
    };
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Row(
        children: [
          Expanded(
            child: ClipRRect(
              borderRadius: radiusOf(ShapeTier.pill),
              child: LinearProgressIndicator(
                value: progress,
                minHeight: 4,
                backgroundColor: AppColors.divider,
                valueColor: AlwaysStoppedAnimation<Color>(color),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Text(
            'Password strength: $label',
            style: theme.textTheme.labelSmall?.copyWith(
              color: color,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }

  List<Widget> _addressFields(ThemeData theme) {
    return [
      _sectionTitle('Address'),
      const SizedBox(height: 12),
      _buildAddressDropdowns(theme),
      const SizedBox(height: 16),
      _labeledField(
        theme,
        'Street / Detailed Address',
        TextFormField(
          controller: _streetController,
          focusNode: _streetFocus,
          keyboardType: TextInputType.streetAddress,
          autofillHints: const [AutofillHints.streetAddressLine1],
          textInputAction: TextInputAction.next,
          onFieldSubmitted: (_) => _plateFocus.requestFocus(),
          maxLines: 2,
          decoration: const InputDecoration(
            hintText: 'House no., street, subdivision',
          ),
          validator: (v) {
            final value = v?.trim() ?? '';
            if (value.isEmpty) return 'Street address is required';
            if (value.length < 5) return 'Enter a more complete address';
            return null;
          },
        ),
      ),
    ];
  }

  List<Widget> _vehicleFields(ThemeData theme) {
    return [
      _sectionTitle('Rider & Vehicle'),
      const SizedBox(height: 12),
      _labeledField(
        theme,
        'Company',
        TextFormField(
          controller: _companyController,
          textCapitalization: TextCapitalization.words,
          textInputAction: TextInputAction.next,
          decoration: const InputDecoration(
            hintText: 'e.g. Bilis Hatid Express Inc.',
          ),
          validator: (v) {
            final value = v?.trim() ?? '';
            if (value.isEmpty) return 'Company name is required';
            return null;
          },
        ),
      ),
      const SizedBox(height: 16),
      _labeledField(
        theme,
        'Vehicle Type',
        DropdownButtonFormField<String>(
          initialValue: _vehicleType,
          isExpanded: true,
          decoration: const InputDecoration(hintText: 'Select vehicle type'),
          items: courierVehicleTypes
              .map((v) => DropdownMenuItem(value: v, child: Text(v)))
              .toList(),
          onChanged: (v) => setState(() => _vehicleType = v),
          validator: (v) => v == null ? 'Select a vehicle type' : null,
        ),
      ),
      const SizedBox(height: 16),
      _labeledField(
        theme,
        'Plate Number',
        TextFormField(
          controller: _plateController,
          focusNode: _plateFocus,
          textInputAction: TextInputAction.next,
          onFieldSubmitted: (_) => FocusScope.of(context).unfocus(),
          textCapitalization: TextCapitalization.characters,
          decoration: const InputDecoration(hintText: 'ABC 1234'),
          validator: (v) {
            final problem = validatePlateNumber(v);
            if (problem != null) return problem;
            if (isPlateRegisteredElsewhere(v, currentPlate: null)) {
              return 'That plate number is already registered.';
            }
            return null;
          },
        ),
      ),
      const SizedBox(height: 16),
      _labeledField(
        theme,
        'Licence Number',
        TextFormField(
          controller: _licenseNumberController,
          textCapitalization: TextCapitalization.characters,
          textInputAction: TextInputAction.done,
          decoration: const InputDecoration(hintText: 'N01-23-456789'),
          validator: validateLicenseNumber,
        ),
      ),
      const SizedBox(height: 16),
      _labeledField(
        theme,
        'Licence Expiry',
        _buildLicenseExpiryPicker(theme),
      ),
    ];
  }

  Widget _buildLicenseExpiryPicker(ThemeData theme) {
    final ext = theme.extension<AppThemeExtension>()!;
    final attempted = _attempted.contains(_StepId.vehicle);
    final showError = (attempted && _licenseExpiry == null) ||
        _licenseExpiryError != null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        InkWell(
          key: const ValueKey('license-expiry-picker'),
          onTap: _pickLicenseExpiry,
          borderRadius: BorderRadius.circular(ext.utilityRadius),
          child: InputDecorator(
            decoration: InputDecoration(
              prefixIcon: const Icon(Icons.badge_outlined),
              enabledBorder: showError
                  ? OutlineInputBorder(
                      borderRadius: BorderRadius.circular(ext.utilityRadius),
                      borderSide: BorderSide(color: AppColors.danger),
                    )
                  : null,
            ),
            child: Text(
              _licenseExpiry == null
                  ? 'Select licence expiry'
                  : '${_licenseExpiry!.month.toString().padLeft(2, '0')}/'
                        '${_licenseExpiry!.day.toString().padLeft(2, '0')}/'
                        '${_licenseExpiry!.year}',
              style: theme.textTheme.bodyMedium?.copyWith(
                color: _licenseExpiry == null
                    ? AppColors.textSecondary
                    : AppColors.textPrimary,
              ),
            ),
          ),
        ),
        if (showError)
          Padding(
            padding: const EdgeInsets.only(top: 6, left: 12),
            child: Text(
              _licenseExpiryError ?? 'Licence expiry is required',
              style: theme.textTheme.bodySmall?.copyWith(
                color: AppColors.danger,
              ),
            ),
          ),
      ],
    );
  }

  List<Widget> _documentFields(ThemeData theme) {
    final attempted = _attempted.contains(_StepId.documents);

    return [
      _sectionTitle('Document Verification'),
      const SizedBox(height: 12),
      if (_role == UserRole.buyer)
        _uploadBox(
          theme,
          label: 'Government ID',
          file: _govIdFile,
          errorText: attempted && _govIdFile == null
              ? 'A photo of your government ID is required'
              : null,
          onTap: () async {
            final picked = await pickAndSaveImage(
              context,
              filenamePrefix: 'gov_id',
            );
            if (picked != null) setState(() => _govIdFile = picked);
          },
        )
      else ...[
        _uploadBox(
          theme,
          label: "Driver's License",
          file: _licenseFile,
          errorText: attempted && _licenseFile == null
              ? "Your driver's license is required"
              : null,
          onTap: () async {
            final picked = await pickAndSaveImage(
              context,
              filenamePrefix: 'license',
            );
            if (picked != null && _riderDocumentOk(picked)) {
              setState(() => _licenseFile = picked);
            }
          },
        ),
        const SizedBox(height: 16),
        _uploadBox(
          theme,
          label: 'OR/CR Document',
          file: _orCrFile,
          errorText: attempted && _orCrFile == null
              ? 'Your OR/CR document is required'
              : null,
          onTap: () async {
            final picked = await pickAndSaveImage(
              context,
              filenamePrefix: 'or_cr',
            );
            if (picked != null && _riderDocumentOk(picked)) {
              setState(() => _orCrFile = picked);
            }
          },
        ),
      ],
    ];
  }

  /// Rejects a rider document that the server would reject too, and keeps the
  /// previous file on screen. The picker copies the file into app storage
  /// first, which is why the check reads the saved path rather than the
  /// original camera/gallery temp file.
  bool _riderDocumentOk(File picked) {
    final problem = validateRiderDocumentPath(picked.path);
    if (problem == null) return true;
    showTimedSnackBar(context, SnackBar(content: Text(problem)));
    return false;
  }

  // ---------------------------------------------------------------------

  // Section titles are the SMALL end of the type contrast on purpose.
  // This screen already has one big element (the EditorialHeader), and
  // the rule is one display-scale item per screen — three 34pt section
  // headings inside a form would just be the old "everything is the
  // same weight" problem at a larger size. Eyebrow + a hairline rule
  // gives the sections real separation without competing.
  Widget _sectionTitle(String title) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Text(title.toUpperCase(), style: AppType.eyebrow),
        const SizedBox(width: 12),
        Expanded(
          child: Divider(color: AppColors.divider, thickness: 1, height: 1),
        ),
      ],
    );
  }

  Widget _labeledField(ThemeData theme, String label, Widget field) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.labelLarge),
        const SizedBox(height: 8),
        field,
      ],
    );
  }

  Widget _buildRoleToggle(ThemeData theme) {
    return Row(
      children: [
        Expanded(
          child: _roleTile(
            theme,
            UserRole.buyer,
            'Buyer',
            Icons.storefront_outlined,
            0,
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: _roleTile(
            theme,
            UserRole.courier,
            'Courier',
            Icons.local_shipping_outlined,
            1,
          ),
        ),
      ],
    );
  }

  // [index] alternates the asymmetric radius so the two tiles lean
  // opposite ways — a pair of identical shapes side by side is just a
  // uniform pattern again, which is the thing editorialRadius exists
  // to avoid.
  //
  // This lives on step one deliberately: switching role changes how
  // many steps there are, and the only position where that's harmless
  // is the one before any of the role-specific steps exist.
  Widget _roleTile(
    ThemeData theme,
    UserRole role,
    String label,
    IconData icon,
    int index,
  ) {
    final selected = _role == role;
    final radius = editorialRadius(index, ShapeTier.accent);
    return Semantics(
      button: true,
      selected: selected,
      label: selected ? '$label, selected' : label,
      child: InkWell(
        onTap: () => setState(() => _role = role),
        borderRadius: radius,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 180),
          curve: Curves.easeOut,
          padding: const EdgeInsets.symmetric(vertical: 18),
          decoration: BoxDecoration(
            color: selected ? AppColors.primaryDark : AppColors.surfaceMuted,
            borderRadius: radius,
            border: Border.all(
              color: selected ? AppColors.primaryDark : AppColors.divider,
            ),
          ),
          child: Column(
            children: [
              Icon(
                icon,
                color: selected ? AppColors.textOnDark : AppColors.textPrimary,
              ),
              const SizedBox(height: 6),
              Text(
                label,
                style: theme.textTheme.titleSmall?.copyWith(
                  color: selected
                      ? AppColors.textOnDark
                      : AppColors.textPrimary,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildDobPicker(
    ThemeData theme, {
    required bool showError,
    String? errorText,
  }) {
    final ext = theme.extension<AppThemeExtension>()!;
    final hasError = showError || errorText != null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        InkWell(
          onTap: _pickDob,
          borderRadius: BorderRadius.circular(ext.utilityRadius),
          child: InputDecorator(
            decoration: InputDecoration(
              prefixIcon: const Icon(Icons.cake_outlined),
              // Matches how a failed TextFormField looks, so a missing
              // date reads as the same kind of problem as a missing name
              // rather than as a different species of error.
              enabledBorder: hasError
                  ? OutlineInputBorder(
                      borderRadius: BorderRadius.circular(ext.utilityRadius),
                      borderSide: BorderSide(color: AppColors.danger),
                    )
                  : null,
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                // Flexible for the same reason the pending screen's status badge
                // is: this row holds a date and an age pill side by side, and
                // only once a date exists does the pill appear beside it. At a
                // large text scale the pair does not fit, and the date is the
                // half that has to give. Found by a test that drives the picker
                // — nothing had rendered this state before.
                Flexible(
                  child: Text(
                    _dob == null
                        ? 'Select date of birth'
                        : '${_dob!.month.toString().padLeft(2, '0')}/${_dob!.day.toString().padLeft(2, '0')}/${_dob!.year}',
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: _dob == null
                          ? AppColors.textSecondary
                          : AppColors.textPrimary,
                    ),
                  ),
                ),
                if (_age != null)
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 10,
                      vertical: 4,
                    ),
                    decoration: BoxDecoration(
                      color: AppColors.surfaceMuted,
                      borderRadius: radiusOf(ShapeTier.pill),
                    ),
                    child: Text(
                      '$_age yrs old',
                      style: theme.textTheme.labelSmall,
                    ),
                  ),
              ],
            ),
          ),
        ),
        if (showError)
          _fieldError(theme, 'Date of birth is required')
        else if (errorText != null)
          _fieldError(theme, errorText),
      ],
    );
  }

  Widget _fieldError(ThemeData theme, String message) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 8, 0, 0),
      child: Text(
        message,
        style: theme.textTheme.bodySmall?.copyWith(color: AppColors.danger),
      ),
    );
  }

  Widget _buildAddressDropdowns(ThemeData theme) {
    final municipalities = _province == null
        ? <String>[]
        : phAddressData[_province]!.keys.toList();
    final barangays = (_province == null || _municipality == null)
        ? <String>[]
        : phAddressData[_province]![_municipality]!;

    return Column(
      children: [
        _labeledField(
          theme,
          'Province',
          DropdownButtonFormField<String>(
            initialValue: _province,
            isExpanded: true,
            decoration: const InputDecoration(hintText: 'Select province'),
            items: phAddressData.keys
                .map((p) => DropdownMenuItem(value: p, child: Text(p)))
                .toList(),
            onChanged: (v) => setState(() {
              _province = v;
              _municipality = null;
              _barangay = null;
            }),
            validator: (v) => v == null ? 'Select a province' : null,
          ),
        ),
        const SizedBox(height: 16),
        _labeledField(
          theme,
          'Municipality / City',
          DropdownButtonFormField<String>(
            initialValue: _municipality,
            isExpanded: true,
            decoration: const InputDecoration(hintText: 'Select municipality'),
            items: municipalities
                .map((m) => DropdownMenuItem(value: m, child: Text(m)))
                .toList(),
            onChanged: _province == null
                ? null
                : (v) => setState(() {
                    _municipality = v;
                    _barangay = null;
                  }),
            validator: (v) => v == null ? 'Select a municipality' : null,
          ),
        ),
        const SizedBox(height: 16),
        _labeledField(
          theme,
          'Barangay',
          DropdownButtonFormField<String>(
            initialValue: _barangay,
            isExpanded: true,
            decoration: const InputDecoration(hintText: 'Select barangay'),
            items: barangays
                .map((b) => DropdownMenuItem(value: b, child: Text(b)))
                .toList(),
            onChanged: _municipality == null
                ? null
                : (v) => setState(() => _barangay = v),
            validator: (v) => v == null ? 'Select a barangay' : null,
          ),
        ),
      ],
    );
  }

  Widget _uploadBox(
    ThemeData theme, {
    required String label,
    required File? file,
    required VoidCallback onTap,
    String? errorText,
  }) {
    final uploaded = file != null;
    final hasError = errorText != null;
    // Asymmetric corners, same as every other content surface in the
    // app. A slightly smaller "big" corner than the default 34 so a
    // stack of three of these (courier flow) doesn't read as scalloped.
    final radius = editorialRadius(0, ShapeTier.accent);

    final Color borderColor = hasError
        ? AppColors.danger
        : uploaded
        ? AppColors.tertiary
        : AppColors.divider;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        InkWell(
          onTap: onTap,
          borderRadius: radius,
          child: Container(
            width: double.infinity,
            clipBehavior: Clip.antiAlias,
            decoration: BoxDecoration(
              color: uploaded
                  ? AppColors.tertiary.withValues(alpha: 0.14)
                  : AppColors.surfaceMuted,
              borderRadius: radius,
              border: Border.all(color: borderColor),
            ),
            child: uploaded
                ? Stack(
                    children: [
                      Image.file(
                        file,
                        width: double.infinity,
                        height: 140,
                        fit: BoxFit.cover,
                      ),
                      Positioned.fill(
                        child: Container(
                          alignment: Alignment.bottomCenter,
                          padding: const EdgeInsets.symmetric(vertical: 8),
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              begin: Alignment.topCenter,
                              end: Alignment.bottomCenter,
                              colors: [
                                Colors.transparent,
                                Colors.black.withValues(alpha: 0.55),
                              ],
                            ),
                          ),
                          child: Text(
                            'Tap to retake — $label',
                            style: theme.textTheme.labelSmall?.copyWith(
                              color: AppColors.textOnDark,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      ),
                    ],
                  )
                : Padding(
                    padding: const EdgeInsets.all(18),
                    child: Column(
                      children: [
                        Icon(
                          Icons.cloud_upload_outlined,
                          color: hasError
                              ? AppColors.danger
                              : AppColors.textSecondary,
                          size: 28,
                        ),
                        const SizedBox(height: 8),
                        Text(
                          'Tap to upload $label',
                          style: theme.textTheme.bodySmall,
                          textAlign: TextAlign.center,
                        ),
                      ],
                    ),
                  ),
          ),
        ),
        if (hasError) _fieldError(theme, errorText),
      ],
    );
  }
}

/// The step indicator.
///
/// Same visual grammar as OrdersStatusScreen's _OrderStageStepper —
/// filled dots, a connecting rule, a check for anything completed —
/// because registration is the second genuinely sequential thing in
/// this app and inventing a third "this is a sequence" device would be
/// its own small inconsistency. Two differences, both forced by the
/// context: the step count is dynamic (Buyer has three, Courier four),
/// and forward taps are refused, since jumping ahead would skip the
/// validation that makes the whole rewrite worth doing.
class _StepProgress extends StatelessWidget {
  const _StepProgress({
    required this.steps,
    required this.current,
    required this.onTapStep,
  });

  final List<_StepId> steps;
  final int current;
  final void Function(int index) onTapStep;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (var i = 0; i < steps.length; i++) ...[
          if (i > 0)
            Expanded(
              child: Padding(
                padding: const EdgeInsets.only(bottom: 22),
                child: Container(
                  height: 2,
                  color: i <= current
                      ? AppColors.primaryDark
                      : AppColors.divider,
                ),
              ),
            ),
          _StepNode(
            index: i,
            label: steps[i].label,
            isPast: i < current,
            isCurrent: i == current,
            onTap: () => onTapStep(i),
          ),
        ],
      ],
    );
  }
}

class _StepNode extends StatelessWidget {
  const _StepNode({
    required this.index,
    required this.label,
    required this.isPast,
    required this.isCurrent,
    required this.onTap,
  });

  final int index;
  final String label;
  final bool isPast;
  final bool isCurrent;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final filled = isPast || isCurrent;
    final stepLabel = isPast
        ? '$label, completed'
        : isCurrent
        ? '$label, current step'
        : label;
    // Forward taps are refused by _StepProgress.onTapStep — only past
    // steps are navigable. The semantic label tells screen readers which
    // steps are interactive so they don't announce a tappable button for
    // a future step.
    return Semantics(
      button: isPast,
      label: stepLabel,
      onTap: isPast ? onTap : null,
      excludeSemantics: true,
      child: GestureDetector(
        onTap: onTap,
        behavior: HitTestBehavior.opaque,
        child: Column(
          children: [
            AnimatedContainer(
              duration: const Duration(milliseconds: 200),
              width: isCurrent ? 30 : 22,
              height: isCurrent ? 30 : 22,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: filled ? AppColors.primaryDark : Colors.transparent,
                border: Border.all(
                  color: AppColors.primaryDark,
                  width: filled ? 0 : 1.5,
                ),
              ),
              alignment: Alignment.center,
              child: isPast
                  ? Icon(Icons.check, size: 13, color: AppColors.textOnDark)
                  : Text(
                      '${index + 1}',
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: filled
                            ? AppColors.textOnDark
                            : AppColors.primaryDark,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
            ),
            const SizedBox(height: 6),
            SizedBox(
              width: 66,
              child: Text(
                label,
                textAlign: TextAlign.center,
                style: theme.textTheme.labelSmall?.copyWith(
                  color: isCurrent
                      ? AppColors.textPrimary
                      : AppColors.textSecondary,
                  fontWeight: isCurrent ? FontWeight.w700 : FontWeight.w500,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Keeps an off-screen PageView child mounted.
///
/// Without this, stepping forward disposes the previous page's
/// FormState. The typed values survive (they live on the screen's
/// State, not in the widgets), but every error message already
/// displayed there is lost, so going Back would show a form that looks
/// clean and then fails again on Continue. That's the buried-error
/// problem returning by a side door.
class _KeepAlive extends StatefulWidget {
  const _KeepAlive({required this.child});

  final Widget child;

  @override
  State<_KeepAlive> createState() => _KeepAliveState();
}

class _KeepAliveState extends State<_KeepAlive>
    with AutomaticKeepAliveClientMixin {
  @override
  bool get wantKeepAlive => true;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    super.build(context);
    return widget.child;
  }
}
