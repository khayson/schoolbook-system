import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/features/catalog/data/lookups_repository.dart';
import 'package:schoolbook/features/catalog/domain/lookup_models.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/presentation/new_sale_controller.dart';

class NewSaleScreen extends StatefulWidget {
  const NewSaleScreen({
    super.key,
    this.previewDebounce = const Duration(milliseconds: 400),
  });

  final Duration previewDebounce;

  @override
  State<NewSaleScreen> createState() => _NewSaleScreenState();
}

class _NewSaleScreenState extends State<NewSaleScreen> {
  late final NewSaleController _controller;
  final _notes = TextEditingController();

  @override
  void initState() {
    super.initState();
    _controller = NewSaleController(
      sales: context.read<SalesRepository>(),
      pendingStore: context.read<PendingSubmissionStore>(),
      debounce: widget.previewDebounce,
    )..addListener(_onChanged);
  }

  @override
  void dispose() {
    _controller
      ..removeListener(_onChanged)
      ..dispose();
    _notes.dispose();
    super.dispose();
  }

  void _onChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  Future<void> _pickCustomer() async {
    final customer = await showModalBottomSheet<Customer>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const _CustomerPicker(),
    );
    if (customer != null) {
      _controller.setCustomer(customer);
    }
  }

  Future<void> _addBooks() async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _ProductPicker(onPick: _controller.addProduct),
    );
  }

  Future<void> _scan() async {
    final product = await context.push<Product>('/products/scan?pick=1');
    if (product != null) {
      _controller.addProduct(product);
    }
  }

  Future<void> _save({required bool thenConfirm}) async {
    final sale = await _controller.saveDraft(notes: _notes.text);
    if (sale == null || !mounted) {
      return;
    }
    context.pushReplacement(
      '/sales/${sale.id}${thenConfirm ? '?confirm=1' : ''}',
    );
  }

  @override
  Widget build(BuildContext context) {
    final c = _controller;
    final customer = c.customer;

    return Scaffold(
      appBar: AppBar(title: const Text('New sale')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: ListTile(
              key: const Key('new_sale_customer'),
              leading: const Icon(Icons.school_outlined),
              title: Text(customer?.name ?? 'Choose customer'),
              subtitle: customer == null
                  ? null
                  : Text(
                      'Owes ${Money.formatPesewas(customer.outstandingBalance)} | credit ${Money.formatPesewas(customer.creditBalance)}'
                      '${customer.creditLimit == null ? '' : ' | limit ${Money.formatPesewas(customer.creditLimit!)}'}',
                    ),
              trailing: const Icon(Icons.chevron_right),
              onTap: _pickCustomer,
            ),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  key: const Key('new_sale_add_books'),
                  onPressed: _addBooks,
                  icon: const Icon(Icons.search),
                  label: const Text('Add books'),
                ),
              ),
              const SizedBox(width: 8),
              OutlinedButton.icon(
                key: const Key('new_sale_scan'),
                onPressed: _scan,
                icon: const Icon(Icons.qr_code_scanner),
                label: const Text('Scan'),
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (c.lines.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 24),
              child: Center(child: Text('No books yet. Add or scan them.')),
            ),
          ...c.lines.map((line) {
            final priced = c.previewFor(line.product.id);
            return _LineRow(
              key: Key('line_${line.product.id}'),
              line: line,
              priceText: priced == null
                  ? '…'
                  : '${Money.formatPesewas(priced.unitPrice)} = ${Money.formatPesewas(priced.lineTotal)}',
              onStep: (d) => c.step(line.product.id, d),
              onSet: (q) => c.setQuantity(line.product.id, q),
              onRemove: () => c.remove(line.product.id),
            );
          }),
          const SizedBox(height: 12),
          TextField(
            controller: _notes,
            decoration: const InputDecoration(
              labelText: 'Notes (optional)',
              border: OutlineInputBorder(),
            ),
            maxLines: 2,
          ),
        ],
      ),
      // Pinned: however long the order, the total and the main actions stay on screen.
      bottomNavigationBar: _SaveBar(
        controller: c,
        onSave: () => _save(thenConfirm: false),
        onSaveAndConfirm: () => _save(thenConfirm: true),
      ),
    );
  }
}

/// Bottom bar: server-priced total (or the pricing/save error) and both save actions.
class _SaveBar extends StatelessWidget {
  const _SaveBar({
    required this.controller,
    required this.onSave,
    required this.onSaveAndConfirm,
  });

  final NewSaleController controller;
  final VoidCallback onSave;
  final VoidCallback onSaveAndConfirm;

