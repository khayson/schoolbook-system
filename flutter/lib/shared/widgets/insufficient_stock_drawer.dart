import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/sales/presentation/sale_actions_controller.dart';
import 'package:schoolbook/features/stock/data/stock_repository.dart';
import 'package:schoolbook/shared/widgets/app_drawer.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';

/// Bottom drawer for `insufficient_stock`: shows each short book and lets staff
/// receive the missing qty in place, then retry confirm.
///
/// Returns `true` when the user taps **Try confirm again**.
Future<bool?> showInsufficientStockDrawer(
  BuildContext context, {
  required List<ShortItem> items,
}) {
  return showAppDrawer<bool>(
    context: context,
    useRootNavigator: true,
    builder: (_) => InsufficientStockDrawer(items: items),
  );
}

class InsufficientStockDrawer extends StatefulWidget {
  const InsufficientStockDrawer({super.key, required this.items});

  final List<ShortItem> items;

  @override
  State<InsufficientStockDrawer> createState() =>
      _InsufficientStockDrawerState();
}

class _LineState {
  _LineState(this.item)
    : available = item.available,
      qty = TextEditingController(
        text: '${item.shortfall > 0 ? item.shortfall : 1}',
      ),
      cost = TextEditingController();

  final ShortItem item;
  int available;
  final TextEditingController qty;
  final TextEditingController cost;
  bool loadingCost = true;
  bool saving = false;
  String? error;

  int get need => item.requested;
  int get short => (need - available).clamp(0, 1 << 30);
  bool get covered => available >= need;

  void dispose() {
    qty.dispose();
    cost.dispose();
  }
}

class _InsufficientStockDrawerState extends State<InsufficientStockDrawer> {
  late final List<_LineState> _lines;
  String? _bannerError;

  @override
  void initState() {
    super.initState();
    _lines = widget.items.map(_LineState.new).toList();
    _loadCosts();
  }

  @override
  void dispose() {
    for (final line in _lines) {
      line.dispose();
    }
    super.dispose();
  }

  Future<void> _loadCosts() async {
    final products = context.read<ProductsRepository>();
    await Future.wait(
      _lines.map((line) async {
        try {
          final product = await products.getProduct(line.item.productId);
          if (!mounted) return;
          line.cost.text = Money.toGhsInput(product.costPrice);
        } catch (_) {
          if (!mounted) return;
          line.cost.text = '0';
        } finally {
          line.loadingCost = false;
        }
      }),
    );
    if (mounted) setState(() {});
  }

