import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../auth/auth_controller.dart';
import '../../models/mikrotik_router.dart';

final routerProvisioningRepositoryProvider = Provider<RouterProvisioningRepository>((ref) {
  return RouterProvisioningRepository(ref);
});

/// Thrown by [RouterProvisioningRepository.pushBootstrapScript] -- distinct
/// from ApiClient's ApiException, since none of these failures ever reach
/// the HotspotFreeRAD backend at all; they're all about the phone's own,
/// separate connection to the router's local network.
class LocalRouterException implements Exception {
  final String message;
  LocalRouterException(this.message);

  @override
  String toString() => message;
}

/// The one place this app talks to a router directly instead of through
/// the HotspotFreeRAD backend -- see CLAUDE.md's "zero-touch phone-to-
/// router provisioning" entry: the backend can never reach a factory-
/// default router's own local network at all, only a phone physically
/// joined to it can, so this one-time push has to run here.
class RouterProvisioningRepository {
  final Ref _ref;

  /// RouterOS's own factory-default local LAN address. Not yet confirmed
  /// against real hardware to be universal across every MikroTik board --
  /// see the plan's own "open items to confirm against one real device"
  /// list. If a future board ships a different default, this is the one
  /// constant that would need to become admin-editable.
  static const String factoryRouterHost = '192.168.88.1';

  RouterProvisioningRepository(this._ref);

  /// [tunnelMode] mirrors the web wizard's own three options
  /// (`wireguard`/`wireguard_zerotier`/`zerotier`) -- relevant here
  /// specifically for a router behind CGNAT, where raw WireGuard can
  /// never form a tunnel at all and ZeroTier's own NAT traversal is the
  /// only way the router will ever actually come online.
  Future<RouterProvisioningResult> createRouter({
    required int shopId,
    required String name,
    String tunnelMode = 'wireguard',
  }) async {
    final client = _ref.read(apiClientProvider);
    final response = await client.post('/router-provisioning', data: {
      'shop_id': shopId,
      'name': name,
      'tunnel_mode': tunnelMode,
    });
    return RouterProvisioningResult.fromJson(response);
  }

  Future<void> markBootstrapPushed(int routerId) async {
    final client = _ref.read(apiClientProvider);
    await client.post('/router-provisioning/$routerId/mark-pushed');
  }

  Future<RouterProvisioningStatus> fetchStatus(int routerId) async {
    final client = _ref.read(apiClientProvider);
    final response = await client.get('/router-provisioning/$routerId/status');
    return RouterProvisioningStatus.fromJson(response);
  }

