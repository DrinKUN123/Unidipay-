import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'screens/home_shell.dart';
import 'screens/login_screen.dart';
import 'services/api_service.dart';
import 'services/app_state.dart';
import 'theme/app_theme.dart';

void main() {
  runApp(const UniDiPayMobileApp());
}

class UniDiPayMobileApp extends StatefulWidget {
  const UniDiPayMobileApp({super.key});

  @override
  State<UniDiPayMobileApp> createState() => _UniDiPayMobileAppState();
}

class _UniDiPayMobileAppState extends State<UniDiPayMobileApp> {
  late final AppState _appState;

  @override
  void initState() {
    super.initState();
    _appState = AppState(ApiService())..initialize();
  }

  @override
  void dispose() {
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider<AppState>(
      create: (_) => _appState,
      child: MaterialApp(
        title: 'UniDiPay Mobile',
        debugShowCheckedModeBanner: false,
        theme: AppTheme.light,
        home: const AuthGate(),
      ),
    );
  }
}

class AuthGate extends StatelessWidget {
  const AuthGate({super.key});

  @override
  Widget build(BuildContext context) {
    final state = context.watch<AppState>();
    final gateKey = state.isLoading
        ? const ValueKey('auth-loading')
        : state.isAuthenticated
            ? const ValueKey('auth-home')
            : const ValueKey('auth-login');

    if (state.isLoading) {
      return const KeyedSubtree(
        key: ValueKey('auth-loading'),
        child: _LaunchSplash(),
      );
    }

    if (!state.isAuthenticated) {
      return const KeyedSubtree(
        key: ValueKey('auth-login'),
        child: LoginScreen(),
      );
    }

    return KeyedSubtree(
      key: gateKey,
      child: const HomeShell(),
    );
  }
}

class _LaunchSplash extends StatelessWidget {
  const _LaunchSplash();

  @override
  Widget build(BuildContext context) {
    final colorScheme = Theme.of(context).colorScheme;
    final textTheme = Theme.of(context).textTheme;

    return Scaffold(
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              colorScheme.primaryContainer.withValues(alpha: 0.9),
              colorScheme.surface,
              colorScheme.secondaryContainer.withValues(alpha: 0.35),
            ],
          ),
        ),
        child: Center(
          child: TweenAnimationBuilder<double>(
            tween: Tween(begin: 0, end: 1),
            duration: const Duration(milliseconds: 700),
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
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 124,
                  height: 124,
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(28),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withValues(alpha: 0.08),
                        blurRadius: 24,
                        offset: const Offset(0, 10),
                      ),
                    ],
                  ),
                  child: Image.asset(
                    'assets/images/unidipay_logo.png',
                    fit: BoxFit.contain,
                  ),
                ),
                const SizedBox(height: 18),
                Text(
                  'UniDiPay',
                  style: textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w900,
                    color: colorScheme.primary,
                    letterSpacing: 0.4,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  'Cashless campus payments',
                  style: textTheme.bodyMedium?.copyWith(
                    color: colorScheme.onSurface,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 22),
                const SizedBox(
                  width: 26,
                  height: 26,
                  child: CircularProgressIndicator(strokeWidth: 2.3),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
