import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../api/api_client.dart';
import '../../models/shop.dart';
import 'router_provisioning_providers.dart';
import 'router_provisioning_repository.dart';
import 'wifi_connect_screen.dart';

/// Step 1 of 4 in the zero-touch router setup flow -- just enough identity
/// (shop + a name) to create a real Router row on the backend, which
/// auto-generates its WireGuard keypair and RouterOS API credentials the
/// same way the web wizard's own Router::creating() hook already does.
/// Nothing here is shown again after this screen -- the next three screens
/// never render the router's own secrets, only progress/status text.
class AddRouterScreen extends ConsumerStatefulWidget {
  const AddRouterScreen({super.key});

  @override
  ConsumerState<AddRouterScreen> createState() => _AddRouterScreenState();
}

class _AddRouterScreenState extends ConsumerState<AddRouterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();

  Shop? _selectedShop;
  bool _submitting = false;
  String? _error;

  @override
  void dispose() {
    _nameController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate() || _selectedShop == null) {
      setState(() => _error = _selectedShop == null ? 'Pick a shop first.' : null);
      return;
    }

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      final result = await ref.read(routerProvisioningRepositoryProvider).createRouter(
            shopId: _selectedShop!.id,
            name: _nameController.text.trim(),
          );

      if (!mounted) return;
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => WifiConnectScreen(provisioningResult: result)),
      );
    } on ApiException catch (e) {
      setState(() => _error = e.displayMessage);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final shopsAsync = ref.watch(shopsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Set up a new router')),
      body: shopsAsync.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, _) => Center(child: Text('Could not load shops: $error')),
        data: (shops) {
          if (shops.isEmpty) {
            return const Center(
              child: Padding(
                padding: EdgeInsets.all(24),
                child: Text(
                  'No shops found for your account yet. Ask an admin to set one up on the web dashboard first.',
                  textAlign: TextAlign.center,
                ),
              ),
            );
          }

          return SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Form(
              key: _formKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text(
                    'Step 1 of 4 -- what is this router for?',
                    style: TextStyle(fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 16),
                  DropdownButtonFormField<Shop>(
                    initialValue: _selectedShop,
                    decoration: const InputDecoration(labelText: 'Shop', border: OutlineInputBorder()),
                    items: shops.map((shop) => DropdownMenuItem(value: shop, child: Text(shop.name))).toList(),
                    onChanged: (shop) => setState(() => _selectedShop = shop),
                    validator: (value) => value == null ? 'Pick a shop' : null,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _nameController,
                    decoration: const InputDecoration(
                      labelText: 'Router name',
                      helperText: 'E.g. "Front desk router" -- just for you to recognize it later',
                      border: OutlineInputBorder(),
                    ),
                    validator: (value) => (value == null || value.trim().isEmpty) ? 'Required' : null,
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 16),
                    Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                  ],
                  const SizedBox(height: 24),
                  ElevatedButton(
                    onPressed: _submitting ? null : _submit,
                    child: _submitting
                        ? const SizedBox(
                            height: 20,
                            width: 20,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                          )
                        : const Text('Continue'),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}
