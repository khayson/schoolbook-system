import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/stock/data/stock_repository.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

/// Summarises nested receipt lines for list + detail.
({int lines, int units, int totalCost}) _receiptStats(Map<String, dynamic> r) {
  final items = (r['items'] as List<dynamic>?) ?? const [];
  var units = 0;
  var total = 0;
  for (final raw in items) {
    final item = raw as Map;
    final qty = (item['quantity'] as num?)?.toInt() ?? 0;
    final cost = (item['unit_cost'] as num?)?.toInt() ?? 0;
    units += qty;
    total += qty * cost;
  }
  return (lines: items.length, units: units, totalCost: total);
}

String? _formatReceivedAt(Object? raw) {
  if (raw is! String) return null;
  final dt = DateTime.tryParse(raw);
  if (dt == null) return null;
  return DateFormat('EEE d MMM y · HH:mm').format(dt.toLocal());
}

String? _formatReceivedShort(Object? raw) {
  if (raw is! String) return null;
  final dt = DateTime.tryParse(raw);
  if (dt == null) return null;
  return DateFormat.yMMMd().format(dt.toLocal());
}

class GoodsReceiptsScreen extends StatefulWidget {
  const GoodsReceiptsScreen({super.key});

  @override
  State<GoodsReceiptsScreen> createState() => _GoodsReceiptsScreenState();
}

class _GoodsReceiptsScreenState extends State<GoodsReceiptsScreen> {
  List<Map<String, dynamic>> _rows = [];
  String? _error;
  bool _loading = true;

  static const _r = 12.0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final rows = await context.read<StockRepository>().listReceipts();
      if (mounted) setState(() => _rows = rows);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;

    return AppPage(
      title: 'Goods receipts',
      subtitle: 'Stock that came in',
      actions: [
        IconButton(
          tooltip: 'Receive stock',
          onPressed: () => context.push('/stock/receive'),
          icon: const AppIcon(AppIcons.add, size: 22),
        ),
      ],
      body: _loading
          ? const LoadingBody()
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : RefreshIndicator(
                  color: AppColors.ink,
                  backgroundColor: AppColors.lime,
                  onRefresh: _load,
                  child: _rows.isEmpty
                      ? ListView(
                          padding: EdgeInsets.only(bottom: bottom + 12),
                          children: [
                            EmptyState(
                              title: 'No receipts yet',
                              subtitle:
                                  'Receive stock to create the first one.',
                              actionLabel: 'Receive stock',
                              onAction: () => context.push('/stock/receive'),
                            ),
                          ],
                        )
                      : ListView.separated(
                          padding:
                              EdgeInsets.fromLTRB(20, 12, 20, bottom + 16),
                          itemCount: _rows.length,
                          separatorBuilder: (_, _) => const SizedBox(height: 8),
                          itemBuilder: (context, i) {
                            final r = _rows[i];
                            final stats = _receiptStats(r);
                            final date = _formatReceivedShort(r['received_at']);
                            final ref =
                                r['supplier_reference']?.toString().trim();
                            final parts = <String>[
                              ?date,
                              if (stats.lines > 0)
                                '${stats.lines} line${stats.lines == 1 ? '' : 's'} · ${stats.units} units',
                              if (ref != null && ref.isNotEmpty) ref,
                            ];
                            return Material(
                              color: AppColors.surface,
                              borderRadius: BorderRadius.circular(_r),
                              child: InkWell(
                                onTap: () =>
                                    context.push('/stock/receipts/${r['id']}'),
                                borderRadius: BorderRadius.circular(_r),
                                child: Container(
                                  padding: const EdgeInsets.all(14),
                                  decoration: BoxDecoration(
                                    border: Border.all(color: AppColors.line),
                                    borderRadius: BorderRadius.circular(_r),
                                  ),
                                  child: Row(
                                    children: [
                                      Container(
                                        width: 4,
                                        height: 44,
                                        decoration: BoxDecoration(
                                          color: AppColors.lime,
                                          borderRadius:
                                              BorderRadius.circular(4),
                                        ),
                                      ),
                                      const SizedBox(width: 12),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              r['receipt_no']?.toString() ??
                                                  '#${r['id']}',
                                              style: const TextStyle(
                                                fontSize: 15,
                                                fontWeight: FontWeight.w800,
                                              ),
                                            ),
                                            if (parts.isNotEmpty) ...[
                                              const SizedBox(height: 3),
                                              Text(
                                                parts.join(' · '),
                                                style: const TextStyle(
                                                  fontSize: 12.5,
                                                  fontWeight: FontWeight.w600,
                                                  color: AppColors.mute,
                                                ),
                                              ),
                                            ],
                                          ],
                                        ),
                                      ),
                                      if (stats.totalCost > 0) ...[
                                        Text(
                                          Money.formatPesewas(stats.totalCost),
                                          style: const TextStyle(
                                            fontSize: 13,
                                            fontWeight: FontWeight.w800,
                                          ),
                                        ),
                                        const SizedBox(width: 6),
                                      ],
                                      const AppIcon(
                                        AppIcons.chevronRight,
                                        size: 18,
                                        color: AppColors.mute,
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            );
                          },
                        ),
                ),
    );
  }
}

