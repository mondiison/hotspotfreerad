import 'tenant.dart';

/// Named AppUser (not User) to avoid colliding with Flutter/Dart's own
/// ambient names once more features land.
class AppUser {
  final int id;
  final String name;
  final String email;
  final String role;
  final List<String>? permissions;
  final Tenant? tenant;

  AppUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    this.permissions,
    this.tenant,
  });

  bool get isSuperAdmin => role == 'super_admin';

  bool get isTenantAdmin => role == 'tenant_admin';

  bool get isTenantStaff => role == 'tenant_staff';

  /// Mirrors User::canAccessRoute() on the backend for the one permission
  /// area Phase 1 cares about -- admins always pass, staff need the key
  /// explicitly in their permissions list. Kept narrow on purpose: a second,
  /// more general client-side permission mirror isn't worth building until
  /// a second permission area (PPPoE/POS/reports) actually needs gating too.
  bool get canManageVouchers =>
      isSuperAdmin || isTenantAdmin || (permissions?.contains('vouchers') ?? false);

  factory AppUser.fromJson(Map<String, dynamic> json) {
    return AppUser(
      id: json['id'] as int,
      name: json['name'] as String,
      email: json['email'] as String,
      role: json['role'] as String,
      permissions: (json['permissions'] as List<dynamic>?)
          ?.map((e) => e as String)
          .toList(),
      tenant: json['tenant'] != null
          ? Tenant.fromJson(json['tenant'] as Map<String, dynamic>)
          : null,
    );
  }
}
