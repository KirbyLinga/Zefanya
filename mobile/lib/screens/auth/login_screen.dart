// lib/screens/auth/login_screen.dart
//
// Path check for anyone editing imports by hand:
//   this file lives at lib/screens/auth/login_screen.dart
//   -> theme lives at lib/theme/app_theme.dart        => '../../theme/app_theme.dart'
//   -> auth state lives at lib/state/auth_state.dart  => '../../state/auth_state.dart'
//   -> registration lives at lib/screens/auth/...      => 'registration_screen.dart' (same folder)
//
// Design-pass note (auth flow):
// This screen used to render the wordmark at theme.textTheme.displaySmall
// — plain Material default — while Home rendered the exact same four
// letters at AppType.masthead. The first screen anyone ever sees
// typeset the brand differently from the screen they landed on two
// seconds later. The lockup below is now byte-for-byte the same
// construction Home uses (eyebrow / masthead / monogram), so the logo
// looks like the logo everywhere.

import 'package:flutter/services.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/dev_flags.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import 'registration_screen.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/staggered_reveal.dart';
import '../../widgets/zefanya_mark.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _identifierController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _obscurePassword = true;
  bool _isSubmitting = false;

  // TEMP: with no backend yet, there's no server response to read a
  // role from, so the tester picks which role this login simulates.
  // Remove this selector once real auth returns the user's role.
  UserRole _loginAsRole = UserRole.buyer;

  @override
  void dispose() {
    _identifierController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isSubmitting = true);

    // TODO: replace with a real auth call (Laravel API) once wired up,
    // and pass the role/name/email the API returns to login() below.
    await Future.delayed(const Duration(milliseconds: 600));

    if (!mounted) return;
    setState(() => _isSubmitting = false);

    context.read<AuthState>().login(
          role: _loginAsRole,
          name: 'Tristan Araneta',
          email: _identifierController.text.trim(),
        );

    // AuthGate (in main.dart) rebuilds to the right shell once the
    // state changes above — this just unwinds back to that root route.
    Navigator.of(context).popUntil((route) => route.isFirst);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      backgroundColor: Colors.transparent, // the app-wide gradient in main.dart paints this
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 28, 24, 36),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // ---- Wordmark lockup --------------------------------
                // Left-aligned, like Home. The old centered treatment
                // was the other half of the inconsistency: same letters,
                // different size AND different alignment.
                const StaggeredReveal(index: 0, child: _LoginMasthead()),
                const SizedBox(height: 26),

                // ---- Credentials panel ------------------------------
                // The fields used to float loose on the background. One
                // surface with an editorial (asymmetric) radius gives
                // the form an actual shape and lets it lift off the
                // gradient the way every other content surface does.
                StaggeredReveal(
                  index: 1,
                  child: _Panel(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text('Email or Phone Number', style: theme.textTheme.labelLarge),
                        const SizedBox(height: 8),
                        TextFormField(
                          controller: _identifierController,
                          keyboardType: TextInputType.emailAddress,
                          decoration: const InputDecoration(
                            hintText: 'you@example.com or 09XXXXXXXXX',
                            prefixIcon: Icon(Icons.person_outline),
                          ),
                          validator: (value) {
                            // TODO: swap for a real email-or-phone regex check.
                            if (value == null || value.trim().isEmpty) {
                              return 'Enter your email or phone number';
                            }
                            return null;
                          },
                        ),
                        const SizedBox(height: 20),
                        Text('Password', style: theme.textTheme.labelLarge),
                        const SizedBox(height: 8),
                        TextFormField(
                          controller: _passwordController,
                          obscureText: _obscurePassword,
                          decoration: InputDecoration(
                            hintText: 'Enter your password',
                            prefixIcon: const Icon(Icons.lock_outline),
                            suffixIcon: IconButton(
                              icon: Icon(_obscurePassword
                                  ? Icons.visibility_outlined
                                  : Icons.visibility_off_outlined),
                              onPressed: () =>
                                  setState(() => _obscurePassword = !_obscurePassword),
                            ),
                          ),
                          validator: (value) {
                            // TODO: enforce real password rules server-side.
                            if (value == null || value.isEmpty) return 'Enter your password';
                            return null;
                          },
                        ),
                        Align(
                          alignment: Alignment.centerRight,
                          child: TextButton(
                            onPressed: () {
                              // TODO: forgot-password flow
                            },
                            style: TextButton.styleFrom(
                              foregroundColor: AppColors.textSecondary,
                              textStyle: theme.textTheme.bodySmall,
                            ),
                            child: const Text('Forgot password?'),
                          ),
                        ),
                        if (kShowDevTools) ...[
                          const SizedBox(height: 4),
                          _buildLoginAsSelector(theme),
                        ],
                        const SizedBox(height: 20),
                        ElevatedButton(
                          onPressed: _isSubmitting ? null : _login,
                          child: _isSubmitting
                              ? const ZefanyaLoader(
                                  size: 18, color: AppColors.textOnDark, strokeWidth: 4)
                              : const Text('Login'),
                        ),
                      ],
                    ),
                  ),
                ),

                const SizedBox(height: 28),
                const StaggeredReveal(index: 2, child: _OrDivider()),
                const SizedBox(height: 18),
                StaggeredReveal(
                  index: 3,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      // Buyer is the primary CTA — filled button so it
                      // reads as the main path, not one of two equal options.
                      ElevatedButton(
                        onPressed: () => Navigator.of(context).push(
                          AppPageRoute(builder: (_) => RegistrationScreen(initialRole: UserRole.buyer)),
                        ),
                        child: const Text('Create a Buyer Account'),
                      ),
                      const SizedBox(height: 10),
                      OutlinedButton(
                        onPressed: () => Navigator.of(context).push(
                          AppPageRoute(builder: (_) => RegistrationScreen(initialRole: UserRole.courier)),
                        ),
                        child: const Text('Register as Courier'),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildLoginAsSelector(ThemeData theme) {
    return Row(
      children: [
        Text('Login as:', style: theme.textTheme.bodySmall),
        const SizedBox(width: 10),
        Expanded(
          child: SegmentedButton<UserRole>(
            segments: const [
              ButtonSegment(value: UserRole.buyer, label: Text('Buyer')),
              ButtonSegment(value: UserRole.courier, label: Text('Courier')),
            ],
            selected: {_loginAsRole},
            onSelectionChanged: (selection) {
              HapticFeedback.selectionClick();
              setState(() => _loginAsRole = selection.first);
            },
          ),
        ),
      ],
    );
  }
}

/// The wordmark, constructed identically to Home's (buyer_shell.dart).
/// If that lockup ever changes, change both — or pull them into one
/// shared widget; they are the same object.
class _LoginMasthead extends StatelessWidget {
  const _LoginMasthead();

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('WELCOME BACK', style: AppType.eyebrow),
        const SizedBox(height: 10),
        Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            Text('Zefanya', style: AppType.masthead),
            const SizedBox(width: 10),
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: ZefanyaMark(
                size: 20,
                color: AppColors.primaryDark.withValues(alpha: 0.75),
              ),
            ),
          ],
        ),
      ],
    );
  }
}

/// Surface for the credential fields — asymmetric corners so it reads
/// as a deliberate shape rather than the default box, and the same
/// lift (white fill + soft shadow) cards get elsewhere.
class _Panel extends StatelessWidget {
  const _Panel({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 22, 20, 22),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(0),
        boxShadow: [
          BoxShadow(
            color: AppColors.neutral.withValues(alpha: 0.16),
            blurRadius: 18,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: child,
    );
  }
}

class _OrDivider extends StatelessWidget {
  const _OrDivider();

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        const Expanded(child: Divider()),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14),
          // eyebrow, not bodySmall — the tiny all-caps kicker is the
          // app's own "small type" voice, and it's what makes the
          // masthead above read as big.
          child: Text('OR', style: AppType.eyebrow),
        ),
        const Expanded(child: Divider()),
      ],
    );
  }
}
