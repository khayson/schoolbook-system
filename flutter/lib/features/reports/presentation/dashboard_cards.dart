import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/features/reports/data/reports_repository.dart';
import 'package:schoolbook/features/reports/domain/report_models.dart';

/// Today's figures from `/reports/dashboard`; each card opens the matching list.
class DashboardCards extends StatefulWidget {
  const DashboardCards({super.key});

  @override
  State<DashboardCards> createState() => DashboardCardsState();
}

class DashboardCardsState extends State<DashboardCards> {
  DashboardFigures? _figures;
  String? _error;

  @override
  void initState() {
    super.initState();
    reload();
  }

  Future<void> reload() async {
    setState(() => _error = null);
    try {
      final figures = await context.read<ReportsRepository>().dashboard();
      if (mounted) {
        setState(() => _figures = figures);
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = describeApiError(e).body);
      }
    }
  }

  Future<void> _open(String location) async {
    await context.push(location);
    if (mounted) {
      reload();
    }
  }

  @override
  Widget build(BuildContext context) {
    final f = _figures;
    if (_error != null) {
      return Card(
        child: ListTile(
          leading: const Icon(Icons.error_outline),
          title: const Text('Figures unavailable'),
          subtitle: Text(_error!),
          trailing: TextButton(onPressed: reload, child: const Text('Retry')),
        ),
      );
    }
    if (f == null) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    String sales(int n) => '$n ${n == 1 ? 'sale' : 'sales'}';
    final cards = [
      _FigureCard(
        key: const Key('card_sales_today'),
        label: 'Sales today',
        value: Money.formatPesewas(f.salesToday.revenue),
        note: sales(f.salesToday.count),
        onTap: () => _open('/sales'),
      ),
      _FigureCard(
        key: const Key('card_sales_month'),
        label: 'This month',
        value: Money.formatPesewas(f.salesMonth.revenue),
        note: sales(f.salesMonth.count),
        onTap: () => _open('/sales'),
      ),
      _FigureCard(
        key: const Key('card_collections'),
        label: 'Collected today',
        value: Money.formatPesewas(f.collectionsToday),
        note: 'Month ${Money.formatPesewas(f.collectionsMonth)}',
        onTap: () => _open('/payments'),
      ),
      _FigureCard(
        key: const Key('card_owed'),
        label: 'Owed to you',
        value: Money.formatPesewas(f.owed),
        onTap: () => _open('/reports/owing'),
      ),
      _FigureCard(
        key: const Key('card_overdue'),
        label: 'Overdue',
        value: Money.formatPesewas(f.overdue),
        warn: f.overdue > 0,
        onTap: () => _open('/reports/owing'),
      ),
      _FigureCard(
        key: const Key('card_credit'),
        label: 'Credit held',
        value: Money.formatPesewas(f.credit),
        onTap: () => _open('/customers'),
      ),
      _FigureCard(
        key: const Key('card_low_stock'),
        label: 'Low stock',
        value: '${f.lowStockCount}',
        note: 'products',
        warn: f.lowStockCount > 0,
        onTap: () => _open('/reports/low-stock'),
      ),
    ];
    return LayoutBuilder(
      builder: (context, constraints) {
        final width = (constraints.maxWidth - 8) / 2;
        return Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final card in cards) SizedBox(width: width, child: card),
          ],
        );
      },
    );
  }
}

class _FigureCard extends StatelessWidget {
  const _FigureCard({
    super.key,
    required this.label,
    required this.value,
    this.note,
    this.warn = false,
    required this.onTap,
  });

  final String label;
  final String value;
  final String? note;
  final bool warn;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      margin: EdgeInsets.zero,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: theme.textTheme.labelMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 4),
              FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: Text(
                  value,
                  style: theme.textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w600,
                    color: warn ? theme.colorScheme.error : null,
                  ),
                ),
              ),
              if (note != null) Text(note!, style: theme.textTheme.bodySmall),
            ],
          ),
        ),
      ),
    );
  }
}
