// Phase 1 smoke test: the real app entry point (HotspotFreeRadApp) renders
// without throwing and lands on the login screen when no token is stored.
// This replaces the Flutter counter-app template test that referenced the
// now-deleted MyApp/MyHomePage classes.
//
// Deliberately overrides apiClientProvider with a fake whose readToken()
// resolves instantly, rather than exercising flutter_secure_storage's real
// platform channel -- that channel has no handler under flutter_test and
// resolves with a MissingPluginException only after a real (non-fake-async)
// round trip that plain tester.pump() calls can't reliably wait out. The
// resulting robustness fix that investigation surfaced (AuthController now
// catches that failure mode too, not just a rejected /me call) lives in
// auth_controller.dart itself, independent of this test's own approach.
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:mobile/api/api_client.dart';
import 'package:mobile/auth/auth_controller.dart';
import 'package:mobile/main.dart';

class _NoTokenApiClient extends ApiClient {
  @override
  Future<String?> readToken() async => null;
}

void main() {
  testWidgets('app boots to the login screen when no token is stored', (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [apiClientProvider.overrideWith((ref) => _NoTokenApiClient())],
        child: const HotspotFreeRadApp(),
      ),
    );
    await tester.pump();
    await tester.pump();

    expect(find.text('HotspotFreeRAD'), findsOneWidget);
    expect(find.widgetWithText(ElevatedButton, 'Sign in'), findsOneWidget);
  });
}
