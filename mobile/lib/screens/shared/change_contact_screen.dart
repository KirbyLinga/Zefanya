// lib/screens/shared/change_contact_screen.dart
//
// One flow for both contact credentials, because the shape is the same: prove
// the new destination can receive a code, and only then replace the old one.
// Email and phone differ in the keyboard, the validator and the field name the
// server uses, not in the sequence.
//
// "Current still works" is not decoration. Until the code is accepted, the
// account keeps the old address/number; this screen says so, and the state
// class does not touch AuthState.email/phone until the confirmation returns.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../../config/dev_flags.dart';
import '../../data/api/api_exception.dart';
import '../../state/auth_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/timed_snackbar.dart';
import '../../utils/validators.dart';
import '../../widgets/dirty_guard.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/zefanya_mark.dart';

class ChangeContactScreen extends StatefulWidget {
  const ChangeContactScreen.email({super.key}) : isEmail = true;

  const ChangeContactScreen.phone({super.key}) : isEmail = false;

  final bool isEmail;

  @override
  State<ChangeContactScreen> createState() => _ChangeContactScreenState();
}

class _ChangeContactScreenState extends State<ChangeContactScreen> {
  final _formKey = GlobalKey<FormState>();
  final _valueController = TextEditingController();
  final _codeController = TextEditingController();

  bool _codeSent = false;
  bool _isSaving = false;
  bool _dirty = false;

  String? _fieldError;
  String? _codeError;
  String? _formError;

  String get _valueLabel => widget.isEmail ? 'New Email' : 'New Phone Number';
  String get _title => widget.isEmail ? 'Change Email' : 'Change Phone';
  String get _hint => widget.isEmail
      ? 'you@example.com'
      : '09XXXXXXXXX or +639XXXXXXXXX';
  String get _sentCopy => widget.isEmail
      ? 'We sent a 6-digit code to the new address. Your current email keeps '
          'working until you enter it.'
      : 'We sent a 6-digit code to the new number. Your current number keeps '
          'working until you enter it.';

  void _markDirty() {
    if (!_dirty) setState(() => _dirty = true);
  }

  @override
  void initState() {
    super.initState();
    _valueController.addListener(_markDirty);
    _codeController.addListener(_markDirty);
  }

  @override
  void dispose() {
    _valueController
      ..removeListener(_markDirty)
      ..dispose();
    _codeController
      ..removeListener(_markDirty)
      ..dispose();
    super.dispose();
  }

  String get _normalizedValue => widget.isEmail
      ? _valueController.text.trim()
      : normalizePhMobile(_valueController.text);

