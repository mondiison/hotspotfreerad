import 'package:app_settings/app_settings.dart';
import 'package:flutter/material.dart';

import '../../models/mikrotik_router.dart';
import 'provisioning_screen.dart';

/// Step 2 of 4 -- the one deliberately manual step in this whole flow (see
/// CLAUDE.md's "zero-touch phone-to-router provisioning" entry for why: a
/// fully automated native Wi-Fi join/leave was considered and rejected as
/// the single biggest engineering risk in this feature). The admin joins
/// the router's own factory Wi-Fi themselves via the OS, same as joining
/// any other network -- this screen only gives instructions and a
/// shortcut button.
class WifiConnectScreen extends StatelessWidget {
  const WifiConnectScreen({super.key, required this.provisioningResult});

  final RouterProvisioningResult provisioningResult;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Connect to the router')),
      body: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Step 2 of 4 -- join the router\'s own Wi-Fi',
              style: TextStyle(fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 16),
            const _InstructionStep(
              number: '1',
              text: 'Power on the router and wait about a minute for it to boot up.',
            ),
            const _InstructionStep(
              number: '2',
              text: 'Find the Wi-Fi network name printed on a label on the router itself.',
            ),
            const _InstructionStep(
              number: '3',
              text: 'Open your phone\'s Wi-Fi settings and connect to that network.',
            ),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: () => AppSettings.openAppSettings(type: AppSettingsType.wifi),
              icon: const Icon(Icons.wifi),
              label: const Text('Open Wi-Fi settings'),
            ),
            const Spacer(),
            Text(
              'Once connected, come back here and tap Continue.',
              style: Theme.of(context).textTheme.bodySmall,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            ElevatedButton(
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => ProvisioningScreen(provisioningResult: provisioningResult)),
              ),
              child: const Text('I\'m connected -- continue'),
            ),
          ],
        ),
      ),
    );
  }
}

class _InstructionStep extends StatelessWidget {
  const _InstructionStep({required this.number, required this.text});

  final String number;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CircleAvatar(radius: 14, child: Text(number, style: const TextStyle(fontSize: 13))),
          const SizedBox(width: 12),
          Expanded(child: Text(text)),
        ],
      ),
    );
  }
}
