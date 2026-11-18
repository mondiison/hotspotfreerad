import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../api/api_client.dart';
import '../../models/mikrotik_router.dart';
import '../../models/router_credentials.dart';
import 'remote_access_repository.dart';

/// Shows the router's tunnel IP(s) plus a dedicated, read-only RouterOS
/// login for the admin's own Winbox/SSH/WebFig client, and a small form to
/// change the hotspot's broadcast SSID -- the one genuinely safe write this
/// feature offers (see RouterOsConnectionService::applySsid()'s docblock
/// for why this is pushed server-side rather than through the read-only
/// account).
///
/// This screen never establishes a connection to the router itself -- the
/// admin's own device still needs real network reachability to one of
/// these IPs (their own WireGuard connection, or being on-site) to
/// actually use the credentials shown here in their own Winbox/SSH/WebFig
/// client.
class RouterRemoteAccessScreen extends ConsumerStatefulWidget {
  const RouterRemoteAccessScreen({super.key, required this.router});

  final MikrotikRouter router;

  @override
  ConsumerState<RouterRemoteAccessScreen> createState() => _RouterRemoteAccessScreenState();
}

class _RouterRemoteAccessScreenState extends ConsumerState<RouterRemoteAccessScreen> {
  late Future<RouterCredentials> _future;
  final _ssidController = TextEditingController();
  bool _savingSsid = false;

  @override
  void initState() {
    super.initState();
    _future = ref.read(remoteAccessRepositoryProvider).fetchCredentials(widget.router.id);
  }

  @override
  void dispose() {
    _ssidController.dispose();
    super.dispose();
  }

  void _notify(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _copy(String label, String value) async {
    await Clipboard.setData(ClipboardData(text: value));
    _notify('$label copied to clipboard');
  }

  Future<void> _saveSsid() async {
    final ssid = _ssidController.text.trim();
    if (ssid.isEmpty) {
      _notify('Enter a Wi-Fi name first.');
      return;
    }

    setState(() => _savingSsid = true);
    try {
      final result = await ref
          .read(remoteAccessRepositoryProvider)
          .updateSsid(widget.router.id, ssid);
      final success = result['success'] as bool? ?? false;
      _notify(success
          ? 'Wi-Fi name updated.'
          : (result['error'] as String? ?? 'The router rejected this change.'));
    } on ApiException catch (e) {
      _notify(e.displayMessage);
    } finally {
      if (mounted) setState(() => _savingSsid = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.router.name)),
      body: FutureBuilder<RouterCredentials>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            final error = snapshot.error;
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(
                  error is ApiException ? error.displayMessage : 'Could not load credentials: $error',
                  textAlign: TextAlign.center,
                ),
              ),
            );
          }

          final credentials = snapshot.data!;

          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: const [
                          Icon(Icons.visibility_outlined, size: 20),
                          SizedBox(width: 8),
                          Text('Read-only login', style: TextStyle(fontWeight: FontWeight.bold)),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text(
                        credentials.note,
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      const Divider(height: 24),
                      if (credentials.wireguardIp != null)
                        _CredentialRow(
                          label: 'IP (WireGuard)',
                          value: credentials.wireguardIp!,
                          onCopy: () => _copy('IP', credentials.wireguardIp!),
                        ),
                      if (credentials.zerotierIp != null)
                        _CredentialRow(
                          label: 'IP (ZeroTier)',
                          value: credentials.zerotierIp!,
                          onCopy: () => _copy('IP', credentials.zerotierIp!),
                        ),
                      _CredentialRow(
                        label: 'Winbox port',
                        value: '${credentials.winboxPort}',
                        onCopy: () => _copy('Port', '${credentials.winboxPort}'),
                      ),
                      _CredentialRow(
                        label: 'SSH port',
                        value: '${credentials.sshPort}',
                        onCopy: () => _copy('Port', '${credentials.sshPort}'),
                      ),
                      _CredentialRow(
                        label: 'Username',
                        value: credentials.username,
                        onCopy: () => _copy('Username', credentials.username),
                      ),
                      _CredentialRow(
                        label: 'Password',
                        value: credentials.password,
                        onCopy: () => _copy('Password', credentials.password),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 16),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Change Wi-Fi name', style: TextStyle(fontWeight: FontWeight.bold)),
                      const SizedBox(height: 4),
                      Text(
                        'Updates the hotspot\'s broadcast SSID directly -- no Winbox needed.',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: _ssidController,
                        maxLength: 32,
                        decoration: const InputDecoration(
                          labelText: 'New Wi-Fi name',
                          border: OutlineInputBorder(),
                        ),
                      ),
                      const SizedBox(height: 8),
                      SizedBox(
                        width: double.infinity,
                        child: FilledButton(
                          onPressed: _savingSsid ? null : _saveSsid,
                          child: _savingSsid
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(strokeWidth: 2),
                                )
                              : const Text('Save'),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _CredentialRow extends StatelessWidget {
  const _CredentialRow({
    required this.label,
    required this.value,
    required this.onCopy,
  });

  final String label;
  final String value;
  final VoidCallback onCopy;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          SizedBox(
            width: 110,
            child: Text(label, style: Theme.of(context).textTheme.bodySmall),
          ),
          Expanded(
            child: SelectableText(
              value,
              style: const TextStyle(fontFamily: 'monospace'),
            ),
          ),
          IconButton(
            icon: const Icon(Icons.copy, size: 18),
            tooltip: 'Copy $label',
            onPressed: onCopy,
          ),
        ],
      ),
    );
  }
}
