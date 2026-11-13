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
///
/// Also collects the router's factory admin password -- confirmed live
/// (2026-11-13) that this can't be assumed blank the way RouterOS's older
/// convention worked: many current MikroTik boards ship with a unique
/// per-device default password printed on a label on the unit itself
/// (the same label the Wi-Fi name comes from), and reject every /rest/
/// request with a plain 401 otherwise. Left blank by default since older
/// boards do still use a blank password.
class WifiConnectScreen extends StatefulWidget {
  const WifiConnectScreen({super.key, required this.provisioningResult});

  final RouterProvisioningResult provisioningResult;

  @override
  State<WifiConnectScreen> createState() => _WifiConnectScreenState();
}

class _WifiConnectScreenState extends State<WifiConnectScreen> {
  final _passwordController = TextEditingController();

  @override
  void dispose() {
    _passwordController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Connect to the router')),
      // SingleChildScrollView, not a plain Column with a Spacer -- confirmed
      // live (2026-11-13) that the Spacer-based layout overflowed ("BOTTOM
      // OVERFLOWED BY 97 PIXELS") the moment the on-screen keyboard opened
      // for the new password field below, since a Spacer demands the
      // Column's content fit the *reduced* viewport height the keyboard
      // leaves behind. A scrollable body has no such fixed-height demand.
      body: SafeArea(
        child: SingleChildScrollView(
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
              const SizedBox(height: 24),
              TextField(
                controller: _passwordController,
                decoration: const InputDecoration(
                  labelText: 'Router admin password',
                  helperText: 'Usually printed on the same label as the Wi-Fi name. '
                      'Leave blank for older routers with no default password.',
                  helperMaxLines: 2,
                ),
              ),
              const SizedBox(height: 32),
              Text(
                'Once connected, come back here and tap Continue.',
                style: Theme.of(context).textTheme.bodySmall,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 8),
              ElevatedButton(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => ProvisioningScreen(
                      provisioningResult: widget.provisioningResult,
                      routerAdminPassword: _passwordController.text,
                    ),
                  ),
                ),
                child: const Text('I\'m connected -- continue'),
              ),
            ],
          ),
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