  Future<void> _requestCode({bool resend = false}) async {
    setState(() {
      _fieldError = null;
      _formError = null;
      _codeError = null;
    });

    if (!resend && !_formKey.currentState!.validate()) return;

    setState(() => _isSaving = true);
    try {
      final auth = context.read<AuthState>();
      if (widget.isEmail) {
        await auth.requestEmailChange(_normalizedValue);
      } else {
        await auth.requestPhoneChange(_normalizedValue);
      }
    } on ValidationException catch (e) {
      if (!mounted) return;
      setState(() {
        _isSaving = false;
        _fieldError = e.forField(widget.isEmail ? 'email' : 'contact_no');
        _formError = _fieldError == null ? e.message : null;
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
      _codeSent = true;
      _codeController.clear();
    });
    showTimedSnackBar(
      context,
      SnackBar(
        content: Text(
          resend ? 'A new code has been sent.' : _sentCopy,
        ),
      ),
    );
  }

  Future<void> _verify() async {
    setState(() {
      _codeError = null;
      _formError = null;
    });
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isSaving = true);
    try {
      final auth = context.read<AuthState>();
      if (widget.isEmail) {
        await auth.confirmEmailChange(_codeController.text);
      } else {
        await auth.confirmPhoneChange(_codeController.text);
      }
    } on ValidationException catch (e) {
      if (!mounted) return;
      setState(() {
        _isSaving = false;
        _codeError = e.forField('otp') ?? e.message;
      });
      _formKey.currentState!.validate();
      return;
    } on RequestRejectedException catch (e) {
      if (!mounted) return;
      setState(() {
        _isSaving = false;
        // A wrong or expired code is a refusal of this field, not a generic
        // screen failure: the person's next act is to retype or resend.
        _codeError = e.message;
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
      SnackBar(
        content: Text(
          widget.isEmail
              ? 'Email changed to ${_normalizedValue.toLowerCase()}.'
              : 'Phone changed to $_normalizedValue.',
        ),
      ),
    );
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return DirtyGuard(
      hasUnsavedInput: () => _dirty,
      message: widget.isEmail
          ? 'Your email change will be lost.'
          : 'Your phone change will be lost.',
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(),
        body: Form(
          key: _formKey,
          autovalidateMode: AutovalidateMode.onUserInteraction,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
            children: [
              EditorialHeader(
                eyebrow: 'ACCOUNT & SECURITY',
                title: _title,
                padding: const EdgeInsets.fromLTRB(0, 0, 0, 16),
              ),
              Text(
                _codeSent
                    ? _sentCopy
                    : widget.isEmail
                        ? 'Enter the address you want to use. We will send a '
                            'code there before changing anything.'
                        : 'Enter the number you want to use. We will send a '
                            'code there before changing anything.',
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: AppColors.textSecondary,
                  height: 1.45,
                ),
              ),
              const SizedBox(height: 22),
              Text(_valueLabel, style: theme.textTheme.labelLarge),
              const SizedBox(height: 8),
              TextFormField(
                key: const ValueKey('contact-value'),
                controller: _valueController,
                readOnly: _codeSent,
                keyboardType: widget.isEmail
                    ? TextInputType.emailAddress
                    : TextInputType.phone,
                autofillHints: [
                  widget.isEmail
                      ? AutofillHints.email
                      : AutofillHints.telephoneNumber,
                ],
                textInputAction: TextInputAction.done,
                onChanged: (_) {
                  if (_fieldError != null) setState(() => _fieldError = null);
                },
                decoration: InputDecoration(
                  hintText: _hint,
                  prefixIcon: Icon(
                    widget.isEmail
                        ? Icons.alternate_email
                        : Icons.phone_outlined,
                  ),
                ),
                validator: (value) {
                  if (_fieldError != null) return _fieldError;
                  if (widget.isEmail) {
                    final email = (value ?? '').trim();
                    if (email.isEmpty) return 'Enter your new email address';
                    if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$')
                        .hasMatch(email)) {
                      return 'Enter a valid email address';
                    }
                    return null;
                  }
                  return validatePhMobile(normalizePhMobile(value));
                },
              ),
              if (!_codeSent) ...[
                const SizedBox(height: 24),
                ElevatedButton(
                  key: const ValueKey('contact-send-code'),
                  onPressed: _isSaving ? null : () => _requestCode(),
                  child: _isSaving
                      ? ZefanyaLoader(
                          size: 18,
                          color: AppColors.textOnDark,
                          strokeWidth: 4,
                        )
                      : const Text('Send Code'),
                ),
              ] else ...[
                const SizedBox(height: 18),
                Text('Verification Code', style: theme.textTheme.labelLarge),
                const SizedBox(height: 8),
                TextFormField(
                  key: const ValueKey('contact-otp'),
                  controller: _codeController,
                  keyboardType: TextInputType.number,
                  textInputAction: TextInputAction.done,
                  inputFormatters: [
                    FilteringTextInputFormatter.digitsOnly,
                    LengthLimitingTextInputFormatter(6),
                  ],
                  onFieldSubmitted: (_) {
                    if (!_isSaving) _verify();
                  },
                  onChanged: (_) {
                    if (_codeError != null) setState(() => _codeError = null);
                  },
                  decoration: const InputDecoration(
                    hintText: '6-digit code',
                    prefixIcon: Icon(Icons.pin_outlined),
                  ),
                  validator: (value) {
                    if (_codeError != null) return _codeError;
                    final code = (value ?? '').trim();
                    if (code.length != 6) return 'Enter the 6-digit code';
                    return null;
                  },
                ),
                if (kShowDevTools) ...[
                  const SizedBox(height: 6),
                  Text(
                    'Mock build: use $kMockOtpCode.',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: AppColors.textSecondary,
                    ),
                  ),
                ],
                const SizedBox(height: 20),
                ElevatedButton(
                  key: const ValueKey('contact-verify'),
                  onPressed: _isSaving ? null : _verify,
                  child: _isSaving
                      ? ZefanyaLoader(
                          size: 18,
                          color: AppColors.textOnDark,
                          strokeWidth: 4,
                        )
                      : const Text('Verify & Save'),
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: TextButton(
                        key: const ValueKey('contact-change-destination'),
                        onPressed: _isSaving
                            ? null
                            : () => setState(() {
                                  _codeSent = false;
                                  _codeError = null;
                                  _formError = null;
                                }),
                        child: const Text('Change destination'),
                      ),
                    ),
                    Expanded(
                      child: TextButton(
                        key: const ValueKey('contact-resend'),
                        onPressed:
                            _isSaving ? null : () => _requestCode(resend: true),
                        child: const Text('Resend code'),
                      ),
                    ),
                  ],
                ),
              ],
              if (_formError != null) ...[
                const SizedBox(height: 16),
                Text(
                  _formError!,
                  key: const ValueKey('contact-form-error'),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: AppColors.danger,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
