class Voucher {
  final int id;
  final String code;
  final String status;
  final double? saleAmount;
  final String? soldAt;
  final String? usedAt;

  Voucher({
    required this.id,
    required this.code,
    required this.status,
    this.saleAmount,
    this.soldAt,
    this.usedAt,
  });

  factory Voucher.fromJson(Map<String, dynamic> json) {
    return Voucher(
      id: json['id'] as int,
      code: json['code'] as String,
      status: json['status'] as String,
      saleAmount: (json['sale_amount'] as num?)?.toDouble(),
      soldAt: json['sold_at'] as String?,
      usedAt: json['used_at'] as String?,
    );
  }
}
