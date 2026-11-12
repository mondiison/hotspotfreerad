import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/mikrotik_router.dart';
import 'router_provisioning_repository.dart';
import 'waiting_for_router_screen.dart';

enum _Stage { detecting, pushing, done, failed }

/// Step 3 of 4 -- the actual bootstrap push. Everything here happens over
/// the phone's own connection to the router's local network; the
/// HotspotFreeRAD backend is not involved in this screen at all (it
/// already did its part back in add_router_screen.dart). Deliberately
/// never renders provisioningResult.bootstrapScript anywhere in this
/// widget tree -- only progress text and, on failure, the router's own
/// error message.
class ProvisioningScreen extends ConsumerStatefulWidget {
  const ProvisioningScreen({super.key, required this.provisioningResult});

  final RouterProvisioningResult provisioningResult;

  @override
  ConsumerState<ProvisioningScreen> createState() => _ProvisioningScreenState();
}

class _ProvisioningScreenState extends ConsumerState<ProvisioningScreen> {
  _Stage _stage = _Stage.detecting;
  String? _detectedBoard;
  String? _error;

  @override
  void initState() {
    super.initState();
    _run();
  }

  Future<void> _run() async {
    final repository = ref.read(routerProvisioningRepositoryProvider);

    setState(() {
      _stage = _Stage.detecting;
      _error = null;
    });

    try {
      final detected = await repository.detectFactoryRouter();
      setState(() {
        _detectedBoard = '${detected.boardName} (RouterOS ${detected.version})';
        _stage = _Stage.pushing;
      });

      await repository.pushBootstrapScript(widget.provisioningResult.bootstrapScript);
      await repository.markBootstrapPushed(widget.provisioningResult.router.id);

      if (!mounted) return;
      setState(() => _stage = _Stage.done);
    } on LocalRouterException catch (e) {
      if (!mounted) return;
      setState(() {
        _stage = _Stage.failed;
        _error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Setting up the router')),
      body: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Text(
              'Step 3 of 4 -- sending setup instructions',
              style: TextStyle(fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 24),
            if (_stage == _Stage.detecting || _stage == _Stage.pushing) ...[
              const Center(child: CircularProgressIndicator()),
              const SizedBox(height: 16),
              Center(
                child: Text(
                  _stage == _Stage.detecting
                      ? 'Looking for the router on this Wi-Fi network...'
                      : 'Found it${_detectedBoard != null ? ' ($_detectedBoard)' : ''} -- sending setup instructions...',
                  textAlign: TextAlign.center,
                ),
              ),
            ],
            if (_stage == _Stage.done) ...[
              const Icon(Icons.check_circle, color: Colors.green, size: 48),
              const SizedBox(height: 16),
              const Text('Setup instructions sent.', textAlign: TextAlign.center),
              const SizedBox(height: 24),
              ElevatedButton(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => WaitingForRouterScreen(router: widget.provisioningResult.router)),
                ),
                child: const Text('Continue'),
              ),
            ],
            if (_stage == _Stage.failed) ...[
              Icon(Icons.error_outline, color: Theme.of(context).colorScheme.error, size: 48),
              const SizedBox(height: 16),
              Text(_error ?? 'Something went wrong.', textAlign: TextAlign.center),
              const SizedBox(height: 24),
              ElevatedButton(onPressed: _run, child: const Text('Try again')),
            ],
          ],
        ),
      ),
    );
  }
}
