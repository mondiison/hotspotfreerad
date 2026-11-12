import 'package:app_settings/app_settings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/mikrotik_router.dart';
import 'router_provisioning_providers.dart';

/// Step 4 of 4 -- switch back to normal Wi-Fi/data, then wait. Everything
/// from here on is already fully automatic: hotspot:auto-provision-routers
/// (an existing scheduled job, unchanged by this feature) retries this
/// router every 5 minutes in the background and will succeed the moment
/// its new WireGuard tunnel actually comes up. This screen only polls
/// the cheap, DB-only status endpoint to show that happening -- it never
/// makes a live RouterOS call itself.
class WaitingForRouterScreen extends ConsumerWidget {
  const WaitingForRouterScreen({super.key, required this.router});

  final MikrotikRouter router;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final statusAsync = ref.watch(routerProvisioningStatusProvider(router.id));

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
                child: statusAsync.when(
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

                    final waitingOnTunnel = status.bootstrapPushedAt != null;
                    return Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const CircularProgressIndicator(),
                        const SizedBox(height: 16),
                        Text(
                          waitingOnTunnel
                              ? 'Waiting for ${router.name} to come online...\nThis can take a minute or two.'
                              : 'Waiting for setup to be confirmed...',
                          textAlign: TextAlign.center,
                        ),
                      ],
                    );
                  },
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
