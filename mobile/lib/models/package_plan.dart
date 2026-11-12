/// Named PackagePlan (not Package) to avoid colliding with Dart's own
/// package-import vocabulary.
class PackagePlan {
  final int id;
  final String name;
  final double price;
  final String currency;
  final int limitUptimeSeconds;
  final int? dataLimitBytes;
  final String speedLimitProfile;
  final String serviceType;

  PackagePlan({
    required this.id,
    required this.name,
    required this.price,
    required this.currency,
    required this.limitUptimeSeconds,
    this.dataLimitBytes,
    required this.speedLimitProfile,
    required this.serviceType,
  });

  factory PackagePlan.fromJson(Map<String, dynamic> json) {
    return PackagePlan(
      id: json['id'] as int,
      name: json['name'] as String,
      price: (json['price'] as num).toDouble(),
      currency: json['currency'] as String,
      limitUptimeSeconds: json['limit_uptime_seconds'] as int,
      dataLimitBytes: json['data_limit_bytes'] as int?,
      speedLimitProfile: json['speed_limit_profile'] as String,
      serviceType: json['service_type'] as String,
    );
  }

  /// Mirrors the web portal's own formatDuration() closure
  /// (resources/views/hotspot/portal.blade.php) so a package reads the
  /// same way on the app as it does to a customer.
  String get durationLabel {
    final seconds = limitUptimeSeconds;
    if (seconds >= 86400) {
      final days = (seconds / 86400).round();
      return '$days day${days == 1 ? '' : 's'}';
    }
    if (seconds >= 3600) {
      final hours = (seconds / 3600).round();
      return '$hours hour${hours == 1 ? '' : 's'}';
    }
    final minutes = (seconds / 60).round().clamp(1, double.infinity).toInt();
    return '$minutes minute${minutes == 1 ? '' : 's'}';
  }

  String get dataLabel {
    final bytes = dataLimitBytes;
    if (bytes == null) return 'Unlimited';
    final gb = bytes / 1073741824;
    return bytes % 1073741824 == 0
        ? '${gb.toStringAsFixed(0)} GB'
        : '${gb.toStringAsFixed(1)} GB';
  }
}
