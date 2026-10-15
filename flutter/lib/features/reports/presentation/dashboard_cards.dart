import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/reports/data/reports_repository.dart';
import 'package:schoolbook/features/reports/domain/report_models.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

/// Today's figures from `/reports/dashboard`.
class DashboardCards extends StatefulWidget {
  const DashboardCards({super.key});

  @override
  State<DashboardCards> createState() => DashboardCardsState();
}

class DashboardCardsState extends State<DashboardCards> {
  DashboardFigures? _figures;
  String? _error;

  static const _r = 12.0;

  @override
  void initState() {
    super.initState();
    reload();
  }

  Future<void> reload() async {
    setState(() => _error = null);
    try {
      final figures = await context.read<ReportsRepository>().dashboard();
      if (mounted) setState(() => _figures = figures);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = describeApiError(e).body);
    }
  }

  Future<void> _open(String location) async {
    await context.push(location);
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null) {
      return ErrorState(message: _error!, onRetry: reload);
    }
    final f = _figures;
    if (f == null) {
      return const Padding(
        padding: EdgeInsets.all(32),
        child: LoadingBody(),
      );
    }

    String sales(int n) => '$n ${n == 1 ? 'sale' : 'sales'}';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _LimeHero(
          key: const Key('card_sales_today'),
          label: 'Sales today',
          value: Money.formatPesewas(f.salesToday.revenue),
          note: sales(f.salesToday.count),
          onTap: () => _open('/sales'),
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: _WhiteMetric(
                key: const Key('card_sales_month'),
                label: 'This month',
                value: Money.formatPesewas(f.salesMonth.revenue),
                note: sales(f.salesMonth.count),
                onTap: () => _open('/sales'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _WhiteMetric(
                key: const Key('card_collections'),
                label: 'Collected today',
                value: Money.formatPesewas(f.collectionsToday),
                note: 'Month ${Money.formatPesewas(f.collectionsMonth)}',
                onTap: () => _open('/payments'),
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: _WhiteMetric(
                key: const Key('card_owed'),
                label: 'Owed to you',
                value: Money.formatPesewas(f.owed),
                onTap: () => _open('/reports/owing'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _WhiteMetric(
                key: const Key('card_credit'),
                label: 'Credit held',
                value: Money.formatPesewas(f.credit),
                onTap: () => _open('/customers'),
              ),
            ),
          ],
        ),
        if (f.overdue > 0 || f.lowStockCount > 0) ...[
          const SizedBox(height: 10),
          if (f.overdue > 0)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: _AlertChip(
                key: const Key('card_overdue'),
                label: 'Overdue',
                value: Money.formatPesewas(f.overdue),
                onTap: () => _open('/reports/owing'),
              ),
            ),
          if (f.lowStockCount > 0)
            _AlertChip(
              key: const Key('card_low_stock'),
              label: 'Low stock',
              value: '${f.lowStockCount}',
              note: 'products',
              onTap: () => _open('/reports/low-stock'),
            ),
        ] else
          Offstage(
            child: Column(
              children: [
                _AlertChip(
                  key: const Key('card_overdue'),
                  label: 'Overdue',
                  value: Money.formatPesewas(f.overdue),
                  onTap: () => _open('/reports/owing'),
                ),
                _AlertChip(
                  key: const Key('card_low_stock'),
                  label: 'Low stock',
                  value: '${f.lowStockCount}',
                  note: 'products',
                  onTap: () => _open('/reports/low-stock'),
                ),
              ],
            ),
          ),
      ],
    );
  }
}

class _LimeHero extends StatelessWidget {
  const _LimeHero({
    super.key,
    required this.label,
    required this.value,
    this.note,
    this.onTap,
  });

  final String label;
  final String value;
  final String? note;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.lime,
      borderRadius: BorderRadius.circular(DashboardCardsState._r),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(DashboardCardsState._r),
        child: Container(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 14),
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.ink.withValues(alpha: 0.08)),
            borderRadius: BorderRadius.circular(DashboardCardsState._r),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w800,
                  color: AppColors.ink,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                value,
                style: const TextStyle(
                  fontSize: 28,
                  fontWeight: FontWeight.w900,
                  letterSpacing: -0.6,
                  color: AppColors.ink,
                  fontFeatures: [FontFeature.tabularFigures()],
                ),
              ),
              if (note != null) ...[
                const SizedBox(height: 4),
                Text(
                  note!,
                  style: const TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: AppColors.ink,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _WhiteMetric extends StatelessWidget {
  const _WhiteMetric({
    super.key,
    required this.label,
    required this.value,
    this.note,
    this.onTap,
  });

  final String label;
  final String value;
  final String? note;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.surface,
      borderRadius: BorderRadius.circular(DashboardCardsState._r),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(DashboardCardsState._r),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.line),
            borderRadius: BorderRadius.circular(DashboardCardsState._r),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: const TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: AppColors.mute,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                value,
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w900,
                  letterSpacing: -0.3,
                  fontFeatures: [FontFeature.tabularFigures()],
                ),
              ),
              if (note != null) ...[
                const SizedBox(height: 4),
                Text(
                  note!,
                  style: const TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: AppColors.mute,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _AlertChip extends StatelessWidget {
  const _AlertChip({
    super.key,
    required this.label,
    required this.value,
    this.note,
    this.onTap,
  });

  final String label;
  final String value;
  final String? note;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.warn.withValues(alpha: 0.08),
      borderRadius: BorderRadius.circular(DashboardCardsState._r),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(DashboardCardsState._r),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.warn, width: 1.5),
            borderRadius: BorderRadius.circular(DashboardCardsState._r),
          ),
          child: Row(
            children: [
              const AppIcon(AppIcons.alert, color: AppColors.warn, size: 20),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  label,
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w800,
                    color: AppColors.warn,
                  ),
                ),
              ),
              Text(
                value,
                style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w800,
                  color: AppColors.warn,
                  fontFeatures: [FontFeature.tabularFigures()],
                ),
              ),
              if (note != null) ...[
                const SizedBox(width: 4),
                Text(
                  note!,
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w800,
                    color: AppColors.warn,
                  ),
                ),
              ],
              const SizedBox(width: 4),
              const AppIcon(
                AppIcons.chevronRight,
                color: AppColors.warn,
                size: 18,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
