import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';

class StockHubScreen extends StatelessWidget {
  const StockHubScreen({super.key});

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
            'Stock',
            style: TextStyle(
              fontSize: 28,
              fontWeight: FontWeight.w900,
              letterSpacing: -0.6,
              height: 1,
            ),
          ),
          const SizedBox(height: 6),
          const Text(
            'Receive, adjust, count',
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
                _IconBox(icon: AppIcons.stock, lime: true),
                SizedBox(width: 12),
                Expanded(
                  child: Text(
                    'Stock only moves through receipts, adjustments, and applied counts — never by editing on-hand directly.',
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
          const _SectionLabel('Movements'),
          const SizedBox(height: 10),
          _NavCard(
            icon: AppIcons.receive,
            title: 'Receive stock',
            subtitle: 'Incoming goods and unit cost',
            lime: true,
            onTap: () => context.push('/stock/receive'),
          ),
          const SizedBox(height: 8),
          _NavCard(
            icon: AppIcons.stock,
            title: 'Adjust stock',
            subtitle: 'Correction or damage with a note',
            onTap: () => context.push('/stock/adjust'),
          ),
          const SizedBox(height: 8),
          _NavCard(
            icon: AppIcons.delivery,
            title: 'Goods receipts',
            subtitle: 'Past receipts',
            onTap: () => context.push('/stock/receipts'),
          ),
          const SizedBox(height: 22),
          const _SectionLabel('Count'),
          const SizedBox(height: 10),
          _NavCard(
            icon: AppIcons.check,
            title: 'Stock-take',
            subtitle: 'Count shelves; apply on the web if needed',
            onTap: () => context.push('/stock/counts'),
          ),
          const SizedBox(height: 22),
          const _SectionLabel('Catalog'),
          const SizedBox(height: 10),
          _NavCard(
            icon: AppIcons.products,
            title: 'Products',
            subtitle: 'Browse, search, edit',
            onTap: () => context.push('/products'),
          ),
          const SizedBox(height: 8),
          _NavCard(
            icon: AppIcons.scan,
            title: 'Scan barcode',
            subtitle: 'Find or attach a code',
            onTap: () => context.push('/products/scan'),
          ),
          const SizedBox(height: 8),
          _NavCard(
            icon: AppIcons.alert,
            title: 'Low stock',
            subtitle: 'Below reorder level',
            onTap: () => context.push('/reports/low-stock'),
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