class GoodsReceiptDetailScreen extends StatefulWidget {
  const GoodsReceiptDetailScreen({super.key, required this.receiptId});

  final int receiptId;

  @override
  State<GoodsReceiptDetailScreen> createState() =>
      _GoodsReceiptDetailScreenState();
}

class _GoodsReceiptDetailScreenState extends State<GoodsReceiptDetailScreen> {
  Map<String, dynamic>? _data;
  String? _error;

  static const _r = 12.0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data =
          await context.read<StockRepository>().getReceipt(widget.receiptId);
      if (mounted) setState(() => _data = data);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    final bottom = MediaQuery.paddingOf(context).bottom;

    return AppPage(
      title: data?['receipt_no']?.toString() ?? 'Receipt',
      subtitle: 'Incoming delivery',
      body: _error != null
          ? ErrorState(message: _error!, onRetry: _load)
          : data == null
              ? const LoadingBody()
              : _DetailBody(data: data, bottom: bottom, radius: _r),
    );
  }
}

class _DetailBody extends StatelessWidget {
  const _DetailBody({
    required this.data,
    required this.bottom,
    required this.radius,
  });

  final Map<String, dynamic> data;
  final double bottom;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final stats = _receiptStats(data);
    final when = _formatReceivedAt(data['received_at']);
    final ref = data['supplier_reference']?.toString().trim();
    final notes = data['notes']?.toString().trim();
    final supplierName = data['supplier'] is Map
        ? (data['supplier'] as Map)['name']?.toString()
        : null;
    final items = (data['items'] as List<dynamic>?) ?? const [];

