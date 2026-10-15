import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/reports/data/reports_repository.dart';
import 'package:schoolbook/features/reports/domain/report_models.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

String _monthRange() {
  final now = DateTime.now();
  final from = DateTime(now.year, now.month, 1);
  final fmt = DateFormat('yyyy-MM-dd');
  return '${fmt.format(from)}|${fmt.format(now)}';
}

String _friendlyRange(String? from, String? to) {
  DateTime? parse(String? s) => s == null ? null : DateTime.tryParse(s);
  final a = parse(from);
  final b = parse(to);
  if (a == null || b == null) {
    return [from, to].whereType<String>().join(' → ');
  }
  final fmt = DateFormat('d MMM');
  return '${fmt.format(a)} → ${fmt.format(b)}';
}

class _ReportLoader<T> extends StatefulWidget {
  const _ReportLoader({
    required this.title,
    required this.load,
    required this.builder,
    this.subtitle,
  });

  final String title;
  final String? subtitle;
  final Future<T> Function(ReportsRepository) load;
  final Widget Function(BuildContext, T) builder;

  @override
  State<_ReportLoader<T>> createState() => _ReportLoaderState<T>();
}

class _ReportLoaderState<T> extends State<_ReportLoader<T>> {
  T? _data;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final data = await widget.load(context.read<ReportsRepository>());
      if (mounted) setState(() => _data = data);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = describeApiError(e).body);
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    return AppPage(
      title: widget.title,
      subtitle: widget.subtitle,
      body: _error != null
          ? ErrorState(message: _error!, onRetry: _load)
          : data == null
              ? const LoadingBody()
              : RefreshIndicator(
                  color: AppColors.ink,
                  backgroundColor: AppColors.lime,
                  onRefresh: _load,
                  child: widget.builder(context, data),
                ),
    );
  }
}

class _HeroTotal extends StatelessWidget {
  const _HeroTotal({
    required this.label,
    required this.value,
    this.note,
    this.valueKey,
  });

  final String label;
  final String value;
  final String? note;
  final Key? valueKey;

