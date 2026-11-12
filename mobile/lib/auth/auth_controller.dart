import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../models/app_user.dart';
import 'auth_state.dart';

final apiClientProvider = Provider<ApiClient>((ref) {
  final client = ApiClient();
  client.onUnauthorized = () => ref.read(authControllerProvider.notifier).forceLogout();
  return client;
});

final authControllerProvider = NotifierProvider<AuthController, AuthState>(AuthController.new);

/// Owns the one piece of state every screen in the app ultimately depends
/// on: is anyone logged in, and who. build() starts in "bootstrapping" and
/// immediately kicks off a token check (does a stored token still work)
/// without blocking the first frame -- main.dart shows a splash while this
/// resolves.
class AuthController extends Notifier<AuthState> {
  @override
  AuthState build() {
    Future.microtask(_bootstrap);
    return const AuthState.bootstrapping();
  }

  Future<void> _bootstrap() async {
    final client = ref.read(apiClientProvider);

    try {
      final token = await client.readToken();

      if (token == null) {
        state = const AuthState.unauthenticated();
        return;
      }

      final response = await client.get('/me');
      state = AuthState.authenticated(AppUser.fromJson(response['data'] as Map<String, dynamic>));
    } catch (_) {
      // Covers both a rejected/expired token (ApiException from /me) and a
      // genuinely broken secure-storage read (e.g. a corrupted Android
      // keystore in the wild, or no platform channel at all in a plain
      // widget test) -- either way there's no token left to trust, so fail
      // safe to the login screen rather than leaving the splash screen up
      // forever. clearToken() itself is defensively swallowed too, since a
      // storage read that just failed is not a storage write that's
      // guaranteed to succeed.
      try {
        await client.clearToken();
      } catch (_) {
        // best-effort only
      }
      state = const AuthState.unauthenticated();
    }
  }

  Future<void> login(String email, String password, String deviceName) async {
    final client = ref.read(apiClientProvider);
    final response = await client.post('/login', data: {
      'email': email,
      'password': password,
      'device_name': deviceName,
    });

    await client.saveToken(response['token'] as String);
    state = AuthState.authenticated(AppUser.fromJson(response['user'] as Map<String, dynamic>));
  }

  Future<void> logout() async {
    final client = ref.read(apiClientProvider);
    try {
      await client.post('/logout');
    } on ApiException {
      // Already logged out server-side (token expired/revoked elsewhere) --
      // still clear local state below either way.
    }
    await client.clearToken();
    state = const AuthState.unauthenticated();
  }

  /// Called by ApiClient.onUnauthorized when any request comes back 401 --
  /// the token is already dead server-side, so this just clears local state
  /// without another round trip to /logout (which would 401 too).
  Future<void> forceLogout() async {
    final client = ref.read(apiClientProvider);
    await client.clearToken();
    state = const AuthState.unauthenticated();
  }
}
