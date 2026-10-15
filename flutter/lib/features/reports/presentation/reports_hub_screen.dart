import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';

/// Mobile index of report pages the API already exposes.
class ReportsHubScreen extends StatelessWidget {
  const ReportsHubScreen({super.key});

  static const _r = 12.0;

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    final bottom = MediaQuery.paddingOf(context).bottom;

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: ListView(
        padding: EdgeInsets.fromLTRB(20, top + 12, 20, bottom + 12),
        children: [
          const Text(
            'Reports',
            style: TextStyle(
              fontSize: 28,
              fontWeight: FontWeight.w900,
              letterSpacing: -0.6,
              height: 1,
            ),
          ),
          const SizedBox(height: 6),
          const Text(
            'Figures from the server',
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: AppColors.mute,
            ),
          ),
          const SizedBox(height: 18),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: AppColors.limeSoft,
              border: Border.all(color: AppColors.lime, width: 1.5),
              borderRadius: BorderRadius.circular(_r),
            ),
            child: const Row(
              children: [
                _IconBox(icon: AppIcons.reports, lime: true),
                SizedBox(width: 12),
                Expanded(
                  child: Text(
                    'All totals are computed on the server. Open a report to review the latest figures.',
                    style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      height: 1.35,
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 22),
          const _SectionLabel('Money'),
          const SizedBox(height: 10),
          _NavCard(
            icon: AppIcons.money,
            title: 'Who owes most',
            subtitle: 'Receivables aging by school',
            lime: true,
            onTap: () => context.push('/reports/owing'),
          ),
          const SizedBox(height: 8),
          _NavCard(
            icon: AppIcons.sales,
            title: 'Sales summary',
            subtitle: 'Confirmed sales by day this month',
            onTap: () => context.push('/reports/sales-summary'),
          ),
          const SizedBox(height: 8),
          _NavCard(
            icon: AppIcons.wallet,
            title: 'Profit',
            subtitle: 'Revenue minus cost this month',
            onTap: () => context.push('/reports/profit'),
          ),
          const SizedBox(height: 22),
          const _SectionLabel('Catalog & stock'),
          const SizedBox(height: 10),
          _NavCard(
            icon: AppIcons.products,
            title: 'Best sellers',
            subtitle: 'By quantity and revenue',
            onTap: () => context.push('/reports/best-sellers'),
          ),
          const SizedBox(height: 8),
          _NavCard(
            icon: AppIcons.alert,
            title: 'Low stock',
            subtitle: 'Below reorder level',
            onTap: () => context.push('/reports/low-stock'),
          ),
          const SizedBox(height: 8),
          _NavCard(
            icon: AppIcons.hourglass,
            title: 'Dead stock',
            subtitle: 'No movement in 90 days',
            onTap: () => context.push('/reports/dead-stock'),
          ),
          const SizedBox(height: 8),
          _NavCard(
            icon: AppIcons.stock,
            title: 'Stock valuation',
            subtitle: 'On hand at cost and selling price',
            onTap: () => context.push('/reports/stock-valuation'),
          ),
        ],
      ),
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text.toUpperCase(),
      style: const TextStyle(
        fontSize: 11,
        fontWeight: FontWeight.w800,
        letterSpacing: 0.8,
        color: AppColors.mute,
      ),
    );
  }
}

class _IconBox extends StatelessWidget {
  const _IconBox({required this.icon, this.lime = false});

  final IconData icon;
  final bool lime;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 40,
      height: 40,
      decoration: BoxDecoration(
        color: lime ? AppColors.lime : AppColors.wash,
        border: Border.all(
          color: lime ? AppColors.ink.withValues(alpha: 0.08) : AppColors.line,
        ),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Center(child: AppIcon(icon, size: 18)),
    );
  }
}

class _NavCard extends StatelessWidget {
  const _NavCard({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.lime = false,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final bool lime;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: lime ? AppColors.limeSoft : AppColors.surface,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            border: Border.all(
              color: lime ? AppColors.lime : AppColors.line,
              width: lime ? 1.5 : 1,
            ),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(
            children: [
              _IconBox(icon: icon, lime: lime),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      subtitle,
                      style: const TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w600,
                        color: AppColors.mute,
                      ),
                    ),
                  ],
                ),
              ),
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
  }
}