  @override
  Widget build(BuildContext context) {
    final c = controller;
    final theme = Theme.of(context);
    final preview = c.preview;
    final books = c.lines.fold<int>(0, (sum, l) => sum + l.quantity);

    return Material(
      key: const Key('new_sale_bar'),
      elevation: 8,
      color: theme.colorScheme.surfaceContainer,
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      c.lines.isEmpty
                          ? 'No books yet'
                          : 'Total for $books book${books == 1 ? '' : 's'}',
                      style: theme.textTheme.bodyMedium,
                    ),
                  ),
                  if (c.previewLoading)
                    const Padding(
                      padding: EdgeInsets.only(right: 8),
                      child: SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      ),
                    ),
                  Text(
                    c.lines.isEmpty || preview == null
                        ? '…'
                        : Money.formatPesewas(preview.total),
                    key: const Key('new_sale_total'),
                    style: theme.textTheme.titleLarge,
                  ),
                ],
              ),
              if (c.previewError != null)
                Text(
                  describeApiError(c.previewError!).body,
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              if (c.saveError != null)
                Text(
                  '${describeApiError(c.saveError!).title}: ${describeApiError(c.saveError!).body}',
                  key: const Key('new_sale_error'),
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      key: const Key('new_sale_save'),
                      onPressed: c.canSave ? onSave : null,
                      style: OutlinedButton.styleFrom(
                        minimumSize: const Size.fromHeight(48),
                      ),
                      child: const Text('Save draft'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 2,
                    child: FilledButton(
                      key: const Key('new_sale_save_confirm'),
                      onPressed: c.canSave ? onSaveAndConfirm : null,
                      style: FilledButton.styleFrom(
                        minimumSize: const Size.fromHeight(48),
                      ),
                      child: Text(c.saving ? 'Saving…' : 'Save & confirm'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _LineRow extends StatefulWidget {
  const _LineRow({
    super.key,
    required this.line,
    required this.priceText,
    required this.onStep,
    required this.onSet,
    required this.onRemove,
  });

  final DraftLine line;
  final String priceText;
  final void Function(int delta) onStep;
  final void Function(int quantity) onSet;
  final VoidCallback onRemove;

  @override
  State<_LineRow> createState() => _LineRowState();
}

class _LineRowState extends State<_LineRow> {
  late final TextEditingController _qty;

  @override
  void initState() {
    super.initState();
    _qty = TextEditingController(text: '${widget.line.quantity}');
  }

  @override
  void didUpdateWidget(covariant _LineRow oldWidget) {
    super.didUpdateWidget(oldWidget);
    final text = '${widget.line.quantity}';
    if (_qty.text != text && int.tryParse(_qty.text) != widget.line.quantity) {
      _qty.text = text;
    }
  }

  @override
  void dispose() {
    _qty.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final p = widget.line.product;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 4, 8),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(p.title, style: Theme.of(context).textTheme.titleSmall),
                  Text(
                    '${p.sku} | stock ${p.stockOnHand}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  Text(widget.priceText),
                ],
              ),
            ),
            IconButton(
              key: const Key('qty_minus'),
              onPressed: widget.line.quantity > 1
                  ? () => widget.onStep(-1)
                  : null,
              icon: const Icon(Icons.remove),
            ),
            SizedBox(
              width: 56,
              child: TextField(
                key: const Key('qty_field'),
                controller: _qty,
                textAlign: TextAlign.center,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                onChanged: (v) {
                  final q = int.tryParse(v);
                  if (q != null && q > 0) {
                    widget.onSet(q);
                  }
                },
              ),
            ),
            IconButton(
              key: const Key('qty_plus'),
              onPressed: () => widget.onStep(1),
              icon: const Icon(Icons.add),
            ),
            IconButton(
              onPressed: widget.onRemove,
              icon: const Icon(Icons.delete_outline),
            ),
          ],
        ),
      ),
    );
  }
}

class _CustomerPicker extends StatefulWidget {
  const _CustomerPicker();

  @override
  State<_CustomerPicker> createState() => _CustomerPickerState();
}

