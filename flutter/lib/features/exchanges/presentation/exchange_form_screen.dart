import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/exchanges/data/exchanges_repository.dart';
import 'package:schoolbook/features/exchanges/domain/stock_exchange.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/features/reference/presentation/reference_book_tile.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';
import 'package:schoolbook/shared/widgets/loading_overlay.dart';

class ExchangeLineDraft {
  ExchangeLineDraft({
    required this.direction,
    required this.product,
    required this.quantity,
    this.unitPricePesewas,
    this.unitCostPesewas,
  });

  ExchangeDirection direction;
  final Product product;
  int quantity;
  int? unitPricePesewas;
  int? unitCostPesewas;
}

class ExchangeFormScreen extends StatefulWidget {
  const ExchangeFormScreen({super.key, this.editingExchangeId});

  final int? editingExchangeId;

  @override
  State<ExchangeFormScreen> createState() => _ExchangeFormScreenState();
}

class _ExchangeFormScreenState extends State<ExchangeFormScreen> {
  final _otherPerson = TextEditingController();
  final _notes = TextEditingController();
  final _search = TextEditingController();
  final _lines = <ExchangeLineDraft>[];
  Customer? _dealer;
  ExchangeDirection _pickDirection = ExchangeDirection.inn;
  ExchangeSettlement? _settlement = ExchangeSettlement.payLater;
  List<Product> _searchResults = [];

  /// Approved-list titles the shop does not carry yet (same pattern as receive stock).
  List<ReferenceBook> _listResults = [];
  bool _searching = false;
  bool _hasSearched = false;
  bool _loading = false;
  bool _saving = false;
  String? _error;
  Timer? _debounce;
  String? _idempotencyKey;

  bool get _isEdit => widget.editingExchangeId != null;

  @override
  void initState() {
    super.initState();
    if (_isEdit) _loadDraft();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _otherPerson.dispose();
    _notes.dispose();
    _search.dispose();
    super.dispose();
  }

