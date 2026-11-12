import 'package:esc_pos_utils_plus/esc_pos_utils_plus.dart';

import '../../models/voucher.dart';
import '../../models/voucher_batch.dart';

/// Builds the ESC/POS byte stream for one batch's vouchers -- mirrors the
/// web admin's own "compact"/"receipt" voucher print style (shop name,
/// package name, code, duration) translated into raw printer commands, not
/// a shared template: the rendering targets (raster HTML/CSS vs. a thermal
/// printer's own command language) are fundamentally different, but the
/// same *content fields* are used.
class VoucherReceiptBuilder {
  static Future<List<int>> build(VoucherBatch batch, {PaperSize paperSize = PaperSize.mm58}) async {
    final profile = await CapabilityProfile.load();
    final generator = Generator(paperSize, profile);
    var bytes = <int>[];

    bytes += generator.reset();

    for (final voucher in batch.vouchers) {
      bytes += _ticket(generator, batch, voucher);
    }

    bytes += generator.cut();
    return bytes;
  }

  /// A single unused voucher, for a quick test print before committing a
  /// whole batch to paper.
  static Future<List<int>> buildSample(VoucherBatch batch, Voucher voucher, {PaperSize paperSize = PaperSize.mm58}) async {
    final profile = await CapabilityProfile.load();
    final generator = Generator(paperSize, profile);
    var bytes = <int>[];

    bytes += generator.reset();
    bytes += _ticket(generator, batch, voucher);
    bytes += generator.cut();
    return bytes;
  }

  static List<int> _ticket(Generator generator, VoucherBatch batch, Voucher voucher) {
    var bytes = <int>[];

    bytes += generator.text(
      batch.shop.name,
      styles: const PosStyles(align: PosAlign.center, bold: true, height: PosTextSize.size2, width: PosTextSize.size2),
    );
    bytes += generator.text(
      batch.package.name,
      styles: const PosStyles(align: PosAlign.center),
    );
    bytes += generator.hr();
    bytes += generator.text(
      voucher.code,
      styles: const PosStyles(align: PosAlign.center, bold: true, height: PosTextSize.size2, width: PosTextSize.size2),
    );
    bytes += generator.hr();
    bytes += generator.text(
      '${batch.package.durationLabel} • ${batch.package.dataLabel}',
      styles: const PosStyles(align: PosAlign.center),
    );
    bytes += generator.feed(2);

    return bytes;
  }
}