  /// Reads the router's own ZeroTier node identity straight off its local
  /// REST API -- the same information `/zerotier print` shows on the
  /// console, which this app used to need a human to read by hand and
  /// relay back (confirmed live 2026-11-13: that manual relay-then-SSH
  /// path is real friction a tenant_admin can't even complete themselves,
  /// since SSH access to the Pi was never part of this role). Called right
  /// after [pushBootstrapScript] succeeds, over the same local connection,
  /// only when the router's tunnel_mode actually uses ZeroTier -- see
  /// MikrotikRouter.usesZeroTier.
  ///
  /// Best-effort: returns null rather than throwing on any failure (a
  /// router that isn't using ZeroTier at all, or one where the identity
  /// genuinely never becomes ready) -- this step is a nice-to-have on top
  /// of an already-successful push, not something that should turn a
  /// successful bootstrap into a reported failure.
  ///
  /// Confirmed live (2026-11-13): a single immediate read right after the
  /// bootstrap script finishes running is not enough. `/zerotier enable
  /// zt1` + `/zerotier interface add` (inside that script) both return
  /// control to RouterOS almost instantly, while the ZeroTier daemon
  /// itself keeps generating/writing the node's identity in the
  /// background for a few seconds afterward -- the exact same
  /// "returns before RouterOS has actually finished" timing this app's
  /// own backend already had to work around on this same menu (the
  /// `:delay 3s` MikroTikProvisioningService::zeroTierLines() inserts
  /// after `/zerotier interface add`, which this one bootstrap run never
  /// got, since that delay line is only emitted once a ZeroTier IP is
  /// already saved -- and the IP is only ever saved *after* this exact
  /// call successfully reads the identity, so there's no way to have both
  /// at once). A real router was confirmed stuck on `ACCESS_DENIED` with
  /// zero node ID ever saved as a direct result -- the identity existed
  /// on the router the whole time, this call just never waited long
  /// enough to see it. Retries a handful of times with a short pause
  /// between attempts instead of one immediate try.
  Future<String?> fetchZeroTierNodeId(String adminPassword) async {
    final dio = _localRouterDio(adminPassword);

    for (var attempt = 0; attempt < 8; attempt++) {
      if (attempt > 0) {
        await Future.delayed(const Duration(seconds: 2));
      }

      try {
        final response = await dio.get('/rest/zerotier');
        final rows = response.data;
        if (rows is! List || rows.isEmpty) continue;

        final row = rows.first as Map<String, dynamic>;
        // Same field preference as the web admin's own
        // RoutersIndex::fetchZeroTierNodeId() -- identity.public over
        // identity, purely so a parsing failure never has private key
        // material to leak. The node's own ID is the part before the
        // first ":" in either field.
        final identity = (row['identity.public'] as String?) ?? (row['identity'] as String?);
        if (identity == null || identity.isEmpty) continue;

        final nodeId = identity.split(':').first;
        if (nodeId.isNotEmpty) return nodeId;
      } on DioException {
        // Keep retrying -- a router that's mid-reboot or briefly
        // unresponsive right after a big script run is exactly the
        // transient case this loop exists for.
      }
    }

    return null;
  }

  /// Hands the node ID discovered above to the backend, which -- running
  /// on the real Pi in production -- can authorize it on the self-hosted
  /// ZeroTier controller directly (ZeroTierControllerService's calls to
  /// 127.0.0.1:9993 only ever succeed from there) and allocate a free
  /// 10.9.0.x address, closing the exact gap that used to need a human
  /// with Pi shell access. Best-effort in the same spirit as
  /// [fetchZeroTierNodeId] -- a failure here still leaves
  /// hotspot:auto-provision-routers' own 5-minute retry as a safety net
  /// once an admin fixes things some other way.
  ///
  /// Returns the ZeroTier IP the backend just allocated (or already had
  /// saved) for this router -- the caller needs it for [addZeroTierAddress]
  /// right after this, since the backend's own attempt to bind that same
  /// address live (inside this same call, via
  /// RouterOsConnectionService::syncZeroTierNetworkMembership()) is
  /// structurally unable to succeed for a ZeroTier-only router with no
  /// address bound yet -- see that method's own docblock. See
  /// [addZeroTierAddress] for why the phone has to do this instead.
  Future<String?> registerZeroTierNodeId(int routerId, String nodeId) async {
    final client = _ref.read(apiClientProvider);
    final response = await client.post('/router-provisioning/$routerId/zerotier-node-id', data: {'node_id': nodeId});
    return response['zerotier_ip'] as String?;
  }

