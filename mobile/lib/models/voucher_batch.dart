import 'package_plan.dart';
import 'voucher.dart';

class VoucherBatchShop {
  final int id;
  final String name;

  VoucherBatchShop({required this.id, required this.name});

  factory VoucherBatchShop.fromJson(Map<String, dynamic> json) {
    return VoucherBatchShop(id: json['id'] as int, name: json['name'] as String);
  }
}

class VoucherBatch {
  final int id;
  final String name;
  final String status;
  final int quantity;
  final int codeLength;
  final String? prefix;
  final String? notes;
  final String? createdAt;
  final VoucherBatchShop shop;
  final PackagePlan package;
  final List<Voucher> vouchers;

  VoucherBatch({
    required this.id,
    required this.name,
    required this.status,
    required this.quantity,
    required this.codeLength,
    this.prefix,
    this.notes,
    this.createdAt,
    required this.shop,
    required this.package,
    required this.vouchers,
  });

  factory VoucherBatch.fromJson(Map<String, dynamic> json) {
    return VoucherBatch(
      id: json['id'] as int,
      name: json['name'] as String,
      status: json['status'] as String,
      quantity: json['quantity'] as int,
      codeLength: json['code_length'] as int,
      prefix: json['prefix'] as String?,
      notes: json['notes'] as String?,
      createdAt: json['created_at'] as String?,
      shop: VoucherBatchShop.fromJson(json['shop'] as Map<String, dynamic>),
      package: PackagePlan.fromJson(json['package'] as Map<String, dynamic>),
      vouchers: (json['vouchers'] as List<dynamic>? ?? [])
          .map((e) => Voucher.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }
}
