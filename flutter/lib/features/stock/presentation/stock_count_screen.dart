import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/stock/data/stock_counts_repository.dart';
import 'package:schoolbook/features/stock/domain/stock_count.dart';
import 'package:schoolbook/features/stock/presentation/stock_count_controller.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_drawer.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

/// Count entry on the phone: scan or tap a product, type what is on the shelf.
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
    return AppPage(
      title: count?.reference ?? 'Stock-take',
      subtitle: 'Shelf count',
      actions: [
        if (count != null && count.isOpen)
          IconButton(
            key: const Key('count_scan'),
            tooltip: 'Scan a product',
            icon: const AppIcon(AppIcons.scan, size: 22),
            onPressed: () => _scan(context),
          ),
      ],
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
                    if (count.isOpen) _NextBar(controller: c),
                  ],
                ),
    );
  }

  Future<void> _scan(BuildContext context) async {
    final controller = context.read<StockCountController>();
    final product = await context.push<Product>('/products/scan?pick=1');
    if (product == null || !context.mounted) {
      return;
    }
    final item = controller.itemFor(product.id);
    if (item == null) {
      AppToast.show(
        context,
        '${product.title} is not in this count.',
        type: ToastType.warning,
      );
      return;
    }
    await enterQuantity(context, item);
  }
}

class _QtyOutcome {
  const _QtyOutcome.save(this.quantity, {this.andNext = false})
      : clear = false;

  const _QtyOutcome.clear()
      : quantity = null,
        andNext = false,
        clear = true;

  final int? quantity;
  final bool andNext;
  final bool clear;
}

/// Asks for the counted quantity of [item] in an app drawer and saves it.
Future<void> enterQuantity(BuildContext context, StockCountItem item) async {
  final controller = context.read<StockCountController>();
  final outcome = await showAppDrawer<_QtyOutcome>(
    context: context,
    builder: (context) => _QuantityDrawer(item: item),
  );
  if (outcome == null || !context.mounted) {
    return;
  }
  final error = await controller.enter(
    item.productId,
    outcome.clear ? null : outcome.quantity,
  );
  if (!context.mounted) {
    return;
  }
  if (error != null) {
    AppToast.show(context, error, type: ToastType.error);
    return;
  }
  final updated = controller.itemFor(item.productId);
  if (updated != null && updated.isCounted) {
    final v = updated.variance ?? 0;
    AppToast.show(
      context,
      v == 0
          ? 'Matches system (${updated.systemQty}).'
          : 'Variance ${v > 0 ? '+' : ''}$v · system ${updated.systemQty}.',
      type: v == 0 ? ToastType.success : ToastType.warning,
    );
  } else if (outcome.clear) {
    AppToast.show(context, 'Count cleared.', type: ToastType.info);
  }
  if (outcome.andNext && context.mounted) {
    final next = controller.nextUncounted;
    if (next != null) {
      await enterQuantity(context, next);
    }
  }
}

class _QuantityDrawer extends StatefulWidget {
  const _QuantityDrawer({required this.item});

  final StockCountItem item;

  @override
  State<_QuantityDrawer> createState() => _QuantityDrawerState();
}

class _QuantityDrawerState extends State<_QuantityDrawer> {
  late final TextEditingController _text = TextEditingController(
    text: widget.item.countedQty?.toString() ?? '',
  );
  String? _error;

  static const _r = 12.0;

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  void _bump(int delta) {
    final current = int.tryParse(_text.text.trim()) ?? 0;
    final next = (current + delta).clamp(0, 1000000);
    _text.text = '$next';
    _text.selection = TextSelection.collapsed(offset: _text.text.length);
    setState(() => _error = null);
  }

  void _save({required bool andNext}) {
    final value = int.tryParse(_text.text.trim());
    if (value == null || value < 0 || value > 1000000) {
      setState(() => _error = 'Enter a whole number, 0 or more.');
      return;
    }
    Navigator.pop(context, _QtyOutcome.save(value, andNext: andNext));
  }