  Future<void> _addStock(_LineState line) async {
    final addQty = int.tryParse(line.qty.text.trim()) ?? 0;
    final unitCost = Money.parseGhsToPesewas(line.cost.text);
    if (addQty < 1) {
      setState(() => line.error = 'Enter how many to add.');
      return;
    }
    if (unitCost == null || unitCost < 0) {
      setState(() => line.error = 'Enter a valid unit cost (GHS).');
      return;
    }

    setState(() {
      line.saving = true;
      line.error = null;
      _bannerError = null;
    });

    final key =
        'short-stock.${line.item.productId}.${DateTime.now().millisecondsSinceEpoch}.${Random().nextInt(1 << 20)}';

    try {
      await context.read<StockRepository>().createReceipt(
        idempotencyKey: key,
        notes: 'Added from not-enough-stock drawer',
        items: [
          {
            'product_id': line.item.productId,
            'quantity': addQty,
            'unit_cost': unitCost,
          },
        ],
      );
      if (!mounted) return;
      setState(() {
        line.available += addQty;
        line.saving = false;
        final stillShort = line.short;
        line.qty.text = '${stillShort > 0 ? stillShort : 1}';
      });
      AppToast.show(
        context,
        'Added $addQty to ${line.item.title}',
        type: ToastType.success,
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        line.saving = false;
        line.error = e.message;
      });
    }
  }

  bool get _allCovered => _lines.every((l) => l.covered);

  @override
  Widget build(BuildContext context) {
    final height = MediaQuery.sizeOf(context).height;

    return AppDrawerScaffold(
      child: SizedBox(
        key: const Key('insufficient_stock_drawer'),
        height: height * 0.78,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Not enough stock',
              style: TextStyle(fontWeight: FontWeight.w900, fontSize: 20),
            ),
            const SizedBox(height: 6),
            const Text(
              'Nothing was confirmed. Add the short books below '
              '(receive into stock), then try confirm again.',
              style: TextStyle(
                fontWeight: FontWeight.w600,
                color: AppColors.mute,
                height: 1.35,
              ),
            ),
            if (_bannerError != null) ...[
              const SizedBox(height: 10),
              Text(
                _bannerError!,
                style: const TextStyle(
                  color: AppColors.danger,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
            const SizedBox(height: 14),
            Expanded(
              child: ListView.separated(
                itemCount: _lines.length,
                separatorBuilder: (_, _) => const SizedBox(height: 10),
                itemBuilder: (context, i) => _ShortBookCard(
                  line: _lines[i],
                  onAdd: () => _addStock(_lines[i]),
                ),
              ),
            ),
            const SizedBox(height: 12),
            FilledButton(
              key: const Key('insufficient_stock_retry'),
              onPressed: _allCovered
                  ? () => Navigator.pop(context, true)
                  : null,
              child: Text(
                _allCovered
                    ? 'Try confirm again'
                    : 'Cover every short book first',
              ),
            ),
            const SizedBox(height: 8),
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Close'),
            ),
          ],
        ),
      ),
    );
  }
}

class _ShortBookCard extends StatelessWidget {
  const _ShortBookCard({required this.line, required this.onAdd});

  final _LineState line;
  final VoidCallback onAdd;

  @override
  Widget build(BuildContext context) {
    final item = line.item;
    return Container(
      key: Key('insufficient_stock_row_${item.productId}'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: line.covered ? const Color(0xFFECFDF3) : AppColors.wash,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: line.covered ? const Color(0xFF86EFAC) : AppColors.line,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.title,
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      item.sku,
                      style: const TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: AppColors.mute,
                      ),
                    ),
                  ],
                ),
              ),
              if (line.covered)
                const AppIcon(
                  AppIcons.check,
                  size: 22,
                  color: Color(0xFF15803D),
                ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              _Stat(label: 'Need', value: '${line.need}', warn: true),
              const SizedBox(width: 8),
              _Stat(label: 'Have', value: '${line.available}'),
              const SizedBox(width: 8),
              _Stat(
                label: 'Short',
                value: '${line.short}',
                warn: line.short > 0,
              ),
            ],
          ),
          if (!line.covered) ...[
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: line.qty,
                    keyboardType: TextInputType.number,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: const InputDecoration(
                      labelText: 'Add qty',
                      isDense: true,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  flex: 2,
                  child: TextField(
                    controller: line.cost,
                    enabled: !line.loadingCost,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(
                      labelText: 'Unit cost (GHS)',
                      isDense: true,
                    ),
                  ),
                ),
              ],
            ),
            if (line.error != null) ...[
              const SizedBox(height: 6),
              Text(
                line.error!,
                style: const TextStyle(
                  color: AppColors.danger,
                  fontWeight: FontWeight.w700,
                  fontSize: 12.5,
                ),
              ),
            ],
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                key: Key('insufficient_stock_add_${item.productId}'),
                onPressed: line.saving || line.loadingCost ? null : onAdd,
                icon: line.saving
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.add, size: 18),
                label: Text(line.saving ? 'Adding…' : 'Add to stock'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value, this.warn = false});

  final String label;
  final String value;
  final bool warn;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 8),
        decoration: BoxDecoration(
          color: warn ? const Color(0xFFFFE4E6) : AppColors.surface,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          children: [
            Text(
              label,
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w700,
                color: warn ? AppColors.danger : AppColors.mute,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              value,
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.w900,
                color: warn ? AppColors.danger : AppColors.ink,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
