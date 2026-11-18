/// Named MikrotikRouter (not Router) to avoid colliding with Flutter SDK's
/// own `Router` widget class.
///
/// Shared by two different backend responses: POST /api/router-provisioning
/// (which returns nasIdentifier/publicToken, used right after creating a
/// router) and GET /api/routers (the remote-access feature's router picker,
/// which returns shopName/isOnline instead -- see
/// Http\Resources\Api\RouterResource, which deliberately omits every
/// technical/secret field). Every field from the provisioning response is
/// nullable here so one model can parse either shape without throwing.
class MikrotikRouter {
  final int id;
  final String name;
  final String? nasIdentifier;
  final String? publicToken;
  final String tunnelMode;
  final String? shopName;
  final bool isOnline;

  MikrotikRouter({
    required this.id,
    required this.name,
    this.nasIdentifier,
    this.publicToken,
    required this.tunnelMode,
    this.shopName,
    this.isOnline = false,
  });

  /// Whether this router needs the extra ZeroTier node-ID registration
  /// step after the bootstrap push -- see ProvisioningScreen and
  /// RouterProvisioningRepository.fetchZeroTierNodeId()/saveZeroTierNodeId().
  bool get usesZeroTier => tunnelMode == 'wireguard_zerotier' || tunnelMode == 'zerotier';

  factory MikrotikRouter.fromJson(Map<String, dynamic> json) {
    return MikrotikRouter(
      id: json['id'] as int,
      name: json['name'] as String,
      nasIdentifier: json['nas_identifier'] as String?,
      publicToken: json['public_token'] as String?,
      tunnelMode: json['tunnel_mode'] as String? ?? 'wireguard',
      shopName: json['shop_name'] as String?,
      isOnline: json['is_online'] as bool? ?? false,
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
