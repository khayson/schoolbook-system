import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/exchanges/domain/stock_exchange.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/shared/widgets/app_drawer.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';

/// A book on an exchange that still needs cost and/or selling price.
class PriceDraftBook {
  const PriceDraftBook({
    required this.productId,
    required this.title,
    required this.sku,
    required this.direction,
    required this.quantity,
    required this.costPesewas,
    required this.sellingPesewas,
  });

  factory PriceDraftBook.fromExchangeItem(StockExchangeItem item) {
    final product = item.product;
    return PriceDraftBook(
      productId: item.productId,
      title: item.productTitle,
      sku: product?.sku ?? '',
      direction: item.direction,
      quantity: item.quantity,
      costPesewas: item.unitCost ?? product?.costPrice ?? 0,
      sellingPesewas: item.unitPrice ?? product?.sellingPrice ?? 0,
    );
  }

  final int productId;
  final String title;
  final String sku;
  final ExchangeDirection direction;
  final int quantity;
  final int costPesewas;
  final int sellingPesewas;

  /// Out lines need a selling price; Taken (in) needs a cost.
  bool get needsPrice {
    if (direction == ExchangeDirection.out) {
      return sellingPesewas <= 0;
    }
    return costPesewas <= 0;
  }
}

/// Unique products from [items] that still need cost/selling for confirm.
List<PriceDraftBook> booksNeedingPrices(Iterable<StockExchangeItem> items) {
  final byProduct = <int, PriceDraftBook>{};
  for (final item in items) {
    final book = PriceDraftBook.fromExchangeItem(item);
    if (!book.needsPrice) continue;
    // Keep the first side that needs a price; both fields are editable anyway.
    byProduct.putIfAbsent(book.productId, () => book);
  }
  return byProduct.values.toList();
}

/// Returns `true` when every book has been saved with valid prices.
Future<bool?> showSetBookPricesDrawer(
  BuildContext context, {
  required List<PriceDraftBook> books,
}) {
  return showAppDrawer<bool>(
    context: context,
    useRootNavigator: true,
    builder: (_) => SetBookPricesDrawer(books: books),
  );
}

class SetBookPricesDrawer extends StatefulWidget {
  const SetBookPricesDrawer({super.key, required this.books});

  final List<PriceDraftBook> books;

  @override
  State<SetBookPricesDrawer> createState() => _SetBookPricesDrawerState();
}

class _RowState {
  _RowState(this.book)
    : cost = TextEditingController(
        text: book.costPesewas > 0
            ? Money.toGhsInput(book.costPesewas)
            : '',
      ),
      selling = TextEditingController(
        text: book.sellingPesewas > 0
            ? Money.toGhsInput(book.sellingPesewas)
            : '',
      );

  final PriceDraftBook book;
  final TextEditingController cost;
  final TextEditingController selling;
  bool saving = false;
  bool saved = false;
  String? error;
  int? savedCost;
  int? savedSelling;

  void dispose() {
    cost.dispose();
    selling.dispose();
  }
}

class _SetBookPricesDrawerState extends State<SetBookPricesDrawer> {
  late final List<_RowState> _rows;

  @override
  void initState() {
    super.initState();
    _rows = widget.books.map(_RowState.new).toList();
  }

  @override
  void dispose() {
    for (final row in _rows) {
      row.dispose();
    }
    super.dispose();
  }

  bool get _allSaved => _rows.every((r) => r.saved);

