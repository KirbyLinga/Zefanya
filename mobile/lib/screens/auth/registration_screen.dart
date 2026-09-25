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
import 'package:provider/provider.dart';
import '../../data/mock_data.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/image_picker_helper.dart';
import '../../utils/motion.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/zefanya_mark.dart';

// Shared validation patterns — used by the form fields below.
final RegExp _emailRegex = RegExp(r'^[\w.+-]+@[\w-]+\.[\w.-]+$');
final RegExp _phPhoneRegex = RegExp(r'^(09\d{9}|\+639\d{9})$');
final RegExp _plateRegex = RegExp(r'^[A-Z]{2,3}[\s-]?\d{3,4}$');

/// Stable identity for a step, independent of its position. Buyer skips
/// [vehicle], so position 2 means Documents for a Buyer and Vehicle for
/// a Courier — anything keyed on the index would mix them up the moment
/// someone flips the role toggle.
enum _StepId { personal, address, vehicle, documents }

extension on _StepId {
  String get label => switch (this) {
        _StepId.personal => 'Personal',
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

  String? _sex;

  DateTime? _dob;
  int? _age;

  String? _province;
  String? _municipality;
  String? _barangay;

  String? _vehicleType;

  File? _govIdFile;
  File? _licenseFile;
  File? _orCrFile;

  bool _isSubmitting = false;

  List<_StepId> get _steps => _role == UserRole.buyer
      ? const [_StepId.personal, _StepId.address, _StepId.documents]
      : const [_StepId.personal, _StepId.address, _StepId.vehicle, _StepId.documents];

  _StepId get _currentStep => _steps[_step];
  bool get _isLastStep => _step == _steps.length - 1;

  @override
  void dispose() {
    _pageController.dispose();
    _lastNameController.dispose();
    _firstNameController.dispose();
    _middleInitialController.dispose();
    _emailController.dispose();
    _phoneController.dispose();
    _streetController.dispose();
    _plateController.dispose();
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

    var age = now.year - picked.year;
    if (now.month < picked.month || (now.month == picked.month && now.day < picked.day)) {
      age--;
    }

    setState(() {
      _dob = picked;
      _age = age;
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
      extraOk = _dob != null;
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

  void _submit() {
    // Every step has been validated on the way here, and forward motion
    // is only possible through _next(), so there's no whole-form sweep
    // left to do — and no generic "something is missing" snackbar,
    // because there is no longer a way to arrive here with something
    // missing.
    setState(() => _isSubmitting = true);

    // TODO: send registration payload (incl. document files) to the API.
    // Per the updated spec, BOTH roles now wait for review — Buyer/Seller
    // by the administrator, Courier by the Logistics/Sorting Center (see
    // PendingApprovalScreen, which picks the copy based on role).
    Future.delayed(const Duration(milliseconds: 500), () {
      if (!mounted) return;
      setState(() => _isSubmitting = false);

      final auth = context.read<AuthState>();
      final fullName = [
        _firstNameController.text.trim(),
        if (_middleInitialController.text.trim().isNotEmpty) '${_middleInitialController.text.trim()}.',
        _lastNameController.text.trim(),
      ].where((p) => p.isNotEmpty).join(' ');

      auth.submitForApproval(role: _role, name: fullName, email: _emailController.text.trim());
      auth.updateProfile(
        lastName: _lastNameController.text.trim(),
        firstName: _firstNameController.text.trim(),
        middleInitial: _middleInitialController.text.trim(),
        sex: _sex,
        phone: _phoneController.text.trim(),
        province: _province,
        municipality: _municipality,
        barangay: _barangay,
        street: _streetController.text.trim(),
      );

      // AuthGate (in main.dart) rebuilds to the right screen once the
      // state changes above — this just unwinds back to that root route.
      Navigator.of(context).popUntil((route) => route.isFirst);
    });
  }

  // ---- Build -----------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return PopScope(
      // System back walks the steps rather than throwing away a
      // part-filled form. Only the first step lets the screen pop.
      canPop: _step == 0,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _back();
      },
      child: Scaffold(
        backgroundColor: Colors.transparent, // the app-wide gradient in main.dart paints this
        appBar: AppBar(
          leading: IconButton(
            icon: const Icon(Icons.arrow_back),
            onPressed: _back,
          ),
        ),
        body: Column(
          children: [
            // Per-step tinted header — the color changes as you advance
            // so each step feels like entering a distinct zone, not just
            // scrolling to a new section of the same neutral form.
            AnimatedContainer(
              duration: const Duration(milliseconds: 300),
              curve: Curves.easeOut,
              color: _stepTint(_currentStep),
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
              // Cross-fade the form content when stepping forward/back.
              // The PageView stays for _KeepAlive to work (off-screen
              // pages stay mounted so entered text and error states
              // survive Back); the AnimatedSwitcher key change just
              // triggers a brief opacity transition over the whole area.
              child: AnimatedSwitcher(
                duration: const Duration(milliseconds: 200),
                switchInCurve: Curves.easeOut,
                switchOutCurve: Curves.easeIn,
                transitionBuilder: (child, animation) => FadeTransition(
                  opacity: animation,
                  child: child,
                ),
                child: KeyedSubtree(
                  key: ValueKey(_step),
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
              ),
            ),
            _buildStepControls(theme),
          ],
        ),
      ),
    );
  }

  // Per-step tint — a soft wash that changes as the user advances
  // so entering Address feels subtly different from entering Personal.
  // Blush → sage → neutral pink → primary pink, cycling through the
  // palette tokens without introducing any new colors.
  Color _stepTint(_StepId id) => switch (id) {
        _StepId.personal  => AppColors.secondary.withValues(alpha: 0.45),
        _StepId.address   => AppColors.tertiary.withValues(alpha: 0.30),
        _StepId.vehicle   => AppColors.primary.withValues(alpha: 0.30),
        _StepId.documents => AppColors.primaryDark.withValues(alpha: 0.08),
      };

  // Contextual eyebrow copy — tells the user exactly what this step is
  // for rather than repeating "JOIN ZEFANYA" on every page.
  String _stepEyebrow(_StepId id) => switch (id) {
        _StepId.personal  => 'STEP 1 · ABOUT YOU',
        _StepId.address   => 'STEP 2 · WHERE YOU ARE',
        _StepId.vehicle   => 'STEP 3 · YOUR VEHICLE',
        _StepId.documents => 'LAST STEP · VERIFICATION',
      };

  List<Widget> _fieldsFor(_StepId id, ThemeData theme) {
    switch (id) {
      case _StepId.personal:
        return _personalFields(theme);
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
      decoration: const BoxDecoration(
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
                  ? const ZefanyaLoader(size: 18, color: AppColors.textOnDark, strokeWidth: 4)
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
      _labeledField(theme, 'Last Name', TextFormField(
        controller: _lastNameController,
        decoration: const InputDecoration(hintText: 'Dela Cruz'),
        validator: (v) => (v == null || v.trim().length < 2) ? 'Last name is required' : null,
      )),
      const SizedBox(height: 16),
      _labeledField(theme, 'First Name', TextFormField(
        controller: _firstNameController,
        decoration: const InputDecoration(hintText: 'Juan'),
        validator: (v) => (v == null || v.trim().length < 2) ? 'First name is required' : null,
      )),
      const SizedBox(height: 16),
      _labeledField(theme, 'Middle Initial', TextFormField(
        controller: _middleInitialController,
        maxLength: 2,
        decoration: const InputDecoration(hintText: 'D  (optional)', counterText: ''),
      )),
      const SizedBox(height: 16),
      _labeledField(theme, 'Sex', DropdownButtonFormField<String>(
        initialValue: _sex,
        decoration: const InputDecoration(hintText: 'Select sex'),
        items: const [
          DropdownMenuItem(value: 'Male', child: Text('Male')),
          DropdownMenuItem(value: 'Female', child: Text('Female')),
        ],
        onChanged: (v) => setState(() => _sex = v),
        validator: (v) => v == null ? 'Select sex' : null,
      )),
      const SizedBox(height: 16),
      _labeledField(theme, 'Email', TextFormField(
        controller: _emailController,
        keyboardType: TextInputType.emailAddress,
        decoration: const InputDecoration(hintText: 'you@example.com'),
        validator: (v) {
          final value = v?.trim() ?? '';
          if (value.isEmpty) return 'Email is required';
          if (!_emailRegex.hasMatch(value)) return 'Enter a valid email address';
          return null;
        },
      )),
      const SizedBox(height: 16),
      _labeledField(theme, 'Phone Number', TextFormField(
        controller: _phoneController,
        keyboardType: TextInputType.phone,
        decoration: const InputDecoration(hintText: '09XXXXXXXXX'),
        validator: (v) {
          final value = v?.trim().replaceAll(RegExp(r'[\s-]'), '') ?? '';
          if (value.isEmpty) return 'Phone number is required';
          if (!_phPhoneRegex.hasMatch(value)) {
            return 'Enter a valid PH number (09XXXXXXXXX)';
          }
          return null;
        },
      )),
      const SizedBox(height: 16),
      _labeledField(theme, 'Date of Birth', _buildDobPicker(theme, showError: dobMissing)),
    ];
  }

  List<Widget> _addressFields(ThemeData theme) {
    return [
      _sectionTitle('Address'),
      const SizedBox(height: 12),
      _buildAddressDropdowns(theme),
      const SizedBox(height: 16),
      _labeledField(theme, 'Street / Detailed Address', TextFormField(
        controller: _streetController,
        maxLines: 2,
        decoration: const InputDecoration(hintText: 'House no., street, subdivision'),
        validator: (v) {
          final value = v?.trim() ?? '';
          if (value.isEmpty) return 'Street address is required';
          if (value.length < 5) return 'Enter a more complete address';
          return null;
        },
      )),
    ];
  }

  List<Widget> _vehicleFields(ThemeData theme) {
    return [
      _sectionTitle('Vehicle'),
      const SizedBox(height: 12),
      _labeledField(theme, 'Vehicle Type', DropdownButtonFormField<String>(
        initialValue: _vehicleType,
        decoration: const InputDecoration(hintText: 'Select vehicle type'),
        items: courierVehicleTypes.map((v) => DropdownMenuItem(value: v, child: Text(v))).toList(),
        onChanged: (v) => setState(() => _vehicleType = v),
        validator: (v) => v == null ? 'Select a vehicle type' : null,
      )),
      const SizedBox(height: 16),
      _labeledField(theme, 'Plate Number', TextFormField(
        controller: _plateController,
        textCapitalization: TextCapitalization.characters,
        decoration: const InputDecoration(hintText: 'ABC 1234'),
        validator: (v) {
          final value = v?.trim().toUpperCase() ?? '';
          if (value.isEmpty) return 'Plate number is required';
          if (!_plateRegex.hasMatch(value)) return 'Enter a valid plate number (e.g. ABC 1234)';
          return null;
        },
      )),
    ];
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
          errorText: attempted && _govIdFile == null ? 'A photo of your government ID is required' : null,
          onTap: () async {
            final picked = await pickAndSaveImage(context, filenamePrefix: 'gov_id');
            if (picked != null) setState(() => _govIdFile = picked);
          },
        )
      else ...[
        _uploadBox(
          theme,
          label: "Driver's License",
          file: _licenseFile,
          errorText: attempted && _licenseFile == null ? "Your driver's license is required" : null,
          onTap: () async {
            final picked = await pickAndSaveImage(context, filenamePrefix: 'license');
            if (picked != null) setState(() => _licenseFile = picked);
          },
        ),
        const SizedBox(height: 16),
        _uploadBox(
          theme,
          label: 'OR/CR Document',
          file: _orCrFile,
          errorText: attempted && _orCrFile == null ? 'Your OR/CR document is required' : null,
          onTap: () async {
            final picked = await pickAndSaveImage(context, filenamePrefix: 'or_cr');
            if (picked != null) setState(() => _orCrFile = picked);
          },
        ),
      ],
    ];
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
        const Expanded(
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
          child: _roleTile(theme, UserRole.buyer, 'Buyer', Icons.storefront_outlined, 0),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: _roleTile(theme, UserRole.courier, 'Courier', Icons.local_shipping_outlined, 1),
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
  Widget _roleTile(ThemeData theme, UserRole role, String label, IconData icon, int index) {
    final selected = _role == role;
    final radius = editorialRadius(index, big: 22, small: 6);
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
              Icon(icon, color: selected ? AppColors.textOnDark : AppColors.textPrimary),
              const SizedBox(height: 6),
              Text(
                label,
                style: theme.textTheme.titleSmall?.copyWith(
                  color: selected ? AppColors.textOnDark : AppColors.textPrimary,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildDobPicker(ThemeData theme, {required bool showError}) {
    final ext = theme.extension<AppThemeExtension>()!;
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
              enabledBorder: showError
                  ? OutlineInputBorder(
                      borderRadius: BorderRadius.circular(ext.utilityRadius),
                      borderSide: const BorderSide(color: AppColors.danger),
                    )
                  : null,
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  _dob == null
                      ? 'Select date of birth'
                      : '${_dob!.month.toString().padLeft(2, '0')}/${_dob!.day.toString().padLeft(2, '0')}/${_dob!.year}',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: _dob == null ? AppColors.textSecondary : AppColors.textPrimary,
                  ),
                ),
                if (_age != null)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: AppColors.secondary,
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: Text('$_age yrs old', style: theme.textTheme.labelSmall),
                  ),
              ],
            ),
          ),
        ),
        if (showError) _fieldError(theme, 'Date of birth is required'),
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
    final municipalities = _province == null ? <String>[] : phAddressData[_province]!.keys.toList();
    final barangays = (_province == null || _municipality == null)
        ? <String>[]
        : phAddressData[_province]![_municipality]!;