  static const _r = 12.0;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.ink,
        borderRadius: BorderRadius.circular(_r),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: TextStyle(
              fontSize: 12.5,
              fontWeight: FontWeight.w700,
              color: Colors.white.withValues(alpha: 0.65),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            value,
            key: valueKey,
            style: const TextStyle(
              fontSize: 28,
              fontWeight: FontWeight.w900,
              letterSpacing: -0.6,
              color: AppColors.lime,
              fontFeatures: [FontFeature.tabularFigures()],
            ),
          ),
          if (note != null) ...[
            const SizedBox(height: 6),
            Text(
              note!,
              style: TextStyle(
                fontSize: 12.5,
                fontWeight: FontWeight.w600,
                color: Colors.white.withValues(alpha: 0.65),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _MetricStrip extends StatelessWidget {
  const _MetricStrip({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        for (var i = 0; i < children.length; i++) ...[
          if (i > 0) const SizedBox(width: 8),
          Expanded(child: children[i]),
        ],
      ],
    );
  }
}

class _MetricTile extends StatelessWidget {
  const _MetricTile({required this.label, required this.value});

  final String label;
  final String value;

  static const _r = 12.0;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 10),
      decoration: BoxDecoration(
        border: Border.all(color: AppColors.line),
        borderRadius: BorderRadius.circular(_r),
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
            style: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w900,
              fontFeatures: [FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }
}

class _ReportRow extends StatelessWidget {
  const _ReportRow({
    super.key,
    required this.title,
    this.subtitle,
    this.trailing,
    this.onTap,
    this.warn = false,
  });

  final String title;
  final String? subtitle;
  final Widget? trailing;
  final VoidCallback? onTap;
  final bool warn;

  static const _r = 12.0;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: warn
          ? AppColors.warn.withValues(alpha: 0.08)
          : AppColors.surface,
      borderRadius: BorderRadius.circular(_r),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(_r),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            border: Border.all(
              color: warn ? AppColors.warn : AppColors.line,
              width: warn ? 1.5 : 1,
            ),
            borderRadius: BorderRadius.circular(_r),
          ),
          child: Row(
            children: [
              Container(
                width: 4,
                height: 40,
                decoration: BoxDecoration(
                  color: warn ? AppColors.warn : AppColors.lime,
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
                      style: TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w800,
                        color: warn ? AppColors.warn : AppColors.ink,
                      ),
                    ),
                    if (subtitle != null && subtitle!.isNotEmpty) ...[
                      const SizedBox(height: 3),
                      Text(
                        subtitle!,
                        style: TextStyle(
                          fontSize: 12.5,
                          fontWeight: FontWeight.w600,
                          color: warn ? AppColors.warn : AppColors.mute,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              if (trailing != null) ...[
                const SizedBox(width: 10),
                DefaultTextStyle(
                  style: TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w900,
                    color: warn ? AppColors.warn : AppColors.ink,
                    fontFeatures: const [FontFeature.tabularFigures()],
                  ),
                  child: trailing!,
                ),
              ],
              if (onTap != null) ...[
                const SizedBox(width: 4),
                AppIcon(
                  AppIcons.chevronRight,
                  size: 16,
                  color: warn ? AppColors.warn : AppColors.mute,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class OwingReportScreen extends StatelessWidget {
  const OwingReportScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return _ReportLoader<AgingReport>(
      title: 'Who owes most',
      subtitle: 'Receivables aging',
      load: (r) => r.receivablesAging(),
      builder: (context, report) {
        final bottom = MediaQuery.paddingOf(context).bottom;
        final rows = report.byAmountOwed;
        if (rows.isEmpty) {
          return ListView(
            padding: EdgeInsets.only(bottom: bottom + 12),
            children: const [
              EmptyState(
                title: 'Nobody owes you anything',
                subtitle: 'Confirmed unpaid invoices would appear here.',
              ),
            ],
          );
        }
        return ListView(
          padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 16),
          children: [
            _HeroTotal(
              label: 'Total owed',
              value: Money.formatPesewas(report.total),
              valueKey: const Key('owing_total'),
              note: '${rows.length} school${rows.length == 1 ? '' : 's'}',
            ),
            const SizedBox(height: 16),
            const Text(
              'BY SCHOOL',
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.8,
                color: AppColors.mute,
              ),
            ),
            const SizedBox(height: 10),
            for (final row in rows) ...[
              _ReportRow(
                key: Key('owing_${row.customerId}'),
                title: row.name,
                subtitle: row.overdue == 0
                    ? 'Nothing overdue'
                    : 'Overdue ${Money.formatPesewas(row.overdue)}',
                trailing: Text(Money.formatPesewas(row.total)),
                warn: row.overdue > 0,
                onTap: () => context.push('/customers/${row.customerId}'),
              ),
              const SizedBox(height: 8),
            ],
          ],
        );
      },
    );
  }
}

class LowStockReportScreen extends StatelessWidget {
  const LowStockReportScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return _ReportLoader<List<LowStockRow>>(
      title: 'Low stock',
      subtitle: 'Below reorder level',
      load: (r) => r.lowStock(),
      builder: (context, rows) {
        final bottom = MediaQuery.paddingOf(context).bottom;
        if (rows.isEmpty) {
          return ListView(
            padding: EdgeInsets.only(bottom: bottom + 12),
            children: const [
              EmptyState(
                title: 'Nothing is low',
                subtitle: 'Every product is above its reorder level.',
              ),
            ],
          );
        }
        return ListView(
          padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 28),
          children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.limeSoft,
                border: Border.all(color: AppColors.lime, width: 1.5),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Text(
                '${rows.length} product${rows.length == 1 ? '' : 's'} need attention. Tap one to open it.',
                style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  height: 1.35,
                ),
              ),
            ),
            const SizedBox(height: 14),
            for (final row in rows) ...[
              _ReportRow(
                key: Key('low_${row.productId}'),
                title: row.title,
                subtitle: '${row.sku} · reorder at ${row.reorderLevel}',
                warn: row.isOutOfStock,
                trailing: Text(
                  row.isOutOfStock ? 'Out' : '${row.stockOnHand} left',
                ),
                onTap: () => context.push('/products/${row.productId}'),
              ),
              const SizedBox(height: 8),
            ],
          ],
        );
      },
    );
  }
}

class SalesSummaryReportScreen extends StatelessWidget {
  const SalesSummaryReportScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final parts = _monthRange().split('|');
    return _ReportLoader<Map<String, dynamic>>(
      title: 'Sales summary',
      subtitle: 'This month',
      load: (r) => r.salesSummary(from: parts[0], to: parts[1]),
      builder: (context, data) {
        final bottom = MediaQuery.paddingOf(context).bottom;
        final rows = (data['rows'] as List<dynamic>? ?? const []);
        final totals = data['totals'] as Map<String, dynamic>? ?? {};
        final revenue = (totals['revenue'] as num?)?.toInt() ?? 0;
        final range = _friendlyRange(
          data['from']?.toString(),
          data['to']?.toString(),
        );
        if (rows.isEmpty) {
          return ListView(
            padding: EdgeInsets.only(bottom: bottom + 12),
            children: const [
              EmptyState(
                title: 'No sales this month',
                subtitle: 'Confirmed invoices appear here by day.',
              ),
            ],
          );
        }
        return ListView(
          padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 16),
          children: [
            _HeroTotal(
              label: 'Revenue',
              value: Money.formatPesewas(revenue),
              note: range.isEmpty ? null : range,
            ),
            const SizedBox(height: 16),
            const Text(
              'BY DAY',
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.8,
                color: AppColors.mute,
              ),
            ),
            const SizedBox(height: 10),
            for (final raw in rows) ...[
              _ReportRow(
                title: '${(raw as Map)['period'] ?? raw['date'] ?? '—'}',
                subtitle: '${raw['count'] ?? raw['quantity'] ?? 0} sales',
                trailing: Text(
                  Money.formatPesewas((raw['revenue'] as num?)?.toInt() ?? 0),
                ),
              ),
              const SizedBox(height: 8),
            ],
          ],
        );
      },
    );
  }
}

