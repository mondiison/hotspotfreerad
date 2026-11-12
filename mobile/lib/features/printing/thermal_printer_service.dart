import 'package:flutter_blue_plus/flutter_blue_plus.dart';

/// Bluetooth LE scanning/printing, built on flutter_blue_plus.
///
/// Two honest caveats this codebase's own convention is to surface, not
/// hide:
///
/// 1. **Licensing**: flutter_blue_plus requires a paid `License.commercial`
///    for any for-profit use (see its own LICENSE file) -- HotspotFreeRAD is
///    a commercial SaaS, so shipping this screen to production needs that
///    commercial license purchased from the flutter_blue_plus maintainers
///    first. Using `License.nonprofit` here would misrepresent this app's
///    actual use, so `connectAndFindWritable()` below passes `commercial`
///    deliberately -- this is a real cost/step to budget for, not a code
///    change to make later.
/// 2. **Hardware coverage**: flutter_blue_plus only talks BLE (GATT). Many
///    cheap thermal receipt printers -- including some of the exact models
///    a hotspot shop owner is likely to already own -- are classic
///    Bluetooth SPP/serial devices, which a BLE-only library cannot see or
///    connect to at all. Only a genuinely BLE-capable printer works through
///    this screen; a classic-SPP-only printer needs a second, Android-only
///    plugin this app doesn't have yet. Not yet verified against any real
///    printer hardware (emulators have no Bluetooth radio at all) --
///    flagged the same way every other "not yet confirmed against real
///    hardware" RouterOS integration is flagged in the main backend's own
///    CLAUDE.md.
class ThermalPrinterService {
  static const Duration scanTimeout = Duration(seconds: 6);

  Future<void> startScan() => FlutterBluePlus.startScan(timeout: scanTimeout);

  Stream<List<ScanResult>> get scanResults => FlutterBluePlus.scanResults;

  Stream<bool> get isScanning => FlutterBluePlus.isScanning;

  Future<void> stopScan() => FlutterBluePlus.stopScan();

  /// Connects, discovers services, and returns the first characteristic
  /// that can actually be written to -- most BLE thermal printers expose
  /// exactly one proprietary service with one such characteristic, so this
  /// deliberately doesn't try to recognize a specific vendor UUID.
  Future<BluetoothCharacteristic> connectAndFindWritable(BluetoothDevice device) async {
    await device.connect(license: License.commercial);
    final services = await device.discoverServices();

    for (final service in services) {
      for (final characteristic in service.characteristics) {
        if (characteristic.properties.write || characteristic.properties.writeWithoutResponse) {
          return characteristic;
        }
      }
    }

    await device.disconnect();
    throw StateError(
      'No writable Bluetooth characteristic was found on this device -- '
      'it may not be a supported BLE printer (see ThermalPrinterService\'s '
      'own docblock on classic/SPP printers).',
    );
  }

  /// BLE has a small per-write size limit (the connection's negotiated MTU,
  /// commonly well under the full receipt's byte length) -- sending
  /// everything in one write silently truncates or drops data on most
  /// printers, so this writes in small chunks with a short pause between
  /// each, the standard pattern for BLE ESC/POS printing.
  Future<void> printBytes(BluetoothCharacteristic characteristic, List<int> bytes) async {
    const chunkSize = 100;
    final withoutResponse = characteristic.properties.writeWithoutResponse;

    for (var offset = 0; offset < bytes.length; offset += chunkSize) {
      final end = (offset + chunkSize < bytes.length) ? offset + chunkSize : bytes.length;
      await characteristic.write(bytes.sublist(offset, end), withoutResponse: withoutResponse);
      await Future.delayed(const Duration(milliseconds: 20));
    }
  }

  Future<void> disconnect(BluetoothDevice device) => device.disconnect();
}
