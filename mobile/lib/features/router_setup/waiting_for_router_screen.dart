import 'package:app_settings/app_settings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/mikrotik_router.dart';
import 'router_provisioning_providers.dart';
import 'router_provisioning_repository.dart';

/// Step 4 of 4 -- switch back to normal Wi-Fi/data, then wait.
///
/// This screen owns the markBootstrapPushed() call deliberately, not
/// provisioning_screen.dart -- when that screen's push just succeeded, the
/// phone was still joined to the router's own isolated factory Wi-Fi,
/// almost certainly with no route back to the real HotspotFreeRAD backend.
/// This screen retries the confirmation call (with a short backoff) until
/// it actually succeeds, which only happens once the admin has genuinely
/// switched back to normal Wi-Fi/mobile data -- so the retry loop doubles
/// as the signal that connectivity is restored, no separate check needed.
///
/// Everything after that confirmation is already fully automatic:
/// hotspot:auto-provision-routers (an existing scheduled job, unchanged by
/// this feature) retries this router every 5 minutes in the background and
/// will succeed the moment its new WireGuard tunnel actually comes up.
/// This screen only polls the cheap, DB-only status endpoint to show that
/// happening -- it never makes a live RouterOS call itself.
class WaitingForRouterScreen extends ConsumerStatefulWidget {
  const WaitingForRouterScreen({super.key, required this.router});

  final MikrotikRouter router;

  @override
  ConsumerState<WaitingForRouterScreen> createState() => _WaitingForRouterScreenState();
}

class _WaitingForRouterScreenState extends ConsumerState<WaitingForRouterScreen> {
  bool _confirmed = false;
  String? _confirmStatus;

  @override
  void initState() {
    super.initState();
    _confirmPushed();
  }

  Future<void> _confirmPushed() async {
    final repository = ref.read(routerProvisioningRepositoryProvider);

    while (mounted && !_confirmed) {
      try {
        await repository.markBootstrapPushed(widget.router.id);
        if (!mounted) return;
        setState(() => _confirmed = true);
        return;
      } catch (_) {
        if (!mounted) return;
        setState(() => _confirmStatus = 'Still waiting for your phone to reconnect...');
        await Future.delayed(const Duration(seconds: 4));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Finishing setup')),
      body: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Step 4 of 4 -- switch back and wait',
              style: TextStyle(fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 16),
            const Text('Switch your phone back to your normal Wi-Fi or mobile data now.'),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: () => AppSettings.openAppSettings(type: AppSettingsType.wifi),
              icon: const Icon(Icons.wifi),
              label: const Text('Open Wi-Fi settings'),
            ),
            const SizedBox(height: 32),
            Expanded(
              child: Center(
                child: _confirmed ? _StatusPoller(router: widget.router) : _ConfirmingPush(status: _confirmStatus),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ConfirmingPush extends StatelessWidget {
  const _ConfirmingPush({required this.status});

  final String? status;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        const CircularProgressIndicator(),
        const SizedBox(height: 16),
        Text(status ?? 'Confirming setup with HotspotFreeRAD...', textAlign: TextAlign.center),
      ],
    );
  }
}

class _StatusPoller extends ConsumerWidget {
  const _StatusPoller({required this.router});

  final MikrotikRouter router;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final statusAsync = ref.watch(routerProvisioningStatusProvider(router.id));

    return statusAsync.when(
      loading: () => const CircularProgressIndicator(),
      error: (error, _) => Text('Could not check status: $error', textAlign: TextAlign.center),
      data: (status) {
        if (status.autoProvisionedAt != null) {
          return Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.check_circle, color: Colors.green, size: 56),
              const SizedBox(height: 16),
              Text('${router.name} is online and ready!', textAlign: TextAlign.center),
              const SizedBox(height: 24),
              ElevatedButton(
                onPressed: () => Navigator.of(context).popUntil((route) => route.isFirst),
                child: const Text('Done'),
              ),
            ],
          );
        }

        return const Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(),
            SizedBox(height: 16),
            Text(
              'Waiting for the router to come online...\nThis can take a minute or two.',
              textAlign: TextAlign.center,
            ),
          ],
        );
      },
    );
  }
}
