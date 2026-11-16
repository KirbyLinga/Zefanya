// lib/screens/shared/change_password_screen.dart
//
// Three fields, in the order the server checks them: the password that proves
// this is the owner, the new one, and the confirmation. The current-password
// check is what makes a borrowed unlocked phone insufficient to take an account.
//
// The rule shown under the new field is the same `validatePassword` the
// registration form uses. Keeping one implementation means a password accepted
// at sign-up cannot be rejected here, or the reverse.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/api/api_exception.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/timed_snackbar.dart';
import '../../utils/validators.dart';
import '../../widgets/dirty_guard.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/zefanya_mark.dart';

class ChangePasswordScreen extends StatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  State<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends State<ChangePasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _currentController = TextEditingController();
  final _newController = TextEditingController();
  final _confirmController = TextEditingController();

  bool _obscureCurrent = true;
  bool _obscureNew = true;
  bool _obscureConfirm = true;
  bool _isSaving = false;
  bool _dirty = false;

  String? _currentError;
  String? _newError;
  String? _formError;

  void _markDirty() {
    if (!_dirty) setState(() => _dirty = true);
  }

  @override
  void initState() {
    super.initState();
    for (final controller in [
      _currentController,
      _newController,
      _confirmController,
    ]) {
      controller.addListener(_markDirty);
    }
  }

  @override
  void dispose() {
    for (final controller in [
      _currentController,
      _newController,
      _confirmController,
    ]) {
      controller.removeListener(_markDirty);
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _formError = null;
      _currentError = null;
      _newError = null;
    });

    if (!_formKey.currentState!.validate()) return;

    setState(() => _isSaving = true);
    try {
      await context.read<AuthState>().changePassword(
            currentPassword: _currentController.text,
            newPassword: _newController.text,
          );
    } on ValidationException catch (e) {
      if (!mounted) return;
      setState(() {
        _isSaving = false;
        _currentError = e.forField('current_password');
        _newError = e.forField('password');
      });
      _formKey.currentState!.validate();
      return;
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _isSaving = false;
        _formError = e.message;
      });
      return;
    }

    if (!mounted) return;
    setState(() {
      _isSaving = false;
      _dirty = false;
    });
    showTimedSnackBar(
      context,
      const SnackBar(
        content: Text('Password changed. Other devices have been signed out.'),
      ),
    );
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return DirtyGuard(
      hasUnsavedInput: () => _dirty,
      message: 'Your password change will be lost.',
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(),
        body: Form(
          key: _formKey,
          autovalidateMode: AutovalidateMode.onUserInteraction,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
            children: [
              const EditorialHeader(
                eyebrow: 'ACCOUNT & SECURITY',
                title: 'Change Password',
                padding: EdgeInsets.fromLTRB(0, 0, 0, 16),
              ),
              Text(
                'Enter the password you use now, then choose a new one.',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: AppColors.textSecondary,
                  height: 1.45,
                ),
              ),
              const SizedBox(height: 22),
              _fieldLabel(theme, 'Current Password'),
              const SizedBox(height: 8),
              TextFormField(
                key: const ValueKey('current-password'),
                controller: _currentController,
                obscureText: _obscureCurrent,
                autofillHints: const [AutofillHints.password],
                textInputAction: TextInputAction.next,
                onChanged: (_) {
                  if (_currentError != null) {
                    setState(() => _currentError = null);
                  }
                },
                decoration: InputDecoration(
                  hintText: 'Your current password',
                  prefixIcon: const Icon(Icons.lock_outline),
                  suffixIcon: _revealButton(
                    visible: _obscureCurrent,
                    onPressed: () => setState(
                      () => _obscureCurrent = !_obscureCurrent,
                    ),
                  ),
                ),
                validator: (value) {
                  if (_currentError != null) return _currentError;
                  if (value == null || value.isEmpty) {
                    return 'Enter your current password';
                  }
                  return null;
                },
              ),
              const SizedBox(height: 18),
              _fieldLabel(theme, 'New Password'),
              const SizedBox(height: 8),
              TextFormField(
                key: const ValueKey('new-password'),
                controller: _newController,
                obscureText: _obscureNew,
                autofillHints: const [AutofillHints.newPassword],
                textInputAction: TextInputAction.next,
                onChanged: (_) {
                  if (_newError != null) setState(() => _newError = null);
                },
                decoration: InputDecoration(
                  hintText: 'At least $kPasswordMinLength characters',
                  prefixIcon: const Icon(Icons.lock_reset_outlined),
                  suffixIcon: _revealButton(
                    visible: _obscureNew,
                    onPressed: () => setState(() => _obscureNew = !_obscureNew),
                  ),
                ),
                validator: (value) {
                  if (_newError != null) return _newError;
                  return validatePassword(value);
                },
              ),
              const SizedBox(height: 8),
              Text(
                'Use $kPasswordMinLength+ characters with uppercase, lowercase, '
                'a number and a symbol.',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
              ),
              const SizedBox(height: 18),
              _fieldLabel(theme, 'Confirm New Password'),
              const SizedBox(height: 8),
              TextFormField(
                key: const ValueKey('confirm-password'),
                controller: _confirmController,
                obscureText: _obscureConfirm,
                autofillHints: const [AutofillHints.newPassword],
                textInputAction: TextInputAction.done,
                onFieldSubmitted: (_) {
                  if (!_isSaving) _save();
                },
                decoration: InputDecoration(
                  hintText: 'Type the new password again',
                  prefixIcon: const Icon(Icons.check_circle_outline),
                  suffixIcon: _revealButton(
                    visible: _obscureConfirm,
                    onPressed: () =>
                        setState(() => _obscureConfirm = !_obscureConfirm),
                  ),
                ),
                validator: (value) {
                  if (value == null || value.isEmpty) {
                    return 'Confirm your new password';
                  }
                  if (value != _newController.text) {
                    return "Passwords don't match";
                  }
                  return null;
                },
              ),
              if (_formError != null) ...[
                const SizedBox(height: 16),
                Text(
                  _formError!,
                  key: const ValueKey('change-password-error'),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: AppColors.danger,
                  ),
                ),
              ],
              const SizedBox(height: 28),
              ElevatedButton(
                key: const ValueKey('change-password-submit'),
                onPressed: _isSaving ? null : _save,
                child: _isSaving
                    ? ZefanyaLoader(
                        size: 18,
                        color: AppColors.textOnDark,
                        strokeWidth: 4,
                      )
                    : const Text('Update Password'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _fieldLabel(ThemeData theme, String label) => Text(
        label,
        style: theme.textTheme.labelLarge,
      );

  Widget _revealButton({
    required bool visible,
    required VoidCallback onPressed,
  }) =>
      IconButton(
        tooltip: visible ? 'Show password' : 'Hide password',
        icon: Icon(
          visible ? Icons.visibility_outlined : Icons.visibility_off_outlined,
        ),
        onPressed: onPressed,
      );
}
