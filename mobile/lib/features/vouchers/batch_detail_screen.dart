import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import '../../api/api_client.dart';
import '../../models/voucher.dart';
import '../../models/voucher_batch.dart';
import '../printing/thermal_printer_screen.dart';
import 'voucher_providers.dart';
import 'voucher_repository.dart';

class BatchDetailScreen extends ConsumerStatefulWidget {
  const BatchDetailScreen({super.key, required this.batchId});

  final int batchId;

  @override
  ConsumerState<BatchDetailScreen> createState() => _BatchDetailScreenState();
}

enum _BatchMenuAction { copyAll, sharePdf, print, voidUnused }

class _BatchDetailScreenState extends ConsumerState<BatchDetailScreen> {
  late Future<VoucherBatch> _future;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<VoucherBatch> _load() => ref.read(voucherRepositoryProvider).fetchBatch(widget.batchId);

  void _reload() => setState(() => _future = _load());

  void _notify(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _copyAll(VoucherBatch batch) async {
    final codes = batch.vouchers.map((v) => v.code).join('\n');
    await Clipboard.setData(ClipboardData(text: codes));
    _notify('${batch.vouchers.length} code(s) copied to clipboard');
  }

  Future<void> _markSold(Voucher voucher) async {
    setState(() => _busy = true);
    try {
      await ref.read(voucherRepositoryProvider).markSold(voucher.id);
      _reload();
      ref.invalidate(voucherBatchesProvider);
    } on ApiException catch (e) {
      _notify(e.displayMessage);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _voidUnused(VoucherBatch batch) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Void unused vouchers?'),
        content: const Text(
          'Every code in this batch that has not been sold or redeemed will be permanently voided. '
          'This cannot be undone.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton.tonal(onPressed: () => Navigator.pop(context, true), child: const Text('Void unused')),
        ],
      ),
    );
    if (confirmed != true) return;

    setState(() => _busy = true);
    try {
      final voided = await ref.read(voucherRepositoryProvider).voidUnused(batch.id);
      _reload();
      ref.invalidate(voucherBatchesProvider);
      _notify('$voided voucher(s) voided');
    } on ApiException catch (e) {
      _notify(e.displayMessage);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sharePdf(VoucherBatch batch) async {
    final doc = pw.Document();

    doc.addPage(
      pw.MultiPage(
        pageFormat: PdfPageFormat.a4,
        build: (context) => [
          pw.Header(level: 0, text: batch.shop.name),
          pw.Text(batch.package.name),
          pw.SizedBox(height: 12),
          pw.Wrap(
            spacing: 8,
            runSpacing: 8,
            children: batch.vouchers
                .map(
                  (voucher) => pw.Container(
                    width: 160,
                    padding: const pw.EdgeInsets.all(8),
                    decoration: pw.BoxDecoration(border: pw.Border.all(width: 0.5)),
                    child: pw.Column(
                      crossAxisAlignment: pw.CrossAxisAlignment.start,
                      children: [
                        pw.Text(batch.shop.name, style: const pw.TextStyle(fontSize: 8)),
                        pw.SizedBox(height: 4),
                        pw.Text(
                          voucher.code,
                          style: pw.TextStyle(fontSize: 14, fontWeight: pw.FontWeight.bold),
                        ),
                        pw.SizedBox(height: 2),
                        pw.Text(batch.package.name, style: const pw.TextStyle(fontSize: 8)),
                        pw.Text(
                          '${batch.package.durationLabel} • ${batch.package.dataLabel}',
                          style: const pw.TextStyle(fontSize: 8),
                        ),
                      ],
                    ),
                  ),
                )
                .toList(),
          ),
        ],
      ),
    );

    await Printing.sharePdf(bytes: await doc.save(), filename: '${batch.name}.pdf');
  }

  Future<void> _handleMenuAction(_BatchMenuAction action, VoucherBatch batch) async {
    switch (action) {
      case _BatchMenuAction.copyAll:
        await _copyAll(batch);
      case _BatchMenuAction.sharePdf:
        await _sharePdf(batch);
      case _BatchMenuAction.print:
        if (!mounted) return;
        await Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => ThermalPrinterScreen(batch: batch)),
        );
      case _BatchMenuAction.voidUnused:
        await _voidUnused(batch);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Batch details'),
        actions: [
          FutureBuilder<VoucherBatch>(
            future: _future,
            builder: (context, snapshot) {
              final batch = snapshot.data;
              if (batch == null) return const SizedBox.shrink();
              final unusedCount = batch.vouchers.where((v) => v.status == 'unused').length;

              return PopupMenuButton<_BatchMenuAction>(
                enabled: !_busy,
                onSelected: (action) => _handleMenuAction(action, batch),
                itemBuilder: (context) => [
                  const PopupMenuItem(
                    value: _BatchMenuAction.copyAll,
                    child: ListTile(leading: Icon(Icons.copy), title: Text('Copy all codes')),
                  ),
                  const PopupMenuItem(
                    value: _BatchMenuAction.sharePdf,
                    child: ListTile(leading: Icon(Icons.picture_as_pdf), title: Text('Share as PDF')),
                  ),
                  const PopupMenuItem(
                    value: _BatchMenuAction.print,
                    child: ListTile(leading: Icon(Icons.print), title: Text('Print')),
                  ),
                  if (unusedCount > 0 && batch.status != 'void')
                    const PopupMenuItem(
                      value: _BatchMenuAction.voidUnused,
                      child: ListTile(leading: Icon(Icons.block), title: Text('Void unused')),
                    ),
                ],
              );
            },
          ),
        ],
      ),
      body: FutureBuilder<VoucherBatch>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return Center(child: Text('Could not load batch: ${snapshot.error}'));
          }

          final batch = snapshot.data!;

          return Column(
            children: [
              Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(batch.name, style: Theme.of(context).textTheme.titleLarge),
                    Text('${batch.shop.name} • ${batch.package.name}'),
                  ],
                ),
              ),
              const Divider(height: 1),
              Expanded(
                child: ListView.separated(
                  itemCount: batch.vouchers.length,
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (context, index) {
                    final voucher = batch.vouchers[index];
                    return ListTile(
                      title: Text(
                        voucher.code,
                        style: const TextStyle(fontWeight: FontWeight.bold, letterSpacing: 1),
                      ),
                      subtitle: Text(_statusLabel(voucher.status)),
                      trailing: voucher.status == 'unused'
                          ? TextButton(
                              onPressed: _busy ? null : () => _markSold(voucher),
                              child: const Text('Mark sold'),
                            )
                          : Icon(_statusIcon(voucher.status), color: Theme.of(context).colorScheme.outline),
                    );
                  },
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  String _statusLabel(String status) {
    switch (status) {
      case 'unused':
        return 'Unused';
      case 'sold':
        return 'Sold';
      case 'used':
        return 'Redeemed';
      case 'void':
        return 'Voided';
      default:
        return status;
    }
  }

  IconData _statusIcon(String status) {
    switch (status) {
      case 'sold':
        return Icons.sell_outlined;
      case 'used':
        return Icons.check_circle_outline;
      case 'void':
        return Icons.block;
      default:
        return Icons.circle_outlined;
    }
  }
}
