import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';

class ProductScanScreen extends StatefulWidget {
  const ProductScanScreen({super.key});

  @override
  State<ProductScanScreen> createState() => _ProductScanScreenState();
}

class _ProductScanScreenState extends State<ProductScanScreen> {
  final MobileScannerController _controller = MobileScannerController();
  bool _handling = false;
  String? _error;
  final _manualController = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    _manualController.dispose();
    super.dispose();
  }

  Future<void> _lookup(String code) async {
    if (_handling || code.trim().isEmpty) {
      return;
    }
    setState(() {
      _handling = true;
      _error = null;
    });
    try {
      final product =
          await context.read<ProductsRepository>().getByCode(code.trim());
      if (!mounted) {
        return;
      }
      context.go('/products/${product.id}');
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _handling = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Scan product')),
      body: Column(
        children: [
          Expanded(
            flex: 2,
            child: Stack(
              fit: StackFit.expand,
              children: [
                MobileScanner(
                  controller: _controller,
                  onDetect: (capture) {
                    if (capture.barcodes.isEmpty) {
                      return;
                    }
                    final raw = capture.barcodes.first.rawValue;
                    if (raw != null) {
                      _lookup(raw);
                    }
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
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Or enter SKU / ISBN / barcode',
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _manualController,
                    decoration: const InputDecoration(
                      border: OutlineInputBorder(),
                      hintText: 'Code',
                    ),
                    onSubmitted: _lookup,
                  ),
                  const SizedBox(height: 12),
                  FilledButton(
                    onPressed: _handling
                        ? null
                        : () => _lookup(_manualController.text),
                    style: FilledButton.styleFrom(
                      minimumSize: const Size.fromHeight(48),
                    ),
                    child: const Text('Look up'),
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 12),
                    Text(
                      _error!,
                      style: TextStyle(
                        color: Theme.of(context).colorScheme.error,
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
