// lib/screens/auth/login_screen.dart
//
// Path check for anyone editing imports by hand:
//   this file lives at lib/screens/auth/login_screen.dart
//   -> theme lives at lib/theme/app_theme.dart        => '../../theme/app_theme.dart'
//   -> auth state lives at lib/state/auth_state.dart  => '../../state/auth_state.dart'
//   -> registration lives at lib/screens/auth/...      => 'registration_screen.dart' (same folder)

import 'dart:async';
import 'dart:io';
import 'package:zefanya/config/dev_flags.dart' show GoogleConfig, kUseLiveApi;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../../data/api/api_exception.dart'
    show ApiException, PendingApprovalException, RequestRejectedException;
import '../../services/google_sign_in_service.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/timed_snackbar.dart';
import 'registration_screen.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/google_mark.dart';
import '../../widgets/google_role_sheet.dart';
import '../../widgets/motion/cloud_transition.dart';
import '../../widgets/staggered_reveal.dart';
import '../../widgets/zefanya_mark.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, this.initialRole = UserRole.buyer});
  final UserRole initialRole;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _identifierController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _obscurePassword = true;
  bool _isSubmitting = false;
  bool _isGoogleSubmitting = false;
  bool _isRiderMode = false;
  bool _isLinehaulMode = false;
  final _passwordFocus = FocusNode();

  bool get _isBusy => _isSubmitting || _isGoogleSubmitting;

  @override
  void initState() {
    super.initState();
    _isRiderMode = widget.initialRole == UserRole.courier;
    _isLinehaulMode = widget.initialRole == UserRole.linehaul;
  }

  /// Google sign-in is usable when mock mode is on
  /// (`MockGoogleSignInService` needs no client ID) or when a live build
  /// supplies the web client ID. Never show a button that cannot complete.
  bool get _googleAvailable =>
      !kUseLiveApi || GoogleConfig.current.isConfigured;

  /// Friendly copy shown when Google sign-in cannot complete in this build.
  static const String _googleNotAvailableMessage =
      'Google sign-in is not available right now. Please use email and password.';

  @override
  void dispose() {
    _identifierController.dispose();
    _passwordController.dispose();
    _passwordFocus.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isSubmitting = true);

    try {
      // The role selector determines which endpoint to call: buyers hit
      // /login/buyer, riders hit /login/courier.
      await context.read<AuthState>().login(
        role: _isLinehaulMode
            ? UserRole.linehaul
            : _isRiderMode
            ? UserRole.courier
            : UserRole.buyer,
        email: _identifierController.text.trim(),
        password: _passwordController.text,
      );
      TextInput.finishAutofillContext();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _isSubmitting = false);

      final gate = e is PendingApprovalException && e.canConfirmEmail
          ? e
          : null;
      if (gate != null) {
        await context.read<AuthState>().beginEmailVerification(
          email: _identifierController.text.trim(),
          verificationId: gate.verificationId!,
        );
        if (!mounted) return;
        Navigator.of(context).popUntil((route) => route.isFirst);
        return;
      }

      showTimedSnackBar(context, SnackBar(content: Text(e.message)));
      return;
    }

    if (!mounted) return;
    setState(() => _isSubmitting = false);

    // If credentials belong to the other role, sign in normally, route by the real
    // role from the server, and show a one-time snackbar.
    final actualRole = context.read<AuthState>().role;
    if (_isRiderMode && actualRole == UserRole.buyer) {
      showTimedSnackBar(
        context,
        const SnackBar(content: Text('Signed in as a buyer account')),
      );
    } else if (!_isRiderMode &&
        !_isLinehaulMode &&
        actualRole == UserRole.courier) {
      showTimedSnackBar(
        context,
        const SnackBar(content: Text('Signed in as a rider account')),
      );
    }

    // AuthGate routes to the shell matching the actual role.
    await CloudTransition.run(
      context,
      swap: () {
        if (mounted) {
          Navigator.of(context).popUntil((route) => route.isFirst);
        }
      },
    );
  }

  Future<void> _continueWithGoogle() async {
    if (_isBusy) return;
    setState(() => _isGoogleSubmitting = true);

    try {
      final outcome = await context.read<AuthState>().signInWithGoogle();
      if (!mounted) return;

      if (outcome.result != null) {
        final actualRole = context.read<AuthState>().role;
        if (_isRiderMode && actualRole == UserRole.buyer) {
          showTimedSnackBar(
            context,
            const SnackBar(content: Text('Signed in as a buyer account')),
          );
        } else if (!_isRiderMode && actualRole == UserRole.courier) {
          showTimedSnackBar(
            context,
            const SnackBar(content: Text('Signed in as a rider account')),
          );
        }

        TextInput.finishAutofillContext();
        await CloudTransition.run(
          context,
          swap: () {
            if (mounted) {
              Navigator.of(context).popUntil((route) => route.isFirst);
            }
          },
        );
        return;
      }

      // New Google identity:
      if (_isRiderMode) {
        // Google in rider mode leads directly to the rider application, never an active rider.
        await Navigator.of(context).push(
          AppPageRoute(
            builder: (_) => RegistrationScreen(
              initialRole: UserRole.courier,
              googleIdentity: outcome.identity,
            ),
          ),
        );
        return;
      }

      final role = await showGoogleRoleSheet(
        context,
        email: outcome.identity.email,
      );
      if (role == null || !mounted) return;

      await Navigator.of(context).push(
        AppPageRoute(
          builder: (_) => RegistrationScreen(
            initialRole: role,
            googleIdentity: outcome.identity,
          ),
        ),
      );
    } on GoogleSignInFailure catch (e) {
      if (!mounted || e.cancelled) return;
      // Configuration error: the flow cannot complete in this build.
      if (e.message.contains('configured')) {
        showTimedSnackBar(
          context,
          SnackBar(content: Text(_googleNotAvailableMessage)),
        );
      } else {
        showTimedSnackBar(context, SnackBar(content: Text(e.message)));
      }
    } on RequestRejectedException catch (e) {
      if (!mounted) return;
      // Account conflict: this Google identity is linked to a different account.
      if (e.statusCode == 409) {
        showTimedSnackBar(
          context,
          const SnackBar(
            content: Text(
              'This Google account is already linked to a different account. '
              'Please sign in with email and password instead.',
            ),
          ),
        );
      } else {
        showTimedSnackBar(context, SnackBar(content: Text(e.message)));
      }
    } on SocketException catch (_) {
      if (!mounted) return;
      showTimedSnackBar(
        context,
        const SnackBar(
          content: Text(
            'No internet connection. Please check your network and try again.',
          ),
        ),
      );
    } on TimeoutException catch (_) {
      if (!mounted) return;
      showTimedSnackBar(
        context,
        const SnackBar(
          content: Text('The request timed out. Please try again.'),
        ),
      );
    } catch (_) {
      if (!mounted) return;
      showTimedSnackBar(
        context,
        const SnackBar(
          content: Text(
            'Google sign-in could not be completed. Please try again.',
          ),
        ),
      );
    } finally {
      if (mounted) setState(() => _isGoogleSubmitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final isRider = _isRiderMode;

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 28, 24, 36),
          child: Form(
            key: _formKey,
            autovalidateMode: AutovalidateMode.onUserInteraction,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // ---- Wordmark lockup --------------------------------
                StaggeredReveal(
                  index: 0,
                  child: _LoginMasthead(
                    isRiderMode: isRider,
                    isLinehaulMode: _isLinehaulMode,
                  ),
                ),
                const SizedBox(height: 12),
                Text(
                  _isLinehaulMode
                      ? 'Sign in to move parcels between hubs'
                      : isRider
                      ? 'Sign in to accept jobs and deliver parcels'
                      : 'Sign in to browse, shop, and track your orders',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: AppColors.textSecondary,
                  ),
                ),
                const SizedBox(height: 20),

                if (kUseLiveApi && !_isLinehaulMode)
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton(
                      key: const ValueKey('courier-mode-switch-link'),
                      style: TextButton.styleFrom(
                        minimumSize: const Size(48, 48),
                        padding: const EdgeInsets.symmetric(
                          horizontal: 4,
                          vertical: 12,
                        ),
                        alignment: Alignment.centerLeft,
                        foregroundColor: AppColors.primaryDark,
                        textStyle: theme.textTheme.labelLarge?.copyWith(
                          fontWeight: FontWeight.w700,
                          decoration: TextDecoration.underline,
                        ),
                      ),
                      onPressed: _isBusy
                          ? null
                          : () => setState(() {
                              _isLinehaulMode = true;
                              _isRiderMode = false;
                            }),
                      child: const Text('Are you a Courier? Sign in here'),
                    ),
                  ),
                // ---- Labeled Switch Link -----------------------------
                Align(
                  alignment: Alignment.centerLeft,
                  child: Semantics(
                    label: isRider || _isLinehaulMode
                        ? 'Back to shopper sign in'
                        : 'Are you a rider? Sign in here',
                    button: true,
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(
                        minHeight: 48,
                        minWidth: 48,
                      ),
                      child: TextButton(
                        key: const ValueKey('role-mode-switch-link'),
                        onPressed: _isBusy
                            ? null
                            : () => setState(() {
                                if (_isLinehaulMode) {
                                  _isLinehaulMode = false;
                                  _isRiderMode = false;
                                } else {
                                  _isRiderMode = !_isRiderMode;
                                }
                              }),
                        style: TextButton.styleFrom(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 4,
                            vertical: 12,
                          ),
                          alignment: Alignment.centerLeft,
                        ),
                        child: Text(
                          isRider || _isLinehaulMode
                              ? 'Back to shopper sign in'
                              : 'Are you a rider? Sign in here',
                          style: theme.textTheme.labelLarge?.copyWith(
                            color: isRider
                                ? (AppColors.isDark
                                      ? AppColors.tertiary
                                      : AppColors.sageIcon)
                                : AppColors.primaryDark,
                            fontWeight: FontWeight.w700,
                            decoration: TextDecoration.underline,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 12),

                // ---- Credentials panel ------------------------------
                StaggeredReveal(
                  index: 1,
                  child: AutofillGroup(
                    child: _Panel(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Text(
                            _isLinehaulMode ? 'Email' : 'Email or Phone Number',
                            style: theme.textTheme.labelLarge,
                          ),
                          const SizedBox(height: 8),
                          TextFormField(
                            controller: _identifierController,
                            keyboardType: TextInputType.emailAddress,
                            autofillHints: const [
                              AutofillHints.email,
                              AutofillHints.telephoneNumber,
                            ],
                            textInputAction: TextInputAction.next,
                            onFieldSubmitted: (_) =>
                                _passwordFocus.requestFocus(),
                            decoration: InputDecoration(
                              hintText: _isLinehaulMode
                                  ? 'you@example.com'
                                  : 'you@example.com or 09XXXXXXXXX',
                              prefixIcon: const Icon(Icons.person_outline),
                            ),
                            validator: (value) {
                              if (value == null || value.trim().isEmpty) {
                                return _isLinehaulMode
                                    ? 'Enter your email'
                                    : 'Enter your email or phone number';
                              }
                              if (_isLinehaulMode &&
                                  !RegExp(
                                    r'^[^\s@]+@[^\s@]+\.[^\s@]+$',
                                  ).hasMatch(value.trim())) {
                                return 'Enter a valid email';
                              }
                              return null;
                            },
                          ),
                          const SizedBox(height: 20),
                          Text('Password', style: theme.textTheme.labelLarge),
                          const SizedBox(height: 8),
                          TextFormField(
                            controller: _passwordController,
                            focusNode: _passwordFocus,
                            obscureText: _obscurePassword,
                            autofillHints: const [AutofillHints.password],
                            textInputAction: TextInputAction.done,
                            onFieldSubmitted: (_) {
                              if (!_isSubmitting) _login();
                            },
                            decoration: InputDecoration(
                              hintText: 'Enter your password',
                              prefixIcon: const Icon(Icons.lock_outline),
                              suffixIcon: IconButton(
                                tooltip: _obscurePassword
                                    ? 'Show password'
                                    : 'Hide password',
                                icon: Icon(
                                  _obscurePassword
                                      ? Icons.visibility_outlined
                                      : Icons.visibility_off_outlined,
                                ),
                                onPressed: () => setState(
                                  () => _obscurePassword = !_obscurePassword,
                                ),
                              ),
                            ),
                            validator: (value) {
                              if (value == null || value.isEmpty) {
                                return 'Enter your password';
                              }
                              return null;
                            },
                          ),
                          if (!kUseLiveApi && !_isLinehaulMode)
                            Align(
                              alignment: Alignment.centerRight,
                              child: TextButton(
                                onPressed: () {
                                  // TODO: forgot-password flow (mock only)
                                },
                                style: TextButton.styleFrom(
                                  foregroundColor: AppColors.textSecondary,
                                  textStyle: theme.textTheme.bodySmall,
                                ),
                                child: const Text('Forgot password?'),
                              ),
                            ),
                          const SizedBox(height: 16),
                          ElevatedButton(
                            style: isRider
                                ? ElevatedButton.styleFrom(
                                    backgroundColor: AppColors.isDark
                                        ? AppColors.tertiary
                                        : AppColors.sageIcon,
                                    foregroundColor: AppColors.textOnDark,
                                  )
                                : null,
                            onPressed: _isSubmitting ? null : _login,
                            child: _isSubmitting
                                ? ZefanyaLoader(
                                    size: 18,
                                    color: AppColors.textOnDark,
                                    strokeWidth: 4,
                                  )
                                : Text(
                                    _isLinehaulMode
                                        ? 'Login as Courier'
                                        : isRider
                                        ? 'Login as Rider'
                                        : 'Login',
                                  ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                if (_googleAvailable && !_isLinehaulMode) ...[
                  const SizedBox(height: 14),
                  StaggeredReveal(
                    index: 2,
                    child: OutlinedButton(
                      key: const ValueKey('login-google-button'),
                      onPressed: _isBusy ? null : _continueWithGoogle,
                      child: _isGoogleSubmitting
                          ? ZefanyaLoader(
                              size: 18,
                              color: AppColors.textPrimary,
                              strokeWidth: 4,
                            )
                          : const Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                GoogleMark(size: 18),
                                SizedBox(width: 10),
                                Text('Continue with Google'),
                              ],
                            ),
                    ),
                  ),
                ],

                const SizedBox(height: 28),
                if (!_isLinehaulMode)
                  const StaggeredReveal(index: 3, child: _OrDivider()),
                const SizedBox(height: 18),
                StaggeredReveal(
                  index: 4,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      if (_isLinehaulMode)
                        const Text(
                          'Your Logistics company provides your Courier account. Contact them for account access.',
                        )
                      else if (!isRider)
                        ElevatedButton(
                          onPressed: () => Navigator.of(context).push(
                            AppPageRoute(
                              builder: (_) => const RegistrationScreen(
                                initialRole: UserRole.buyer,
                              ),
                            ),
                          ),
                          child: const Text('Create a Buyer Account'),
                        )
                      else
                        ElevatedButton(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AppColors.isDark
                                ? AppColors.tertiary
                                : AppColors.sageIcon,
                            foregroundColor: AppColors.textOnDark,
                          ),
                          onPressed: () => Navigator.of(context).push(
                            AppPageRoute(
                              builder: (_) => const RegistrationScreen(
                                initialRole: UserRole.courier,
                              ),
                            ),
                          ),
                          child: const Text('Apply as a Rider'),
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
}

/// The wordmark lockup, matching the app's editorial brand style.
class _LoginMasthead extends StatelessWidget {
  const _LoginMasthead({
    required this.isRiderMode,
    this.isLinehaulMode = false,
  });

  final bool isRiderMode;
  final bool isLinehaulMode;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          isLinehaulMode
              ? 'COURIER PORTAL'
              : isRiderMode
              ? 'RIDER PORTAL'
              : 'WELCOME BACK',
          style: AppType.eyebrow,
        ),
        const SizedBox(height: 10),
        Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            Flexible(
              child: Text(
                isLinehaulMode
                    ? 'Zefanya Courier'
                    : isRiderMode
                    ? 'Zefanya Rider'
                    : 'Zefanya',
                style: AppType.masthead,
              ),
            ),
            const SizedBox(width: 10),
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: isRiderMode || isLinehaulMode
                  ? Icon(
                      isLinehaulMode
                          ? Icons.local_shipping_outlined
                          : Icons.two_wheeler_outlined,
                      size: 22,
                      color: AppColors.isDark
                          ? AppColors.tertiary
                          : AppColors.sageIcon,
                    )
                  : ZefanyaMark(
                      size: 20,
                      color: AppColors.textPrimary.withValues(alpha: 0.45),
                    ),
            ),
          ],
        ),
      ],
    );
  }
}

class _Panel extends StatelessWidget {
  const _Panel({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 22, 20, 22),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(0, ShapeTier.card),
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
    AppColors.watch(context);
    return Row(
      children: [
        const Expanded(child: Divider()),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14),
          child: Text('OR', style: AppType.eyebrow),
        ),
        const Expanded(child: Divider()),
      ],
    );
  }
}
