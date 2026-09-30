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
// on a person who was never going to be asked. The gate copy deliberately
// does *not* offer an in-app code field, because this build has no OTP screen:
// it names the action (confirm the emailed code) and where the action happens.
//
// The two axes are independent: the gate copy is not gated on kShowDevTools,
// because a repository that answers `pending_verification` is a repository
// that reached a server which really did send a code.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/dev_flags.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/staggered_reveal.dart';
import '../../widgets/zefanya_mark.dart';

class PendingApprovalScreen extends StatelessWidget {
  const PendingApprovalScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final auth = context.watch<AuthState>();
    final role = auth.role;
    // Which of the two gates this is. Both read as "waiting", but only one has
    // something the person can do about it — see the header note.
    final awaitingEmail = auth.awaitsEmailVerification;
    final email = auth.email;
    // Per the spec: Buyer/Seller applications are reviewed by the
    // administrator; Courier applications are reviewed by the
    // Logistics/Sorting Center instead. Only meaningful in the
    // kShowDevTools-true copy below — the honest copy doesn't name a
    // reviewer, because naming one implies review is actually happening.
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
                      // Names the action and where it happens. It does not
                      // offer an in-app code field, because there isn't one:
                      // the confirmation happens from the email itself
                      // (server-side that is
                      // `register.{buyer,logistics}.verify-otp`), and pointing
                      // at a field that doesn't exist would be the same class of
                      // lie as the reviewer ETA this file already removed.
                      ? 'We emailed a confirmation code to '
                          '${email ?? 'the address on your application'}. '
                          'Confirming that code is what moves your application '
                          'on to review, so check that inbox — including the '
                          'spam folder, where a code from a new sender often '
                          'lands.'
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
                    ElevatedButton(
                      onPressed: () {
                        // TODO: replace with a real status-check API call.
                        // clearSnackBars() first — same reasoning as the
                        // other buttons fixed this pass: nothing stops
                        // repeated taps, so each one queued behind the last.
                        ScaffoldMessenger.of(context)
                          ..clearSnackBars()
                          ..showSnackBar(
                            SnackBar(
                              content: Text(
                                // Third case, first: in the email gate there is
                                // nothing to "approve" yet, so the reviewer copy
                                // would describe a queue the person is not in.
                                awaitingEmail
                                    ? "We're still waiting on that confirmation code."
                                    : kShowDevTools
                                        ? 'Still under review — check back soon.'
                                        : "Approvals aren't live yet — nothing to check "
                                            "just yet, but thanks for checking in.",
                              ),
                            ),
                          );
                      },
                      child: const Text('Check Status'),
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
