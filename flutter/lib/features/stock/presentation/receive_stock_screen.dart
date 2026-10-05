import 'dart:math';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/features/reference/presentation/reference_book_tile.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';
import 'package:schoolbook/features/stock/data/stock_repository.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

class ReceiveStockLine {
  ReceiveStockLine({
    required this.product,
    required this.quantity,
    required this.unitCostPesewas,
  });

  final Product product;
  int quantity;
  int unitCostPesewas;
}

class ReceiveStockScreen extends StatefulWidget {
  const ReceiveStockScreen({super.key});

  @override
  State<ReceiveStockScreen> createState() => _ReceiveStockScreenState();
}

class _ReceiveStockScreenState extends State<ReceiveStockScreen> {
  final _searchController = TextEditingController();
  final _referenceController = TextEditingController();
  final _notesController = TextEditingController();
  final List<ReceiveStockLine> _lines = [];
  List<Product> _searchResults = [];

  /// Approved titles matching the search that the shop has no product for yet.
  List<ReferenceBook> _listResults = [];
  bool _searching = false;
  bool _submitting = false;
  String? _error;
  /// One key per save attempt; reused on retries until success or a new attempt.
  String? _pendingIdempotencyKey;

  @override
  void dispose() {
    _searchController.dispose();
    _referenceController.dispose();
    _notesController.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    final query = _searchController.text.trim();
    if (query.isEmpty) {
      return;
    }
    final catalog = context.read<ReferenceCatalog>();
    setState(() {
      _searching = true;
      _error = null;
      // Offline-capable: the approved list is on the phone.
      _listResults = catalog.search(query, limit: 30).where((b) => catalog.stockFor(b.id) == null).take(10).toList();
    });
    try {
      final page = await context.read<ProductsRepository>().listProducts(
            search: query,
            page: 1,
          );
      setState(() {
        _searchResults = page.data;
        _searching = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _searching = false;
      });
    }
  }

  /// A title the shop does not carry yet: create the product (no opening stock: this
  /// receipt line is the stock), then add it as a line.
  Future<void> _addFromList(ReferenceBook book) async {
    final product = await context.push<Product>('/products/approved/${book.id}/new?receive=1');
    if (product != null && mounted) {
      setState(() => _listResults = _listResults.where((b) => b.id != book.id).toList());
      _addLine(product);
    }
  }