class ProfitReportScreen extends StatelessWidget {
  const ProfitReportScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final parts = _monthRange().split('|');
    return _ReportLoader<Map<String, dynamic>>(
      title: 'Profit',
      subtitle: 'This month',
      load: (r) => r.profit(from: parts[0], to: parts[1]),
      builder: (context, data) {
        final bottom = MediaQuery.paddingOf(context).bottom;
        final totals = data['totals'] as Map<String, dynamic>? ?? {};
        final rows = (data['rows'] as List<dynamic>? ?? const []);
        final net = (totals['net_profit'] as num?)?.toInt() ?? 0;
        final revenue = (totals['revenue'] as num?)?.toInt() ?? 0;
        final cost = (totals['cost'] as num?)?.toInt() ?? 0;
        return ListView(
          padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 16),
          children: [
            _HeroTotal(
              label: 'Net profit',
              value: Money.formatPesewas(net),
              note: 'Revenue minus cost',
            ),
            const SizedBox(height: 12),
            _MetricStrip(
              children: [
                _MetricTile(
                  label: 'Revenue',
                  value: Money.formatPesewas(revenue),
                ),
                _MetricTile(
                  label: 'Cost',
                  value: Money.formatPesewas(cost),
                ),
              ],
            ),
            const SizedBox(height: 20),
            const Text(
              'BY PRODUCT',
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.8,
                color: AppColors.mute,
              ),
            ),
            const SizedBox(height: 10),
            if (rows.isEmpty)
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  border: Border.all(color: AppColors.line),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Text(
                  'No profit lines yet this month.',
                  style: TextStyle(
                    fontWeight: FontWeight.w600,
                    color: AppColors.mute,
                  ),
                ),
              )
            else
              for (final raw in rows.take(40)) ...[
                _ReportRow(
                  title:
                      '${(raw as Map)['label'] ?? raw['title'] ?? raw['name'] ?? 'Item'}',
                  subtitle: 'Qty ${(raw['quantity'] as num?)?.toInt() ?? 0}',
                  trailing: Text(
                    Money.formatPesewas(
                      (raw['gross_profit'] as num?)?.toInt() ?? 0,
                    ),
                  ),
                ),
                const SizedBox(height: 8),
              ],
          ],
        );
      },
    );
  }
}

