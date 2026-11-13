import 'dart:io';

import 'package:dio/dio.dart';
import 'package:dio/io.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Thrown for any non-2xx API response so screens can show the backend's
/// own validation/error message instead of a generic Dio exception.
class ApiException implements Exception {
  final int? statusCode;
  final String message;
  final Map<String, List<String>>? errors;

  ApiException(this.message, {this.statusCode, this.errors});

  /// The first message from Laravel's own validation error bag, if any --
  /// this is almost always more useful to show the user than the generic
  /// top-level "message" field.
  String get displayMessage {
    final firstFieldErrors = errors?.values.firstOrNull;
    return firstFieldErrors?.firstOrNull ?? message;
  }

  @override
  String toString() => displayMessage;
}

/// 2026-11-11: the Flutter app's one HTTP client, wired to the HotspotFreeRAD
/// Sanctum API (see routes/api.php). The app never holds a router credential
/// of any kind -- it only ever carries this one bearer token, issued by
/// POST /api/login, stored in flutter_secure_storage (Android's encrypted
/// keystore-backed storage, never plain prefs). A 401 here always means the
/// token is gone/invalid (logged out elsewhere, revoked, expired) -- the
/// onUnauthorized callback lets the app route back to the login screen from
/// a single place rather than every screen checking this itself.
class ApiClient {
  static const _tokenKey = 'auth_token';

  /// Points at the real production API (mmsradius.com) -- this is what a
  /// real tenant_admin's phone needs to reach regardless of which network
  /// they're on, so this is the right default to ship rather than a
  /// dev-only address. Not yet made runtime-configurable (e.g. per-build
  /// flavor) -- a clean follow-up once there's a second real environment
  /// (staging) to switch between; for now, swap this one constant locally
  /// for whichever of these a specific dev session needs:
  ///   - Android emulator against a local dev server: `http://10.0.2.2:8001/api`
  ///     (10.0.2.2 is the emulator's own alias for the host machine's
  ///     localhost; `php artisan serve --host=127.0.0.1 --port=8001` per
  ///     CLAUDE.md's documented local dev port).
  ///   - A real device on the same Wi-Fi as a local dev server: the host's
  ///     real LAN IP instead of 10.0.2.2, with the dev server bound to
  ///     `--host=0.0.0.0` (not `127.0.0.1`) so it actually accepts that
  ///     connection.
  ///
  /// Confirmed live (2026-11-13): on this project's own dev machine
  /// specifically, `php artisan serve`'s cli-server SAPI was found to be
  /// genuinely broken (random internal corruption on every request,
  /// confirmed independent of GD/opcache/host binding) -- routing local
  /// testing through XAMPP's Apache (mod_php) instead worked cleanly. Not
  /// an application bug; likely specific to that one machine's PHP
  /// install, not something every dev setup needs to work around.
  static const String baseUrl = 'https://mmsradius.com/api';

  final Dio _dio;
  final FlutterSecureStorage _storage;
  void Function()? onUnauthorized;

  ApiClient({Dio? dio, FlutterSecureStorage? storage})
      : _dio = dio ?? Dio(BaseOptions(baseUrl: baseUrl)),
        _storage = storage ?? const FlutterSecureStorage() {
    // Dio/HttpClient pools and reuses a keep-alive connection by default --
    // confirmed live against `php artisan serve` (this codebase's own local
    // dev server, CLAUDE.md: "Local dev runs on port 8001"), which closes
    // the TCP connection after every single response rather than supporting
    // HTTP keep-alive. Reusing a connection the server already closed
    // throws exactly "HttpException: Software caused connection abort" on
    // the very next request, intermittently, with no response at all --
    // not something an error-message-matching retry can safely distinguish
    // from a request that genuinely failed after reaching the server (and
    // matters most for POST, where a stale-connection retry could silently
    // double-submit).
    //
    // idleTimeout: Duration.zero alone (closing an idle connection the
    // moment it goes idle, instead of holding it open for reuse) wasn't
    // enough on its own, confirmed live: a connection can still be handed
    // to a new request in the narrow window right as the previous one
    // finishes, before dart:io's own idle bookkeeping has caught up. The
    // `Connection: close` request header below is the belt to this
    // suspenders -- it tells dart:io's HttpClient up front not to pool this
    // connection for reuse at all, so the very next request always opens a
    // fresh one rather than racing to notice a stale one.
    if (_dio.httpClientAdapter is IOHttpClientAdapter) {
      (_dio.httpClientAdapter as IOHttpClientAdapter).createHttpClient = () {
        return HttpClient()..idleTimeout = Duration.zero;
      };
    }

    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await readToken();
          if (token != null) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          options.headers['Accept'] = 'application/json';
          options.headers['Connection'] = 'close';
          handler.next(options);
        },
        onError: (error, handler) {
          if (error.response?.statusCode == 401) {
            onUnauthorized?.call();
          }
          handler.next(error);
        },
      ),
    );
  }

  Future<String?> readToken() => _storage.read(key: _tokenKey);

  Future<void> saveToken(String token) => _storage.write(key: _tokenKey, value: token);

  Future<void> clearToken() => _storage.delete(key: _tokenKey);

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) async {
    final response = await _request(() => _dio.get(path, queryParameters: query));
    return response.data as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> post(String path, {Map<String, dynamic>? data}) async {
    final response = await _request(() => _dio.post(path, data: data));
    return response.data as Map<String, dynamic>;
  }

  Future<Response> _request(Future<Response> Function() call) async {
    try {
      return await call();
    } on DioException catch (e) {
      final data = e.response?.data;
      final message = (data is Map && data['message'] is String)
          ? data['message'] as String
          : 'Something went wrong. Please check your connection and try again.';
      final errors = (data is Map && data['errors'] is Map)
          ? (data['errors'] as Map).map(
              (key, value) => MapEntry(key.toString(), (value as List).map((v) => v.toString()).toList()),
            )
          : null;

      throw ApiException(message, statusCode: e.response?.statusCode, errors: errors);
    }
  }
}