    return Column(
      children: [
        _labeledField(theme, 'Province', DropdownButtonFormField<String>(
          initialValue: _province,
          decoration: const InputDecoration(hintText: 'Select province'),
          items: phAddressData.keys.map((p) => DropdownMenuItem(value: p, child: Text(p))).toList(),
          onChanged: (v) => setState(() {
            _province = v;
            _municipality = null;
            _barangay = null;
          }),
          validator: (v) => v == null ? 'Select a province' : null,
        )),
        const SizedBox(height: 16),
        _labeledField(theme, 'Municipality / City', DropdownButtonFormField<String>(
          initialValue: _municipality,
          decoration: const InputDecoration(hintText: 'Select municipality'),
          items: municipalities.map((m) => DropdownMenuItem(value: m, child: Text(m))).toList(),
          onChanged: _province == null
              ? null
              : (v) => setState(() {
                    _municipality = v;
                    _barangay = null;
                  }),
          validator: (v) => v == null ? 'Select a municipality' : null,
        )),
        const SizedBox(height: 16),
        _labeledField(theme, 'Barangay', DropdownButtonFormField<String>(
          initialValue: _barangay,
          decoration: const InputDecoration(hintText: 'Select barangay'),
          items: barangays.map((b) => DropdownMenuItem(value: b, child: Text(b))).toList(),
          onChanged: _municipality == null ? null : (v) => setState(() => _barangay = v),
          validator: (v) => v == null ? 'Select a barangay' : null,
        )),
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
    final radius = editorialRadius(0, big: 24, small: 6);

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
              color: uploaded ? AppColors.tertiary.withValues(alpha: 0.14) : AppColors.surfaceMuted,
              borderRadius: radius,
              border: Border.all(color: borderColor),
            ),
            child: uploaded
                ? Stack(
                    children: [
                      Image.file(file, width: double.infinity, height: 140, fit: BoxFit.cover),
                      Positioned.fill(
                        child: Container(
                          alignment: Alignment.bottomCenter,
                          padding: const EdgeInsets.symmetric(vertical: 8),
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              begin: Alignment.topCenter,
                              end: Alignment.bottomCenter,
                              colors: [Colors.transparent, Colors.black.withValues(alpha: 0.55)],
                            ),
                          ),
                          child: Text(
                            'Tap to retake — $label',
                            style: theme.textTheme.labelSmall?.copyWith(color: AppColors.textOnDark, fontWeight: FontWeight.w600),
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
                          color: hasError ? AppColors.danger : AppColors.textSecondary,
                          size: 28,
                        ),
                        const SizedBox(height: 8),
                        Text('Tap to upload $label', style: theme.textTheme.bodySmall, textAlign: TextAlign.center),
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
                  color: i <= current ? AppColors.primaryDark : AppColors.divider,
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
    final theme = Theme.of(context);
    final filled = isPast || isCurrent;
    final stepLabel = isPast ? '$label, completed' : isCurrent ? '$label, current step' : label;
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
              border: Border.all(color: AppColors.primaryDark, width: filled ? 0 : 1.5),
            ),
            alignment: Alignment.center,
            child: isPast
                ? const Icon(Icons.check, size: 13, color: AppColors.textOnDark)
                : Text(
                    '${index + 1}',
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: filled ? AppColors.textOnDark : AppColors.primaryDark,
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
                color: isCurrent ? AppColors.textPrimary : AppColors.textSecondary,
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

class _KeepAliveState extends State<_KeepAlive> with AutomaticKeepAliveClientMixin {
  @override
  bool get wantKeepAlive => true;

  @override
  Widget build(BuildContext context) {
    super.build(context);
    return widget.child;
  }
}
