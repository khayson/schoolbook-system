import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/catalog/data/lookups_repository.dart';
import 'package:schoolbook/features/catalog/domain/lookup_models.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/presentation/new_sale_controller.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';
import 'package:schoolbook/shared/widgets/option_picker.dart';

class NewSaleScreen extends StatefulWidget {
  const NewSaleScreen({
    super.key,
    this.previewDebounce = const Duration(milliseconds: 400),
    this.initialCustomerId,
    this.editingSaleId,
  });

  final Duration previewDebounce;

  /// When opened from a school detail, pre-select that customer.
  final int? initialCustomerId;

  /// When set, load that draft and save via `PUT /sales/{id}`.
  final int? editingSaleId;

  @override
  State<NewSaleScreen> createState() => _NewSaleScreenState();
}

class _NewSaleScreenState extends State<NewSaleScreen> {
  late final NewSaleController _controller;
  final _notes = TextEditingController();
  bool _loadingDraft = false;
  String? _loadError;

  @override
  void initState() {
    super.initState();
    _notes.addListener(_onNotesChanged);
    _controller = NewSaleController(
      sales: context.read<SalesRepository>(),
      pendingStore: context.read<PendingSubmissionStore>(),
      debounce: widget.previewDebounce,
    )..addListener(_onChanged);
    // Warm filter lookups so Add books opens with Level/Subject ready.
    context.read<LookupsRepository>().prefetchProductFilters();
    final editId = widget.editingSaleId;
    if (editId != null) {
      _loadDraft(editId);
    } else {
      final id = widget.initialCustomerId;
      if (id != null) {
        _preselectCustomer(id);
      }
    }
  }

  Future<void> _preselectCustomer(int id) async {
    try {
      final customer = await context.read<CustomersRepository>().getCustomer(id);
      if (mounted) _controller.setCustomer(customer);
    } on ApiException {
      // Picker stays empty; user can choose manually.
    }
  }

  Future<void> _loadDraft(int id) async {
    setState(() {
      _loadingDraft = true;
      _loadError = null;
    });
    final sales = context.read<SalesRepository>();
    final productsRepo = context.read<ProductsRepository>();
    final customers = context.read<CustomersRepository>();
    try {
      final sale = await sales.getSale(id);
      if (!sale.isDraft) {
        if (mounted) context.go('/sales/$id');
        return;
      }
      final products = await Future.wait(
        sale.items.map((line) => productsRepo.getProduct(line.productId)),
      );
      var customer = sale.customer;
      customer ??= await customers.getCustomer(sale.customerId);
      if (!mounted) return;
      final notes = sale.notes ?? '';
      _notes.text = notes;
      _controller.loadExistingDraft(
        saleId: sale.id,
        customer: customer,
        draftLines: [
          for (var i = 0; i < sale.items.length; i++)
            DraftLine(product: products[i], quantity: sale.items[i].quantity),
        ],
        notes: notes,
      );
    } on ApiException catch (e) {
      if (mounted) setState(() => _loadError = e.message);
    } finally {
      if (mounted) setState(() => _loadingDraft = false);
    }
  }

  @override
  void dispose() {
    _notes.removeListener(_onNotesChanged);
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

  void _onNotesChanged() {
    if (mounted) setState(() {});
  }

  Future<void> _pickCustomer() async {
    final customer = await showModalBottomSheet<Customer>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
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
      backgroundColor: AppColors.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      builder: (_) => _ProductPicker(onPick: _controller.addProduct),
    );
  }

  Future<void> _scan() async {
    final product = await context.push<Product>('/products/scan?pick=1');
    if (product != null) {
      _controller.addProduct(product);
    }
  }

