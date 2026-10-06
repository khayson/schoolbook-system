import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/stock/data/stock_counts_repository.dart';
import 'package:schoolbook/features/stock/domain/stock_count.dart';
import 'package:schoolbook/features/stock/presentation/stock_count_controller.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

/// Count entry on the phone: scan or search a product, type what is on the shelf.
/// Applying the count is done by the owner on the web admin.
class StockCountScreen extends StatelessWidget {
  const StockCountScreen({super.key, required this.countId});

  final int countId;

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider(
      create: (context) => StockCountController(
        repository: context.read<StockCountsRepository>(),
        countId: countId,
      )..load(),
      child: const _StockCountView(),
    );
  }
}

class _StockCountView extends StatelessWidget {
  const _StockCountView();

  @override
  Widget build(BuildContext context) {
    final c = context.watch<StockCountController>();
    final count = c.count;
    return Scaffold(
      appBar: AppBar(
        title: Text(count?.reference ?? 'Stock-take'),
        actions: [
          if (count != null && count.isOpen)
            IconButton(
              key: const Key('count_scan'),
              tooltip: 'Scan a product',
              icon: const Icon(Icons.qr_code_scanner),
              onPressed: () => _scan(context),
            ),
        ],
      ),
      body: c.loadError != null
          ? ErrorState(message: c.loadError!, onRetry: c.load)
          : count == null
          ? const LoadingBody()
          : Column(
              children: [
                _Header(count: count, controller: c),
                Expanded(
                  child: _ItemsList(count: count, controller: c),
                ),
              ],
            ),
    );
  }

  Future<void> _scan(BuildContext context) async {
    final controller = context.read<StockCountController>();
    final messenger = ScaffoldMessenger.of(context);
    final product = await context.push<Product>('/products/scan?pick=1');
    if (product == null || !context.mounted) {
      return;
    }
    final item = controller.itemFor(product.id);
    if (item == null) {
      messenger.showSnackBar(
        SnackBar(content: Text('${product.title} is not in this count.')),
      );
      return;
    }
    await enterQuantity(context, item);
  }
}

/// Asks for the counted quantity of [item] and saves it.
Future<void> enterQuantity(BuildContext context, StockCountItem item) async {
  final controller = context.read<StockCountController>();
  final messenger = ScaffoldMessenger.of(context);
  final value = await showDialog<_Entry>(
    context: context,
    builder: (context) => _QuantityDialog(item: item),
  );
  if (value == null) {
    return;
  }
  final error = await controller.enter(item.productId, value.quantity);
  if (error != null) {
    messenger.showSnackBar(SnackBar(content: Text(error)));
  }
}

class _Entry {
  const _Entry(this.quantity);

  final int? quantity;
}

class _QuantityDialog extends StatefulWidget {
  const _QuantityDialog({required this.item});

  final StockCountItem item;

  @override
  State<_QuantityDialog> createState() => _QuantityDialogState();
}

class _QuantityDialogState extends State<_QuantityDialog> {
  late final TextEditingController _text = TextEditingController(
    text: widget.item.countedQty?.toString() ?? '',
  );
  String? _error;

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  void _save() {
    final value = int.tryParse(_text.text.trim());
    if (value == null || value < 0 || value > 1000000) {
      setState(() => _error = 'Enter a whole number, 0 or more.');
      return;
    }
    Navigator.pop(context, _Entry(value));
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(widget.item.title),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(widget.item.sku),
          const SizedBox(height: 12),
          TextField(
            key: const Key('count_qty_field'),
            controller: _text,
            autofocus: true,
            keyboardType: TextInputType.number,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            decoration: InputDecoration(
              labelText: 'Counted on the shelf',
              errorText: _error,
            ),
            onSubmitted: (_) => _save(),
          ),
        ],
      ),
      actions: [
        if (widget.item.isCounted)
          TextButton(
            key: const Key('count_qty_clear'),
            onPressed: () => Navigator.pop(context, const _Entry(null)),
            child: const Text('Clear'),
          ),
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const Key('count_qty_save'),
          onPressed: _save,
          child: const Text('Save'),
        ),
      ],
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.count, required this.controller});

  final StockCount count;
  final StockCountController controller;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final totals = count.totals;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '${controller.countedItems} of ${controller.totalItems} counted',
            key: const Key('count_progress'),
            style: theme.textTheme.titleMedium,
          ),
          const SizedBox(height: 6),
          LinearProgressIndicator(value: controller.progress),
          if (totals != null) ...[
            const SizedBox(height: 6),
            Text(
              'Variance so far: ${totals.varianceUnits > 0 ? '+' : ''}${totals.varianceUnits} units, ${Money.formatPesewas(totals.varianceValue)} at cost',
              key: const Key('count_variance_total'),
            ),
          ],
          if (!count.isOpen)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                'This count is ${count.status}; entries are closed.',
                style: TextStyle(color: theme.colorScheme.error),
              ),
            )
          else
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                'Applying the count is done from the web admin.',
                style: theme.textTheme.bodySmall,
              ),
            ),
          const SizedBox(height: 8),
          TextField(
            key: const Key('count_search'),
            decoration: const InputDecoration(
              prefixIcon: Icon(Icons.search),
              hintText: 'Search SKU or title',
              isDense: true,
            ),
            onChanged: controller.setQuery,
          ),
          const SizedBox(height: 8),
          SegmentedButton<CountFilter>(
            key: const Key('count_filter'),
            segments: const [
              ButtonSegment(value: CountFilter.all, label: Text('All')),
              ButtonSegment(
                value: CountFilter.uncounted,
                label: Text('To count'),
              ),
              ButtonSegment(
                value: CountFilter.variances,
                label: Text('Variances'),
              ),
            ],
            selected: {controller.filter},
            onSelectionChanged: (s) => controller.setFilter(s.first),
          ),
        ],
      ),
    );
  }
}

class _ItemsList extends StatelessWidget {
  const _ItemsList({required this.count, required this.controller});

  final StockCount count;
  final StockCountController controller;

  @override
  Widget build(BuildContext context) {
    final items = controller.visibleItems;
    if (items.isEmpty) {
      return const EmptyState(icon: Icons.search_off, title: 'Nothing to show');
    }
    final theme = Theme.of(context);
    return RefreshIndicator(
      onRefresh: controller.load,
      child: ListView.builder(
        itemCount: items.length,
        itemBuilder: (context, index) {
          final item = items[index];
          final variance = item.variance;
          return ListTile(
            key: Key('count_item_${item.productId}'),
            title: Text(item.title),
            subtitle: Text(
              item.isCounted
                  ? '${item.sku} | system ${item.systemQty}'
                  : item.sku,
            ),
            trailing: controller.savingProductId == item.productId
                ? const SizedBox(
                    width: 24,
                    height: 24,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(
                        item.isCounted ? '${item.countedQty}' : '-',
                        style: theme.textTheme.titleMedium,
                      ),
                      if (variance != null && variance != 0)
                        Text(
                          '${variance > 0 ? '+' : ''}$variance',
                          style: TextStyle(
                            color: variance < 0
                                ? theme.colorScheme.error
                                : Colors.green.shade700,
                          ),
                        ),
                    ],
                  ),
            onTap: count.isOpen ? () => enterQuantity(context, item) : null,
          );
        },
      ),
    );
  }
}
