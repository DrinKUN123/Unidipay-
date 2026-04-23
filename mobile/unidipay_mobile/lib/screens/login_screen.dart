import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';
import 'package:provider/provider.dart';

import '../services/api_service.dart';
import '../services/app_state.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _studentIdController = TextEditingController();
  final _nfcCardController = TextEditingController();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final _setupEmailController = TextEditingController();
  final _setupPasswordController = TextEditingController();
  final _setupConfirmController = TextEditingController();
  bool _useEmailLogin = true;

  String? _passwordPolicyError(String value) {
    if (value.length < 10) {
      return 'Minimum 10 characters';
    }
    if (!RegExp(r'[A-Z]').hasMatch(value)) {
      return 'Add at least one uppercase letter';
    }
    if (!RegExp(r'[a-z]').hasMatch(value)) {
      return 'Add at least one lowercase letter';
    }
    if (!RegExp(r'[0-9]').hasMatch(value)) {
      return 'Add at least one number';
    }
    if (!RegExp(r'[^A-Za-z0-9]').hasMatch(value)) {
      return 'Add at least one special character';
    }
    return null;
  }

  String _extractResetToken(String value) {
    final trimmed = value.trim();
    if (trimmed.isEmpty) {
      return '';
    }

    final uri = Uri.tryParse(trimmed);
    if (uri != null && uri.queryParameters.containsKey('token')) {
      return (uri.queryParameters['token'] ?? '').trim();
    }

    return trimmed;
  }

  @override
  void dispose() {
    _studentIdController.dispose();
    _nfcCardController.dispose();
    _emailController.dispose();
    _passwordController.dispose();
    _setupEmailController.dispose();
    _setupPasswordController.dispose();
    _setupConfirmController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final appState = context.read<AppState>();
    try {
      if (_useEmailLogin) {
        await appState.loginWithEmail(
          email: _emailController.text.trim(),
          password: _passwordController.text,
        );
      } else {
        await appState.loginWithRfid(
          studentId: _studentIdController.text.trim(),
          nfcCardId: _nfcCardController.text.trim(),
        );
      }

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Login successful')),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Login failed: $e')),
      );
    }
  }

  Future<void> _submitInitialCredentials() async {
    if (!_formKey.currentState!.validate()) return;

    final appState = context.read<AppState>();
    try {
      await appState.setInitialCredentials(
        email: _setupEmailController.text.trim(),
        password: _setupPasswordController.text,
        confirmPassword: _setupConfirmController.text,
      );

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Credentials saved. Please continue.')),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Setup failed: $e')),
      );
    }
  }

  Future<void> _showForgotPasswordDialog() async {
    final emailCtrl = TextEditingController();
    final appState = context.read<AppState>();
    try {
      final requested = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Forgot Password'),
          content: TextField(
            controller: emailCtrl,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(
              labelText: 'Registered email',
              hintText: 'name@school.edu',
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Send link'),
            ),
          ],
        ),
      );

      if (requested != true || !mounted) return;

      await appState.requestPasswordReset(email: emailCtrl.text.trim());

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'If your account exists, a reset link was sent to your email.',
          ),
        ),
      );

      await _showResetPasswordDialog();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Unable to request reset: $e')),
      );
    } finally {
      emailCtrl.dispose();
    }
  }

  Future<void> _showResetPasswordDialog() async {
    final tokenCtrl = TextEditingController();
    final passwordCtrl = TextEditingController();
    final confirmCtrl = TextEditingController();
    final dialogFormKey = GlobalKey<FormState>();
    final appState = context.read<AppState>();

    try {
      final proceed = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Reset Password'),
          content: Form(
            key: dialogFormKey,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextFormField(
                  controller: tokenCtrl,
                  decoration: const InputDecoration(
                    labelText: 'Reset token or reset link',
                  ),
                  validator: (v) => (v == null || v.trim().isEmpty)
                      ? 'Reset token is required'
                      : null,
                ),
                const SizedBox(height: 10),
                TextFormField(
                  controller: passwordCtrl,
                  obscureText: true,
                  decoration: const InputDecoration(labelText: 'New password'),
                  validator: (v) =>
                      v == null ? 'Password is required' : _passwordPolicyError(v),
                ),
                const SizedBox(height: 10),
                TextFormField(
                  controller: confirmCtrl,
                  obscureText: true,
                  decoration:
                      const InputDecoration(labelText: 'Confirm password'),
                  validator: (v) =>
                      v != passwordCtrl.text ? 'Passwords do not match' : null,
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () {
                if (dialogFormKey.currentState!.validate()) {
                  Navigator.pop(context, true);
                }
              },
              child: const Text('Reset now'),
            ),
          ],
        ),
      );

      if (proceed != true || !mounted) return;

      final parsedToken = _extractResetToken(tokenCtrl.text);
      await appState.validateResetToken(token: parsedToken);

      await appState.resetPassword(
        token: parsedToken,
        newPassword: passwordCtrl.text,
        confirmPassword: confirmCtrl.text,
      );

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Password reset successful. Sign in with email.'),
        ),
      );
      setState(() {
        _useEmailLogin = true;
      });
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Reset failed: $e')),
      );
    } finally {
      tokenCtrl.dispose();
      passwordCtrl.dispose();
      confirmCtrl.dispose();
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = context.watch<AppState>();
    final loading = state.isLoading;
    final authError = state.authError;
    final colorScheme = Theme.of(context).colorScheme;

    return Scaffold(
      body: LayoutBuilder(
        builder: (context, constraints) {
          final isWide = constraints.maxWidth >= 920;

          return Container(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  colorScheme.primaryContainer.withValues(alpha: 0.95),
                  colorScheme.secondaryContainer.withValues(alpha: 0.25),
                  colorScheme.surface,
                ],
              ),
            ),
            child: SafeArea(
              child: Center(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(20),
                  child: ConstrainedBox(
                    constraints: BoxConstraints(maxWidth: isWide ? 980 : 420),
                    child: isWide
                        ? Row(
                            children: [
                              Expanded(
                                child: TweenAnimationBuilder<double>(
                                  tween: Tween(begin: 0, end: 1),
                                  duration: const Duration(milliseconds: 550),
                                  curve: Curves.easeOutCubic,
                                  builder: (context, value, child) {
                                    return Opacity(
                                      opacity: value,
                                      child: Transform.translate(
                                        offset: Offset(0, 14 * (1 - value)),
                                        child: child,
                                      ),
                                    );
                                  },
                                  child: Padding(
                                    padding: const EdgeInsets.only(right: 18),
                                    child: Container(
                                      padding: const EdgeInsets.all(28),
                                      decoration: BoxDecoration(
                                        color: colorScheme.primaryContainer
                                            .withValues(alpha: 0.5),
                                        borderRadius: BorderRadius.circular(28),
                                      ),
                                      child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          Container(
                                            width: 98,
                                            height: 98,
                                            padding: const EdgeInsets.all(10),
                                            decoration: BoxDecoration(
                                              color: colorScheme.surface
                                                  .withValues(alpha: 0.7),
                                              borderRadius:
                                                  BorderRadius.circular(18),
                                            ),
                                            child: Image.asset(
                                              'assets/images/unidipay_logo.png',
                                              fit: BoxFit.contain,
                                            ),
                                          ),
                                          const SizedBox(height: 14),
                                          Text(
                                            'Pay Smarter On Campus',
                                            style: Theme.of(context)
                                                .textTheme
                                                .headlineSmall
                                                ?.copyWith(
                                                  fontWeight: FontWeight.w800,
                                                ),
                                          ),
                                          const SizedBox(height: 8),
                                          Text(
                                            'Order meals, manage wallet balance, and pay through NFC from one place.',
                                            style: Theme.of(context)
                                                .textTheme
                                                .bodyMedium,
                                          ),
                                        ],
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                              Expanded(
                                child: TweenAnimationBuilder<double>(
                                  tween: Tween(begin: 0, end: 1),
                                  duration: const Duration(milliseconds: 650),
                                  curve: Curves.easeOutCubic,
                                  builder: (context, value, child) {
                                    return Opacity(
                                      opacity: value,
                                      child: Transform.translate(
                                        offset: Offset(0, 16 * (1 - value)),
                                        child: child,
                                      ),
                                    );
                                  },
                                  child: _buildLoginCard(
                                    context,
                                    loading,
                                    authError,
                                    colorScheme,
                                  ),
                                ),
                              ),
                            ],
                          )
                        : TweenAnimationBuilder<double>(
                            tween: Tween(begin: 0, end: 1),
                            duration: const Duration(milliseconds: 600),
                            curve: Curves.easeOutCubic,
                            builder: (context, value, child) {
                              return Opacity(
                                opacity: value,
                                child: Transform.translate(
                                  offset: Offset(0, 18 * (1 - value)),
                                  child: child,
                                ),
                              );
                            },
                            child: _buildLoginCard(
                              context,
                              loading,
                              authError,
                              colorScheme,
                            ),
                          ),
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _buildLoginCard(
    BuildContext context,
    bool loading,
    String? authError,
    ColorScheme colorScheme,
  ) {
    final state = context.watch<AppState>();
    final pendingSetup = state.hasPendingSetup;

    return Card(
      elevation: 12,
      shadowColor: colorScheme.shadow.withValues(alpha: 0.25),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(28),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 24, 20, 20),
        child: Form(
          key: _formKey,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: colorScheme.primary.withValues(alpha: 0.10),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Column(
                  children: [
                    Container(
                      width: 90,
                      height: 90,
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(
                        color: colorScheme.surface.withValues(alpha: 0.85),
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: Image.asset(
                        'assets/images/unidipay_logo.png',
                        fit: BoxFit.contain,
                      ),
                    ),
                    const SizedBox(height: 12),
                    const Text(
                      'UniDiPay',
                      style:
                          TextStyle(fontSize: 26, fontWeight: FontWeight.w800),
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 6),
                    Text(
                      'Student cashless ordering and wallet access',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: colorScheme.onSurfaceVariant,
                          ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 24),
              if (pendingSetup) ...[
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: colorScheme.errorContainer.withValues(alpha: 0.45),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Text(
                    'First-time setup required. Set your email and password to continue.',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: _setupEmailController,
                  keyboardType: TextInputType.emailAddress,
                  textInputAction: TextInputAction.next,
                  decoration: InputDecoration(
                    labelText: 'Email',
                    prefixIcon: const Icon(Icons.email_outlined, size: 20),
                    prefixIconConstraints:
                        const BoxConstraints(minWidth: 44, minHeight: 44),
                    filled: true,
                    fillColor: colorScheme.surfaceContainerHighest
                        .withValues(alpha: 0.55),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(18),
                      borderSide: BorderSide.none,
                    ),
                  ),
                  validator: (v) {
                    if (!pendingSetup) return null;
                    if (v == null || v.trim().isEmpty) {
                      return 'Email is required';
                    }
                    if (!v.contains('@')) return 'Enter a valid email';
                    return null;
                  },
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: _setupPasswordController,
                  obscureText: true,
                  textInputAction: TextInputAction.next,
                  decoration: InputDecoration(
                    labelText: 'Password',
                    prefixIcon: const Icon(Icons.lock_outline, size: 20),
                    prefixIconConstraints:
                        const BoxConstraints(minWidth: 44, minHeight: 44),
                    filled: true,
                    fillColor: colorScheme.surfaceContainerHighest
                        .withValues(alpha: 0.55),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(18),
                      borderSide: BorderSide.none,
                    ),
                  ),
                  validator: (v) {
                    if (!pendingSetup) return null;
                    if (v == null) return 'Password is required';
                    return _passwordPolicyError(v);
                  },
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: _setupConfirmController,
                  obscureText: true,
                  textInputAction: TextInputAction.done,
                  onFieldSubmitted: (_) =>
                      loading ? null : _submitInitialCredentials(),
                  decoration: InputDecoration(
                    labelText: 'Confirm Password',
                    prefixIcon:
                        const Icon(Icons.verified_user_outlined, size: 20),
                    prefixIconConstraints:
                        const BoxConstraints(minWidth: 44, minHeight: 44),
                    filled: true,
                    fillColor: colorScheme.surfaceContainerHighest
                        .withValues(alpha: 0.55),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(18),
                      borderSide: BorderSide.none,
                    ),
                  ),
                  validator: (v) {
                    if (!pendingSetup) return null;
                    if (v != _setupPasswordController.text) {
                      return 'Passwords do not match';
                    }
                    return null;
                  },
                ),
              ] else ...[
                SegmentedButton<bool>(
                  segments: const [
                    ButtonSegment<bool>(
                      value: true,
                      icon: Icon(Icons.alternate_email),
                      label: Text('Email'),
                    ),
                    ButtonSegment<bool>(
                      value: false,
                      icon: Icon(Icons.badge_outlined),
                      label: Text('Student + RFID'),
                    ),
                  ],
                  selected: {_useEmailLogin},
                  onSelectionChanged: (selection) {
                    setState(() {
                      _useEmailLogin = selection.first;
                    });
                  },
                ),
                const SizedBox(height: 14),
                if (_useEmailLogin) ...[
                  TextFormField(
                    controller: _emailController,
                    keyboardType: TextInputType.emailAddress,
                    textInputAction: TextInputAction.next,
                    decoration: InputDecoration(
                      labelText: 'Email',
                      prefixIcon: const Icon(Icons.email_outlined, size: 20),
                      prefixIconConstraints:
                          const BoxConstraints(minWidth: 44, minHeight: 44),
                      filled: true,
                      fillColor: colorScheme.surfaceContainerHighest
                          .withValues(alpha: 0.55),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(18),
                        borderSide: BorderSide.none,
                      ),
                    ),
                    validator: (v) {
                      if (!_useEmailLogin) return null;
                      if (v == null || v.trim().isEmpty) {
                        return 'Email is required';
                      }
                      if (!v.contains('@')) return 'Enter a valid email';
                      return null;
                    },
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: _passwordController,
                    obscureText: true,
                    textInputAction: TextInputAction.done,
                    onFieldSubmitted: (_) => loading ? null : _submit(),
                    decoration: InputDecoration(
                      labelText: 'Password',
                      prefixIcon: const Icon(Icons.lock_outline, size: 20),
                      prefixIconConstraints:
                          const BoxConstraints(minWidth: 44, minHeight: 44),
                      filled: true,
                      fillColor: colorScheme.surfaceContainerHighest
                          .withValues(alpha: 0.55),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(18),
                        borderSide: BorderSide.none,
                      ),
                    ),
                    validator: (v) {
                      if (!_useEmailLogin) return null;
                      if (v == null || v.isEmpty) {
                        return 'Password is required';
                      }
                      return null;
                    },
                  ),
                ] else ...[
                  TextFormField(
                    controller: _studentIdController,
                    textInputAction: TextInputAction.next,
                    decoration: InputDecoration(
                      labelText: 'Student ID',
                      prefixIcon: const Icon(Icons.school_outlined, size: 20),
                      prefixIconConstraints:
                          const BoxConstraints(minWidth: 44, minHeight: 44),
                      filled: true,
                      fillColor: colorScheme.surfaceContainerHighest
                          .withValues(alpha: 0.55),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(18),
                        borderSide: BorderSide.none,
                      ),
                    ),
                    validator: (v) {
                      if (_useEmailLogin) return null;
                      return (v == null || v.trim().isEmpty)
                          ? 'Student ID is required'
                          : null;
                    },
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: _nfcCardController,
                    textInputAction: TextInputAction.done,
                    onFieldSubmitted: (_) => loading ? null : _submit(),
                    decoration: InputDecoration(
                      labelText: 'RFID Card ID',
                      prefixIcon: const Icon(Icons.badge_outlined, size: 20),
                      prefixIconConstraints:
                          const BoxConstraints(minWidth: 44, minHeight: 44),
                      filled: true,
                      fillColor: colorScheme.surfaceContainerHighest
                          .withValues(alpha: 0.55),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(18),
                        borderSide: BorderSide.none,
                      ),
                    ),
                    validator: (v) {
                      if (_useEmailLogin) return null;
                      return (v == null || v.trim().isEmpty)
                          ? 'NFC Card ID is required'
                          : null;
                    },
                  ),
                ],
              ],
              const SizedBox(height: 18),
              FilledButton.icon(
                onPressed: loading
                    ? null
                    : (pendingSetup ? _submitInitialCredentials : _submit),
                icon: loading
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const FaIcon(FontAwesomeIcons.rightToBracket, size: 16),
                label: Text(
                  loading
                      ? (pendingSetup ? 'Saving...' : 'Signing in...')
                      : (pendingSetup ? 'Save Credentials' : 'Login'),
                ),
              ),
              if (!pendingSetup && _useEmailLogin) ...[
                const SizedBox(height: 8),
                TextButton(
                  onPressed: loading ? null : _showForgotPasswordDialog,
                  child: const Text('Forgot password?'),
                ),
              ],
              const SizedBox(height: 14),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: colorScheme.surfaceContainerHighest
                      .withValues(alpha: 0.45),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      'API: ${ApiService.configuredBaseUrl}',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    if (authError != null && authError.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Text(
                        authError,
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          color: colorScheme.error,
                          fontWeight: FontWeight.w600,
                        ),
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
}
