import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/stock/data/stock_counts_repository.dart';
import 'package:schoolbook/features/stock/domain/stock_count.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

/// Open stock-takes: continue one, or start a count of every active product.
class StockCountsScreen extends StatefulWidget {
  const StockCountsScreen({super.key});

  @override
  State<StockCountsScreen> createState() => _StockCountsScreenState();
}

class _StockCountsScreenState extends State<StockCountsScreen> {
  List<StockCount>? _counts;
  String? _error;
  bool _starting = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final counts = await context.read<StockCountsRepository>().openCounts();
      if (mounted) {
        setState(() => _counts = counts);
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = describeApiError(e).body);
      }
    }
  }

  Future<void> _start() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Start a stock-take?'),
        content: const Text(
          'Lists every active product. Count the shelves and enter what you find; the owner applies the count from the web admin.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const Key('start_count_confirm'),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Start'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) {
      return;
    }
    setState(() => _starting = true);
    final messenger = ScaffoldMessenger.of(context);
    final router = GoRouter.of(context);
    try {
      final count = await context.read<StockCountsRepository>().create();
      await router.push('/stock/counts/${count.id}');
      await _load();
    } on ApiException catch (e) {
      final d = describeApiError(e);
      messenger.showSnackBar(SnackBar(content: Text('${d.title}: ${d.body}')));
    } finally {
      if (mounted) {
        setState(() => _starting = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final counts = _counts;
    return Scaffold(
      appBar: AppBar(title: const Text('Stock-take')),
      floatingActionButton: FloatingActionButton.extended(
        key: const Key('start_count'),
        onPressed: _starting ? null : _start,
        icon: const Icon(Icons.playlist_add_check),
        label: const Text('New count'),
      ),
      body: _error != null
          ? ErrorState(message: _error!, onRetry: _load)
          : counts == null
          ? const LoadingBody()
          : counts.isEmpty
          ? const EmptyState(
              icon: Icons.inventory_outlined,
              title: 'No open counts',
              subtitle: 'Start one to count the shelves.',
            )
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  for (final c in counts)
                    Card(
                      child: ListTile(
                        key: Key('count_${c.id}'),
                        title: Text(c.reference),
                        subtitle: Text(
                          'Opened ${DateFormat('d MMM y, HH:mm').format(c.createdAt)}',
                        ),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () async {
                          await context.push('/stock/counts/${c.id}');
                          _load();
                        },
                      ),
                    ),
                ],
              ),
            ),
    );
  }
}