class BestSellersReportScreen extends StatelessWidget {
  const BestSellersReportScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final parts = _monthRange().split('|');
    return _ReportLoader<Map<String, dynamic>>(
      title: 'Best sellers',
      subtitle: 'This month',
      load: (r) => r.bestSellers(from: parts[0], to: parts[1]),
      builder: (context, data) {
        final bottom = MediaQuery.paddingOf(context).bottom;
        final rows = (data['rows'] as List<dynamic>? ?? const []);
        if (rows.isEmpty) {
          return ListView(
            padding: EdgeInsets.only(bottom: bottom + 12),
            children: const [
              EmptyState(
                title: 'No sellers this month',
                subtitle: 'Confirmed sales will rank products here.',
              ),
            ],
          );
        }
        return ListView(
          padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 16),
          children: [
            for (var i = 0; i < rows.length; i++) ...[
              Builder(
                builder: (context) {
                  final raw = rows[i] as Map;
                  return _ReportRow(
                    title:
                        '${raw['title'] ?? raw['label'] ?? raw['name'] ?? 'Product'}',
                    subtitle:
                        '#${i + 1} · Qty ${(raw['quantity'] as num?)?.toInt() ?? 0}',
                    trailing: Text(
                      Money.formatPesewas(
                        (raw['revenue'] as num?)?.toInt() ?? 0,
                      ),
                    ),
                    onTap: raw['product_id'] == null
                        ? null
                        : () =>
                            context.push('/products/${raw['product_id']}'),
                  );
                },
              ),
              const SizedBox(height: 8),
            ],
          ],
        );
      },
    );
  }
}

class StockValuationReportScreen extends StatelessWidget {
  const StockValuationReportScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return _ReportLoader<Map<String, dynamic>>(
      title: 'Stock valuation',
      subtitle: 'On-hand inventory',
      load: (r) => r.stockValuation(),
      builder: (context, data) {
        final bottom = MediaQuery.paddingOf(context).bottom;
        final totals = data['totals'] as Map<String, dynamic>? ?? {};
        final atCost = (totals['value_at_cost'] as num?)?.toInt() ?? 0;
        final atPrice = (totals['value_at_price'] as num?)?.toInt() ?? 0;
        final units = (totals['counted_quantity'] as num?)?.toInt() ?? 0;
        return ListView(
          padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 16),
          children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.limeSoft,
                border: Border.all(color: AppColors.lime, width: 1.5),
                borderRadius: BorderRadius.circular(12),
              ),
              child: const Text(
                'What your shelves are worth right now — at what you paid, and at selling price.',
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  height: 1.35,
                ),
              ),
            ),
            const SizedBox(height: 14),
            _HeroTotal(
              label: 'Value at cost',
              value: Money.formatPesewas(atCost),
              note: '$units units on hand',
            ),
            const SizedBox(height: 12),
            _MetricStrip(
              children: [
                _MetricTile(
                  label: 'At selling',
                  value: Money.formatPesewas(atPrice),
                ),
                _MetricTile(label: 'Units', value: '$units'),
              ],
            ),
          ],
        );
      },
    );
  }
}

class DeadStockReportScreen extends StatelessWidget {
  const DeadStockReportScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return _ReportLoader<Map<String, dynamic>>(
      title: 'Dead stock',
      subtitle: 'No movement in 90 days',
      load: (r) => r.deadStock(),
      builder: (context, data) {
        final bottom = MediaQuery.paddingOf(context).bottom;
        final rows = (data['rows'] as List<dynamic>? ?? const []);
        if (rows.isEmpty) {
          return ListView(
            padding: EdgeInsets.only(bottom: bottom + 12),
            children: const [
              EmptyState(
                title: 'No dead stock',
                subtitle: 'Nothing sat idle for 90 days.',
              ),
            ],
          );
        }
        return ListView(
          padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 16),
          children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                border: Border.all(color: AppColors.line),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Text(
                '${rows.length} product${rows.length == 1 ? '' : 's'} with stock but no movement in 90 days.',
                style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  height: 1.35,
                ),
              ),
            ),
            const SizedBox(height: 14),
            for (final raw in rows) ...[
              _ReportRow(
                title: '${(raw as Map)['title'] ?? raw['name'] ?? 'Product'}',
                subtitle: raw['sku']?.toString() ?? '',
                trailing: Text(
                  '${(raw['stock_on_hand'] as num?)?.toInt() ?? 0}',
                ),
                onTap: raw['product_id'] == null
                    ? null
                    : () => context.push('/products/${raw['product_id']}'),
              ),
              const SizedBox(height: 8),
            ],
          ],
        );
      },
    );
  }
}