  void _addLine(Product product) {
    if (_lines.any((l) => l.product.id == product.id)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Product already on this receipt')),
      );
      return;
    }
    setState(() {
      _pendingIdempotencyKey = null;
      _lines.add(
        ReceiveStockLine(
          product: product,
          quantity: 1,
          unitCostPesewas: product.costPrice > 0 ? product.costPrice : 0,
        ),
      );
      _searchResults = [];
      _listResults = [];
      _searchController.clear();
    });
  }

  Future<void> _submit() async {
    if (_lines.isEmpty) {
      setState(() => _error = 'Add at least one product line.');
      return;
    }
    for (final line in _lines) {
      if (line.quantity <= 0) {
        setState(() => _error = 'Quantities must be positive.');
        return;
      }
      if (line.unitCostPesewas <= 0) {
        setState(() => _error = 'Enter a unit cost for each line.');
        return;
      }
    }

    setState(() {
      _submitting = true;
      _error = null;
    });

    _pendingIdempotencyKey ??=
        '${DateTime.now().millisecondsSinceEpoch}-${Random().nextInt(1 << 32)}';

    try {
      await context.read<StockRepository>().createReceipt(
            idempotencyKey: _pendingIdempotencyKey!,
            supplierReference: _referenceController.text.trim().isEmpty
                ? null
                : _referenceController.text.trim(),
            notes: _notesController.text.trim().isEmpty
                ? null
                : _notesController.text.trim(),
            items: _lines
                .map(
                  (l) => {
                    'product_id': l.product.id,
                    'quantity': l.quantity,
                    'unit_cost': l.unitCostPesewas,
                  },
                )
                .toList(),
          );
      if (!mounted) {
        return;
      }
      _pendingIdempotencyKey = null;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Stock received')),
      );
      context.go('/');
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _submitting = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Receive stock')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          SearchBar(
            controller: _searchController,
            hintText: 'Search my products and the approved list…',
            leading: const Icon(Icons.search),
            onSubmitted: (_) => _search(),
            trailing: [
              IconButton(
                icon: const Icon(Icons.add_circle_outline),
                onPressed: _searching ? null : _search,
              ),
            ],
          ),
          if (_searching) const LinearProgressIndicator(),
          if (_searchResults.isNotEmpty)
            ..._searchResults.map(
              (p) => ListTile(
                key: Key('receive_product_${p.id}'),
                title: Text(p.title),
                subtitle: Text(p.sku),
                trailing: IconButton(
                  icon: const Icon(Icons.add),
                  onPressed: () => _addLine(p),
                ),
              ),
            ),
          if (_listResults.isNotEmpty) ...[
            Padding(
              padding: const EdgeInsets.only(top: 12, bottom: 4),
              child: Text(
                'On the approved list, not in your products yet',
                style: Theme.of(context).textTheme.titleSmall,
              ),
            ),
            ..._listResults.map(
              (b) => ReferenceBookTile(
                book: b,
                stock: null,
                onTap: () => _addFromList(b),
              ),
            ),
          ],
          const SizedBox(height: 16),
          Text('Receipt lines', style: Theme.of(context).textTheme.titleMedium),
          if (_lines.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 24),
              child: EmptyState(
                title: 'No lines yet',
                subtitle: 'Search and add products above.',
              ),
            )
          else
            ..._lines.map((line) => _LineEditor(
                  line: line,
                  onChanged: () {
                    _pendingIdempotencyKey = null;
                    setState(() {});
                  },
                  onRemove: () {
                    setState(() {
                      _pendingIdempotencyKey = null;
                      _lines.remove(line);
                    });
                  },
                )),
          const SizedBox(height: 16),
          TextField(
            controller: _referenceController,
            decoration: const InputDecoration(
              labelText: 'Supplier reference (optional)',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _notesController,
            decoration: const InputDecoration(
              labelText: 'Notes (optional)',
              border: OutlineInputBorder(),
            ),
            maxLines: 2,
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _submitting ? null : _submit,
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
            child: _submitting
                ? const SizedBox(
                    height: 22,
                    width: 22,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Text('Submit receipt'),
          ),
        ],
      ),
    );
  }
}

class _LineEditor extends StatefulWidget {
  const _LineEditor({
    required this.line,
    required this.onChanged,
    required this.onRemove,
  });

  final ReceiveStockLine line;
  final VoidCallback onChanged;
  final VoidCallback onRemove;

  @override
  State<_LineEditor> createState() => _LineEditorState();
}

class _LineEditorState extends State<_LineEditor> {
  late final TextEditingController _qtyController;
  late final TextEditingController _costController;

  @override
  void initState() {
    super.initState();
    _qtyController =
        TextEditingController(text: widget.line.quantity.toString());
    _costController = TextEditingController(
      text: (widget.line.unitCostPesewas / 100).toStringAsFixed(2),
    );
  }

  @override
  void dispose() {
    _qtyController.dispose();
    _costController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final line = widget.line;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    line.product.title,
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                ),
                IconButton(
                  onPressed: widget.onRemove,
                  icon: const Icon(Icons.close),
                ),
              ],
            ),
            Text(line.product.sku),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _qtyController,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'Qty',
                      border: OutlineInputBorder(),
                    ),
                    onChanged: (v) {
                      line.quantity = int.tryParse(v) ?? line.quantity;
                      widget.onChanged();
                    },
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TextField(
                    controller: _costController,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(
                      labelText: 'Unit cost (GHS)',
                      border: OutlineInputBorder(),
                    ),
                    onChanged: (v) {
                      line.unitCostPesewas =
                          Money.parseGhsToPesewas(v) ?? line.unitCostPesewas;
                      widget.onChanged();
                    },
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