  Future<void> _loadDraft() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final exchange = await context.read<ExchangesRepository>().getExchange(
        widget.editingExchangeId!,
      );
      if (!mounted) return;
      if (!exchange.isDraft) {
        context.go('/stock/exchanges/${exchange.id}');
        return;
      }
      _otherPerson.text = exchange.counterpartyName;
      _notes.text = exchange.notes ?? '';
      _settlement = exchange.settlement ?? ExchangeSettlement.payLater;
      _dealer = exchange.customer;
      if (_dealer == null) {
        _dealer = await context.read<CustomersRepository>().getCustomer(
          exchange.customerId,
        );
      }
      _lines
        ..clear()
        ..addAll(
          exchange.items.map((item) {
            final product =
                item.product ??
                Product(
                  id: item.productId,
                  sku: '',
                  title: item.productTitle,
                  levelId: 0,
                  subjectId: 0,
                  languageId: 0,
                  costPrice: item.unitCost ?? 0,
                  sellingPrice: item.unitPrice ?? 0,
                  reorderLevel: 0,
                  stockOnHand: 0,
                  isActive: true,
                );
            return ExchangeLineDraft(
              direction: item.direction,
              product: product,
              quantity: item.quantity,
              unitPricePesewas: item.unitPrice,
              unitCostPesewas: item.unitCost,
            );
          }),
        );
      setState(() => _loading = false);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _error = e.message;
          _loading = false;
        });
      }
    }
  }

  Future<void> _pickDealer() async {
    final customer = await showModalBottomSheet<Customer>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      backgroundColor: AppColors.surface,
      builder: (_) => const _PersonPickerSheet(),
    );
    if (customer == null || !mounted) return;
    setState(() {
      final previousName = _dealer?.name;
      _dealer = customer;
      // Fill paper name when empty, or when it still matched the previous pick.
      if (_otherPerson.text.trim().isEmpty ||
          (previousName != null && _otherPerson.text.trim() == previousName)) {
        _otherPerson.text = customer.name;
      }
    });
  }

  void _onSearchChanged(String value) {
    _debounce?.cancel();
    final q = value.trim();
    if (q.isEmpty) {
      setState(() {
        _searchResults = [];
        _listResults = [];
        _hasSearched = false;
        _searching = false;
      });
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 350), _searchProducts);
  }

  Future<void> _searchProducts() async {
    final q = _search.text.trim();
    if (q.isEmpty) return;
    final catalog = context.read<ReferenceCatalog>();
    final requestQuery = q;
    setState(() {
      _searching = true;
      _error = null;
      _listResults = catalog
          .search(q, limit: 30)
          .where((b) => catalog.stockFor(b.id) == null)
          .take(10)
          .toList();
    });
    try {
      final page = await context.read<ProductsRepository>().listProducts(
        search: q,
        page: 1,
      );
      if (!mounted || _search.text.trim() != requestQuery) return;
      setState(() {
        _searchResults = page.data;
        _searching = false;
        _hasSearched = true;
      });
    } on ApiException catch (e) {
      if (!mounted || _search.text.trim() != requestQuery) return;
      setState(() {
        _searching = false;
        _hasSearched = true;
        _error = e.message;
      });
    }
  }

  Future<void> _addFromApprovedList(ReferenceBook book) async {
    final product = await context.push<Product>(
      '/products/approved/${book.id}/new?receive=1',
    );
    if (product != null && mounted) {
      setState(
        () => _listResults = _listResults.where((b) => b.id != book.id).toList(),
      );
      _addProduct(product);
    }
  }

  /// Book not on the approved list either — create it, then keep this exchange.
  Future<void> _addManualProduct() async {
    final title = _search.text.trim();
    final path = title.isEmpty
        ? '/products/new?return=1'
        : '/products/new?return=1&title=${Uri.encodeQueryComponent(title)}';
    final product = await context.push<Product>(path);
    if (product != null && mounted) {
      _addProduct(product);
    }
  }

  void _addProduct(Product product) {
    final existing = _lines.indexWhere(
      (l) => l.product.id == product.id && l.direction == _pickDirection,
    );
    setState(() {
      if (existing >= 0) {
        _lines[existing].quantity += 1;
      } else {
        _lines.add(
          ExchangeLineDraft(
            direction: _pickDirection,
            product: product,
            quantity: 1,
            unitPricePesewas: _pickDirection == ExchangeDirection.out
                ? product.sellingPrice
                : null,
            unitCostPesewas: _pickDirection == ExchangeDirection.inn
                ? product.costPrice
                : null,
          ),
        );
      }
      _search.clear();
      _searchResults = [];
      _listResults = [];
      _hasSearched = false;
    });
  }

  Future<void> _editQty(ExchangeLineDraft line) async {
    final controller = TextEditingController(text: '${line.quantity}');
    final qty = await showDialog<int>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('How many — ${line.product.title}'),
        content: TextField(
          controller: controller,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          autofocus: true,
          decoration: const InputDecoration(labelText: 'Number of copies'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () {
              final n = int.tryParse(controller.text.trim()) ?? 0;
              Navigator.pop(ctx, n > 0 ? n : null);
            },
            child: const Text('Save'),
          ),
        ],
      ),
    );
    if (qty != null && mounted) setState(() => line.quantity = qty);
  }

  Future<void> _save() async {
    final name = _otherPerson.text.trim();
    if (_dealer == null) {
      setState(
        () => _error =
            'Pick or save a person (individual, reseller, or school).',
      );
      return;
    }
    if (name.isEmpty) {
      setState(() => _error = 'Enter the name as on the paper / list.');
      return;
    }
    if (_lines.isEmpty) {
      setState(() => _error = 'Add books: who took them, which title, and how many.');
      return;
    }
    if (_settlement == null) {
      setState(() => _error = 'Choose cash and carry, installment, or pay later.');
      return;
    }

    final payload = {
      'customer_id': _dealer!.id,
      'counterparty_name': name,
      'settlement': _settlement!.apiValue,
      'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      'items': _lines
          .map(
            (l) => {
              'direction': l.direction.apiValue,
              'product_id': l.product.id,
              'quantity': l.quantity,
              'unit_price': l.direction == ExchangeDirection.out
                  ? l.unitPricePesewas
                  : null,
              'unit_cost': l.direction == ExchangeDirection.inn
                  ? l.unitCostPesewas
                  : null,
            },
          )
          .toList(),
    };

    setState(() {
      _saving = true;
      _error = null;
    });

    try {
      final repo = context.read<ExchangesRepository>();
      final StockExchange saved;
      if (_isEdit) {
        saved = await repo.updateDraft(widget.editingExchangeId!, payload);
      } else {
        _idempotencyKey ??=
            'exc-${DateTime.now().millisecondsSinceEpoch}-${Random().nextInt(1 << 32)}';
        saved = await repo.createDraft(
          idempotencyKey: _idempotencyKey!,
          payload: payload,
        );
      }
      if (!mounted) return;
      AppToast.show(context, 'Draft saved', type: ToastType.success);
      context.go('/stock/exchanges/${saved.id}');
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _error = e.message;
          _saving = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    final taken = _lines.where((l) => l.direction == ExchangeDirection.inn);
    final theyTook = _lines.where((l) => l.direction == ExchangeDirection.out);

    return LoadingOverlay(
      isLoading: _saving,
      child: AppPage(
        title: _isEdit ? 'Edit exchange' : 'New book exchange',
        subtitle: 'Who → books & qty → how they pay',
        body: _loading
            ? const LoadingBody()
            : ListView(
                padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 24),
                children: [
                  if (_error != null) ...[
                    Text(
                      _error!,
                      style: const TextStyle(
                        color: Colors.red,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 12),
                  ],
                  const _StepTitle('1. Who is exchanging'),
                  const Text(
                    'School, reseller, or individual — pick someone saved, or save a new person here.',
                    style: TextStyle(
                      color: AppColors.mute,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 10),
                  Material(
                    color: AppColors.surface,
                    borderRadius: BorderRadius.circular(12),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(12),
                      onTap: _pickDealer,
                      child: Container(
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          border: Border.all(color: AppColors.line),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Row(
                          children: [
                            Expanded(
                              child: _dealer == null
                                  ? const Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          'Pick or save a person',
                                          style: TextStyle(
                                            fontWeight: FontWeight.w800,
                                            fontSize: 15,
                                          ),
                                        ),
                                        SizedBox(height: 2),
                                        Text(
                                          'Tap to search or add individual / reseller / school',
                                          style: TextStyle(
                                            color: AppColors.mute,
                                            fontWeight: FontWeight.w600,
                                            fontSize: 12.5,
                                          ),
                                        ),
                                      ],
                                    )
                                  : Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          _dealer!.name,
                                          style: const TextStyle(
                                            fontWeight: FontWeight.w800,
                                            fontSize: 15,
                                          ),
                                        ),
                                        const SizedBox(height: 4),
                                        Row(
                                          children: [
                                            _TypeChip(_dealer!.type),
                                            if (_dealer!.phone != null &&
                                                _dealer!.phone!.isNotEmpty) ...[
                                              const SizedBox(width: 8),
                                              Flexible(
                                                child: Text(
                                                  _dealer!.phone!,
                                                  style: const TextStyle(
                                                    color: AppColors.mute,
                                                    fontWeight: FontWeight.w600,
                                                    fontSize: 12.5,
                                                  ),
                                                  overflow:
                                                      TextOverflow.ellipsis,
                                                ),
                                              ),
                                            ],
                                          ],
                                        ),
                                      ],
                                    ),
                            ),
                            const AppIcon(
                              AppIcons.chevronRight,
                              color: AppColors.mute,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton.icon(
                      onPressed: () async {
                        final created = await showModalBottomSheet<Customer>(
                          context: context,
                          isScrollControlled: true,
                          useRootNavigator: true,
                          backgroundColor: AppColors.surface,
                          builder: (_) => const _NewPersonSheet(),
                        );
                        if (created == null || !mounted) return;
                        setState(() {
                          _dealer = created;
                          if (_otherPerson.text.trim().isEmpty) {
                            _otherPerson.text = created.name;
                          }
                        });
                        AppToast.show(
                          context,
                          '${created.name} saved',
                          type: ToastType.success,
                        );
                      },
                      icon: const Icon(Icons.person_add_alt_1, size: 18),
                      label: const Text('Save new person here'),
                    ),
                  ),
                  TextField(
                    controller: _otherPerson,
                    decoration: const InputDecoration(
                      labelText: 'Name on the paper / list',
                      helperText:
                          'Filled from the person above; change if the list spelling differs',
                    ),
                    textCapitalization: TextCapitalization.words,
                  ),
                  const SizedBox(height: 20),
                  const _StepTitle('2. Books each side took'),
                  const Text(
                    'Choose Taken or They took, search a book, then set how many.',
                    style: TextStyle(
                      color: AppColors.mute,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 8),
                  SegmentedButton<ExchangeDirection>(
                    segments: const [
                      ButtonSegment(
                        value: ExchangeDirection.inn,
                        label: Text('Taken'),
                      ),
                      ButtonSegment(
                        value: ExchangeDirection.out,
                        label: Text('They took'),
                      ),
                    ],
                    selected: {_pickDirection},
                    onSelectionChanged: (s) =>
                        setState(() => _pickDirection = s.first),
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _search,
                    decoration: InputDecoration(
                      labelText: 'Search books',
                      helperText:
                          'Your catalog first; approved titles not in stock appear below.',
                      suffixIcon: _searching
                          ? const Padding(
                              padding: EdgeInsets.all(12),
                              child: SizedBox(
                                width: 16,
                                height: 16,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              ),
                            )
                          : const Icon(Icons.search),
                    ),
                    onChanged: _onSearchChanged,
                  ),
                  if (_searchResults.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    const Text(
                      'In your library',
                      style: TextStyle(fontWeight: FontWeight.w800),
                    ),
                    ..._searchResults.map(
                      (p) => ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(
                          p.title,
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        subtitle: Text(
                          '${p.sku} · ${Money.formatPesewas(p.sellingPrice)}',
                        ),
                        onTap: () => _addProduct(p),
                      ),
                    ),
                  ],
                  if (_listResults.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    const Text(
                      'Approved list — not in your library yet',
                      style: TextStyle(fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(height: 4),
                    const Text(
                      'Tap to create the product, then it is added to this exchange.',
                      style: TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w600,
                        color: AppColors.mute,
                      ),
                    ),
                    const SizedBox(height: 4),
                    for (final b in _listResults)
                      ReferenceBookTile(
                        book: b,
                        stock: null,
                        onTap: () => _addFromApprovedList(b),
                      ),
                  ],
                  if (_hasSearched &&
                      !_searching &&
                      _searchResults.isEmpty &&
                      _listResults.isEmpty) ...[
                    const SizedBox(height: 12),
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        border: Border.all(color: AppColors.line),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Nothing matched “${_search.text.trim()}”.',
                            style: const TextStyle(
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                          const SizedBox(height: 6),
                          const Text(
                            'Not in your library and not on the approved list. '
                            'Add it here — you come straight back to this exchange.',
                            style: TextStyle(
                              fontSize: 12.5,
                              fontWeight: FontWeight.w600,
                              color: AppColors.mute,
                              height: 1.35,
                            ),
                          ),
                          const SizedBox(height: 12),
                          FilledButton.icon(
                            key: const Key('exchange_add_missing_book'),
                            onPressed: _addManualProduct,
                            icon: const Icon(Icons.add, size: 18),
                            label: Text(
                              _search.text.trim().isEmpty
                                  ? 'Add new book'
                                  : 'Add “${_search.text.trim()}”',
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                  const SizedBox(height: 12),
                  _LinesBlock(
                    title: 'They gave us (Taken)',
                    lines: taken.toList(),
                    onQty: _editQty,
                    onRemove: (l) => setState(() => _lines.remove(l)),
                    onBump: (l, d) => setState(() {
                      final next = l.quantity + d;
                      if (next >= 1) l.quantity = next;
                    }),
                  ),
                  const SizedBox(height: 8),
                  _LinesBlock(
                    title: 'They took from us',
                    lines: theyTook.toList(),
                    showPrice: true,
                    onQty: _editQty,
                    onRemove: (l) => setState(() => _lines.remove(l)),
                    onBump: (l, d) => setState(() {
                      final next = l.quantity + d;
                      if (next >= 1) l.quantity = next;
                    }),
                  ),
                  const SizedBox(height: 20),
                  const _StepTitle('3. How they pay'),
                  ...ExchangeSettlement.values.map(
                    (s) => RadioListTile<ExchangeSettlement>(
                      value: s,
                      groupValue: _settlement,
                      onChanged: (v) => setState(() => _settlement = v),
                      title: Text(
                        s.label,
                        style: const TextStyle(fontWeight: FontWeight.w800),
                      ),
                      subtitle: Text(s.description),
                    ),
                  ),
                  TextField(
                    controller: _notes,
                    decoration: const InputDecoration(labelText: 'Notes'),
                    maxLines: 2,
                  ),
                  const SizedBox(height: 20),
                  FilledButton(
                    onPressed: _saving ? null : _save,
                    child: const Text('Save draft'),
                  ),
                ],
              ),
      ),
    );
  }
}

class _StepTitle extends StatelessWidget {
  const _StepTitle(this.text);
  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Text(
        text,
        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w900),
      ),
    );
  }
}

class _LinesBlock extends StatelessWidget {
  const _LinesBlock({
    required this.title,
    required this.lines,
    required this.onQty,
    required this.onRemove,
    required this.onBump,
    this.showPrice = false,
  });

  final String title;
  final List<ExchangeLineDraft> lines;
  final Future<void> Function(ExchangeLineDraft) onQty;
  final void Function(ExchangeLineDraft) onRemove;
  final void Function(ExchangeLineDraft, int) onBump;
  final bool showPrice;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
        if (lines.isEmpty)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 8),
            child: Text(
              'None yet',
              style: TextStyle(color: AppColors.mute),
            ),
          )
        else
          ...lines.map(
            (line) => Container(
              margin: const EdgeInsets.only(top: 8),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                border: Border.all(color: AppColors.line),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    line.product.title,
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                  if (showPrice && line.unitPricePesewas != null)
                    Text(
                      'Price ${Money.formatPesewas(line.unitPricePesewas!)}',
                      style: const TextStyle(
                        color: AppColors.mute,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  Row(
                    children: [
                      const Text(
                        'How many:',
                        style: TextStyle(fontWeight: FontWeight.w700),
                      ),
                      IconButton(
                        onPressed: () => onBump(line, -1),
                        icon: const Icon(Icons.remove),
                      ),
                      InkWell(
                        onTap: () => onQty(line),
                        child: Text(
                          '${line.quantity}',
                          style: const TextStyle(
                            fontWeight: FontWeight.w900,
                            fontSize: 18,
                          ),
                        ),
                      ),
                      IconButton(
                        onPressed: () => onBump(line, 1),
                        icon: const Icon(Icons.add),
                      ),
                      const Spacer(),
                      IconButton(
                        onPressed: () => onRemove(line),
                        icon: const Icon(Icons.delete_outline),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

class _TypeChip extends StatelessWidget {
  const _TypeChip(this.type);

  final String type;

  @override
  Widget build(BuildContext context) {
    final label = CustomerOptions.types[type] ?? type;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: AppColors.wash,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: AppColors.line),
      ),
      child: Text(
        label,
        style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800),
      ),
    );
  }
}

class _PersonPickerSheet extends StatefulWidget {
  const _PersonPickerSheet();

  @override
  State<_PersonPickerSheet> createState() => _PersonPickerSheetState();
}

class _PersonPickerSheetState extends State<_PersonPickerSheet> {
  final _search = TextEditingController();
  List<Customer> _rows = [];
  bool _loading = true;
  String? _error;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final page = await context.read<CustomersRepository>().listCustomers(
        search: _search.text.trim().isEmpty ? null : _search.text.trim(),
      );
      if (mounted) {
        setState(() {
          _rows = page.data;
          _loading = false;
        });
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _error = e.message;
          _loading = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final height = MediaQuery.sizeOf(context).height * 0.85;
    final safeBottom = MediaQuery.paddingOf(context).bottom;
    final keyboard = MediaQuery.viewInsetsOf(context).bottom;
    return Padding(
      padding: EdgeInsets.only(bottom: keyboard),
      child: SizedBox(
        height: height,
        child: SafeArea(
          top: false,
          child: Column(
            children: [
              const Padding(
                padding: EdgeInsets.fromLTRB(16, 16, 16, 0),
                child: Align(
                  alignment: Alignment.centerLeft,
                  child: Text(
                    'Pick a person',
                    style: TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: TextField(
                  controller: _search,
                  decoration: const InputDecoration(
                    labelText: 'Search name, code, or phone',
                    prefixIcon: Icon(Icons.search),
                    helperText: 'Individuals, resellers, and schools',
                  ),
                  onChanged: (_) {
                    _debounce?.cancel();
                    _debounce = Timer(const Duration(milliseconds: 300), _load);
                  },
                ),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: SizedBox(
                  width: double.infinity,
                  child: OutlinedButton.icon(
                    onPressed: () async {
                      final created = await showModalBottomSheet<Customer>(
                        context: context,
                        isScrollControlled: true,
                        useRootNavigator: true,
                        backgroundColor: AppColors.surface,
                        builder: (_) => const _NewPersonSheet(),
                      );
                      if (created != null && context.mounted) {
                        Navigator.pop(context, created);
                      }
                    },
                    icon: const Icon(Icons.person_add_alt_1),
                    label: const Text('Not on the list? Save new person'),
                  ),
                ),
              ),
              const SizedBox(height: 8),
              Expanded(
                child: _loading
                    ? const LoadingBody()
                    : _error != null
                        ? ErrorState(message: _error!, onRetry: _load)
                        : _rows.isEmpty
                            ? const EmptyState(
                                title: 'No match',
                                subtitle: 'Save a new person instead.',
                              )
                            : ListView.builder(
                                padding: EdgeInsets.only(bottom: safeBottom + 16),
                                itemCount: _rows.length,
                                itemBuilder: (context, i) {
                                  final c = _rows[i];
                                  return ListTile(
                                    title: Text(
                                      c.name,
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w800,
                                      ),
                                    ),
                                    subtitle: Text(
                                      '${CustomerOptions.types[c.type] ?? c.type} · ${c.code}',
                                    ),
                                    trailing: _TypeChip(c.type),
                                    onTap: () => Navigator.pop(context, c),
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

class _NewPersonSheet extends StatefulWidget {
  const _NewPersonSheet();

  @override
  State<_NewPersonSheet> createState() => _NewPersonSheetState();
}

class _NewPersonSheetState extends State<_NewPersonSheet> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  String _type = 'individual';
  String? _region = 'Greater Accra';
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final name = _name.text.trim();
    if (name.isEmpty) {
      setState(() => _error = 'Enter their name.');
      return;
    }
    if (_region == null) {
      setState(() => _error = 'Pick a region.');
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final customer = await context.read<CustomersRepository>().createCustomer({
        'name': name,
        'type': _type,
        'region': _region,
        'phone': _phone.text.trim().isEmpty ? null : _phone.text.trim(),
        'is_active': true,
      });
      if (mounted) Navigator.pop(context, customer);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _error = e.message;
          _saving = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final safeBottom = MediaQuery.paddingOf(context).bottom;
    final keyboard = MediaQuery.viewInsetsOf(context).bottom;
    return SafeArea(
      top: false,
      child: Padding(
        padding: EdgeInsets.only(bottom: keyboard),
        child: SingleChildScrollView(
          padding: EdgeInsets.fromLTRB(20, 16, 20, safeBottom + 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: [
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  margin: const EdgeInsets.only(bottom: 16),
                  decoration: BoxDecoration(
                    color: AppColors.line,
                    borderRadius: BorderRadius.circular(999),
                  ),
                ),
              ),
              const Text(
                'Save new person',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 4),
              const Text(
                'Stays on this exchange — no need to leave and come back.',
                style: TextStyle(
                  color: AppColors.mute,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 16),
              if (_error != null) ...[
                Text(
                  _error!,
                  style: const TextStyle(
                    color: Colors.red,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 8),
              ],
              TextField(
                controller: _name,
                decoration: const InputDecoration(labelText: 'Full name'),
                textCapitalization: TextCapitalization.words,
                autofocus: true,
              ),
              const SizedBox(height: 12),
              const Text(
                'Type',
                style: TextStyle(fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 6),
              SegmentedButton<String>(
                segments: const [
                  ButtonSegment(
                    value: 'individual',
                    label: Text('Individual'),
                  ),
                  ButtonSegment(value: 'reseller', label: Text('Reseller')),
                  ButtonSegment(value: 'school', label: Text('School')),
                ],
                selected: {_type},
                onSelectionChanged: (s) => setState(() => _type = s.first),
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                // ignore: deprecated_member_use
                value: _region,
                decoration: const InputDecoration(labelText: 'Region'),
                items: [
                  for (final r in CustomerOptions.regions)
                    DropdownMenuItem(value: r, child: Text(r)),
                ],
                onChanged: (v) => setState(() => _region = v),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _phone,
                decoration: const InputDecoration(
                  labelText: 'Phone (optional)',
                ),
                keyboardType: TextInputType.phone,
              ),
              const SizedBox(height: 20),
              FilledButton(
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