  /// Binds this router's newly-allocated ZeroTier IP to its `zerotier1`
  /// interface, directly over the phone's own local connection to the
  /// router -- NOT something the backend can do on its own here.
  ///
  /// Confirmed live (2026-11-14): a ZeroTier-only router (no WireGuard
  /// fallback) that just had its node ID registered showed its ZeroTier
  /// interface as genuinely authorized (STATUS: OK on the router itself),
  /// yet every backend attempt to reach it -- "Test Connection",
  /// "Authorize & connect now" -- failed with "Unable to establish socket
  /// session, Connection timed out". Root cause: RouterOsConnectionService::
  /// candidateHosts() for a zerotier-only router can only ever try
  /// zerotier_ip, but nothing had bound that IP to the router's own
  /// zerotier1 interface -- the bootstrap script this app pushed was
  /// generated and run *before* a ZeroTier IP even existed for this router
  /// (one is only allocated once [registerZeroTierNodeId] runs), so
  /// MikroTikProvisioningService::zeroTierLines()'s own IP-assignment line
  /// was never included in what got pushed. And the backend's own live-API
  /// fallback for this (syncZeroTierNetworkMembership()'s "address" step)
  /// is circular for a router with no other working tunnel: it needs a
  /// RouterOS API connection to push the address, but that connection can
  /// only go over the very address it's trying to create. The phone is the
  /// one thing that can still reach this router directly (it's still on
  /// the router's own factory Wi-Fi at this exact point in the flow),
  /// regardless of tunnel state -- so it closes this gap itself rather than
  /// leaving every zero-touch ZeroTier-only router stuck here permanently.
  ///
  /// Best-effort: swallowed by the caller on failure, same as the rest of
  /// this step -- a router that misses this can still be fixed later via
  /// the web admin's "Authorize & connect now" IF something else (a
  /// WireGuard fallback, or a manual script re-paste) ever gives the
  /// backend a reachable path; this call is just what makes the fully
  /// automatic case actually complete.
  Future<void> addZeroTierAddress(String zerotierIp, String adminPassword) async {
    final dio = _localRouterDio(adminPassword);

    for (var attempt = 0; attempt < 5; attempt++) {
      if (attempt > 0) {
        await Future.delayed(const Duration(seconds: 2));
      }

      try {
        await dio.put('/rest/ip/address', data: {
          'address': '$zerotierIp/24',
          'interface': 'zerotier1',
        });
        return;
      } on DioException {
        // The zerotier1 interface itself may not have finished registering
        // yet (the same RouterOS timing quirk [fetchZeroTierNodeId] already
        // retries for) -- keep trying rather than giving up on the first
        // miss.
      }
    }
  }

  /// Confirms the phone can actually reach a RouterOS 7 device at the
  /// factory address, before attempting to push anything to it. Returns
  /// the detected board name/version so the UI can echo it back to the
  /// admin as a sanity check, mirroring RouterOsConnectionService::
  /// testConnection()'s existing identity-echo convention on the backend.
  ///
  /// [adminPassword] is the router's own factory `admin` password --
  /// confirmed live (2026-11-13) that this can NOT be assumed blank: many
  /// current MikroTik boards ship with a unique per-device default
  /// password printed on a label on the unit itself, the same label the
  /// Wi-Fi network name comes from, rather than RouterOS's older
  /// blank-password convention. The admin types it in on the previous
  /// screen; an empty string is still tried for older boards that do use
  /// a blank password.
  Future<({String boardName, String version})> detectFactoryRouter(String adminPassword) async {
    final dio = _localRouterDio(adminPassword);

    try {
      final response = await dio.get('/rest/system/resource');
      final data = response.data as Map<String, dynamic>;
      final version = (data['version'] as String?) ?? '';

      if (!version.startsWith('7.')) {
        throw LocalRouterException(
          'This router is running RouterOS $version. Zero-touch setup needs RouterOS 7 -- '
          'ask your provider to set this router up the usual way instead.',
        );
      }

      return (boardName: (data['board-name'] as String?) ?? 'Unknown board', version: version);
    } on DioException catch (e) {
      throw LocalRouterException(_describeConnectionFailure(e));
    }
  }

