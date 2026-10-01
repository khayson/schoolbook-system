import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

class ProductDetailScreen extends StatefulWidget {
  const ProductDetailScreen({super.key, required this.productId});

  final int productId;

  @override
  State<ProductDetailScreen> createState() => _ProductDetailScreenState();
}

class _ProductDetailScreenState extends State<ProductDetailScreen> {
  Product? _product;
  List<StockMovement> _movements = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final repo = context.read<ProductsRepository>();
    try {
      final product = await repo.getProduct(widget.productId);
      final movements = await repo.listMovements(widget.productId);
      if (!mounted) {
        return;
      }
      setState(() {
        _product = product;
        _movements = movements.data;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) {
        return;
      }
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final product = _product;

    return Scaffold(
      appBar: AppBar(
        title: Text(product?.title ?? 'Product'),
        actions: [
          if (product != null)
            IconButton(
              tooltip: 'Edit',
              onPressed: () => context.push('/products/${product.id}/edit'),
              icon: const Icon(Icons.edit),
            ),
        ],
      ),
      body: _loading
          ? const LoadingBody()
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : product == null
                  ? const EmptyState(title: 'Product not found')
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.all(16),
                        children: [
                          _InfoCard(product: product),
                          const SizedBox(height: 16),
                          Text(
                            'Stock movements',
                            style: Theme.of(context).textTheme.titleMedium,
                          ),
                          const SizedBox(height: 8),
                          if (_movements.isEmpty)
                            const EmptyState(
                              title: 'No movements yet',
                              icon: Icons.swap_vert,
                            )
                          else
                            ..._movements.map(
                              (m) => Card(
                                child: ListTile(
                                  title: Text(
                                    '${m.type} · ${m.quantity > 0 ? '+' : ''}${m.quantity}',
                                  ),
                                  subtitle: Text(
                                    'Balance ${m.balanceAfter}'
                                    '${m.unitCost != null ? ' · ${Money.formatPrice(m.unitCost!)}' : ''}',
                                  ),
                                  trailing: Text(
                                    DateFormat.yMMMd().add_jm().format(
                                          m.occurredAt.toLocal(),
                                        ),
                                    style: Theme.of(context).textTheme.bodySmall,
                                  ),
                                ),
                              ),
                            ),
                        ],
                      ),
                    ),
    );
  }
}

class _InfoCard extends StatelessWidget {
  const _InfoCard({required this.product});

  final Product product;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(product.title, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 8),
            _row('SKU', product.sku),
            if (product.isbn != null) _row('ISBN', product.isbn!),
            if (product.barcode != null) _row('Barcode', product.barcode!),
            _row('Stock on hand', '${product.stockOnHand}'),
            _row('Selling price', Money.formatPrice(product.sellingPrice)),
            _row('Cost price', Money.formatPrice(product.costPrice)),
            _row('Reorder level', '${product.reorderLevel}'),
            if (product.level != null) _row('Level', product.level!.name),
            if (product.subject != null) _row('Subject', product.subject!.name),
            if (product.language != null)
              _row('Language', product.language!.name),
          ],
        ),
      ),
    );
  }

  Widget _row(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(label, style: const TextStyle(fontWeight: FontWeight.w500)),
          ),
          Expanded(child: Text(value)),
        ],
      ),
    );
  }
}