  @override
  Widget build(BuildContext context) {
    final item = widget.item;
    return AppDrawerScaffold(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            item.title,
            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900),
          ),
          const SizedBox(height: 4),
          Text(
            item.isCounted
                ? '${item.sku} · system ${item.systemQty}'
                : '${item.sku} · enter what you see on the shelf',
            style: const TextStyle(
              fontWeight: FontWeight.w600,
              color: AppColors.mute,
            ),
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              _StepperButton(
                icon: AppIcons.remove,
                onPressed: () => _bump(-1),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: TextField(
                  key: const Key('count_qty_field'),
                  controller: _text,
                  autofocus: true,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    fontSize: 28,
                    fontWeight: FontWeight.w900,
                  ),
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: InputDecoration(
                    labelText: 'Counted on the shelf',
                    errorText: _error,
                    enabledBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(_r),
                      borderSide: const BorderSide(color: AppColors.line),
                    ),
                    focusedBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(_r),
                      borderSide:
                          const BorderSide(color: AppColors.ink, width: 1.5),
                    ),
                  ),
                  onSubmitted: (_) => _save(andNext: false),
                ),
              ),
              const SizedBox(width: 10),
              _StepperButton(
                icon: AppIcons.add,
                onPressed: () => _bump(1),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              if (item.isCounted)
                TextButton(
                  key: const Key('count_qty_clear'),
                  onPressed: () =>
                      Navigator.pop(context, const _QtyOutcome.clear()),
                  child: const Text(
                    'Clear',
                    style: TextStyle(fontWeight: FontWeight.w800),
                  ),
                ),
              const Spacer(),
              OutlinedButton(
                style: OutlinedButton.styleFrom(
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(_r),
                  ),
                ),
                onPressed: () => Navigator.pop(context),
                child: const Text(
                  'Cancel',
                  style: TextStyle(fontWeight: FontWeight.w800),
                ),
              ),
              const SizedBox(width: 8),
              FilledButton(
                key: const Key('count_qty_save'),
                style: FilledButton.styleFrom(
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(_r),
                  ),
                ),
                onPressed: () => _save(andNext: false),
                child: const Text('Save'),
              ),
            ],
          ),
          const SizedBox(height: 8),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              key: const Key('count_qty_save_next'),
              style: FilledButton.styleFrom(
                backgroundColor: AppColors.lime,
                foregroundColor: AppColors.ink,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(_r),
                ),
              ),
              onPressed: () => _save(andNext: true),
              child: const Text(
                'Save & next',
                style: TextStyle(fontWeight: FontWeight.w900),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _StepperButton extends StatelessWidget {
  const _StepperButton({required this.icon, required this.onPressed});

  final IconData icon;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.wash,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onPressed,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          width: 48,
          height: 56,
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.line),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Center(child: AppIcon(icon, size: 20)),
        ),
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.count, required this.controller});

  final StockCount count;
  final StockCountController controller;

  static const _r = 12.0;

  @override
  Widget build(BuildContext context) {
    final totals = count.totals;
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              border: Border.all(color: AppColors.line),
              borderRadius: BorderRadius.circular(_r),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${controller.countedItems} of ${controller.totalItems} counted',
                  key: const Key('count_progress'),
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 10),
                ClipRRect(
                  borderRadius: BorderRadius.circular(4),
                  child: LinearProgressIndicator(
                    value: controller.progress,
                    minHeight: 6,
                    backgroundColor: AppColors.line,
                    color: AppColors.lime,
                  ),
                ),
                if (totals != null) ...[
                  const SizedBox(height: 10),
                  Text(
                    'Variance so far: ${totals.varianceUnits > 0 ? '+' : ''}${totals.varianceUnits} units, ${Money.formatPesewas(totals.varianceValue)} at cost',
                    key: const Key('count_variance_total'),
                    style: const TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w600,
                      color: AppColors.mute,
                    ),
                  ),
                ],
                const SizedBox(height: 8),
                Text(
                  count.isOpen
                      ? 'Applying the count is done from the web admin.'
                      : 'This count is ${count.status}; entries are closed.',
                  style: TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: count.isOpen ? AppColors.mute : AppColors.danger,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('count_search'),
            decoration: InputDecoration(
              hintText: 'Search SKU or title',
              prefixIcon: const Padding(
                padding: EdgeInsets.only(left: 14, right: 8),
                child: AppIcon(AppIcons.search, size: 20),
              ),
              prefixIconConstraints:
                  const BoxConstraints(minWidth: 44, minHeight: 44),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(_r),
                borderSide: const BorderSide(color: AppColors.line),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(_r),
                borderSide: const BorderSide(color: AppColors.ink, width: 1.5),
              ),
            ),
            onChanged: controller.setQuery,
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              for (final f in CountFilter.values) ...[
                if (f != CountFilter.values.first) const SizedBox(width: 8),
                Expanded(
                  child: _FilterChip(
                    key: f == CountFilter.all
                        ? const Key('count_filter')
                        : Key('count_filter_${f.name}'),
                    label: switch (f) {
                      CountFilter.all => 'All',
                      CountFilter.uncounted => 'To count',
                      CountFilter.variances => 'Variances',
                    },
                    selected: controller.filter == f,
                    onTap: () => controller.setFilter(f),
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

class _FilterChip extends StatelessWidget {
  const _FilterChip({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected ? AppColors.ink : AppColors.surface,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 10),
          decoration: BoxDecoration(
            border: Border.all(
              color: selected ? AppColors.ink : AppColors.line,
            ),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Text(
            label,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 12.5,
              fontWeight: FontWeight.w800,
              color: selected ? AppColors.lime : AppColors.ink,
            ),
          ),
        ),
      ),
    );
  }
}