  /// Adjust stock for a short book, then refresh its on-hand and stay on this sale.
  Future<void> _fixStock(Product product) async {
    await context.push('/stock/adjust?product_id=${product.id}');
    if (!mounted) return;
    try {
      final fresh = await context.read<ProductsRepository>().getProduct(
        product.id,
      );
      _controller.replaceProduct(fresh);
    } on ApiException {
      // Keep the stale figure; user can try again.
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
    final editing = c.isEditing || widget.editingSaleId != null;

    if (_loadingDraft) {
      return Scaffold(
        appBar: AppBar(title: Text(editing ? 'Edit draft' : 'New sale')),
        body: const LoadingBody(),
      );
    }
    if (_loadError != null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Edit draft')),
        body: ErrorState(
          message: _loadError!,
          onRetry: () => _loadDraft(widget.editingSaleId!),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(title: Text(editing ? 'Edit draft' : 'New sale')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Material(
            color: customer == null ? AppColors.limeSoft : AppColors.wash,
            borderRadius: BorderRadius.circular(20),
            child: InkWell(
              key: const Key('new_sale_customer'),
              onTap: _pickCustomer,
              borderRadius: BorderRadius.circular(20),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Row(
                  children: [
                    Container(
                      width: 44,
                      height: 44,
                      decoration: const BoxDecoration(
                        color: AppColors.surface,
                        shape: BoxShape.circle,
                      ),
                      child: const Center(child: AppIcon(AppIcons.schools)),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            customer?.name ?? 'Choose a school',
                            style: const TextStyle(
                              fontSize: 16,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                          const SizedBox(height: 3),
                          Text(
                            customer == null
                                ? 'Required — pick one or add a new school'
                                : 'Owes ${Money.formatPesewas(customer.outstandingBalance)} · credit ${Money.formatPesewas(customer.creditBalance)}'
                                    '${customer.creditLimit == null ? '' : ' · limit ${Money.formatPesewas(customer.creditLimit!)}'}',
                            style: const TextStyle(
                              fontSize: 12.5,
                              fontWeight: FontWeight.w600,
                              color: AppColors.mute,
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
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  key: const Key('new_sale_add_books'),
                  onPressed: _addBooks,
                  icon: const AppIcon(AppIcons.search),
                  label: const Text('Add books'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  key: const Key('new_sale_scan'),
                  onPressed: _scan,
                  icon: const AppIcon(AppIcons.scan),
                  label: const Text('Scan'),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (c.lines.isEmpty)
            Container(
              width: double.infinity,
              margin: const EdgeInsets.symmetric(vertical: 8),
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                color: AppColors.wash,
                borderRadius: BorderRadius.circular(22),
              ),
              child: const Column(
                children: [
                  AppIcon(AppIcons.products, size: 28, color: AppColors.mute),
                  SizedBox(height: 10),
                  Text(
                    'No books yet',
                    style: TextStyle(fontWeight: FontWeight.w800),
                  ),
                  SizedBox(height: 4),
                  Text(
                    'Add from the list or scan a barcode.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontWeight: FontWeight.w600,
                      color: AppColors.mute,
                    ),
                  ),
                ],
              ),
            ),
          if (c.lines.any((l) => l.isShort)) ...[
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: const Color(0xFFFFF1E0),
                borderRadius: BorderRadius.circular(16),
              ),
              child: Row(
                children: [
                  const Expanded(
                    child: Text(
                      'Some books need more stock than you have.',
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        color: AppColors.warn,
                      ),
                    ),
                  ),
                  TextButton(
                    key: const Key('new_sale_remove_short'),
                    onPressed: c.removeShortLines,
                    child: const Text(
                      'Remove short',
                      style: TextStyle(fontWeight: FontWeight.w800),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 10),
          ],
          ...c.lines.map((line) {
            final priced = c.previewFor(line.product.id);
            return _LineRow(
              key: Key('line_${line.product.id}'),
              line: line,
              priceText: priced == null
                  ? '…'
                  : '${Money.formatPesewas(priced.unitPrice)} · ${Money.formatPesewas(priced.lineTotal)}',
              onStep: (d) => c.step(line.product.id, d),
              onSet: (q) => c.setQuantity(line.product.id, q),
              onRemove: () => c.remove(line.product.id),
              onFixStock: () => _fixStock(line.product),
            );
          }),
          const SizedBox(height: 12),
          TextField(
            controller: _notes,
            decoration: const InputDecoration(
              labelText: 'Notes (optional)',
            ),
            maxLines: 2,
          ),
        ],
      ),
      // Pinned: however long the order, the total and the main actions stay on screen.
      bottomNavigationBar: _SaveBar(
        controller: c,
        notes: _notes.text,
        loading: _loadingDraft || c.saving,
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
    required this.notes,
    required this.loading,
    required this.onSave,
    required this.onSaveAndConfirm,
  });

  final NewSaleController controller;
  final String notes;
  final bool loading;
  final VoidCallback onSave;
  final VoidCallback onSaveAndConfirm;

  @override
  Widget build(BuildContext context) {
    final c = controller;
    final theme = Theme.of(context);
    final preview = c.preview;
    final books = c.lines.fold<int>(0, (sum, l) => sum + l.quantity);
    final canPersist = !loading && c.canPersist(notes: notes);
    final dirty = c.isDirty(notes: notes);

    return Material(
      key: const Key('new_sale_bar'),
      color: AppColors.surface,
      child: SafeArea(
        top: false,
        child: Container(
          padding: const EdgeInsets.fromLTRB(20, 14, 20, 14),
          decoration: const BoxDecoration(
            border: Border(top: BorderSide(color: AppColors.line)),
          ),
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
                      style: const TextStyle(
                        fontWeight: FontWeight.w700,
                        color: AppColors.mute,
                      ),
                    ),
                  ),
                  if (c.previewLoading)
                    const Padding(
                      padding: EdgeInsets.only(right: 8),
                      child: SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: AppColors.ink,
                        ),
                      ),
                    ),
                  Text(
                    c.lines.isEmpty || preview == null
                        ? '…'
                        : Money.formatPesewas(preview.total),
                    key: const Key('new_sale_total'),
                    style: const TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.w900,
                      letterSpacing: -0.3,
                      fontFeatures: [FontFeature.tabularFigures()],
                    ),
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
              if (c.isEditing && !dirty) ...[
                const SizedBox(height: 8),
                const Text(
                  'No changes yet',
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: AppColors.mute,
                  ),
                ),
              ],
              const SizedBox(height: 12),
              if (c.isEditing) ...[
                FilledButton(
                  key: const Key('new_sale_save'),
                  onPressed: canPersist ? onSave : null,
                  child: Text(c.saving ? 'Saving…' : 'Save changes'),
                ),
                const SizedBox(height: 4),
                TextButton(
                  key: const Key('new_sale_save_confirm'),
                  onPressed: canPersist ? onSaveAndConfirm : null,
                  child: const Text(
                    'Save & confirm',
                    style: TextStyle(fontWeight: FontWeight.w800),
                  ),
                ),
              ] else
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton(
                        key: const Key('new_sale_save'),
                        onPressed: canPersist ? onSave : null,
                        child: const Text('Save draft'),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      flex: 2,
                      child: FilledButton(
                        key: const Key('new_sale_save_confirm'),
                        onPressed: canPersist ? onSaveAndConfirm : null,
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
    required this.onFixStock,
  });

  final DraftLine line;
  final String priceText;
  final void Function(int delta) onStep;
  final void Function(int quantity) onSet;
  final VoidCallback onRemove;
  final VoidCallback onFixStock;

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
    final short = widget.line.isShort;
    final stock = p.stockOnHand;

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.fromLTRB(14, 14, 10, 12),
      decoration: BoxDecoration(
        color: short ? const Color(0xFFFFF1E0) : AppColors.wash,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      p.title,
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      '${p.sku} · ${stock == 0 ? 'Out of stock' : '$stock on hand'}',
                      style: TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w600,
                        color: short || stock == 0
                            ? AppColors.warn
                            : AppColors.mute,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      widget.priceText,
                      style: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
              ),
              IconButton(
                key: Key('line_remove_${p.id}'),
                tooltip: 'Remove',
                onPressed: widget.onRemove,
                icon: const AppIcon(AppIcons.delete, size: 20),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              _QtyButton(
                key: const Key('qty_minus'),
                icon: AppIcons.remove,
                onPressed: widget.line.quantity > 1
                    ? () => widget.onStep(-1)
                    : null,
              ),
              SizedBox(
                width: 56,
                child: TextField(
                  key: const Key('qty_field'),
                  controller: _qty,
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontWeight: FontWeight.w900),
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: const InputDecoration(
                    isDense: true,
                    contentPadding: EdgeInsets.symmetric(vertical: 10),
                  ),
                  onChanged: (v) {
                    final q = int.tryParse(v);
                    if (q != null && q > 0) widget.onSet(q);
                  },
                ),
              ),
              _QtyButton(
                key: const Key('qty_plus'),
                icon: AppIcons.add,
                onPressed: () => widget.onStep(1),
              ),
              const Spacer(),
              if (short)
                TextButton.icon(
                  key: Key('line_fix_stock_${p.id}'),
                  onPressed: widget.onFixStock,
                  icon: const AppIcon(AppIcons.stock, size: 18),
                  label: const Text(
                    'Fix stock',
                    style: TextStyle(fontWeight: FontWeight.w800),
                  ),
                ),
            ],
          ),
          if (short) ...[
            const SizedBox(height: 8),
            Text(
              'Need ${widget.line.quantity}, have $stock'
              '${stock > 0 ? ' — short by ${widget.line.quantity - stock}' : ''}. '
              'Remove this book or add stock, then continue the sale.',
              style: const TextStyle(
                fontSize: 12.5,
                fontWeight: FontWeight.w600,
                color: AppColors.warn,
                height: 1.35,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _QtyButton extends StatelessWidget {
  const _QtyButton({
    super.key,
    required this.icon,
    required this.onPressed,
  });

  final IconData icon;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.surface,
      shape: const CircleBorder(),
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onPressed,
        child: SizedBox(
          width: 40,
          height: 40,
          child: Center(
            child: AppIcon(
              icon,
              size: 18,
              color: onPressed == null ? AppColors.mute : AppColors.ink,
            ),
          ),
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
  String _query = '';
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
      _query = query;
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

  Future<void> _addSchool() async {
    final created = await showModalBottomSheet<Customer>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const _QuickAddSchoolSheet(),
    );
    if (created != null && mounted) {
      Navigator.pop(context, created);
    }
  }

  Future<void> _openDirectory() async {
    await context.push('/customers/directory');
    if (mounted) await _search(_query);
  }

  @override
  Widget build(BuildContext context) {
    final empty = !_loading && _customers.isEmpty && _error == null;

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(
          bottom: MediaQuery.of(context).viewInsets.bottom,
        ),
        child: Container(
          height: MediaQuery.of(context).size.height * 0.82,
          decoration: const BoxDecoration(
            color: AppColors.surface,
            borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
          ),
          child: Column(
            children: [
              const SizedBox(height: 12),
              Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: AppColors.line,
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 18, 12, 8),
                child: Row(
                  children: [
                    const Expanded(
                      child: Text(
                        'Choose a school',
                        style: TextStyle(
                          fontSize: 22,
                          fontWeight: FontWeight.w900,
                          letterSpacing: -0.3,
                        ),
                      ),
                    ),
                    IconButton(
                      onPressed: () => Navigator.pop(context),
                      icon: const AppIcon(AppIcons.close),
                    ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
                child: TextField(
                  key: const Key('customer_picker_search'),
                  autofocus: true,
                  decoration: const InputDecoration(
                    hintText: 'Search by school name',
                    prefixIcon: Padding(
                      padding: EdgeInsets.only(left: 14, right: 8),
                      child: AppIcon(AppIcons.search, size: 20),
                    ),
                    prefixIconConstraints:
                        BoxConstraints(minWidth: 44, minHeight: 44),
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
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                child: Row(
                  children: [
                    Expanded(
                      child: FilledButton.icon(
                        key: const Key('customer_picker_add'),
                        onPressed: _addSchool,
                        icon: const AppIcon(
                          AppIcons.add,
                          size: 18,
                          color: Colors.white,
                        ),
                        label: const Text('Add school'),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: OutlinedButton.icon(
                        key: const Key('customer_picker_directory'),
                        onPressed: _openDirectory,
                        icon: const AppIcon(AppIcons.search, size: 18),
                        label: const Text('Directory'),
                      ),
                    ),
                  ],
                ),
              ),
              if (_loading)
                const LinearProgressIndicator(
                  minHeight: 2,
                  color: AppColors.ink,
                  backgroundColor: AppColors.line,
                ),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(
                    _error!,
                    style: const TextStyle(
                      color: AppColors.danger,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              Expanded(
                child: empty
                    ? _EmptySchools(
                        searching: _query.trim().isNotEmpty,
                        onAdd: _addSchool,
                        onDirectory: _openDirectory,
                      )
                    : ListView.separated(
                        padding: const EdgeInsets.fromLTRB(12, 4, 12, 20),
                        itemCount: _customers.length,
                        separatorBuilder: (_, _) => const SizedBox(height: 4),
                        itemBuilder: (context, i) {
                          final c = _customers[i];
                          return Material(
                            color: AppColors.surface,
                            child: ListTile(
                              key: Key('pick_customer_${c.id}'),
                              enabled: c.isActive,
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(16),
                              ),
                              leading: Container(
                                width: 40,
                                height: 40,
                                decoration: const BoxDecoration(
                                  color: AppColors.wash,
                                  shape: BoxShape.circle,
                                ),
                                child: const Center(
                                  child: AppIcon(AppIcons.schools, size: 20),
                                ),
                              ),
                              title: Text(
                                c.name,
                                style: const TextStyle(
                                  fontWeight: FontWeight.w800,
                                ),
                              ),
                              subtitle: Text(
                                c.isActive
                                    ? '${c.region} · owes ${Money.formatPesewas(c.outstandingBalance)}'
                                    : 'Inactive — cannot use on new sales',
                                style: const TextStyle(
                                  fontWeight: FontWeight.w600,
                                  color: AppColors.mute,
                                ),
                              ),
                              trailing: const AppIcon(
                                AppIcons.chevronRight,
                                size: 18,
                                color: AppColors.mute,
                              ),
                              onTap: () => Navigator.pop(context, c),
                            ),
                          );
                        },
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _EmptySchools extends StatelessWidget {
  const _EmptySchools({
    required this.searching,
    required this.onAdd,
    required this.onDirectory,
  });

  final bool searching;
  final VoidCallback onAdd;
  final VoidCallback onDirectory;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(28, 24, 28, 24),
      child: Column(
        children: [
          Container(
            width: 64,
            height: 64,
            decoration: const BoxDecoration(
              color: AppColors.limeSoft,
              shape: BoxShape.circle,
            ),
            child: const Center(
              child: AppIcon(AppIcons.schools, size: 28),
            ),
          ),
          const SizedBox(height: 16),
          Text(
            searching ? 'No school matches' : 'No schools yet',
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 8),
          Text(
            searching
                ? 'Try another name, or add this school now.'
                : 'Add a school to sell to — it only takes a name and region.',
            style: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: AppColors.mute,
              height: 1.4,
            ),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 20),
          FilledButton(
            onPressed: onAdd,
            child: const Text('Add school'),
          ),
          const SizedBox(height: 10),
          TextButton(
            onPressed: onDirectory,
            child: const Text(
              'Find in school directory',
              style: TextStyle(fontWeight: FontWeight.w800),
            ),
          ),
        ],
      ),
    );
  }
}

/// Minimal create form: name + region. Type defaults to school.
class _QuickAddSchoolSheet extends StatefulWidget {
  const _QuickAddSchoolSheet();

  @override
  State<_QuickAddSchoolSheet> createState() => _QuickAddSchoolSheetState();
}

class _QuickAddSchoolSheetState extends State<_QuickAddSchoolSheet> {
  final _name = TextEditingController();
  String? _region;
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final name = _name.text.trim();
    if (name.isEmpty) {
      setState(() => _error = 'Enter the school name');
      return;
    }
    if (_region == null) {
      setState(() => _error = 'Pick a region');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final customer = await context.read<CustomersRepository>().createCustomer({
        'name': name,
        'type': 'school',
        'region': _region,
      });
      if (mounted) Navigator.pop(context, customer);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = describeApiError(e).body);
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(
          bottom: MediaQuery.of(context).viewInsets.bottom,
        ),
        child: Container(
          decoration: const BoxDecoration(
            color: AppColors.surface,
            borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
          ),
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AppColors.line,
                    borderRadius: BorderRadius.circular(999),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              const Text(
                'Add school',
                style: TextStyle(
                  fontSize: 22,
                  fontWeight: FontWeight.w900,
                  letterSpacing: -0.3,
                ),
              ),
              const SizedBox(height: 6),
              const Text(
                'Name and region are enough to start selling.',
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: AppColors.mute,
                ),
              ),
              const SizedBox(height: 16),
              TextField(
                key: const Key('customer_quick_name'),
                controller: _name,
                textCapitalization: TextCapitalization.words,
                autofocus: true,
                decoration: const InputDecoration(hintText: 'School name'),
                onSubmitted: (_) => _save(),
              ),
              const SizedBox(height: 12),
              OptionPicker<String>(
                key: const Key('customer_quick_region'),
                label: 'Region',
                value: _region,
                clearLabel: 'Pick a region',
                allowClear: false,
                options: [
                  for (final r in CustomerOptions.regions) (r, r),
                ],
                onChanged: (v) => setState(() => _region = v),
              ),
              if (_error != null) ...[
                const SizedBox(height: 12),
                Text(
                  _error!,
                  style: const TextStyle(
                    color: AppColors.danger,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
              const SizedBox(height: 16),
              FilledButton(
                key: const Key('customer_quick_save'),
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Saving…' : 'Save and use'),
              ),
            ],
          ),
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
  bool _lookupsLoading = true;
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
    setState(() => _lookupsLoading = true);
    try {
      // Start all three at once (cache / in-flight dedupe in LookupsRepository).
      final levelsF = lookups.fetchLevels();
      final subjectsF = lookups.fetchSubjects();
      final languagesF = lookups.fetchLanguages();
      final levels = await levelsF;
      final subjects = await subjectsF;
      final languages = await languagesF;
      if (mounted) {
        setState(() {
          _levels = levels;
          _subjects = subjects;
          _languages = languages;
          _lookupsLoading = false;
        });
      }
    } on ApiException {
      // Filters are a convenience; search still works without them.
      if (mounted) setState(() => _lookupsLoading = false);
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
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    final addedCount = _added.values.fold<int>(0, (a, b) => a + b);

    return SizedBox(
      height: MediaQuery.of(context).size.height * 0.92,
      child: Column(
        children: [
          const SizedBox(height: 10),
          Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color: AppColors.line,
              borderRadius: BorderRadius.circular(999),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 16, 12, 0),
            child: Row(
              children: [
                const Expanded(
                  child: Text(
                    'Add books',
                    style: TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.w900,
                      letterSpacing: -0.3,
                    ),
                  ),
                ),
                TextButton(
                  key: const Key('product_picker_done'),
                  onPressed: () => Navigator.pop(context),
                  child: Text(
                    addedCount == 0 ? 'Done' : 'Done ($addedCount)',
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 10),
            child: TextField(
              key: const Key('product_picker_search'),
              decoration: const InputDecoration(
                prefixIcon: Padding(
                  padding: EdgeInsets.only(left: 14, right: 8),
                  child: AppIcon(AppIcons.search, size: 20),
                ),
                prefixIconConstraints: BoxConstraints(
                  minWidth: 44,
                  minHeight: 44,
                ),
                hintText: 'Title, SKU, ISBN…',
              ),
              onChanged: (v) {
                _query = v;
                _debounce?.cancel();
                _debounce = Timer(const Duration(milliseconds: 300), _search);
              },
            ),
          ),
          if (_lookupsLoading)
            const Padding(
              padding: EdgeInsets.fromLTRB(20, 4, 20, 12),
              child: LinearProgressIndicator(
                color: AppColors.ink,
                backgroundColor: AppColors.line,
                minHeight: 2,
              ),
            )
          else ...[
            if (_levels.isNotEmpty || _subjects.isNotEmpty)
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                child: Row(
                  children: [
                    if (_levels.isNotEmpty)
                      Expanded(
                        child: OptionPicker<int>(
                          label: 'Level',
                          value: _levelId,
                          options: [
                            for (final l in _levels) (l.id, l.name),
                          ],
                          onChanged: (v) {
                            setState(() => _levelId = v);
                            _search();
                          },
                        ),
                      ),
                    if (_levels.isNotEmpty && _subjects.isNotEmpty)
                      const SizedBox(width: 10),
                    if (_subjects.isNotEmpty)
                      Expanded(
                        child: OptionPicker<int>(
                          label: 'Subject',
                          value: _subjectId,
                          options: [
                            for (final s in _subjects) (s.id, s.name),
                          ],
                          onChanged: (v) {
                            setState(() => _subjectId = v);
                            _search();
                          },
                        ),
                      ),
                  ],
                ),
              ),
            if (_languages.isNotEmpty)
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                child: OptionPicker<int>(
                  label: 'Language',
                  value: _languageId,
                  options: [
                    for (final l in _languages) (l.id, l.name),
                  ],
                  onChanged: (v) {
                    setState(() => _languageId = v);
                    _search();
                  },
                ),
              ),
          ],
          if (_loading)
            const Padding(
              padding: EdgeInsets.only(top: 8),
              child: LinearProgressIndicator(
                color: AppColors.ink,
                backgroundColor: AppColors.line,
              ),
            ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.all(16),
              child: Text(
                _error!,
                style: const TextStyle(
                  color: AppColors.danger,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          Expanded(
            child: ListView.separated(
              padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 20),
              itemCount: _results.length,
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (context, i) {
                final p = _results[i];
                final added = _added[p.id];
                final oos = p.stockOnHand <= 0;
                return Material(
                  key: Key('pick_product_${p.id}'),
                  color: AppColors.wash,
                  borderRadius: BorderRadius.circular(18),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(18),
                    onTap: () {
                      widget.onPick(p);
                      setState(() => _added[p.id] = (added ?? 0) + 1);
                    },
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
                      child: Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  p.title,
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w800,
                                    fontSize: 15,
                                  ),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  '${p.sku} · ${Money.formatPesewas(p.sellingPrice)}',
                                  style: const TextStyle(
                                    fontSize: 12.5,
                                    fontWeight: FontWeight.w600,
                                    color: AppColors.mute,
                                  ),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  oos
                                      ? 'Out of stock — add then fix stock'
                                      : '${p.stockOnHand} on hand',
                                  style: TextStyle(
                                    fontSize: 12,
                                    fontWeight: FontWeight.w700,
                                    color: oos
                                        ? AppColors.warn
                                        : AppColors.mute,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(width: 8),
                          Container(
                            width: 40,
                            height: 40,
                            decoration: BoxDecoration(
                              color: added == null
                                  ? AppColors.surface
                                  : AppColors.ink,
                              shape: BoxShape.circle,
                            ),
                            child: Center(
                              child: added == null
                                  ? const AppIcon(AppIcons.add, size: 20)
                                  : Text(
                                      '+$added',
                                      style: const TextStyle(
                                        color: AppColors.lime,
                                        fontWeight: FontWeight.w900,
                                        fontSize: 12,
                                      ),
                                    ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
