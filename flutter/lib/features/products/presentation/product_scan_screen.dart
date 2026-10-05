import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';

/// Scan (or type) a code.
///
/// Resolution order: the shop's products first (`products/by-code`: SKU, ISBN or
/// barcode). An unknown code is offered for attaching ("scan to learn"): to one of the
/// shop's products, or to an approved title as a new product. The approved list's ISBN
/// is only a hint (a title can have several variants, and it learns only the first
/// ISBN), so the user still picks the variant. Once attached, the next scan of the
/// same code finds the product directly.
class ProductScanScreen extends StatefulWidget {
  const ProductScanScreen({
    super.key,
    this.pickMode = false,
    this.captureMode = false,
    this.scannerBuilder,
  });

  /// New sale: the product is returned to the caller with `context.pop(product)`.
  final bool pickMode;

  /// Only read a code and return it (`context.pop(code)`), e.g. for a form field.
  final bool captureMode;

  /// Tests replace the camera.
  final Widget Function(void Function(String code) onCode)? scannerBuilder;

  @override
  State<ProductScanScreen> createState() => _ProductScanScreenState();
}

class _ProductScanScreenState extends State<ProductScanScreen> {
  MobileScannerController? _camera;
  bool _handling = false;
  String? _error;
  String? _unknownCode;
  final _manualController = TextEditingController();

  @override
  void initState() {
    super.initState();
    if (widget.scannerBuilder == null) {
      _camera = MobileScannerController();
    }
  }

  @override
  void dispose() {
    _camera?.dispose();
    _manualController.dispose();
    super.dispose();
  }

  Future<void> _lookup(String raw) async {
    final code = raw.trim();
    if (_handling || code.isEmpty) return;
    if (widget.captureMode) {
      context.pop(code);
      return;
    }
    setState(() {
      _handling = true;
      _error = null;
      _unknownCode = null;
    });
    try {
      final product = await context.read<ProductsRepository>().getByCode(code);
      if (mounted) _done(product);
    } on ApiException catch (e) {
      setState(() {
        _handling = false;
        if (e.statusCode == 404) {
          _unknownCode = code;
        } else {
          _error = describeApiError(e).body;
        }
      });
    }
  }

  void _done(Product product) {
    if (widget.pickMode) {
      context.pop(product);
    } else {
      context.go('/products/${product.id}');
    }
  }

