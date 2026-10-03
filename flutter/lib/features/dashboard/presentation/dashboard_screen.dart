import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';

class DashboardScreen extends StatelessWidget {
  const DashboardScreen({super.key});

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
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            'Hello, $name',
            style: theme.textTheme.headlineSmall?.copyWith(
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 8),
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
            onTap: () => context.push('/sales/new'),
          ),
          _ActionTile(
            icon: Icons.receipt_long_outlined,
            title: 'Sales',
            subtitle: 'Drafts, invoices, balances',
            onTap: () => context.push('/sales'),
          ),
          _ActionTile(
            icon: Icons.school_outlined,
            title: 'Customers',
            subtitle: 'Schools, balances, record payments',
            onTap: () => context.push('/customers'),
          ),
          _ActionTile(
            icon: Icons.payments_outlined,
            title: 'Payments',
            subtitle: 'Receipts, voids, share PDFs',
            onTap: () => context.push('/payments'),
          ),
          _ActionTile(
            icon: Icons.inventory_2_outlined,
            title: 'Products',
            subtitle: 'Browse, search, and edit catalog',
            onTap: () => context.push('/products'),
          ),
          _ActionTile(
            icon: Icons.move_to_inbox_outlined,
            title: 'Receive stock',
            subtitle: 'Record incoming goods and costs',
            onTap: () => context.push('/stock/receive'),
          ),
          _ActionTile(
            icon: Icons.qr_code_scanner,
            title: 'Scan',
            subtitle: 'Look up a product by barcode',
            onTap: () => context.push('/products/scan'),
          ),
        ],
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
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
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
