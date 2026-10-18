// lib/screens/auth/pending_approval_screen.dart
//
// Path check: this file lives at lib/screens/auth/pending_approval_screen.dart
//   -> theme      => '../../theme/app_theme.dart'
//   -> auth state => '../../state/auth_state.dart'
//
// Design-pass note (auth flow):
// This screen is, literally, a waiting state — so the thing at the top
// of it should be the app's own wait (ZefanyaLoader), not Material's
// hourglass glyph in a plain circle. The circle is now an Arch, which
// is the shape language's "this is the important element" silhouette,
// and the one number on the screen ("1-2 business days") is pulled out
// of the paragraph and set as a display numeral, so the screen has a
// focal point instead of three evenly-weighted blocks of body copy.
//
// Honesty note (added after closing the dev-tools security hole):
// This screen's old copy — "$reviewer is verifying your documents,
// usually 1-2 business days, we'll email you" — was always fiction;
// with no backend, nothing reviews anything. It got away with being
// fiction because kShowDevTools being on meant a real, if fake, path
// out existed (Simulate Approval). Gate that same flag off — a real
// release build, which is the only build a real applicant ever sees —
// and the fiction stops being harmless: it's now a promise ("we'll
// email you") this build can never keep. Applies to BOTH roles, not
// just Courier — submitForApproval() (auth_state.dart) routes Buyer
// registrations through the identical dead end.
//
// So this screen now tells the truth about which build it's running
// in. kShowDevTools true: keep the reviewer/ETA copy, because the test
// button makes it a working (if fake) demo of the real flow. False:
// swap to copy that promises nothing this build can't deliver, and
// drop the fabricated "1-2 business days" for an honest one.
//
// Second axis (added with the password field): *which* pending gate.
// Registration has two — an email OTP and an admin review — and they are
// not interchangeable copy. One has something the person can go and do; the
// other has only waiting. Until the form collected a password, only the admin
// gate was reachable here, so this screen could afford to assume it. Now the
// repository can answer `pending_verification`, and telling someone to wait
// for a reviewer when their real blocker is an unconfirmed email strands them
// on a person who was never going to be asked.
//
// The email gate now *hands over* that step instead of describing it: the OTP
// screen takes the six digits, so the action is the app's own "Enter Code"
// button rather than a sentence pointing at the mail client. The previous
// version of this file named the action and where it happened, which was the
// honest thing to do while no field existed — and the rule it keeps arriving at
// is "name it or provide it, never invent it", inventing being what the fake
// reviewer ETA was.
//
// The two axes are independent: the gate copy is not gated on kShowDevTools,
// because a repository that answers `pending_verification` is a repository
// that reached a server which really did send a code.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/dev_flags.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/staggered_reveal.dart';
import '../../widgets/zefanya_mark.dart';
import 'otp_screen.dart';

class PendingApprovalScreen extends StatefulWidget {
  const PendingApprovalScreen({super.key});

  @override
  State<PendingApprovalScreen> createState() => _PendingApprovalScreenState();
}

