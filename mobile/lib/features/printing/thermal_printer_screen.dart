import 'package:esc_pos_utils_plus/esc_pos_utils_plus.dart' show PaperSize;
import 'package:flutter/material.dart';
import 'package:flutter_blue_plus/flutter_blue_plus.dart';

import '../../models/voucher_batch.dart';
import 'thermal_printer_service.dart';
import 'voucher_receipt_builder.dart';

/// Device picker + print action for one voucher batch -- scans for nearby
/// BLE devices, lets the shop attendant pick their printer, then streams
/// the whole batch's ESC/POS receipt to it. See ThermalPrinterService's own
/// docblock for the licensing and classic-SPP-hardware caveats this screen
/// inherits.
class ThermalPrinterScreen extends StatefulWidget {
  const ThermalPrinterScreen({super.key, required this.batch});

  final VoucherBatch batch;

  @override
  State<ThermalPrinterScreen> createState() => _ThermalPrinterScreenState();
}

class _ThermalPrinterScreenState extends State<ThermalPrinterScreen> {
  final _printerService = ThermalPrinterService();
  PaperSize _paperSize = PaperSize.mm58;
  bool _printing = false;
  String? _statusMessage;

  @override
  void initState() {
    super.initState();
    _printerService.startScan();
  }

  @override
  void dispose() {
    _printerService.stopScan();
    super.dispose();
  }

  Future<void> _printTo(BluetoothDevice device) async {
    setState(() {
      _printing = true;
      _statusMessage = 'Connecting to ${device.platformName.isNotEmpty ? device.platformName : device.remoteId}...';
    });

    try {
      final characteristic = await _printerService.connectAndFindWritable(device);

      setState(() => _statusMessage = 'Printing ${widget.batch.vouchers.length} voucher(s)...');
      final bytes = await VoucherReceiptBuilder.build(widget.batch, paperSize: _paperSize);
      await _printerService.printBytes(characteristic, bytes);
      await _printerService.disconnect(device);

      if (!mounted) return;
      setState(() => _statusMessage = null);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Sent to printer.')),
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _statusMessage = null);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Could not print: $e')),
      );
    } finally {
      if (mounted) setState(() => _printing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Print vouchers')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${widget.batch.vouchers.length} code(s) from "${widget.batch.name}"',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 12),
                SegmentedButton<PaperSize>(
                  segments: const [
                    ButtonSegment(value: PaperSize.mm58, label: Text('58mm')),
                    ButtonSegment(value: PaperSize.mm80, label: Text('80mm')),
                  ],
                  selected: {_paperSize},
                  onSelectionChanged: _printing ? null : (selection) => setState(() => _paperSize = selection.first),
                ),
                if (_statusMessage != null) ...[
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      const SizedBox(height: 16, width: 16, child: CircularProgressIndicator(strokeWidth: 2)),
                      const SizedBox(width: 12),
                      Expanded(child: Text(_statusMessage!)),
                    ],
                  ),
                ],
              ],
            ),
          ),
          const Divider(height: 1),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Text('Nearby Bluetooth devices', style: Theme.of(context).textTheme.titleSmall),
                const Spacer(),
                StreamBuilder<bool>(
                  stream: _printerService.isScanning,
                  initialData: true,
                  builder: (context, snapshot) {
                    final scanning = snapshot.data ?? false;
                    return TextButton.icon(
                      onPressed: _printing
                          ? null
                          : () => scanning ? _printerService.stopScan() : _printerService.startScan(),
                      icon: Icon(scanning ? Icons.stop : Icons.refresh),
                      label: Text(scanning ? 'Stop' : 'Rescan'),
                    );
                  },
                ),
              ],
            ),
          ),
          Expanded(
            child: StreamBuilder<List<ScanResult>>(
              stream: _printerService.scanResults,
              initialData: const [],
              builder: (context, snapshot) {
                final results = snapshot.data ?? [];

                if (results.isEmpty) {
                  return const Center(
                    child: Padding(
                      padding: EdgeInsets.all(24),
                      child: Text(
                        'Searching for nearby devices...\nMake sure your printer is powered on and in pairing mode.',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  );
                }

                return ListView.builder(
                  itemCount: results.length,
                  itemBuilder: (context, index) {
                    final result = results[index];
                    final name = result.device.platformName.isNotEmpty
                        ? result.device.platformName
                        : (result.advertisementData.advName.isNotEmpty
                            ? result.advertisementData.advName
                            : result.device.remoteId.str);

                    return ListTile(
                      leading: const Icon(Icons.print),
                      title: Text(name),
                      subtitle: Text(result.device.remoteId.str),
                      trailing: _printing ? null : const Icon(Icons.chevron_right),
                      onTap: _printing ? null : () => _printTo(result.device),
                    );
                  },
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
