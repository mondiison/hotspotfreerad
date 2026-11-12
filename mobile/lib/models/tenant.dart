class Tenant {
  final int id;
  final String companyName;
  final String? brandColor;
  final String? logoUrl;

  Tenant({
    required this.id,
    required this.companyName,
    this.brandColor,
    this.logoUrl,
  });

  factory Tenant.fromJson(Map<String, dynamic> json) {
    return Tenant(
      id: json['id'] as int,
      companyName: json['company_name'] as String,
      brandColor: json['brand_color'] as String?,
      logoUrl: json['logo_url'] as String?,
    );
  }
}