  Future<void> _save(_RowState row) async {
    final cost = Money.parseGhsToPesewas(row.cost.text);
    final selling = Money.parseGhsToPesewas(row.selling.text);
    if (cost == null) {
      setState(() => row.error = 'Enter a valid cost (GHS).');
      return;
    }
    if (selling == null) {
      setState(() => row.error = 'Enter a valid selling price (GHS).');
      return;
    }
    if (row.book.direction == ExchangeDirection.out && selling <= 0) {
      setState(() => row.error = 'Selling price is required for books they took.');
      return;
    }

    setState(() {
      row.saving = true;
      row.error = null;
    });

    try {
      final products = context.read<ProductsRepository>();
      final updated = await products.updateProduct(row.book.productId, {
        'cost_price': cost,
        'selling_price': selling,
      });
      if (!mounted) return;
      setState(() {
        row.saving = false;
        row.saved = true;
        row.savedCost = updated.costPrice;
        row.savedSelling = updated.sellingPrice;
      });
      AppToast.show(
        context,
        'Prices saved for ${row.book.title}',
        type: ToastType.success,
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        row.saving = false;
        row.error = e.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final height = MediaQuery.sizeOf(context).height;

    return AppDrawerScaffold(
      child: SizedBox(
        key: const Key('set_book_prices_drawer'),
        height: height * 0.78,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Set book prices',
              style: TextStyle(fontWeight: FontWeight.w900, fontSize: 20),
            ),
            const SizedBox(height: 6),
            const Text(
              'These books still need cost and selling price. '
              'Save each one — the catalog and this exchange both update — '
              'then continue confirm.',
              style: TextStyle(
                fontWeight: FontWeight.w600,
                color: AppColors.mute,
                height: 1.35,
              ),
            ),
            const SizedBox(height: 14),
            Expanded(
              child: ListView.separated(
                itemCount: _rows.length,
                separatorBuilder: (_, _) => const SizedBox(height: 10),
                itemBuilder: (context, i) => _PriceCard(
                  row: _rows[i],
                  onSave: () => _save(_rows[i]),
                ),
              ),
            ),
            const SizedBox(height: 12),
            FilledButton(
              key: const Key('set_book_prices_continue'),
              onPressed: _allSaved
                  ? () => Navigator.pop(context, true)
                  : null,
              child: Text(
                _allSaved
                    ? 'Continue confirm'
                    : 'Save every book’s prices first',
              ),
            ),
            const SizedBox(height: 8),
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel'),
            ),
          ],
        ),
      ),
    );
  }
}

class _PriceCard extends StatelessWidget {
  const _PriceCard({required this.row, required this.onSave});

  final _RowState row;
  final VoidCallback onSave;

  @override
  Widget build(BuildContext context) {
    final book = row.book;
    return Container(
      key: Key('set_price_row_${book.productId}'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: row.saved ? const Color(0xFFECFDF3) : AppColors.wash,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: row.saved ? const Color(0xFF86EFAC) : AppColors.line,
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
                      book.title,
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      [
                        if (book.sku.isNotEmpty) book.sku,
                        book.direction.shortLabel,
                        '× ${book.quantity}',
                      ].join(' · '),
                      style: const TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w600,
                        color: AppColors.mute,
                      ),
                    ),
                  ],
                ),
              ),
              if (row.saved)
                const AppIcon(
                  AppIcons.check,
                  size: 22,
                  color: Color(0xFF15803D),
                ),
            ],
          ),
          if (!row.saved) ...[
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: row.cost,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(
                      labelText: 'Cost (GHS)',
                      isDense: true,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: TextField(
                    controller: row.selling,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(
                      labelText: 'Selling (GHS)',
                      isDense: true,
                    ),
                  ),
                ),
              ],
            ),
            if (row.error != null) ...[
              const SizedBox(height: 6),
              Text(
                row.error!,
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
                key: Key('set_price_save_${book.productId}'),
                onPressed: row.saving ? null : onSave,
                icon: row.saving
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.save_outlined, size: 18),
                label: Text(row.saving ? 'Saving…' : 'Save prices'),
              ),
            ),
          ] else ...[
            const SizedBox(height: 8),
            Text(
              'Cost ${Money.formatPesewas(row.savedCost ?? 0)} · '
              'Selling ${Money.formatPesewas(row.savedSelling ?? 0)}',
              style: const TextStyle(
                fontWeight: FontWeight.w700,
                color: Color(0xFF15803D),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
