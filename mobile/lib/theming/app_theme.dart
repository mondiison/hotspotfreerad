import 'package:flutter/material.dart';

/// "Client centered with template design and customization based on
/// tenant plan" (Phase 1's branding half, feature-gating deferred) -- the
/// app reskins itself per tenant using the exact brand_color the web admin
/// already lets a tenant set (Tenant.brand_color, surfaced via
/// UserResource on /api/me), with no second branding system to maintain.
class AppTheme {
  static const _defaultSeed = Color(0xFF10B981); // matches the hotspot portal's own fallback brand color

  static ThemeData build({String? brandColorHex}) {
    final seed = _parseHexColor(brandColorHex) ?? _defaultSeed;

    return ThemeData(
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(seedColor: seed),
      appBarTheme: AppBarTheme(
        backgroundColor: seed,
        foregroundColor: Colors.white,
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: seed,
          foregroundColor: Colors.white,
          padding: const EdgeInsets.symmetric(vertical: 14),
        ),
      ),
    );
  }

  static Color? _parseHexColor(String? hex) {
    if (hex == null || hex.isEmpty) return null;
    var value = hex.replaceAll('#', '');
    if (value.length == 6) value = 'FF$value';
    final parsed = int.tryParse(value, radix: 16);
    return parsed != null ? Color(parsed) : null;
  }
}
