/// Named MikrotikRouter (not Router) to avoid colliding with Flutter SDK's
/// own `Router` widget class.
class MikrotikRouter {
  final int id;
  final String name;
  final String nasIdentifier;
  final String publicToken;

  MikrotikRouter({
    required this.id,
    required this.name,
    required this.nasIdentifier,
    required this.publicToken,
  });

  factory MikrotikRouter.fromJson(Map<String, dynamic> json) {
    return MikrotikRouter(
      id: json['id'] as int,
      name: json['name'] as String,
      nasIdentifier: json['nas_identifier'] as String,
      publicToken: json['public_token'] as String,
    );
  }
}

/// The result of POST /api/router-provisioning -- the router record plus
/// the one-time bootstrap script text. This app never renders the script
/// in any widget; it only ever gets executed programmatically against the
/// router's own local REST API, then discarded.
class RouterProvisioningResult {
  final MikrotikRouter router;
  final String bootstrapScript;

  RouterProvisioningResult({required this.router, required this.bootstrapScript});

  factory RouterProvisioningResult.fromJson(Map<String, dynamic> json) {
    return RouterProvisioningResult(
      router: MikrotikRouter.fromJson(json['router'] as Map<String, dynamic>),
      bootstrapScript: json['bootstrap_script'] as String,
    );
  }
}

/// GET /api/router-provisioning/{id}/status -- a cheap, DB-only read; the
/// real work happens in the background via the existing
/// hotspot:auto-provision-routers scheduled job once the tunnel comes up.
class RouterProvisioningStatus {
  final DateTime? bootstrapPushedAt;
  final DateTime? autoProvisionedAt;
  final bool isOnline;

  RouterProvisioningStatus({
    required this.bootstrapPushedAt,
    required this.autoProvisionedAt,
    required this.isOnline,
  });

  factory RouterProvisioningStatus.fromJson(Map<String, dynamic> json) {
    return RouterProvisioningStatus(
      bootstrapPushedAt: json['bootstrap_pushed_at'] != null
          ? DateTime.tryParse(json['bootstrap_pushed_at'] as String)
          : null,
      autoProvisionedAt: json['auto_provisioned_at'] != null
          ? DateTime.tryParse(json['auto_provisioned_at'] as String)
          : null,
      isOnline: json['is_online'] as bool? ?? false,
    );
  }
}
