// lib/screens/courier/courier_activation_screen.dart
//
// First sign-in of a WebZefanya Transfer Courier. Logistics issues the login ID
// and a one-time access code from its Couriers page and hands them over in
// person; here the Courier enters both and chooses a password. WebZefanya
// checks everything (`POST /api/courier/activate`); the app stores nothing but
// the resulting session token.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../data/api/api_exception.dart';
import '../../state/auth_state.dart';
import '../../utils/timed_snackbar.dart';

class CourierActivationScreen extends StatefulWidget {
  const CourierActivationScreen({super.key});

  @override
  State<CourierActivationScreen> createState() =>
      _CourierActivationScreenState();
}

class _CourierActivationScreenState extends State<CourierActivationScreen> {
  static const int _minPassword = 8;

  final _formKey = GlobalKey<FormState>();
  final _loginId = TextEditingController();
  final _code = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  bool _busy = false;
  bool _obscure = true;

  @override
  void dispose() {
    _loginId.dispose();
    _code.dispose();
    _password.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy || !_formKey.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await context.read<AuthState>().activateCourier(
        loginId: _loginId.text,
        activationCode: _code.text,
        password: _password.text,
      );
      if (!mounted) return;
      // Signed in: the AuthGate below this route now shows the Courier portal.
      Navigator.of(context).popUntil((route) => route.isFirst);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _busy = false);
      showTimedSnackBar(context, SnackBar(content: Text(_message(e))));
    }
  }

  /// The server answers every wrong ID, code or expiry the same way on
  /// purpose; the app repeats it without guessing which one it was.
  String _message(ApiException e) => switch (e) {
    ForbiddenException() =>
      'Your Courier access is not available right now. Contact your logistics company.',
    _ => e.message,
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Activate Courier app')),
      body: SafeArea(
        child: Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              Text(
                'Enter the login ID and one-time access code your logistics company gave you, then choose your password.',
                style: theme.textTheme.bodyMedium,
              ),
              const SizedBox(height: 20),
              TextFormField(
                key: const ValueKey('activate-login-id'),
                controller: _loginId,
                textCapitalization: TextCapitalization.characters,
                decoration: const InputDecoration(
                  labelText: 'Login ID',
                  hintText: 'ZC-XXXXXXXX',
                ),
                validator: (v) => (v == null || v.trim().isEmpty)
                    ? 'Enter your login ID'
                    : null,
              ),
              const SizedBox(height: 16),
              TextFormField(
                key: const ValueKey('activate-code'),
                controller: _code,
                textCapitalization: TextCapitalization.characters,
                decoration: const InputDecoration(
                  labelText: 'Access code',
                  hintText: 'XXXXX-XXXXX',
                ),
                validator: (v) => (v == null || v.trim().isEmpty)
                    ? 'Enter your access code'
                    : null,
              ),
              const SizedBox(height: 16),
              TextFormField(
                key: const ValueKey('activate-password'),
                controller: _password,
                obscureText: _obscure,
                autofillHints: const [AutofillHints.newPassword],
                decoration: InputDecoration(
                  labelText: 'New password',
                  helperText: 'At least $_minPassword characters',
                  suffixIcon: IconButton(
                    tooltip: _obscure ? 'Show password' : 'Hide password',
                    icon: Icon(
                      _obscure
                          ? Icons.visibility_outlined
                          : Icons.visibility_off_outlined,
                    ),
                    onPressed: () => setState(() => _obscure = !_obscure),
                  ),
                ),
                validator: (v) => (v == null || v.length < _minPassword)
                    ? 'Use at least $_minPassword characters'
                    : null,
              ),
              const SizedBox(height: 16),
              TextFormField(
                key: const ValueKey('activate-confirm'),
                controller: _confirm,
                obscureText: _obscure,
                decoration: const InputDecoration(
                  labelText: 'Confirm password',
                ),
                validator: (v) =>
                    v != _password.text ? 'The passwords do not match' : null,
              ),
              const SizedBox(height: 24),
              ElevatedButton(
                key: const ValueKey('activate-submit'),
                onPressed: _busy ? null : _submit,
                child: _busy
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('Activate and sign in'),
              ),
              const SizedBox(height: 12),
              Text(
                'The code works once. If it expired or was used up, ask your logistics company for a new one.',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
