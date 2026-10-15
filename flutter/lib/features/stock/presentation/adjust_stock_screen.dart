import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/stock/data/stock_repository.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/loading_overlay.dart';

class AdjustStockScreen extends StatefulWidget {
  const AdjustStockScreen({super.key, this.productId});

  final int? productId;

  @override
  State<AdjustStockScreen> createState() => _AdjustStockScreenState();
}

class _AdjustStockScreenState extends State<AdjustStockScreen> {
  final _qty = TextEditingController();
  final _note = TextEditingController();
  Product? _product;
  String _type = 'adjustment';
  bool _busy = false;
  String? _error;

  static const _r = 12.0;

  @override
  void initState() {
    super.initState();
    final id = widget.productId;
    if (id != null) {
      _loadProduct(id);
    }
  }

  @override
  void dispose() {
    _qty.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _loadProduct(int id) async {
    try {
      final p = await context.read<ProductsRepository>().getProduct(id);
      if (mounted) setState(() => _product = p);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _pickProduct() async {
    final chosen = await showModalBottomSheet<Product>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => const _ProductPickerSheet(),
    );
    if (chosen != null && mounted) {
      setState(() => _product = chosen);
    }
  }

  Future<void> _submit() async {
    final product = _product;
    final qty = int.tryParse(_qty.text.trim());
    final note = _note.text.trim();
    if (product == null) {
      setState(() => _error = 'Pick a product');
      return;
    }
    if (qty == null || qty == 0) {
      setState(() => _error = 'Enter a non-zero quantity (+ in, − out)');
      return;
    }
    if (note.isEmpty) {
      setState(() => _error = 'A note is required');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<StockRepository>().adjustStock(
            productId: product.id,
            quantity: qty,
            type: _type,
            note: note,
          );
      if (!mounted) return;
      AppToast.show(context, 'Stock adjusted', type: ToastType.success);
      context.pop();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  InputDecoration _field(String label, {String? hint}) {
    return InputDecoration(
      labelText: label,
      hintText: hint,
      filled: true,
      fillColor: AppColors.surface,
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(_r),
        borderSide: const BorderSide(color: AppColors.line),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(_r),
        borderSide: const BorderSide(color: AppColors.ink, width: 1.5),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final product = _product;
    return AppPage(
      title: 'Adjust stock',
      subtitle: 'Correction or damage',
      body: LoadingOverlay(
        isLoading: _busy,
        message: 'Saving…',
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
          children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.limeSoft,
                border: Border.all(color: AppColors.lime, width: 1.5),
                borderRadius: BorderRadius.circular(_r),
              ),
              child: const Row(
                children: [
                  _SquareIcon(icon: AppIcons.stock, lime: true),
                  SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      'Use + to add copies or − to remove. Damage and corrections both need a note.',
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        height: 1.35,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            const _Label('Product'),
            const SizedBox(height: 8),
            Material(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(_r),
              child: InkWell(
                onTap: _busy ? null : _pickProduct,
                borderRadius: BorderRadius.circular(_r),
                child: Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    border: Border.all(
                      color: product == null ? AppColors.line : AppColors.lime,
                      width: product == null ? 1 : 1.5,
                    ),
                    borderRadius: BorderRadius.circular(_r),
                  ),
                  child: Row(
                    children: [
                      _SquareIcon(
                        icon: AppIcons.products,
                        lime: product != null,
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: product == null
                            ? const Text(
                                'Choose product',
                                style: TextStyle(
                                  fontSize: 15,
                                  fontWeight: FontWeight.w800,
                                  color: AppColors.mute,
                                ),
                              )
                            : Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    product.title,
                                    style: const TextStyle(
                                      fontSize: 15,
                                      fontWeight: FontWeight.w800,
                                    ),
                                  ),
                                  const SizedBox(height: 2),
                                  Text(
                                    '${product.sku} · ${product.stockOnHand} on hand',
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
            const SizedBox(height: 18),
            const _Label('Type'),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: _TypeChip(
                    label: 'Adjustment',
                    selected: _type == 'adjustment',
                    onTap: () => setState(() => _type = 'adjustment'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _TypeChip(
                    label: 'Damage',
                    selected: _type == 'damage',
                    onTap: () => setState(() => _type = 'damage'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 18),
            TextField(
              controller: _qty,
              keyboardType: const TextInputType.numberWithOptions(signed: true),
              decoration: _field('Quantity', hint: 'e.g. -3 or 5'),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _note,
              maxLines: 3,
              decoration: _field('Note', hint: 'Why this change?'),
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
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _busy ? null : _submit,
              style: FilledButton.styleFrom(
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(_r),
                ),
              ),
              child: const Text('Save adjustment'),
            ),
          ],
        ),
      ),
    );
  }
}

class _Label extends StatelessWidget {
  const _Label(this.text);

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

class _SquareIcon extends StatelessWidget {
  const _SquareIcon({required this.icon, this.lime = false});

  final IconData icon;
  final bool lime;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 40,
      height: 40,
      decoration: BoxDecoration(
        color: lime ? AppColors.lime : AppColors.wash,
        border: Border.all(
          color: lime ? AppColors.ink.withValues(alpha: 0.08) : AppColors.line,
        ),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Center(child: AppIcon(icon, size: 18)),
    );
  }
}

class _TypeChip extends StatelessWidget {
  const _TypeChip({
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
          padding: const EdgeInsets.symmetric(vertical: 14),
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
              fontSize: 14,
              fontWeight: FontWeight.w800,
              color: selected ? AppColors.lime : AppColors.ink,
            ),
          ),
        ),
      ),
    );
  }
}

class _ProductPickerSheet extends StatefulWidget {
  const _ProductPickerSheet();

  @override
  State<_ProductPickerSheet> createState() => _ProductPickerSheetState();
}

class _ProductPickerSheetState extends State<_ProductPickerSheet> {
  final _search = TextEditingController();
  List<Product> _items = [];
  bool _loading = false;

  static const _r = 12.0;

  @override
  void initState() {
    super.initState();
    _run('');
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _run(String q) async {
    setState(() => _loading = true);
    try {
      final page = await context.read<ProductsRepository>().listProducts(
            search: q,
          );
      if (mounted) setState(() => _items = page.data);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final height = MediaQuery.sizeOf(context).height * 0.75;
    return SizedBox(
      height: height,
      child: Column(
        children: [
          const SizedBox(height: 12),
          Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color: AppColors.line,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const Padding(
            padding: EdgeInsets.fromLTRB(20, 16, 20, 8),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Text(
                'Choose product',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
            child: TextField(
              controller: _search,
              decoration: InputDecoration(
                hintText: 'Search products',
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
                  borderSide:
                      const BorderSide(color: AppColors.ink, width: 1.5),
                ),
              ),
              onSubmitted: _run,
              onChanged: (v) {
                if (v.trim().isEmpty) _run('');
              },
            ),
          ),
          Expanded(
            child: _loading
                ? const Center(
                    child: SizedBox(
                      width: 22,
                      height: 22,
                      child: CircularProgressIndicator(strokeWidth: 2.4),
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
                    itemCount: _items.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, i) {
                      final p = _items[i];
                      return Material(
                        color: AppColors.surface,
                        borderRadius: BorderRadius.circular(_r),
                        child: InkWell(
                          onTap: () => Navigator.pop(context, p),
                          borderRadius: BorderRadius.circular(_r),
                          child: Container(
                            padding: const EdgeInsets.all(14),
                            decoration: BoxDecoration(
                              border: Border.all(color: AppColors.line),
                              borderRadius: BorderRadius.circular(_r),
                            ),
                            child: Row(
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        p.title,
                                        style: const TextStyle(
                                          fontSize: 14,
                                          fontWeight: FontWeight.w800,
                                        ),
                                      ),
                                      const SizedBox(height: 2),
                                      Text(
                                        '${p.sku} · ${p.stockOnHand} on hand',
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
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}
