import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/features/reports/presentation/dashboard_cards.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  final _cards = GlobalKey<DashboardCardsState>();

  /// Opens [location] and refreshes the figures when the user comes back.
  Future<void> _open(String location) async {
    await context.push(location);
    _cards.currentState?.reload();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final auth = context.watch<AuthProvider>();
    final name = auth.user?.name ?? 'Owner';

    return Scaffold(
      appBar: AppBar(
        title: const Text('Schoolbook Supply'),
        actions: [
          IconButton(
            tooltip: 'Sign out',
            onPressed: auth.isBusy ? null : () => auth.logout(),
            icon: const Icon(Icons.logout),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () async => _cards.currentState?.reload(),
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              'Hello, $name',
              style: theme.textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 12),
            DashboardCards(key: _cards),
            const SizedBox(height: 16),
            Text(
              'Quick actions',
              style: theme.textTheme.titleMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 16),
            _ActionTile(
              icon: Icons.add_shopping_cart,
              title: 'New sale',
              subtitle: 'Draft an order and confirm the invoice',
              onTap: () => _open('/sales/new'),
            ),
            _ActionTile(
              icon: Icons.receipt_long_outlined,
              title: 'Sales',
              subtitle: 'Drafts, invoices, balances',
              onTap: () => _open('/sales'),
            ),
            _ActionTile(
              icon: Icons.school_outlined,
              title: 'Customers',
              subtitle: 'Schools, balances, record payments',
              onTap: () => _open('/customers'),
            ),
            _ActionTile(
              icon: Icons.payments_outlined,
              title: 'Payments',
              subtitle: 'Receipts, voids, share PDFs',
              onTap: () => _open('/payments'),
            ),
            _ActionTile(
              icon: Icons.inventory_2_outlined,
              title: 'Products',
              subtitle: 'Browse, search, and edit catalog',
              onTap: () => _open('/products'),
            ),
            _ActionTile(
              icon: Icons.move_to_inbox_outlined,
              title: 'Receive stock',
              subtitle: 'Record incoming goods and costs',
              onTap: () => _open('/stock/receive'),
            ),
            _ActionTile(
              icon: Icons.qr_code_scanner,
              title: 'Scan',
              subtitle: 'Look up a product by barcode',
              onTap: () => _open('/products/scan'),
            ),
            _ActionTile(
              icon: Icons.fact_check_outlined,
              title: 'Stock-take',
              subtitle: 'Count the shelves; the owner applies it on the web',
              onTap: () => _open('/stock/counts'),
            ),
          ],
        ),
      ),
    );
  }
}

class _ActionTile extends StatelessWidget {
  const _ActionTile({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 12,
        ),
        minVerticalPadding: 12,
        leading: Icon(icon, size: 32),
        title: Text(title),
        subtitle: Text(subtitle),
        trailing: const Icon(Icons.chevron_right),
        onTap: onTap,
      ),
    );
  }
}
