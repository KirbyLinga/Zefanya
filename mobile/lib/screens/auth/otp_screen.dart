// lib/screens/auth/otp_screen.dart
//
// The six digits from the confirmation email, entered in the app.
//
// ## Why a screen, when the server also confirms from the email
//
// `VerifyBuyerOtpController` has a `show` action that renders a standalone page
// for deep links, so a confirmation *can* happen in a browser. That is not the
// flow this app's people are in: they registered on a phone, and a large share
// of them read their mail on that same phone. The alternative to this screen was
// a pending screen saying "check your inbox" and then offering no way to act on
// what arrived — the app describing a step and declining to provide it, which is
// the state this screen was added to end. See the note in
// pending_approval_screen.dart.
//
// ## It does not decide that the code was right
//
// `AuthState.confirmEmail` submits and reports the *repository's* answer; the
// session moves only if that answer says so — the rule `login()` and
// `submitForApproval()` already follow. A wrong code and a right one arrive from
// the same button press, so the screen's error copy is the server's own sentence
// rather than a claim composed here: only the server knows whether the code
// mismatched, expired, or belonged to an account that was already verified.
//
// ## The one piece of state this screen owns
//
// The resend cooldown. The server throttles the route, so the cooldown is not
// what keeps anything safe — it is there so the button stops inviting a tap that
// would only be answered with a 429. It starts *after* this screen sends a code,
// not on arrival: someone who opened this screen because no mail ever came must
// be able to ask straight away, and that is the most likely reason they are here.

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../../config/dev_flags.dart';
import '../../data/api/api_exception.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/timed_snackbar.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/motion/cloud_transition.dart';
import '../../widgets/staggered_reveal.dart';
import '../../widgets/zefanya_mark.dart';

/// How long the resend link stays disabled after this screen sends a code.
///
/// Long enough for a slow mail server to land a message, short enough that
/// someone who genuinely received nothing is not stuck watching a timer.
const Duration kResendCooldown = Duration(seconds: 30);

/// How long a confirmation code stays valid. Mirrors the server's lifetime;
/// the countdown is display only, the server still decides.
const Duration kOtpLifetime = Duration(minutes: 5);

class OtpScreen extends StatefulWidget {
  const OtpScreen({super.key});

  @override
  State<OtpScreen> createState() => _OtpScreenState();
}

class _OtpScreenState extends State<OtpScreen> {
  final _formKey = GlobalKey<FormState>();
  final _codeController = TextEditingController();
  bool _isSubmitting = false;
  bool _isResending = false;

  /// The last failure, as the server wrote it. Cleared the moment the person
  /// edits the field, because an error about the code they are no longer typing
  /// is stale copy sitting under live text.
  String? _error;

  int _cooldownSeconds = 0;
  Timer? _cooldownTimer;

  int _secondsLeft = kOtpLifetime.inSeconds;
  Timer? _otpTimer;

  bool get _isExpired => _secondsLeft <= 0;

