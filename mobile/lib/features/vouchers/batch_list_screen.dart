import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../api/api_client.dart';
import '../../auth/auth_controller.dart';
import '../../models/voucher_batch.dart';
import 'batch_detail_screen.dart';
import 'create_voucher_screen.dart';
import 'voucher_providers.dart';

class BatchListScreen extends ConsumerWidget {
  const BatchListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final batchesAsync = ref.watch(voucherBatchesProvider);
    final auth = ref.watch(authControllerProvider);
    // Mirrors the web sidebar's own canAccessRoute() habit of hiding, not
    // just rejecting, an action a staff member's permissions don't cover --
    // see AppUser.canManageVouchers' own docblock.
    final canManageVouchers = auth.user?.canManageVouchers ?? false;

    return Scaffold(
      appBar: AppBar(
        title: Text(auth.user?.tenant?.companyName ?? 'Voucher batches'),
        actions: [
          IconButton(
            icon: const Icon(Icons.logout),
            tooltip: 'Sign out',
            onPressed: () => ref.read(authControllerProvider.notifier).logout(),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async => ref.invalidate(voucherBatchesProvider),
        child: batchesAsync.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (error, _) => Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Text(
                error is ApiException && error.statusCode == 403
                    ? 'Your account does not have permission to manage vouchers. Ask an admin to grant it.'
                    : 'Could not load batches: $error',
                textAlign: TextAlign.center,
              ),
            ),
          ),
          data: (batches) {
            if (batches.isEmpty) {
              return Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text(
                    canManageVouchers
                        ? 'No voucher batches yet.\nTap + to create your first one.'
                        : 'No voucher batches yet.',
                    textAlign: TextAlign.center,
                  ),
                ),
              );
            }

            return ListView.separated(
              itemCount: batches.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (context, index) => _BatchTile(batch: batches[index]),
            );
          },
        ),
      ),
      floatingActionButton: canManageVouchers
          ? FloatingActionButton(
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => const CreateVoucherScreen()),
              ),
              child: const Icon(Icons.add),
            )
          : null,
    );
  }
}

class _BatchTile extends StatelessWidget {
  const _BatchTile({required this.batch});

  final VoucherBatch batch;

  @override
  Widget build(BuildContext context) {
    final dateFormat = DateFormat.yMMMd();
    final createdAt = batch.createdAt != null ? DateTime.tryParse(batch.createdAt!) : null;
    final isVoid = batch.status == 'void';

    return ListTile(
      leading: CircleAvatar(
        backgroundColor: isVoid
            ? Theme.of(context).colorScheme.surfaceContainerHighest
            : Theme.of(context).colorScheme.primaryContainer,
        child: Text('${batch.quantity}', style: const TextStyle(fontWeight: FontWeight.bold)),
      ),
      title: Text(batch.name),
      subtitle: Text(
        '${batch.shop.name} • ${batch.package.name}\n'
        '${batch.quantity} codes'
        '${createdAt != null ? ' • created ${dateFormat.format(createdAt)}' : ''}'
        '${isVoid ? ' • VOID' : ''}',
      ),
      isThreeLine: true,
      trailing: const Icon(Icons.chevron_right),
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => BatchDetailScreen(batchId: batch.id)),
      ),
    );
  }
}