class _PendingApprovalScreenState extends State<PendingApprovalScreen>
    with WidgetsBindingObserver {
  bool _checking = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Called by the OS when the app returns to the foreground.
  ///
  /// Only acts while this screen is still mounted and not already
  /// checking — redundant resume events (some devices fire two) are
  /// swallowed rather than stacking.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted && !_checking) {
      _checkStatus();
    }
  }

  Future<void> _checkStatus() async {
    if (_checking) return;
    setState(() => _checking = true);

    final auth = context.read<AuthState>();
    try {
      // `AuthState.refresh()` calls GET /me or GET /me/courier and applies
      // the returned status — if the account is now approved, AuthGate
      // rebuilds to the shell automatically via notifyListeners.
      await auth.refresh();
    } catch (_) {
      // A network failure is not surfaced here: this is a background check.
      // Explicit taps get the snackbar in _checkStatusWithFeedback below.
    } finally {
      if (mounted) setState(() => _checking = false);
    }
  }

  Future<void> _checkStatusWithFeedback(BuildContext context, AuthState auth, bool awaitingEmail) async {
    final messenger = ScaffoldMessenger.of(context);
    await _checkStatus();
    if (!mounted) return;
    messenger
      ..clearSnackBars()
      ..showSnackBar(
        SnackBar(
          content: Text(
            awaitingEmail
                ? "We're still waiting on that confirmation code."
                : kShowDevTools
                    ? 'Still under review — check back soon.'
                    : "Approvals aren't live yet — nothing to check "
                        "just yet, but thanks for checking in.",
          ),
        ),
      );
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final auth = context.watch<AuthState>();
    final role = auth.role;
    final awaitingEmail = auth.awaitsEmailVerification;
    final canConfirmEmail = auth.canConfirmEmail;
    final email = auth.email;
    final reviewer = role == UserRole.courier ? 'the Logistics/Sorting Center' : 'our admin team';

    return Scaffold(
      backgroundColor: Colors.transparent, // the app-wide gradient in main.dart paints this
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 24),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const SizedBox(height: 12),

              // ---- The wait itself --------------------------------
              // Was a 110px circle with Icons.hourglass_top_rounded.
              // The arch is the app's "feature" silhouette (product
              // hero, seller portrait), and the monogram trace is the
              // app's loading state — which is exactly what this is.
              const StaggeredReveal(index: 0, child: _WaitingArch()),
              const SizedBox(height: 30),

              StaggeredReveal(
                index: 1,
                child: Column(
                  children: [
                    Text('THANKS FOR SIGNING UP', style: AppType.eyebrow, textAlign: TextAlign.center),
                    const SizedBox(height: 10),
                    Text(
                      // The email gate takes precedence over the dev-tools split
                      // and needs no variant of itself: it is only reachable
                      // when the repository answered `pending_verification`,
                      // which means a server was reached and really did send a
                      // code. The kShowDevTools pair below covers the opposite
                      // case — no backend at all.
                      awaitingEmail
                          ? 'Check Your Email'
                          : (kShowDevTools ? 'Under Review' : "You're on the List"),
                      style: AppType.sectionDisplay,
                      textAlign: TextAlign.center,
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),

              StaggeredReveal(
                index: 2,
                child: Text(
                  awaitingEmail
                      // The action, now that there is somewhere to take it: the
                      // OTP screen. The inner branch is the case where the server
                      // named the email gate but sent no handle to confirm
                      // against, where a field could only ever fail with "we
                      // don't know which registration this is" — so the copy
                      // points at the email rather than at this app.
                      ? (canConfirmEmail
                          ? 'We emailed a confirmation code to '
                              '${email ?? 'the address on your application'}. '
                              'Enter it below to move your application on to '
                              'review.'
                          : 'We emailed a confirmation code to '
                              '${email ?? 'the address on your application'}. '
                              'Confirming that code is what moves your '
                              'application on to review, so check that inbox '
                              '— including the spam folder, where a code from a '
                              'new sender often lands.')
                      : kShowDevTools
                          ? '$reviewer is verifying your submitted credentials and '
                              'documents. We\'ll notify you by email the moment your '
                              'account is approved.'
                          : "Account review isn't live yet — we're still building the "
                              "piece that lets $reviewer approve new sign-ups. Your "
                              "application is saved, and this screen will reflect it "
                              "the moment that's ready. Thanks for your patience.",
                  style: theme.textTheme.bodyMedium?.copyWith(
                    height: 1.55,
                    color: AppColors.textSecondary,
                  ),
                  textAlign: TextAlign.center,
                ),
              ),
              const SizedBox(height: 28),

              // ---- The one numeral on the screen -------------------
              // "1-2 business days" was buried mid-paragraph. It's the
              // only fact on this screen the person actually wants, so
              // it gets the display treatment everything else here
              // deliberately doesn't. Only shown in the kShowDevTools
              // build — in the honest build there's no real ETA to
              // give a number to, and a made-up one is exactly the
              // kind of promise this whole rewrite exists to remove.
              // Not in the email-gate case: "1-2 business days" is the
              // reviewer's queue, and someone who hasn't confirmed their email
              // is not in it yet — they are one confirmation away from joining
              // it. Printing the reviewer's ETA beside "check your email" would
              // answer a question they have not reached.
              if (kShowDevTools && !awaitingEmail) ...[
                const StaggeredReveal(index: 3, child: _EtaBlock()),
                const SizedBox(height: 24),
              ],

              StaggeredReveal(index: 4, child: _buildStatusBadge(theme, ext, awaitingEmail)),
              const SizedBox(height: 34),

              StaggeredReveal(
                index: 5,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // The email gate's action, and the reason this screen is no
                    // longer purely a status report: it hands over the one step
                    // the person can take. Offered only when there is a handle to
                    // confirm against, because a field with nothing to send the
                    // code to would fail in a way nobody could act on — see
                    // AuthState.canConfirmEmail.
                    if (awaitingEmail && canConfirmEmail) ...[
                      ElevatedButton(
                        onPressed: () => Navigator.of(context).push(
                          AppPageRoute(builder: (_) => const OtpScreen()),
                        ),
                        child: const Text('Enter Code'),
                      ),
                      const SizedBox(height: 12),
                    ],
                    ElevatedButton(
                      onPressed: _checking
                          ? null
                          : () => _checkStatusWithFeedback(context, auth, awaitingEmail),
                      child: _checking
                          ? ZefanyaLoader(
                              size: 18,
                              color: AppColors.textOnDark,
                              strokeWidth: 4,
                            )
                          : const Text('Check Status'),
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton(
                      onPressed: () {
                        context.read<AuthState>().logout();
                        // AuthGate (in main.dart) rebuilds to LoginScreen once
                        // the state changes above — this unwinds back to it.
                        Navigator.of(context).popUntil((route) => route.isFirst);
                      },
                      child: const Text('Back to Login'),
                    ),
                    if (kShowDevTools) ...[
                      const SizedBox(height: 16),
                      // TEST-ONLY: opens the *email* gate — and the OTP screen
                      // behind it — without a mail server, so the confirmation
                      // flow can be walked end to end. The mock cannot answer
                      // `pending_verification` on its own, because there is no
                      // mail to send; the gate is opened as an affordance rather
                      // than faked as a registration answer. See
                      // AuthState.debugStartEmailVerification.
                      if (!canConfirmEmail)
                        TextButton(
                          onPressed: () {
                            context.read<AuthState>().debugStartEmailVerification();
                            Navigator.of(context).push(
                              AppPageRoute(builder: (_) => const OtpScreen()),
                            );
                          },
                          child: const Text('Enter Code (Test)'),
                        ),
                      // TEST-ONLY: simulates an admin approving this courier so
                      // the whole loop is verifiable without a real backend.
                      // Remove once real admin approval is wired up.
                      TextButton(
                        onPressed: () {
                          context.read<AuthState>().debugApprovePendingAccount();
                          Navigator.of(context).popUntil((route) => route.isFirst);
                        },
                        child: const Text('Simulate Approval (Test)'),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildStatusBadge(ThemeData theme, AppThemeExtension ext, bool awaitingEmail) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: BorderRadius.circular(ext.pillRadius),
        border: Border.all(color: AppColors.divider),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(color: AppColors.warning, shape: BoxShape.circle),
          ),
          const SizedBox(width: 8),
          // "Pending Verification" implies verification is underway, which is
          // only true in the kShowDevTools build. The email gate is a third
          // case and the only one where the status is a plain fact the server
          // itself stated — hence "Awaiting Confirmation" rather than a
          // hedge.
          //
          // Flexible because a status is a server-owned string that this screen
          // renders at whatever width it arrives in: the row is `min`-sized
          // inside a padded pill, so without it a long status (or a large text
          // scale) overflows the pill rather than wrapping inside it. Found by a
          // test that pumps this screen, since nothing rendered it before.
          Flexible(
            child: Text(
              awaitingEmail
                  ? 'Status: Awaiting Confirmation'
                  : (kShowDevTools ? 'Status: Pending Verification' : 'Status: Awaiting Launch'),
              style: theme.textTheme.labelMedium,
            ),
          ),
        ],
      ),
    );
  }
}

class _WaitingArch extends StatelessWidget {
  const _WaitingArch();

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Arch(
      footRadius: 16,
      child: Container(
        width: 132,
        height: 168,
        color: AppColors.surfaceMuted,
        alignment: Alignment.center,
        child: const ZefanyaLoader(size: 52, strokeWidth: 5),
      ),
    );
  }
}

class _EtaBlock extends StatelessWidget {
  const _EtaBlock();

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Column(
      children: [
        Text('USUALLY TAKES', style: AppType.eyebrow),
        const SizedBox(height: 8),
        Row(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.baseline,
          textBaseline: TextBaseline.alphabetic,
          children: [
            Text('1\u20132', style: AppType.priceNumeral(46)),
            const SizedBox(width: 10),
            Text(
              'business\ndays',
              style: AppType.eyebrow.copyWith(height: 1.5, letterSpacing: 1.8),
            ),
          ],
        ),
      ],
    );
  }
}