  /// Mirrors RouterOsConnectionService::pushFreshInfrastructureScript()'s
  /// own "temporary /system/script object, run it, delete it regardless of
  /// outcome" pattern -- just over REST from the phone instead of the
  /// binary API from the backend. Authenticates as the router's own
  /// factory `admin` user (see [detectFactoryRouter]'s own docblock for
  /// why [adminPassword] can't be assumed blank) -- never the
  /// mmsradius-api user this app provisions and manages long-term, which
  /// deliberately never gets REST access at all.
  Future<void> pushBootstrapScript(String script, String adminPassword) async {
    final dio = _localRouterDio(adminPassword);
    const scriptName = 'mms-zero-touch-bootstrap';

    try {
      final addResponse = await dio.put('/rest/system/script', data: {
        'name': scriptName,
        'source': script,
        // The exact token list MikroTikProvisioningService::scriptObjectPolicy()
        // uses server-side for the same object type -- confirmed live against
        // real RouterOS 7 hardware (see that method's own docblock for the
        // three-round debugging trail that found it): a `/system/script`
        // object's own `policy=` property only accepts this fixed 5-token
        // allow-list, nothing more, and never the deny-prefixed ("!x") shape
        // `/user group` tolerates.
        'policy': 'read,write,test,sensitive,ftp',
      });
      final scriptId = (addResponse.data as Map<String, dynamic>)['.id'] as String;

      try {
        await dio.post('/rest/system/script/run', data: {'.id': scriptId});
      } finally {
        // Cleanup is best-effort -- a router that's already dialing out
        // over its new WireGuard tunnel by the time this runs is a
        // success either way, not a reason to report failure.
        try {
          await dio.delete('/rest/system/script/$scriptId');
        } catch (_) {}
      }
    } on DioException catch (e) {
      throw LocalRouterException(_describeConnectionFailure(e));
    }
  }

  String _describeConnectionFailure(DioException e) {
    if (e.type == DioExceptionType.connectionTimeout || e.type == DioExceptionType.connectionError) {
      return 'Could not reach a router at $factoryRouterHost. Make sure your phone is still '
          'connected to the router\'s own Wi-Fi network, then try again.';
    }

    // Confirmed live (2026-11-13): a factory router with a non-blank
    // default admin password rejects every /rest/ request with a plain
    // 401, not a RouterOS-shaped {detail: ...} body -- worth a specific,
    // actionable message rather than Dio's generic (and, for 401
    // specifically, misleading -- it describes 400, not 401) default text.
    if (e.response?.statusCode == 401) {
      return 'The router rejected this password. Check the label on the router for its real '
          'admin password, go back, and try again.';
    }

    final data = e.response?.data;
    final detail = (data is Map && data['detail'] is String) ? data['detail'] as String : e.message;
    return 'The router rejected this request: $detail';
  }

  /// A separate Dio instance from the app's main ApiClient on purpose --
  /// this one only ever talks to the router's own local IP, which the
  /// main API client (talking to the real HotspotFreeRAD backend) must
  /// never be pointed at.
  ///
  /// Plain HTTP, not HTTPS -- confirmed live against a real factory-reset
  /// router (2026-11-13): a factory-default RouterOS device ships with no
  /// SSL certificate installed, so its `www-ssl` service is never enabled
  /// out of the box -- only `www` (plain HTTP) is reachable, the same
  /// service WebFig itself uses for first-time setup. An earlier version
  /// of this assumed `https://` (RouterOS's own self-signed-cert REST
  /// convention for an *already-configured* router) and failed outright
  /// with a connection error against real hardware -- this was the first
  /// of this feature's two flagged "not yet confirmed against real
  /// hardware" risks resolving in practice. RouterOS's REST API itself
  /// works identically over either transport; only the factory-default
  /// service availability differs.
  Dio _localRouterDio(String adminPassword) {
    final dio = Dio(BaseOptions(
      baseUrl: 'http://$factoryRouterHost',
      connectTimeout: const Duration(seconds: 6),
      receiveTimeout: const Duration(seconds: 10),
    ));

    dio.options.headers['Authorization'] =
        'Basic ${base64Encode(utf8.encode('admin:$adminPassword'))}';

    return dio;
  }
}
