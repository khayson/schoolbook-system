import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/features/reference/presentation/reference_book_tile.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';
import 'package:schoolbook/features/stock/data/stock_repository.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';
import 'package:schoolbook/shared/widgets/loading_overlay.dart';

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
  const ReceiveStockScreen({super.key, this.productId});

  /// When opened from a product, that product is pre-added as a receipt line.
  final int? productId;

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
  bool _preloading = false;

  /// True after a search ran (so we can show “nothing found”).
  bool _hasSearched = false;
  String? _error;
  Timer? _searchDebounce;

  /// One key per save attempt; reused on retries until success or a new attempt.
  String? _pendingIdempotencyKey;

  static const _r = 12.0;

  @override
  void initState() {
    super.initState();
    final id = widget.productId;
    if (id != null) {
      _preloadProduct(id);
    }
  }

  Future<void> _preloadProduct(int id) async {
    setState(() {
      _preloading = true;
      _error = null;
    });
    try {
      final product = await context.read<ProductsRepository>().getProduct(id);
      if (!mounted) return;
      _addLine(product);
      setState(() => _preloading = false);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _preloading = false;
      });
    }
  }

  @override
  void dispose() {
    _searchDebounce?.cancel();
    _searchController.dispose();
    _referenceController.dispose();
    _notesController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String value) {
    _searchDebounce?.cancel();
    final query = value.trim();
    if (query.isEmpty) {
      setState(() {
        _searchResults = [];
        _listResults = [];
        _hasSearched = false;
        _searching = false;
      });
      return;
    }
    _searchDebounce = Timer(const Duration(milliseconds: 350), () {
      if (mounted) _search();
    });
  }

  Future<void> _search() async {
    final query = _searchController.text.trim();
    if (query.isEmpty) {
      setState(() {
        _searchResults = [];
        _listResults = [];
        _hasSearched = false;
        _searching = false;
      });
      return;
    }
    final catalog = context.read<ReferenceCatalog>();
    final requestQuery = query;
    setState(() {
      _searching = true;
      _error = null;
      _listResults = catalog
          .search(query, limit: 30)
          .where((b) => catalog.stockFor(b.id) == null)
          .take(10)
          .toList();
    });
    try {
      final page = await context.read<ProductsRepository>().listProducts(
        search: query,
        page: 1,
      );
      if (!mounted || _searchController.text.trim() != requestQuery) {
        return;
      }
      setState(() {
        _searchResults = page.data;
        _searching = false;
        _hasSearched = true;
      });
    } on ApiException catch (e) {
      if (!mounted || _searchController.text.trim() != requestQuery) {
        return;
      }
      setState(() {
        _error = e.message;
        _searching = false;
        _hasSearched = true;
      });
    }
  }

  void _clearSearch() {
    _searchDebounce?.cancel();
    _searchController.clear();
    setState(() {
      _searchResults = [];
      _listResults = [];
      _hasSearched = false;
      _searching = false;
    });
  }

  Future<void> _addFromList(ReferenceBook book) async {
    final product = await context.push<Product>(
      '/products/approved/${book.id}/new?receive=1',
    );
    if (product != null && mounted) {
      setState(
        () =>
            _listResults = _listResults.where((b) => b.id != book.id).toList(),
      );
      _addLine(product);
    }
  }

  Future<void> _addManualProduct() async {
    final title = _searchController.text.trim();
    final path = title.isEmpty
        ? '/products/new?return=1'
        : '/products/new?return=1&title=${Uri.encodeQueryComponent(title)}';
    final product = await context.push<Product>(path);
    if (product != null && mounted) {
      _addLine(product);
    }
  }

  void _addLine(Product product) {
    if (_lines.any((l) => l.product.id == product.id)) {
      AppToast.show(
        context,
        'Product already on this receipt',
        type: ToastType.warning,
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
      if (!mounted) return;
      _pendingIdempotencyKey = null;
      AppToast.show(context, 'Stock received', type: ToastType.success);
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
    final bottom = MediaQuery.paddingOf(context).bottom;
    final noHits = _hasSearched &&
        !_searching &&
        _searchResults.isEmpty &&
        _listResults.isEmpty;

    return LoadingOverlay(
      isLoading: _submitting,
      message: 'Submitting receipt…',
      child: AppPage(
        title: 'Receive stock',
        subtitle: 'Add copies to the shelves',
        body: _preloading
            ? const LoadingBody(message: 'Loading product…')
            : ListView(
                padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 24),
                children: [
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      color: AppColors.limeSoft,
                      border: Border.all(color: AppColors.lime, width: 1.5),
                      borderRadius: BorderRadius.circular(_r),
                    ),
                    child: Text(
                      widget.productId != null && _lines.isNotEmpty
                          ? '1. Product is on the receipt · 2. Set qty & cost · 3. Submit — or search to add more titles'
                          : '1. Search and tap a product to add it · 2. Set qty & unit cost · 3. Submit receipt',
                      style: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        height: 1.35,
                      ),
                    ),
                  ),
                  const SizedBox(height: 18),
                  const _SectionLabel('1 · Find a product'),
                  const SizedBox(height: 10),
                  ListenableBuilder(
                    listenable: _searchController,
                    builder: (context, _) {
                      final hasText = _searchController.text.trim().isNotEmpty;
                      return TextField(
                        key: const Key('receive_search'),
                        controller: _searchController,
                        textInputAction: TextInputAction.search,
                        decoration: InputDecoration(
                          hintText: 'Type a title or SKU…',
                          helperText:
                              'Results appear as you type. Tap a row to add it.',
                          helperMaxLines: 2,
                          prefixIcon: const Padding(
                            padding: EdgeInsets.only(left: 14, right: 8),
                            child: AppIcon(AppIcons.search, size: 20),
                          ),
                          prefixIconConstraints:
                              const BoxConstraints(minWidth: 44, minHeight: 44),
                          suffixIcon: hasText
                              ? IconButton(
                                  key: const Key('receive_search_clear'),
                                  tooltip: 'Clear',
                                  onPressed: _clearSearch,
                                  icon:
                                      const AppIcon(AppIcons.close, size: 18),
                                )
                              : IconButton(
                                  key: const Key('receive_search_go'),
                                  tooltip: 'Search',
                                  onPressed: _searching ? null : _search,
                                  icon:
                                      const AppIcon(AppIcons.search, size: 20),
                                ),
                          enabledBorder: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(_r),
                            borderSide:
                                const BorderSide(color: AppColors.line),
                          ),
                          focusedBorder: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(_r),
                            borderSide: const BorderSide(
                              color: AppColors.ink,
                              width: 1.5,
                            ),
                          ),
                        ),
                        onChanged: _onSearchChanged,
                        onSubmitted: (_) => _search(),
                      );
                    },
                  ),
                  if (_searching) ...[
                    const SizedBox(height: 10),
                    const LinearProgressIndicator(
                      minHeight: 2,
                      backgroundColor: AppColors.line,
                      color: AppColors.lime,
                    ),
                  ],
                  if (_searchResults.isNotEmpty) ...[
                    const SizedBox(height: 12),
                    const Text(
                      'In your catalog',
                      style: TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w800,
                        color: AppColors.mute,
                      ),
                    ),
                    const SizedBox(height: 8),
                    for (final p in _searchResults) ...[
                      _SearchHit(
                        key: Key('receive_product_${p.id}'),
                        title: p.title,
                        subtitle: p.sku,
                        onAdd: () => _addLine(p),
                      ),
                      const SizedBox(height: 8),
                    ],
                  ],
                  if (_listResults.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    const _SectionLabel('Approved list — not in catalog yet'),
                    const SizedBox(height: 8),
                    const Text(
                      'Tap to create the product, then it is added to this receipt.',
                      style: TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w600,
                        color: AppColors.mute,
                        height: 1.35,
                      ),
                    ),
                    const SizedBox(height: 8),
                    for (final b in _listResults)
                      ReferenceBookTile(
                        book: b,
                        stock: null,
                        onTap: () => _addFromList(b),
                      ),
                  ],
                  if (noHits) ...[
                    const SizedBox(height: 12),
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
                            'Nothing matched “${_searchController.text.trim()}”.',
                            style: const TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.w800,
                              height: 1.35,
                            ),
                          ),
                          const SizedBox(height: 6),
                          const Text(
                            'Not in your library and not on the approved list. '
                            'Add it here — you come straight back to this receipt.',
                            style: TextStyle(
                              fontSize: 12.5,
                              fontWeight: FontWeight.w600,
                              color: AppColors.mute,
                              height: 1.35,
                            ),
                          ),
                          const SizedBox(height: 12),
                          FilledButton.icon(
                            key: const Key('receive_add_missing_book'),
                            onPressed: _addManualProduct,
                            icon: const Icon(Icons.add, size: 18),
                            label: Text(
                              _searchController.text.trim().isEmpty
                                  ? 'Add new book'
                                  : 'Add “${_searchController.text.trim()}”',
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                  const SizedBox(height: 22),
                  Row(
                    children: [
                      const Expanded(
                        child: _SectionLabel('2 · Receipt lines'),
                      ),
                      if (_lines.isNotEmpty)
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 10,
                            vertical: 4,
                          ),
                          decoration: BoxDecoration(
                            color: AppColors.lime,
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Text(
                            '${_lines.length}',
                            style: const TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                        ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  if (_lines.isEmpty)
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.symmetric(
                        horizontal: 16,
                        vertical: 28,
                      ),
                      decoration: BoxDecoration(
                        border: Border.all(color: AppColors.line),
                        borderRadius: BorderRadius.circular(_r),
                      ),
                      child: const EmptyState(
                        title: 'No products on this receipt yet',
                        subtitle:
                            'Search above, then tap a result to add it here.',
                      ),
                    )
                  else
                    for (final line in _lines) ...[
                      _LineEditor(
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
                      ),
                      const SizedBox(height: 10),
                    ],
                  const SizedBox(height: 12),
                  const _SectionLabel('3 · Optional details'),
                  const SizedBox(height: 10),
                  TextField(
                    controller: _referenceController,
                    decoration: InputDecoration(
                      labelText: 'Supplier reference (optional)',
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
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _notesController,
                    maxLines: 2,
                    decoration: InputDecoration(
                      labelText: 'Notes (optional)',
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
                  const SizedBox(height: 20),
                  FilledButton(
                    onPressed: _submitting || _lines.isEmpty ? null : _submit,
                    style: FilledButton.styleFrom(
                      minimumSize: const Size.fromHeight(48),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(_r),
                      ),
                    ),
                    child: Text(
                      _submitting
                          ? 'Submitting…'
                          : _lines.isEmpty
                              ? 'Add a product to submit'
                              : 'Submit receipt',
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text.toUpperCase(),
      style: const TextStyle(
        fontSize: 11,
        fontWeight: FontWeight.w800,
        letterSpacing: 0.8,
        color: AppColors.mute,
      ),
    );
  }
}

class _SearchHit extends StatelessWidget {
  const _SearchHit({
    super.key,
    required this.title,
    required this.subtitle,
    required this.onAdd,
  });

  final String title;
  final String subtitle;
  final VoidCallback onAdd;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.surface,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onAdd,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.fromLTRB(12, 12, 10, 12),
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.line),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: AppColors.limeSoft,
                  border: Border.all(color: AppColors.lime),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Center(
                  child: AppIcon(AppIcons.products, size: 18),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      subtitle,
                      style: const TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: AppColors.mute,
                      ),
                    ),
                  ],
                ),
              ),
              Material(
                color: AppColors.lime,
                borderRadius: BorderRadius.circular(10),
                child: InkWell(
                  onTap: onAdd,
                  borderRadius: BorderRadius.circular(10),
                  child: const SizedBox(
                    width: 36,
                    height: 36,
                    child: Center(
                      child: AppIcon(AppIcons.add, size: 18),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
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
    _qtyController = TextEditingController(
      text: widget.line.quantity.toString(),
    );
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
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surface,
        border: Border.all(color: AppColors.line),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 4,
                height: 40,
                decoration: BoxDecoration(
                  color: AppColors.lime,
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      line.product.title,
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      line.product.sku,
                      style: const TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w600,
                        color: AppColors.mute,
                      ),
                    ),
                  ],
                ),
              ),
              IconButton(
                onPressed: widget.onRemove,
                visualDensity: VisualDensity.compact,
                icon: const AppIcon(AppIcons.close, size: 18),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _qtyController,
                  keyboardType: TextInputType.number,
                  decoration: InputDecoration(
                    labelText: 'Qty',
                    enabledBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10),
                      borderSide: const BorderSide(color: AppColors.line),
                    ),
                    focusedBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10),
                      borderSide:
                          const BorderSide(color: AppColors.ink, width: 1.5),
                    ),
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
                  decoration: InputDecoration(
                    labelText: 'Unit cost (GHS)',
                    enabledBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10),
                      borderSide: const BorderSide(color: AppColors.line),
                    ),
                    focusedBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10),
                      borderSide:
                          const BorderSide(color: AppColors.ink, width: 1.5),
                    ),
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
    );
  }
}
