import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'auth/auth_controller.dart';
import 'auth/auth_state.dart';
import 'features/auth/login_screen.dart';
import 'features/vouchers/batch_list_screen.dart';
import 'theming/app_theme.dart';

void main() {
  runApp(const ProviderScope(child: HotspotFreeRadApp()));
}

/// Routes purely off AuthController's current state -- no named routes or
/// a separate router package, since Phase 1's whole navigation graph is
/// this one state machine (bootstrapping -> login -> batch list) plus a
/// handful of screens pushed with plain Navigator.push from there.
class HotspotFreeRadApp extends ConsumerWidget {
  const HotspotFreeRadApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);

    return MaterialApp(
      title: 'HotspotFreeRAD',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.build(brandColorHex: auth.user?.tenant?.brandColor),
      home: switch (auth.status) {
        AuthStatus.bootstrapping => const _SplashScreen(),
        AuthStatus.unauthenticated => const LoginScreen(),
        AuthStatus.authenticated => const BatchListScreen(),
      },
    );
  }
}

class _SplashScreen extends StatelessWidget {
  const _SplashScreen();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(child: CircularProgressIndicator()),
    );
  }
}