class _CustomerPickerState extends State<_CustomerPicker> {
  List<Customer> _customers = const [];
  bool _loading = true;
  String? _error;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _search('');
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _search(String query) async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final page = await context.read<CustomersRepository>().listCustomers(
        search: query,
      );
      if (mounted) {
        setState(() => _customers = page.data);
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = e.message);
      }
    } finally {
      if (mounted) {
        setState(() => _loading = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.8,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.all(16),
              child: TextField(
                key: const Key('customer_picker_search'),
                autofocus: true,
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search),
                  hintText: 'Search customers',
                  border: OutlineInputBorder(),
                ),
                onChanged: (v) {
                  _debounce?.cancel();
                  _debounce = Timer(
                    const Duration(milliseconds: 300),
                    () => _search(v),
                  );
                },
              ),
            ),
            if (_loading) const LinearProgressIndicator(),
            if (_error != null) Text(_error!),
            Expanded(
              child: ListView(
                children: [
                  for (final c in _customers)
                    ListTile(
                      key: Key('pick_customer_${c.id}'),
                      enabled: c.isActive,
                      title: Text(c.name),
                      subtitle: Text(
                        c.isActive
                            ? 'Owes ${Money.formatPesewas(c.outstandingBalance)} | credit ${Money.formatPesewas(c.creditBalance)}'
                            : 'Inactive: cannot get new sales',
                      ),
                      onTap: () => Navigator.pop(context, c),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ProductPicker extends StatefulWidget {
  const _ProductPicker({required this.onPick});

  final void Function(Product product) onPick;

  @override
  State<_ProductPicker> createState() => _ProductPickerState();
}

class _ProductPickerState extends State<_ProductPicker> {
  List<NamedLookup> _levels = const [];
  List<NamedLookup> _subjects = const [];
  List<NamedLookup> _languages = const [];
  int? _levelId;
  int? _subjectId;
  int? _languageId;
  String _query = '';
  List<Product> _results = const [];
  bool _loading = false;
  String? _error;
  Timer? _debounce;
  final Map<int, int> _added = {};

  @override
  void initState() {
    super.initState();
    _loadLookups();
    _search();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _loadLookups() async {
    final lookups = context.read<LookupsRepository>();
    try {
      final levels = await lookups.fetchLevels();
      final subjects = await lookups.fetchSubjects();
      final languages = await lookups.fetchLanguages();
      if (mounted) {
        setState(() {
          _levels = levels;
          _subjects = subjects;
          _languages = languages;
        });
      }
    } on ApiException {
      // Chips are a convenience; search still works without them.
    }
  }

  Future<void> _search() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final page = await context.read<ProductsRepository>().listProducts(
        search: _query,
        levelId: _levelId,
        subjectId: _subjectId,
        languageId: _languageId,
      );
      if (mounted) {
        setState(() => _results = page.data.where((p) => p.isActive).toList());
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = e.message);
      }
    } finally {
      if (mounted) {
        setState(() => _loading = false);
      }
    }
  }

  Widget _chips(
    List<NamedLookup> items,
    int? selected,
    void Function(int?) onSelect,
  ) {
    return SizedBox(
      height: 44,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        children: [
          for (final item in items)
            Padding(
              padding: const EdgeInsets.only(right: 6),
              child: FilterChip(
                label: Text(item.name),
                selected: selected == item.id,
                onSelected: (on) {
                  onSelect(on ? item.id : null);
                  _search();
                },
              ),
            ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.9,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
              child: TextField(
                key: const Key('product_picker_search'),
                decoration: InputDecoration(
                  prefixIcon: const Icon(Icons.search),
                  hintText: 'Title, SKU, ISBN…',
                  border: const OutlineInputBorder(),
                  suffixIcon: TextButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text('Done'),
                  ),
                ),
                onChanged: (v) {
                  _query = v;
                  _debounce?.cancel();
                  _debounce = Timer(const Duration(milliseconds: 300), _search);
                },
              ),
            ),
            _chips(_levels, _levelId, (v) => setState(() => _levelId = v)),
            _chips(
              _subjects,
              _subjectId,
              (v) => setState(() => _subjectId = v),
            ),
            _chips(
              _languages,
              _languageId,
              (v) => setState(() => _languageId = v),
            ),
            if (_loading) const LinearProgressIndicator(),
            if (_error != null)
              Padding(padding: const EdgeInsets.all(8), child: Text(_error!)),
            Expanded(
              child: ListView(
                children: [
                  for (final p in _results)
                    ListTile(
                      key: Key('pick_product_${p.id}'),
                      title: Text(p.title),
                      subtitle: Text(
                        '${p.sku} | ${Money.formatPesewas(p.sellingPrice)} | stock ${p.stockOnHand}',
                      ),
                      trailing: _added[p.id] == null
                          ? const Icon(Icons.add_circle_outline)
                          : Chip(label: Text('+${_added[p.id]}')),
                      onTap: () {
                        widget.onPick(p);
                        setState(() => _added[p.id] = (_added[p.id] ?? 0) + 1);
                      },
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