class _ItemsList extends StatelessWidget {
  const _ItemsList({required this.count, required this.controller});

  final StockCount count;
  final StockCountController controller;

  static const _r = 12.0;

  @override
  Widget build(BuildContext context) {
    final items = controller.visibleItems;
    if (items.isEmpty) {
      final allDone = controller.remainingToCount == 0 &&
          controller.totalItems > 0 &&
          controller.filter == CountFilter.uncounted &&
          controller.query.trim().isEmpty;
      return EmptyState(
        title: allDone ? 'All counted' : 'Nothing to show',
        subtitle: allDone
            ? 'Review Variances, then apply from the web admin.'
            : null,
        actionLabel: allDone ? 'Show all' : null,
        onAction: allDone
            ? () => controller.setFilter(CountFilter.all)
            : null,
      );
    }
    return RefreshIndicator(
      color: AppColors.ink,
      backgroundColor: AppColors.lime,
      onRefresh: controller.load,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(20, 4, 20, 16),
        itemCount: items.length,
        separatorBuilder: (_, _) => const SizedBox(height: 8),
        itemBuilder: (context, index) {
          final item = items[index];
          final variance = item.variance;
          final counted = item.isCounted;
          return Material(
            color: counted
                ? AppColors.limeSoft.withValues(alpha: 0.35)
                : AppColors.surface,
            borderRadius: BorderRadius.circular(_r),
            child: InkWell(
              key: Key('count_item_${item.productId}'),
              borderRadius: BorderRadius.circular(_r),
              onTap: count.isOpen ? () => enterQuantity(context, item) : null,
              child: Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  border: Border.all(
                    color: counted ? AppColors.lime : AppColors.line,
                    width: counted ? 1.5 : 1,
                  ),
                  borderRadius: BorderRadius.circular(_r),
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            item.title,
                            style: const TextStyle(
                              fontSize: 14,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                          const SizedBox(height: 3),
                          Text(
                            counted
                                ? '${item.sku} · system ${item.systemQty}'
                                : item.sku,
                            style: const TextStyle(
                              fontSize: 12.5,
                              fontWeight: FontWeight.w600,
                              color: AppColors.mute,
                            ),
                          ),
                        ],
                      ),
                    ),
                    if (controller.savingProductId == item.productId)
                      const SizedBox(
                        width: 22,
                        height: 22,
                        child: CircularProgressIndicator(strokeWidth: 2.4),
                      )
                    else
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text(
                            counted ? '${item.countedQty}' : '—',
                            style: const TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                          if (variance != null && variance != 0)
                            Text(
                              '${variance > 0 ? '+' : ''}$variance',
                              style: TextStyle(
                                fontSize: 12.5,
                                fontWeight: FontWeight.w800,
                                color: variance < 0
                                    ? AppColors.danger
                                    : AppColors.ink,
                              ),
                            ),
                        ],
                      ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

/// Sticky CTA: jump into the next uncounted title, or confirm the count is done.
class _NextBar extends StatelessWidget {
  const _NextBar({required this.controller});

  final StockCountController controller;

  static const _r = 12.0;

  @override
  Widget build(BuildContext context) {
    final next = controller.nextUncounted;
    final bottom = MediaQuery.paddingOf(context).bottom;
    return Material(
      color: AppColors.surface,
      child: Container(
        width: double.infinity,
        padding: EdgeInsets.fromLTRB(20, 10, 20, bottom + 12),
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: AppColors.line)),
        ),
        child: next == null
            ? Container(
                key: const Key('count_all_done'),
                padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 14),
                decoration: BoxDecoration(
                  color: AppColors.limeSoft,
                  border: Border.all(color: AppColors.lime, width: 1.5),
                  borderRadius: BorderRadius.circular(_r),
                ),
                child: const Row(
                  children: [
                    AppIcon(AppIcons.check, size: 20),
                    SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        'All counted. Review variances, then apply on web.',
                        style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w800,
                          height: 1.3,
                        ),
                      ),
                    ),
                  ],
                ),
              )
            : FilledButton(
                key: const Key('count_next'),
                style: FilledButton.styleFrom(
                  backgroundColor: AppColors.ink,
                  foregroundColor: AppColors.lime,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(_r),
                  ),
                ),
                onPressed: () => enterQuantity(context, next),
                child: Text(
                  'Count next · ${next.title}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w900),
                ),
              ),
      ),
    );
  }
}