  /// The unknown code becomes this product's ISBN or barcode.
  Future<void> _attachToProduct() async {
    final code = _unknownCode!;
    final picked = await showModalBottomSheet<Product>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const _ProductPickerSheet(),
    );
    if (picked == null || !mounted) return;
    setState(() => _handling = true);
    try {
      final product = await context.read<ProductsRepository>().attachCode(code: code, productId: picked.id);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Code $code saved on ${product.title}')));
      _done(product);
    } on ApiException catch (e) {
      final d = describeApiError(e);
      setState(() {
        _handling = false;
        _error = '${d.title}: ${d.body}';
      });
    }
  }

  /// A new product from an approved title, with the code attached.
  Future<void> _addFromList(ReferenceBook? hinted) async {
    final code = _unknownCode!;
    final book = hinted ?? await context.push<ReferenceBook>('/products/approved?pick=1');
    if (book == null || !mounted) return;
    final product = await context.push<Product>(
      '/products/approved/${book.id}/new?code=${Uri.encodeQueryComponent(code)}',
    );
    if (product != null && mounted) _done(product);
  }

  @override
  Widget build(BuildContext context) {
    final onCode = _lookup;
    return Scaffold(
      appBar: AppBar(title: Text(widget.captureMode ? 'Scan a code' : 'Scan product')),
      body: Column(
        children: [
          Expanded(
            flex: 2,
            child: Stack(
              fit: StackFit.expand,
              children: [
                widget.scannerBuilder?.call(onCode) ??
                    MobileScanner(
                      controller: _camera,
                      onDetect: (capture) {
                        final raw = capture.barcodes.isEmpty ? null : capture.barcodes.first.rawValue;
                        if (raw != null) onCode(raw);
                      },
                    ),
                if (_handling)
                  const ColoredBox(
                    color: Colors.black38,
                    child: Center(child: CircularProgressIndicator()),
                  ),
              ],
            ),
          ),
          Expanded(
            flex: 3,
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (_unknownCode != null) _unknownPanel(context) else ..._manualEntry(context),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _error!,
                    key: const Key('scan_error'),
                    style: TextStyle(color: Theme.of(context).colorScheme.error),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  List<Widget> _manualEntry(BuildContext context) => [
        Text('Or enter SKU / ISBN / barcode', style: Theme.of(context).textTheme.titleSmall),
        const SizedBox(height: 8),
        TextField(
          key: const Key('scan_manual'),
          controller: _manualController,
          decoration: const InputDecoration(border: OutlineInputBorder(), hintText: 'Code'),
          onSubmitted: _lookup,
        ),
        const SizedBox(height: 12),
        FilledButton(
          key: const Key('scan_lookup'),
          onPressed: _handling ? null : () => _lookup(_manualController.text),
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
          child: Text(widget.captureMode ? 'Use this code' : 'Look up'),
        ),
      ];

  Widget _unknownPanel(BuildContext context) {
    final code = _unknownCode!;
    final hint = context.read<ReferenceCatalog>().byIsbn(code);
    final theme = Theme.of(context);
    return Column(
      key: const Key('scan_unknown'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Unknown code $code', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        const Text('Attach it once and the next scan finds the book directly.'),
        const SizedBox(height: 12),
        if (hint != null) ...[
          Card(
            child: ListTile(
              title: Text(hint.title),
              subtitle: Text('On the approved list: ${hint.subtitle}'),
            ),
          ),
          FilledButton(
            key: const Key('scan_add_hinted'),
            onPressed: _handling ? null : () => _addFromList(hint),
            child: const Text('Add as a new product (choose the variant)'),
          ),
          const SizedBox(height: 8),
        ],
        OutlinedButton(
          key: const Key('scan_attach_product'),
          onPressed: _handling ? null : _attachToProduct,
          child: const Text('Attach to one of my products'),
        ),
        const SizedBox(height: 8),
        OutlinedButton(
          key: const Key('scan_find_on_list'),
          onPressed: _handling ? null : () => _addFromList(null),
          child: const Text('Find it on the approved list'),
        ),
        const SizedBox(height: 8),
        TextButton(
          key: const Key('scan_again'),
          onPressed: () => setState(() => _unknownCode = null),
          child: const Text('Scan another code'),
        ),
      ],
    );
  }
}

/// Pick one of the shop's products (search by title or SKU).
class _ProductPickerSheet extends StatefulWidget {
  const _ProductPickerSheet();

  @override
  State<_ProductPickerSheet> createState() => _ProductPickerSheetState();
}

class _ProductPickerSheetState extends State<_ProductPickerSheet> {
  List<Product> _results = const [];
  String? _error;

  Future<void> _search(String text) async {
    try {
      final page = await context.read<ProductsRepository>().listProducts(search: text);
      if (mounted) setState(() => _results = page.data);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = describeApiError(e).body);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
        child: SizedBox(
          height: MediaQuery.of(context).size.height * 0.7,
          child: Column(
            children: [
              Padding(
                padding: const EdgeInsets.all(16),
                child: TextField(
                  key: const Key('picker_search'),
                  autofocus: true,
                  decoration: const InputDecoration(
                    prefixIcon: Icon(Icons.search),
                    hintText: 'Search my products',
                    border: OutlineInputBorder(),
                  ),
                  onChanged: _search,
                ),
              ),
              if (_error != null) Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
              Expanded(
                child: ListView(
                  children: [
                    for (final p in _results)
                      ListTile(
                        key: Key('picker_product_${p.id}'),
                        title: Text(p.title),
                        subtitle: Text(p.sku),
                        onTap: () => Navigator.of(context).pop(p),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
