import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/shop.dart';
import '../../models/voucher_batch.dart';
import 'voucher_repository.dart';

final shopsProvider = FutureProvider.autoDispose<List<Shop>>((ref) {
  return ref.read(voucherRepositoryProvider).fetchShops();
});

final voucherBatchesProvider = FutureProvider.autoDispose<List<VoucherBatch>>((ref) {
  return ref.read(voucherRepositoryProvider).fetchBatches();
});
