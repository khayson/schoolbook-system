import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/features/reports/data/reports_repository.dart';
import 'package:schoolbook/features/reports/domain/report_models.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

/// Loads once, shows loading / error with retry / content.
class _ReportLoader<T> extends StatefulWidget {
  const _ReportLoader({
    required this.title,
    required this.load,
    required this.builder,
  });

  final String title;
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
      if (mounted) {
        setState(() => _data = data);
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = describeApiError(e).body);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    return Scaffold(
      appBar: AppBar(title: Text(widget.title)),
      body: _error != null
          ? ErrorState(message: _error!, onRetry: _load)
          : data == null
          ? const LoadingBody()
          : RefreshIndicator(
              onRefresh: _load,
              child: widget.builder(context, data),
            ),
    );
  }
}

/// Who owes most: customers by outstanding balance, with the overdue part.
class OwingReportScreen extends StatelessWidget {
  const OwingReportScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return _ReportLoader<AgingReport>(
      title: 'Who owes most',
      load: (r) => r.receivablesAging(),
      builder: (context, report) {
        final rows = report.byAmountOwed;
        if (rows.isEmpty) {
          return ListView(
            children: const [
              EmptyState(
                icon: Icons.check_circle_outline,
                title: 'Nobody owes you anything',
              ),
            ],
          );
        }
        return ListView(
          children: [
            ListTile(
              title: const Text('Total owed'),
              trailing: Text(
                Money.formatPesewas(report.total),
                key: const Key('owing_total'),
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
            const Divider(height: 1),
            for (final row in rows)
              ListTile(
                key: Key('owing_${row.customerId}'),
                title: Text(row.name),
                subtitle: Text(
                  row.overdue == 0
                      ? 'Nothing overdue'
                      : 'Overdue ${Money.formatPesewas(row.overdue)}'
                            '${row.days90Plus > 0 ? ' (over 90 days ${Money.formatPesewas(row.days90Plus)})' : ''}',
                ),
                trailing: Text(Money.formatPesewas(row.total)),
                onTap: () => context.push('/customers/${row.customerId}'),
              ),
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
      load: (r) => r.lowStock(),
      builder: (context, rows) {
        if (rows.isEmpty) {
          return ListView(
            children: const [
              EmptyState(
                icon: Icons.check_circle_outline,
                title: 'Nothing is low',
              ),
            ],
          );
        }
        final theme = Theme.of(context);
        return ListView(
          children: [
            for (final row in rows)
              ListTile(
                key: Key('low_${row.productId}'),
                title: Text(row.title),
                subtitle: Text('${row.sku} | reorder at ${row.reorderLevel}'),
                trailing: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      '${row.stockOnHand} left',
                      style: theme.textTheme.titleSmall,
                    ),
                    Text(
                      row.isOutOfStock
                          ? 'Out of stock'
                          : 'Short ${row.shortfall}',
                      style: TextStyle(
                        color: row.isOutOfStock
                            ? theme.colorScheme.error
                            : null,
                      ),
                    ),
                  ],
                ),
                onTap: () => context.push('/products/${row.productId}'),
              ),
          ],
        );
      },
    );
  }
}
