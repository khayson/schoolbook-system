import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';

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
      backgroundColor: Colors.transparent,
      builder: (_) => const _ProductPickerSheet(),
    );
    if (picked == null || !mounted) return;
    setState(() => _handling = true);
    try {
      final product = await context.read<ProductsRepository>().attachCode(
        code: code,
        productId: picked.id,
      );
      if (!mounted) return;
      AppToast.show(
        context,
        'Code $code saved on ${product.title}',
        type: ToastType.success,
      );
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
    final book =
        hinted ??
        await context.push<ReferenceBook>('/products/approved?pick=1');
    if (book == null || !mounted) return;
    final product = await context.push<Product>(
      '/products/approved/${book.id}/new?code=${Uri.encodeQueryComponent(code)}',
    );
    if (product != null && mounted) _done(product);
  }

  @override
  Widget build(BuildContext context) {
    final onCode = _lookup;
    final top = MediaQuery.paddingOf(context).top;

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        backgroundColor: AppColors.ink,
        body: Column(
          children: [
            Expanded(
              flex: 5,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  widget.scannerBuilder?.call(onCode) ??
                      MobileScanner(
                        controller: _camera,
                        onDetect: (capture) {
                          final raw = capture.barcodes.isEmpty
                              ? null
                              : capture.barcodes.first.rawValue;
                          if (raw != null) onCode(raw);
                        },
                      ),
                  const _ScanFrameOverlay(),
                  Positioned(
                    top: top + 8,
                    left: 12,
                    right: 12,
                    child: Row(
                      children: [
                        _CircleAction(
                          icon: AppIcons.back,
                          onTap: () => Navigator.of(context).maybePop(),
                        ),
                        const Spacer(),
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 14,
                            vertical: 8,
                          ),
                          decoration: BoxDecoration(
                            color: Colors.black.withValues(alpha: 0.45),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: Text(
                            widget.captureMode ? 'Scan a code' : 'Scan product',
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w800,
                              fontSize: 14,
                            ),
                          ),
                        ),
                        const Spacer(),
                        const SizedBox(width: 44),
                      ],
                    ),
                  ),
                  if (_handling)
                    ColoredBox(
                      color: Colors.black.withValues(alpha: 0.45),
                      child: const Center(
                        child: SizedBox(
                          width: 36,
                          height: 36,
                          child: CircularProgressIndicator(
                            strokeWidth: 3,
                            color: AppColors.lime,
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ),
            Expanded(
              flex: 6,
              child: Container(
                width: double.infinity,
                decoration: const BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
                ),
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(20, 20, 20, 28),
                  children: [
                    Center(
                      child: Container(
                        width: 40,
                        height: 4,
                        decoration: BoxDecoration(
                          color: AppColors.line,
                          borderRadius: BorderRadius.circular(12),
                        ),
                      ),
                    ),
                    const SizedBox(height: 18),
                    if (_unknownCode != null)
                      _unknownPanel(context)
                    else
                      ..._manualEntry(context),
                    if (_error != null) ...[
                      const SizedBox(height: 14),
                      Container(
                        key: const Key('scan_error'),
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          color: const Color(0xFFFFE4E6),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: Text(
                          _error!,
                          style: const TextStyle(
                            color: AppColors.danger,
                            fontWeight: FontWeight.w700,
                            height: 1.35,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  List<Widget> _manualEntry(BuildContext context) => [
    const Text(
      'Point at a barcode',
      style: TextStyle(
        fontSize: 22,
        fontWeight: FontWeight.w900,
        letterSpacing: -0.3,
      ),
    ),
    const SizedBox(height: 6),
    const Text(
      'Or type a SKU, ISBN, or barcode below.',
      style: TextStyle(
        fontSize: 14,
        fontWeight: FontWeight.w600,
        color: AppColors.mute,
        height: 1.35,
      ),
    ),
    const SizedBox(height: 18),
    TextField(
      key: const Key('scan_manual'),
      controller: _manualController,
      textInputAction: TextInputAction.search,
      decoration: const InputDecoration(
        hintText: 'Enter code',
        prefixIcon: Padding(
          padding: EdgeInsets.only(left: 14, right: 8),
          child: AppIcon(AppIcons.scan, size: 20),
        ),
        prefixIconConstraints: BoxConstraints(minWidth: 44, minHeight: 44),
      ),
      onSubmitted: _lookup,
    ),
    const SizedBox(height: 14),
    FilledButton(
      key: const Key('scan_lookup'),
      onPressed: _handling ? null : () => _lookup(_manualController.text),
      child: Text(widget.captureMode ? 'Use this code' : 'Look up'),
    ),
  ];

  Widget _unknownPanel(BuildContext context) {
    final code = _unknownCode!;
    final hint = context.read<ReferenceCatalog>().byIsbn(code);
    return Column(
      key: const Key('scan_unknown'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: AppColors.lime,
            borderRadius: BorderRadius.circular(12),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Unknown code',
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w800,
                  color: AppColors.ink,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                code,
                style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w900,
                  letterSpacing: -0.3,
                  color: AppColors.ink,
                ),
              ),
              const SizedBox(height: 8),
              const Text(
                'Attach it once and the next scan finds the book directly.',
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: AppColors.ink,
                  height: 1.35,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        if (hint != null) ...[
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppColors.wash,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'On the approved list',
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: AppColors.mute,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  hint.title,
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  hint.subtitle,
                  style: const TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: AppColors.mute,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          FilledButton(
            key: const Key('scan_add_hinted'),
            onPressed: _handling ? null : () => _addFromList(hint),
            child: const Text('Add as a new product'),
          ),
          const SizedBox(height: 10),
        ],
        _ActionTile(
          key: const Key('scan_attach_product'),
          icon: AppIcons.products,
          title: 'Attach to one of my products',
          subtitle: 'Link this code to a book you already sell',
          onTap: _handling ? null : _attachToProduct,
        ),
        const SizedBox(height: 10),
        _ActionTile(
          key: const Key('scan_find_on_list'),
          icon: AppIcons.approvedList,
          title: 'Find on the approved list',
          subtitle: 'Create a product from a NaCCA title',
          onTap: _handling ? null : () => _addFromList(null),
        ),
        const SizedBox(height: 8),
        TextButton(
          key: const Key('scan_again'),
          onPressed: () => setState(() {
            _unknownCode = null;
            _error = null;
          }),
          child: const Text(
            'Scan another code',
            style: TextStyle(fontWeight: FontWeight.w800),
          ),
        ),
      ],
    );
  }
}

class _ScanFrameOverlay extends StatelessWidget {
  const _ScanFrameOverlay();

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: CustomPaint(
        painter: _ScanFramePainter(),
        child: const SizedBox.expand(),
      ),
    );
  }
}

class _ScanFramePainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final dim = Paint()..color = const Color(0x66000000);
    final hole = RRect.fromRectAndRadius(
      Rect.fromCenter(
        center: Offset(size.width / 2, size.height * 0.48),
        width: size.width * 0.72,
        height: size.width * 0.72,
      ),
      const Radius.circular(28),
    );
    final path = Path()
      ..addRect(Offset.zero & size)
      ..addRRect(hole)
      ..fillType = PathFillType.evenOdd;
    canvas.drawPath(path, dim);

    final stroke = Paint()
      ..color = AppColors.lime
      ..style = PaintingStyle.stroke
      ..strokeWidth = 3.5
      ..strokeCap = StrokeCap.round;
    const corner = 28.0;
    final r = hole.outerRect;
    // Top-left
    canvas.drawLine(Offset(r.left, r.top + corner), Offset(r.left, r.top), stroke);
    canvas.drawLine(Offset(r.left, r.top), Offset(r.left + corner, r.top), stroke);
    // Top-right
    canvas.drawLine(Offset(r.right - corner, r.top), Offset(r.right, r.top), stroke);
    canvas.drawLine(Offset(r.right, r.top), Offset(r.right, r.top + corner), stroke);
    // Bottom-left
    canvas.drawLine(Offset(r.left, r.bottom - corner), Offset(r.left, r.bottom), stroke);
    canvas.drawLine(Offset(r.left, r.bottom), Offset(r.left + corner, r.bottom), stroke);
    // Bottom-right
    canvas.drawLine(Offset(r.right - corner, r.bottom), Offset(r.right, r.bottom), stroke);
    canvas.drawLine(Offset(r.right, r.bottom), Offset(r.right, r.bottom - corner), stroke);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _CircleAction extends StatelessWidget {
  const _CircleAction({required this.icon, required this.onTap});

  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.black.withValues(alpha: 0.45),
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: SizedBox(
          width: 44,
          height: 44,
          child: Center(child: AppIcon(icon, size: 20, color: Colors.white)),
        ),
      ),
    );
  }
}

class _ActionTile extends StatelessWidget {
  const _ActionTile({
    super.key,
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.wash,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: const BoxDecoration(
                  color: AppColors.surface,
                  shape: BoxShape.circle,
                ),
                child: Center(child: AppIcon(icon, size: 22)),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      subtitle,
                      style: const TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w600,
                        color: AppColors.mute,
                      ),
                    ),
                  ],
                ),
              ),
              const AppIcon(AppIcons.chevronRight, size: 18, color: AppColors.mute),
            ],
          ),
        ),
      ),
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
      final page = await context.read<ProductsRepository>().listProducts(
        search: text,
      );
      if (mounted) setState(() => _results = page.data);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = describeApiError(e).body);
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
          height: MediaQuery.of(context).size.height * 0.72,
          decoration: const BoxDecoration(
            color: AppColors.surface,
            borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
          ),
          child: Column(
            children: [
              const SizedBox(height: 12),
              Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: AppColors.line,
                  borderRadius: BorderRadius.circular(12),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 18, 20, 8),
                child: Row(
                  children: [
                    const Expanded(
                      child: Text(
                        'Attach to a product',
                        style: TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ),
                    IconButton(
                      onPressed: () => Navigator.of(context).pop(),
                      icon: const AppIcon(AppIcons.close),
                    ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                child: TextField(
                  key: const Key('picker_search'),
                  autofocus: true,
                  decoration: const InputDecoration(
                    prefixIcon: Padding(
                      padding: EdgeInsets.only(left: 14, right: 8),
                      child: AppIcon(AppIcons.search, size: 20),
                    ),
                    prefixIconConstraints:
                        BoxConstraints(minWidth: 44, minHeight: 44),
                    hintText: 'Search my products',
                  ),
                  onChanged: _search,
                ),
              ),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 20),
                  child: Text(
                    _error!,
                    style: const TextStyle(
                      color: AppColors.danger,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              Expanded(
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(12, 4, 12, 20),
                  children: [
                    for (final p in _results)
                      Material(
                        color: AppColors.surface,
                        child: ListTile(
                          key: Key('picker_product_${p.id}'),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(16),
                          ),
                          title: Text(
                            p.title,
                            style: const TextStyle(fontWeight: FontWeight.w800),
                          ),
                          subtitle: Text(
                            p.sku,
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
                          onTap: () => Navigator.of(context).pop(p),
                        ),
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
