import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../auth/auth_controller.dart';
import '../../models/shop.dart';
import '../../models/voucher_batch.dart';

final voucherRepositoryProvider = Provider<VoucherRepository>((ref) {
  return VoucherRepository(ref);
});

class VoucherRepository {
  final Ref _ref;

  VoucherRepository(this._ref);

  Future<List<Shop>> fetchShops() async {
    final client = _ref.read(apiClientProvider);
    final response = await client.get('/shops');
    final data = response['data'] as List<dynamic>;
    return data.map((e) => Shop.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<VoucherBatch>> fetchBatches({int? shopId}) async {
    final client = _ref.read(apiClientProvider);
    final response = await client.get('/voucher-batches', query: {
      if (shopId != null) 'shop_id': shopId,
    });
    final data = response['data'] as List<dynamic>;
    return data.map((e) => VoucherBatch.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<VoucherBatch> fetchBatch(int id) async {
    final client = _ref.read(apiClientProvider);
    final response = await client.get('/voucher-batches/$id');
    return VoucherBatch.fromJson(response['data'] as Map<String, dynamic>);
  }

  Future<VoucherBatch> createBatch({
    required int shopId,
    required int packageId,
    required String name,
    required int quantity,
    required int codeLength,
    String? prefix,
    String? notes,
  }) async {
    final client = _ref.read(apiClientProvider);
    final response = await client.post('/voucher-batches', data: {
      'shop_id': shopId,
      'package_id': packageId,
      'name': name,
      'quantity': quantity,
      'code_length': codeLength,
      if (prefix != null && prefix.isNotEmpty) 'prefix': prefix,
      if (notes != null && notes.isNotEmpty) 'notes': notes,
    });
    return VoucherBatch.fromJson(response['data'] as Map<String, dynamic>);
  }

  Future<int> voidUnused(int batchId) async {
    final client = _ref.read(apiClientProvider);
    final response = await client.post('/voucher-batches/$batchId/void-unused');
    return response['voided'] as int;
  }

  Future<void> markSold(int voucherId) async {
    final client = _ref.read(apiClientProvider);
    await client.post('/vouchers/$voucherId/mark-sold');
  }
}
