import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../auth/auth_controller.dart';
import '../../models/mikrotik_router.dart';
import '../../models/router_credentials.dart';

final remoteAccessRepositoryProvider = Provider<RemoteAccessRepository>((ref) {
  return RemoteAccessRepository(ref);
});

/// Talks to the real HotspotFreeRAD backend (the normal ApiClient, NOT the
/// router_setup feature's separate local-router Dio instance) -- every call
/// here goes to mmsradius.com, never directly to a router. See
/// Api\RouterConsoleController for the server side.
class RemoteAccessRepository {
  final Ref _ref;

  RemoteAccessRepository(this._ref);

  Future<List<MikrotikRouter>> fetchRouters() async {
    final client = _ref.read(apiClientProvider);
    final response = await client.get('/routers');
    final data = response['data'] as List<dynamic>;
    return data.map((row) => MikrotikRouter.fromJson(row as Map<String, dynamic>)).toList();
  }

  Future<RouterCredentials> fetchCredentials(int routerId) async {
    final client = _ref.read(apiClientProvider);
    final response = await client.get('/routers/$routerId/credentials');
    return RouterCredentials.fromJson(response);
  }

  /// Returns the raw {success, error} map from the backend -- a failed
  /// push here is still an HTTP 200 (see RouterConsoleController::updateSsid()),
  /// since "the router rejected this" is an expected, routine outcome
  /// (router unreachable, no built-in Wi-Fi configured yet), not a server
  /// error worth throwing an ApiException over.
  Future<Map<String, dynamic>> updateSsid(int routerId, String ssid) async {
    final client = _ref.read(apiClientProvider);
    return client.post('/routers/$routerId/ssid', data: {'ssid': ssid});
  }
}