  @override
  void initState() {
    super.initState();
    // The state is not readable in initState (no inherited widgets yet), so
    // the first tick waits one frame. The timer itself is wall-clock based:
    // returning from the mail app recomputes from `otpSentAt`, it does not
    // resume a paused counter at 5:00.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _startOtpCountdown();
    });
  }

  @override
  void dispose() {
    _cooldownTimer?.cancel();
    _otpTimer?.cancel();
    _codeController.dispose();
    super.dispose();
  }

  void _startOtpCountdown() {
    _otpTimer?.cancel();
    final sentAt = context.read<AuthState>().otpSentAt ?? DateTime.now();

    void tick() {
      final remaining = sentAt
          .add(kOtpLifetime)
          .difference(DateTime.now())
          .inSeconds
          .clamp(0, kOtpLifetime.inSeconds)
          .toInt();
      if (!mounted) return;
      if (remaining != _secondsLeft) {
        setState(() => _secondsLeft = remaining);
      }
      if (remaining <= 0) _otpTimer?.cancel();
    }

    tick();
    _otpTimer = Timer.periodic(const Duration(seconds: 1), (_) => tick());
  }

  String get _remainingLabel {
    final minutes = _secondsLeft ~/ 60;
    final seconds = _secondsLeft % 60;
    return '$minutes:${seconds.toString().padLeft(2, '0')}';
  }

  Future<void> _submit() async {
    if (_isSubmitting) return;
    if (_isExpired) {
      setState(() => _error = 'That code has expired. Send a new code.');
      return;
    }
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isSubmitting = true;
      _error = null;
    });

    try {
      await context.read<AuthState>().confirmEmail(_codeController.text.trim());
    } on ApiException catch (e) {
      // A rejection is a message, not a classification: the server answers
      // `422 {ok: false, message}` for a wrong code *and* for an expired one, and
      // its sentences already name the remedy ("please try again" / "request a
      // new one"). Inventing a second vocabulary here would be guessing at a
      // distinction the response does not draw.
      if (!mounted) return;
      setState(() {
        _isSubmitting = false;
        _error = e.message;
      });
      return;
    }

    if (!mounted) return;
    setState(() => _isSubmitting = false);

    // Unwind rather than push: AuthGate (main.dart) is this app's single route
    // root and now resolves to whichever gate the account is actually at.
    await CloudTransition.run(
      context,
      swap: () {
        if (mounted) {
          Navigator.of(context).popUntil((route) => route.isFirst);
        }
      },
    );
  }

  Future<void> _resend() async {
    if (_isResending || _cooldownSeconds > 0) return;

    setState(() {
      _isResending = true;
      _error = null;
    });

    try {
      await context.read<AuthState>().resendConfirmationCode();
    } on ApiException catch (e) {
      // Thrown rather than swallowed in AuthState precisely so this branch can
      // exist: a resend that failed silently leaves someone watching an inbox
      // for mail that is not coming.
      if (!mounted) return;
      setState(() {
        _isResending = false;
        _error = e.message;
      });
      return;
    }

    if (!mounted) return;
    setState(() => _isResending = false);
    _startCooldown();
    _startOtpCountdown();
    // The server's own sentence, not one composed here: "a new code has been
    // sent" is a claim only the party that sent the mail is in a position to make.
    showTimedSnackBar(
      context,
      const SnackBar(content: Text('A new code has been sent.')),
    );
  }

  void _startCooldown() {
    _cooldownTimer?.cancel();
    setState(() => _cooldownSeconds = kResendCooldown.inSeconds);
    _cooldownTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      setState(() => _cooldownSeconds -= 1);
      if (_cooldownSeconds <= 0) timer.cancel();
    });
  }

  /// Mirrors the server's rule, `regex:/^\d{6}$/`, so a shape it would reject
  /// never costs a round-trip to discover. Length is the only part worth
  /// checking: the field cannot hold anything but digits (see the formatter in
  /// `_buildCodeField`), so "you typed a letter" is not a reachable state.
  String? _validateCode(String? value) {
    final code = (value ?? '').trim();
    if (code.isEmpty) return 'Enter the 6-digit code from the email.';
    if (code.length != 6) return 'The code is six digits.';
    return null;
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final auth = context.watch<AuthState>();
    final email = auth.email ?? 'the address on your application';

    return Scaffold(
      // The app-wide gradient in main.dart paints this.
      backgroundColor: Colors.transparent,
      appBar: AppBar(
        leading: IconButton(
          tooltip: 'Go back',
          icon: const Icon(Icons.arrow_back),
          // Back goes to the pending screen, which is where this was opened from
          // and which has lost nothing: the six digits are the only thing here,
          // and they have a resend link of their own.
          onPressed: () => Navigator.of(context).maybePop(),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
        // Every child is keyed, and the reason is the error block below: it
        // appears and disappears between two of these children, and without keys
        // the slot identities shift — which makes Flutter re-inflate the widgets
        // *after* it rather than reuse them. A fresh `StaggeredReveal` restarts
        // its delay from zero, so retyping a digit after a refused code would
        // visibly re-fade the Confirm button and the resend row, and would leave
        // its timers pending in a test that ends on the rebuild. Found by exactly
        // such a test failing on a pending timer.
        children: [
          const EditorialHeader(
            key: ValueKey('otp-header'),
            eyebrow: 'ONE LAST STEP',
            title: 'Confirm Email',
          ),
          const SizedBox(key: ValueKey('otp-gap-1'), height: 10),
          StaggeredReveal(
            key: const ValueKey('otp-copy'),
            index: 0,
            child: Text(
              'Enter the confirmation code we emailed to $email. Confirming it is '
              'what moves your application on to review. If it has not arrived, '
              'check the spam folder — a code from a new sender often lands there.',
              style: theme.textTheme.bodyMedium?.copyWith(
                height: 1.55,
                color: AppColors.textSecondary,
              ),
            ),
          ),
          const SizedBox(key: ValueKey('otp-gap-2'), height: 26),
          StaggeredReveal(
            key: const ValueKey('otp-code-field'),
            index: 1,
            child: _buildCodeField(theme),
          ),
          StaggeredReveal(
            key: const ValueKey('otp-expiry'),
            index: 2,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
              child: Text(
                _isExpired
                    ? 'This code has expired. Send a new code.'
                    : 'Code expires in $_remainingLabel',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: _isExpired
                      ? AppColors.danger
                      : AppColors.textSecondary,
                ),
              ),
            ),
          ),
          if (_error != null)
            StaggeredReveal(
              key: const ValueKey('otp-error'),
              index: 2,
              child: _fieldError(theme, _error!),
            ),
          const SizedBox(key: ValueKey('otp-gap-3'), height: 26),
          StaggeredReveal(
            key: const ValueKey('otp-confirm'),
            index: 3,
            child: _buildConfirmButton(),
          ),
          const SizedBox(key: ValueKey('otp-gap-4'), height: 18),
          StaggeredReveal(
            key: const ValueKey('otp-resend'),
            index: 4,
            child: _buildResendLink(theme),
          ),
          if (kShowDevTools) ...[
            const SizedBox(key: ValueKey('otp-gap-5'), height: 30),
            StaggeredReveal(
              key: const ValueKey('otp-dev-hint'),
              index: 5,
              child: _buildDevHint(theme),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildCodeField(ThemeData theme) {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 18),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(0, ShapeTier.card),
        border: Border.all(color: AppColors.divider),
      ),
      child: Form(
        key: _formKey,
        child: TextFormField(
          controller: _codeController,
          autofocus: true,
          keyboardType: TextInputType.number,
          textInputAction: TextInputAction.done,
          textAlign: TextAlign.center,
          // Digits, and six of them: the server's own rule (`regex:/^\d{6}$/`)
          // enforced at the keyboard, so the field cannot hold a value the
          // request would be rejected for. The validator below is then about
          // *completeness*, which is the only thing left that can be wrong.
          inputFormatters: [
            FilteringTextInputFormatter.digitsOnly,
            LengthLimitingTextInputFormatter(6),
          ],
          style: theme.textTheme.headlineSmall?.copyWith(
            letterSpacing: 10,
            fontWeight: FontWeight.w600,
          ),
          decoration: const InputDecoration(
            hintText: '000000',
            counterText: '',
          ),
          validator: _validateCode,
          onChanged: (_) {
            // The error is about the code that was submitted, so it stops being
            // true the moment a different one is typed.
            if (_error == null) return;
            setState(() => _error = null);
          },
          onFieldSubmitted: (_) => _submit(),
        ),
      ),
    );
  }

  /// Inline rather than the registration form's snackbar, and the difference is
  /// the error's *subject*: "that code doesn't match" is about the field
  /// directly above it, and the person's next act is to retype that field. A
  /// banner that disappears after three seconds points away from the thing that
  /// needs changing.
  Widget _fieldError(ThemeData theme, String message) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 0, 0),
      child: Text(
        message,
        style: theme.textTheme.bodySmall?.copyWith(color: AppColors.danger),
      ),
    );
  }

  Widget _buildConfirmButton() {
    return SizedBox(
      height: 52,
      child: ElevatedButton(
        onPressed: _isSubmitting ? null : _submit,
        child: _isSubmitting
            // The same spinner the registration form's own submit button uses,
            // so "this is being sent" looks the same in both halves of one flow.
            ? ZefanyaLoader(
                size: 18,
                color: AppColors.textOnDark,
                strokeWidth: 4,
              )
            : const Text('Confirm Code'),
      ),
    );
  }

  Widget _buildResendLink(ThemeData theme) {
    final waiting = _cooldownSeconds > 0;
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Text(
          'Nothing arrived?',
          style: theme.textTheme.bodySmall?.copyWith(
            color: AppColors.textSecondary,
          ),
        ),
        const SizedBox(width: 6),
        // Flexible because this row is a sentence plus a control, and the test
        // font is roughly twice as wide as a device's — the button's label wraps
        // inside its half rather than pushing the row past the screen.
        Flexible(
          child: TextButton(
            onPressed: (_isResending || waiting) ? null : _resend,
            child: Text(
              // The countdown is a fact rather than a scold: it says how long
              // until the server will consider another request at all, which is
              // what someone pressing the button a second time wants to know.
              waiting
                  ? 'Send another in ${_cooldownSeconds}s'
                  : 'Send a new code',
            ),
          ),
        ),
      ],
    );
  }

  /// Test-build only. It names the *mock's* code rather than merely hinting that
  /// one exists, because a tester cannot guess six digits and a hint that named
  /// no code would leave this flow unfinishable in exactly the build that exists
  /// to finish it. Gone entirely from a release build: `kShowDevTools` there is a
  /// compile-time false, so this branch is tree-shaken out.
  Widget _buildDevHint(ThemeData theme) {
    return Text(
      'Test build: mock registrations accept $kMockOtpCode.',
      style: theme.textTheme.bodySmall?.copyWith(
        color: AppColors.textSecondary,
      ),
      textAlign: TextAlign.center,
    );
  }
}
