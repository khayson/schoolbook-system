import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/features/reports/presentation/dashboard_cards.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  final _cards = GlobalKey<DashboardCardsState>();

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final name = auth.user?.name ?? 'Owner';
    final first = name.split(' ').first;
    final top = MediaQuery.paddingOf(context).top;
    final bottom = MediaQuery.paddingOf(context).bottom;

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: RefreshIndicator(
        color: AppColors.ink,
        backgroundColor: AppColors.lime,
        onRefresh: () async => _cards.currentState?.reload(),
        child: ListView(
          padding: EdgeInsets.fromLTRB(20, top + 12, 20, bottom + 12),
          children: [
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Schoolbook',
                        style: TextStyle(
                          fontSize: 28,
                          fontWeight: FontWeight.w900,
                          letterSpacing: -0.6,
                          height: 1,
                          color: AppColors.ink,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        'Hello, $first',
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                          color: AppColors.mute,
                        ),
                      ),
                    ],
                  ),
                ),
                _SquareIconButton(
                  icon: AppIcons.scan,
                  onTap: () => context.push('/products/scan'),
                ),
                const SizedBox(width: 8),
                _SquareIconButton(
                  icon: AppIcons.logout,
                  onTap: auth.isBusy ? null : () => auth.logout(),
                ),
              ],
            ),
            const SizedBox(height: 18),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  _NavChip(label: 'Today', active: true, onTap: () {}),
                  const SizedBox(width: 8),
                  _NavChip(label: 'Sales', onTap: () => context.go('/sales')),
                  const SizedBox(width: 8),
                  _NavChip(
                    label: 'Schools',
                    onTap: () => context.go('/customers'),
                  ),
                  const SizedBox(width: 8),
                  _NavChip(label: 'Stock', onTap: () => context.go('/stock')),
                  const SizedBox(width: 8),
                  _NavChip(
                    label: 'Reports',
                    onTap: () => context.push('/reports'),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            DashboardCards(key: _cards),
            const SizedBox(height: 18),
            const Text(
              'QUICK ACTIONS',
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.8,
                color: AppColors.mute,
              ),
            ),
            const SizedBox(height: 10),
            _QuickCard(
              title: 'Receive stock',
              subtitle: 'Incoming goods and unit cost',
              icon: AppIcons.receive,
              lime: true,
              onTap: () => context.push('/stock/receive'),
            ),
            const SizedBox(height: 8),
            _QuickCard(
              title: 'Record payment',
              subtitle: 'Against a school’s invoices',
              icon: AppIcons.payment,
              onTap: () => context.push('/customers'),
            ),
            const SizedBox(height: 8),
            _QuickCard(
              title: 'Products',
              subtitle: 'Catalog, approved list, barcodes',
              icon: AppIcons.products,
              onTap: () => context.push('/products'),
            ),
          ],
        ),
      ),
    );
  }
}

class _SquareIconButton extends StatelessWidget {
  const _SquareIconButton({required this.icon, this.onTap});

  final IconData icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.wash,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Container(
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.line),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Center(child: AppIcon(icon, size: 20, color: AppColors.ink)),
        ),
      ),
    );
  }
}

class _NavChip extends StatelessWidget {
  const _NavChip({
    required this.label,
    required this.onTap,
    this.active = false,
  });

  final String label;
  final bool active;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: active ? AppColors.ink : AppColors.surface,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: BoxDecoration(
            border: Border.all(
              color: active ? AppColors.ink : AppColors.line,
            ),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Text(
            label,
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w800,
              color: active ? AppColors.lime : AppColors.ink,
            ),
          ),
        ),
      ),
    );
  }
}

class _QuickCard extends StatelessWidget {
  const _QuickCard({
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.onTap,
    this.lime = false,
  });

  final String title;
  final String subtitle;
  final IconData icon;
  final VoidCallback onTap;
  final bool lime;

  static const _r = 12.0;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: lime ? AppColors.limeSoft : AppColors.surface,
      borderRadius: BorderRadius.circular(_r),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(_r),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            border: Border.all(
              color: lime ? AppColors.lime : AppColors.line,
              width: lime ? 1.5 : 1,
            ),
            borderRadius: BorderRadius.circular(_r),
          ),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: lime ? AppColors.lime : AppColors.wash,
                  border: Border.all(
                    color: lime
                        ? AppColors.ink.withValues(alpha: 0.08)
                        : AppColors.line,
                  ),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Center(
                  child: AppIcon(icon, color: AppColors.ink, size: 18),
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
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      subtitle,
                      style: const TextStyle(
                        fontSize: 12.5,
                        color: AppColors.mute,
                        fontWeight: FontWeight.w600,
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
