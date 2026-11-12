import 'package_plan.dart';

class Shop {
  final int id;
  final String name;
  final String? locationCity;
  final List<PackagePlan> packages;

  Shop({
    required this.id,
    required this.name,
    this.locationCity,
    required this.packages,
  });

  factory Shop.fromJson(Map<String, dynamic> json) {
    return Shop(
      id: json['id'] as int,
      name: json['name'] as String,
      locationCity: json['location_city'] as String?,
      packages: (json['packages'] as List<dynamic>? ?? [])
          .map((e) => PackagePlan.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }
}
