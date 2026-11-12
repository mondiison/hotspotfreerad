import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../api/api_client.dart';
import '../../models/package_plan.dart';
import '../../models/shop.dart';
import 'batch_detail_screen.dart';
import 'voucher_providers.dart';
import 'voucher_repository.dart';

class CreateVoucherScreen extends ConsumerStatefulWidget {
  const CreateVoucherScreen({super.key});

  @override
  ConsumerState<CreateVoucherScreen> createState() => _CreateVoucherScreenState();
}

class _CreateVoucherScreenState extends ConsumerState<CreateVoucherScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController(text: 'Voucher batch');
  final _quantityController = TextEditingController(text: '10');
  final _codeLengthController = TextEditingController(text: '8');
  final _prefixController = TextEditingController();
  final _notesController = TextEditingController();

  Shop? _selectedShop;
  PackagePlan? _selectedPackage;
  bool _submitting = false;
  String? _error;

  @override
  void dispose() {
    _nameController.dispose();
    _quantityController.dispose();
    _codeLengthController.dispose();
    _prefixController.dispose();
    _notesController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_selectedShop == null || _selectedPackage == null) {
      setState(() => _error = 'Pick a shop and a package first.');
      return;
    }

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      final batch = await ref.read(voucherRepositoryProvider).createBatch(
            shopId: _selectedShop!.id,
            packageId: _selectedPackage!.id,
            name: _nameController.text.trim(),
            quantity: int.parse(_quantityController.text),
            codeLength: int.parse(_codeLengthController.text),
            prefix: _prefixController.text.trim(),
            notes: _notesController.text.trim(),
          );

      ref.invalidate(voucherBatchesProvider);

      if (!mounted) return;
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => BatchDetailScreen(batchId: batch.id)),
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
      appBar: AppBar(title: const Text('New voucher batch')),
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
                  DropdownButtonFormField<Shop>(
                    initialValue: _selectedShop,
                    decoration: const InputDecoration(labelText: 'Shop', border: OutlineInputBorder()),
                    items: shops
                        .map((shop) => DropdownMenuItem(value: shop, child: Text(shop.name)))
                        .toList(),
                    onChanged: (shop) => setState(() {
                      _selectedShop = shop;
                      _selectedPackage = null;
                    }),
                    validator: (value) => value == null ? 'Pick a shop' : null,
                  ),
                  const SizedBox(height: 16),
                  DropdownButtonFormField<PackagePlan>(
                    initialValue: _selectedPackage,
                    decoration: const InputDecoration(labelText: 'Package', border: OutlineInputBorder()),
                    items: (_selectedShop?.packages ?? [])
                        .map((package) => DropdownMenuItem(
                              value: package,
                              child: Text('${package.name} (${package.currency} ${package.price.toStringAsFixed(0)})'),
                            ))
                        .toList(),
                    onChanged: _selectedShop == null ? null : (package) => setState(() => _selectedPackage = package),
                    validator: (value) => value == null ? 'Pick a package' : null,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _nameController,
                    decoration: const InputDecoration(labelText: 'Batch name', border: OutlineInputBorder()),
                    validator: (value) => (value == null || value.isEmpty) ? 'Required' : null,
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                        child: TextFormField(
                          controller: _quantityController,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(labelText: 'Quantity', border: OutlineInputBorder()),
                          validator: (value) {
                            final n = int.tryParse(value ?? '');
                            if (n == null || n < 1 || n > 500) return '1-500';
                            return null;
                          },
                        ),
                      ),
                      const SizedBox(width: 16),
                      Expanded(
                        child: TextFormField(
                          controller: _codeLengthController,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(labelText: 'Code length', border: OutlineInputBorder()),
                          validator: (value) {
                            final n = int.tryParse(value ?? '');
                            if (n == null || n < 6 || n > 16) return '6-16';
                            return null;
                          },
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _prefixController,
                    decoration: const InputDecoration(
                      labelText: 'Prefix (optional)',
                      border: OutlineInputBorder(),
                      helperText: 'Letters, numbers, - and _ only',
                    ),
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _notesController,
                    maxLines: 2,
                    decoration: const InputDecoration(labelText: 'Notes (optional)', border: OutlineInputBorder()),
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
                        : const Text('Generate vouchers'),
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