    return ListView(
      padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 24),
      children: [
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: AppColors.limeSoft,
            border: Border.all(color: AppColors.lime, width: 1.5),
            borderRadius: BorderRadius.circular(radius),
          ),
          child: const Text(
            'Stock added to shelves from this delivery. Costs below are what you paid per copy.',
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w700,
              height: 1.35,
            ),
          ),
        ),
        const SizedBox(height: 14),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.line),
            borderRadius: BorderRadius.circular(radius),
          ),
          child: Column(
            children: [
              _Fact(
                label: 'Received',
                value: when ?? '—',
              ),
              if (supplierName != null && supplierName.isNotEmpty)
                _Fact(label: 'Supplier', value: supplierName),
              if (ref != null && ref.isNotEmpty)
                _Fact(label: 'Supplier ref', value: ref),
              if (notes != null && notes.isNotEmpty)
                _Fact(label: 'Notes', value: notes),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(
              child: _StatTile(
                label: 'Lines',
                value: '${stats.lines}',
                radius: radius,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _StatTile(
                label: 'Units in',
                value: '${stats.units}',
                radius: radius,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _StatTile(
                label: 'At cost',
                value: Money.formatPesewas(stats.totalCost),
                radius: radius,
                compact: true,
              ),
            ),
          ],
        ),
        const SizedBox(height: 20),
        const Text(
          'PRODUCTS RECEIVED',
          style: TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w800,
            letterSpacing: 0.8,
            color: AppColors.mute,
          ),
        ),
        const SizedBox(height: 10),
        if (items.isEmpty)
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppColors.wash,
              borderRadius: BorderRadius.circular(radius),
            ),
            child: const Text(
              'No lines on this receipt.',
              style: TextStyle(
                fontWeight: FontWeight.w600,
                color: AppColors.mute,
              ),
            ),
          )
        else
          for (final raw in items) ...[
            _LineRow(item: raw as Map, radius: radius),
            const SizedBox(height: 8),
          ],
        if (items.isNotEmpty) ...[
          const SizedBox(height: 4),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: AppColors.ink,
              borderRadius: BorderRadius.circular(radius),
            ),
            child: Row(
              children: [
                const Expanded(
                  child: Text(
                    'Delivery total',
                    style: TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w800,
                      color: Colors.white,
                    ),
                  ),
                ),
                Text(
                  Money.formatPesewas(stats.totalCost),
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w900,
                    color: AppColors.lime,
                  ),
                ),
              ],
            ),
          ),
        ],
      ],
    );
  }
}

class _Fact extends StatelessWidget {
  const _Fact({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 100,
            child: Text(
              label,
              style: const TextStyle(
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
                color: AppColors.mute,
              ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(
                fontSize: 14,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _StatTile extends StatelessWidget {
  const _StatTile({
    required this.label,
    required this.value,
    required this.radius,
    this.compact = false,
  });

  final String label;
  final String value;
  final double radius;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 10),
      decoration: BoxDecoration(
        border: Border.all(color: AppColors.line),
        borderRadius: BorderRadius.circular(radius),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: const TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w700,
              color: AppColors.mute,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontSize: compact ? 12.5 : 16,
              fontWeight: FontWeight.w900,
            ),
          ),
        ],
      ),
    );
  }
}

class _LineRow extends StatelessWidget {
  const _LineRow({required this.item, required this.radius});

  final Map item;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final product = item['product'];
    final productMap = product is Map ? product : null;
    final title = productMap?['title']?.toString() ??
        'Product ${item['product_id']}';
    final sku = productMap?['sku']?.toString();
    final productId = (item['product_id'] as num?)?.toInt() ??
        (productMap?['id'] as num?)?.toInt();
    final qty = (item['quantity'] as num?)?.toInt() ?? 0;
    final cost = (item['unit_cost'] as num?)?.toInt() ?? 0;
    final lineTotal = qty * cost;

    return Material(
      color: AppColors.surface,
      borderRadius: BorderRadius.circular(radius),
      child: InkWell(
        borderRadius: BorderRadius.circular(radius),
        onTap: productId == null
            ? null
            : () => context.push('/products/$productId'),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.line),
            borderRadius: BorderRadius.circular(radius),
          ),
          child: Row(
            children: [
              Container(
                width: 4,
                height: 44,
                decoration: BoxDecoration(
                  color: AppColors.lime,
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      [
                        if (sku != null && sku.isNotEmpty) sku,
                        '$qty × ${Money.formatPesewas(cost)}',
                      ].join(' · '),
                      style: const TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w600,
                        color: AppColors.mute,
                      ),
                    ),
                  ],
                ),
              ),
              Text(
                Money.formatPesewas(lineTotal),
                style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w900,
                ),
              ),
              if (productId != null) ...[
                const SizedBox(width: 4),
                const AppIcon(
                  AppIcons.chevronRight,
                  size: 16,
                  color: AppColors.mute,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
