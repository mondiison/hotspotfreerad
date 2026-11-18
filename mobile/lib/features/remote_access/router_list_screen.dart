import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../api/api_client.dart';
import 'remote_access_providers.dart';
import 'router_remote_access_screen.dart';

/// The remote-access feature's entry point -- pick which router to view.
/// Reuses GET /api/routers (RouterResource), which deliberately omits
/// every technical/secret field -- this list is safe to show in full
/// regardless of what happens on the next screen.
class RouterListScreen extends ConsumerWidget {
  const RouterListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final routersAsync = ref.watch(remoteAccessRoutersProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Remote access')),
      body: RefreshIndicator(
        onRefresh: () async => ref.invalidate(remoteAccessRoutersProvider),
        child: routersAsync.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (error, _) => Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Text(
                error is ApiException ? error.displayMessage : 'Could not load routers: $error',
                textAlign: TextAlign.center,
              ),
            ),
          ),
          data: (routers) {
            if (routers.isEmpty) {
              return const Center(
                child: Padding(
                  padding: EdgeInsets.all(24),
                  child: Text('No routers yet.', textAlign: TextAlign.center),
                ),
              );
            }

            return ListView.separated(
              itemCount: routers.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (context, index) {
                final router = routers[index];
                return ListTile(
                  leading: Icon(
                    router.isOnline ? Icons.wifi : Icons.wifi_off,
                    color: router.isOnline ? Colors.green : Colors.grey,
                  ),
                  title: Text(router.name),
                  subtitle: Text(router.shopName ?? ''),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => RouterRemoteAccessScreen(router: router)),
                  ),
                );
              },
            );
          },
        ),
      ),
    );
  }
}
