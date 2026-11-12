import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:dio/io.dart';
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

  Future<RouterProvisioningResult> createRouter({required int shopId, required String name}) async {
    final client = _ref.read(apiClientProvider);
    final response = await client.post('/router-provisioning', data: {
      'shop_id': shopId,
      'name': name,
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

  /// Confirms the phone can actually reach a RouterOS 7 device at the
  /// factory address, before attempting to push anything to it. Returns
  /// the detected board name/version so the UI can echo it back to the
  /// admin as a sanity check, mirroring RouterOsConnectionService::
  /// testConnection()'s existing identity-echo convention on the backend.
  Future<({String boardName, String version})> detectFactoryRouter() async {
    final dio = _localRouterDio();

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
  /// factory `admin` user (blank password, RouterOS's own out-of-box
  /// default) -- never the mmsradius-api user this app provisions and
  /// manages long-term, which deliberately never gets REST access at all.
  Future<void> pushBootstrapScript(String script) async {
    final dio = _localRouterDio();
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

    final data = e.response?.data;
    final detail = (data is Map && data['detail'] is String) ? data['detail'] as String : e.message;
    return 'The router rejected this request: $detail';
  }

  /// A separate Dio instance from the app's main ApiClient on purpose --
  /// this one only ever talks to the router's own local IP over a self-
  /// signed HTTPS certificate RouterOS generates itself, which the main
  /// API client (talking to the real HotspotFreeRAD backend) must never
  /// be relaxed to accept.
  Dio _localRouterDio() {
    final dio = Dio(BaseOptions(
      baseUrl: 'https://$factoryRouterHost',
      connectTimeout: const Duration(seconds: 6),
      receiveTimeout: const Duration(seconds: 10),
    ));

    dio.options.headers['Authorization'] =
        'Basic ${base64Encode(utf8.encode('admin:'))}';

    final adapter = IOHttpClientAdapter();
    adapter.createHttpClient = () {
      final client = HttpClient();
      // RouterOS's own self-signed cert -- scoped to exactly this one
      // local-network client, never the app's main API Dio instance.
      client.badCertificateCallback = (cert, host, port) => host == factoryRouterHost;
      return client;
    };
    dio.httpClientAdapter = adapter;

    return dio;
  }
}
