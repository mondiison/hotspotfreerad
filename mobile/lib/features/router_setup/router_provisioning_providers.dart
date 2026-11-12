import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/mikrotik_router.dart';
import 'router_provisioning_repository.dart';

export '../vouchers/voucher_providers.dart' show shopsProvider;

/// Polls the status endpoint every few seconds while
/// waiting_for_router_screen.dart is on screen -- a plain Timer.periodic
/// driving a Riverpod StreamProvider, since wire:poll is a Livewire-only
/// mechanism with no Flutter equivalent. autoDispose means polling stops
/// the moment the admin navigates away.
final routerProvisioningStatusProvider =
    StreamProvider.autoDispose.family<RouterProvisioningStatus, int>((ref, routerId) {
  final repository = ref.read(routerProvisioningRepositoryProvider);
  late final StreamController<RouterProvisioningStatus> controller;
  Timer? timer;

  Future<void> poll() async {
    try {
      controller.add(await repository.fetchStatus(routerId));
    } catch (error, stackTrace) {
      controller.addError(error, stackTrace);
    }
  }

  controller = StreamController<RouterProvisioningStatus>(
    onListen: () {
      poll();
      timer = Timer.periodic(const Duration(seconds: 5), (_) => poll());
    },
    onCancel: () => timer?.cancel(),
  );

  ref.onDispose(() {
    timer?.cancel();
    controller.close();
  });

  return controller.stream;
});
