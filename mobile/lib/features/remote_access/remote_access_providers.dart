import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/mikrotik_router.dart';
import 'remote_access_repository.dart';

final remoteAccessRoutersProvider = FutureProvider.autoDispose<List<MikrotikRouter>>((ref) {
  return ref.read(remoteAccessRepositoryProvider).fetchRouters();
});
