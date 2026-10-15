import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
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

  static const _r = 12.0;

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
      if (!mounted) return;
      setState(() {
        _product = product;
        _movements = movements.data;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final product = _product;
    final bottom = MediaQuery.paddingOf(context).bottom;
    final low = product != null &&
        product.reorderLevel > 0 &&
        product.stockOnHand <= product.reorderLevel;
    final out = product != null && product.stockOnHand <= 0;

    return AppPage(
      title: product?.title ?? 'Product',
      subtitle: product?.sku,
      actions: [
        if (product != null)
          IconButton(
            key: const Key('product_edit'),
            tooltip: 'Edit',
            onPressed: () async {
              await context.push('/products/${product.id}/edit');
              _load();
            },
            icon: const AppIcon(AppIcons.edit, size: 22),
          ),
      ],
      body: _loading
          ? const LoadingBody()
          : _error != null
              ? ErrorState(message: _error!, onRetry: _load)
              : product == null
                  ? const EmptyState(title: 'Product not found')
                  : RefreshIndicator(
                      color: AppColors.ink,
                      backgroundColor: AppColors.lime,
                      onRefresh: _load,
                      child: ListView(
                        padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 24),
                        children: [
                          Container(
                            width: double.infinity,
                            padding: const EdgeInsets.all(16),
                            decoration: BoxDecoration(
                              color: AppColors.ink,
                              borderRadius: BorderRadius.circular(_r),
                              border: Border.all(color: AppColors.ink),
                            ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
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
                                    product.isActive ? 'Active' : 'Inactive',
                                    style: const TextStyle(
                                      fontSize: 11,
                                      fontWeight: FontWeight.w900,
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 12),
                                Text(
                                  product.title,
                                  style: const TextStyle(
                                    fontSize: 20,
                                    fontWeight: FontWeight.w900,
                                    color: Colors.white,
                                    height: 1.2,
                                  ),
                                ),
                                const SizedBox(height: 6),
                                Text(
                                  product.sku,
                                  style: TextStyle(
                                    fontSize: 13,
                                    fontWeight: FontWeight.w700,
                                    color: Colors.white.withValues(alpha: 0.65),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 12),
                          Row(
                            children: [
                              Expanded(
                                child: _MetricTile(
                                  label: 'On hand',
                                  value: '${product.stockOnHand}',
                                  note: out
                                      ? 'Out of stock'
                                      : low
                                          ? 'Below reorder (${product.reorderLevel})'
                                          : 'Reorder at ${product.reorderLevel}',
                                  warn: out || low,
                                  icon: AppIcons.stock,
                                ),
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                child: _MetricTile(
                                  label: 'Selling',
                                  value:
                                      Money.formatPrice(product.sellingPrice),
                                  note:
                                      'Cost ${Money.formatPrice(product.costPrice)}',
                                  icon: AppIcons.money,
                                  accent: true,
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 20),
                          const _SectionLabel('Details'),
                          const SizedBox(height: 10),
                          Container(
                            decoration: BoxDecoration(
                              border: Border.all(color: AppColors.line),
                              borderRadius: BorderRadius.circular(_r),
                            ),
                            child: Column(
                              children: [
                                _DetailRow(label: 'SKU', value: product.sku),
                                if (product.isbn != null)
                                  _DetailRow(
                                    label: 'ISBN',
                                    value: product.isbn!,
                                  ),
                                if (product.barcode != null)
                                  _DetailRow(
                                    label: 'Barcode',
                                    value: product.barcode!,
                                  ),
                                if (product.level != null)
                                  _DetailRow(
                                    label: 'Level',
                                    value: product.level!.name,
                                  ),
                                if (product.subject != null)
                                  _DetailRow(
                                    label: 'Subject',
                                    value: product.subject!.name,
                                  ),
                                if (product.language != null)
                                  _DetailRow(
                                    label: 'Language',
                                    value: product.language!.name,
                                  ),
                                if (product.variantLabel != null)
                                  _DetailRow(
                                    label: 'Variant',
                                    value: product.variantLabel!,
                                  ),
                                if (product.edition != null)
                                  _DetailRow(
                                    label: 'Edition',
                                    value: product.edition!,
                                  ),
                                _DetailRow(
                                  label: 'Status',
                                  value:
                                      product.isActive ? 'Active' : 'Inactive',
                                  mute: !product.isActive,
                                  last: true,
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 20),
                          const _SectionLabel('Actions'),
                          const SizedBox(height: 10),
                          _ActionCard(
                            icon: AppIcons.stock,
                            title: 'Adjust stock',
                            subtitle: 'Correction or damage',
                            onTap: () async {
                              await context.push(
                                '/stock/adjust?product_id=${product.id}',
                              );
                              _load();
                            },
                          ),
                          const SizedBox(height: 8),
                          _ActionCard(
                            icon: AppIcons.receive,
                            title: 'Receive stock',
                            subtitle: 'Add incoming copies',
                            lime: true,
                            onTap: () async {
                              await context.push(
                                '/stock/receive?product_id=${product.id}',
                              );
                              _load();
                            },
                          ),
                          const SizedBox(height: 8),
                          _ActionCard(
                            icon: AppIcons.edit,
                            title: 'Edit product',
                            subtitle: 'Title, prices, codes',
                            onTap: () async {
                              await context.push(
                                '/products/${product.id}/edit',
                              );
                              _load();
                            },
                          ),
                          const SizedBox(height: 20),
                          const _SectionLabel('Stock movements'),
                          const SizedBox(height: 10),
                          if (_movements.isEmpty)
                            Container(
                              width: double.infinity,
                              padding: const EdgeInsets.all(16),
                              decoration: BoxDecoration(
                                border: Border.all(color: AppColors.line),
                                borderRadius: BorderRadius.circular(_r),
                              ),
                              child: const Text(
                                'No movements yet. Receive or adjust stock to start the ledger.',
                                style: TextStyle(
                                  fontSize: 13,
                                  height: 1.45,
                                  fontWeight: FontWeight.w600,
                                  color: AppColors.mute,
                                ),
                              ),
                            )
                          else
                            for (final m in _movements) ...[
                              _MovementCard(movement: m),
                              const SizedBox(height: 8),
                            ],
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

class _MetricTile extends StatelessWidget {
  const _MetricTile({
    required this.label,
    required this.value,
    required this.note,
    required this.icon,
    this.warn = false,
    this.accent = false,
  });

  final String label;
  final String value;
  final String note;
  final IconData icon;
  final bool warn;
  final bool accent;

  @override
  Widget build(BuildContext context) {
    final borderColor = warn
        ? AppColors.warn
        : accent
            ? AppColors.lime
            : AppColors.line;
    final bg = warn
        ? AppColors.warn.withValues(alpha: 0.08)
        : accent
            ? AppColors.limeSoft
            : AppColors.surface;

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: bg,
        border: Border.all(color: borderColor, width: accent || warn ? 1.5 : 1),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 32,
                height: 32,
                decoration: BoxDecoration(
                  color: warn ? AppColors.surface : AppColors.surface,
                  border: Border.all(color: borderColor),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Center(
                  child: AppIcon(
                    icon,
                    size: 16,
                    color: warn ? AppColors.warn : AppColors.ink,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Text(
                label,
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: warn ? AppColors.warn : AppColors.mute,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            value,
            style: TextStyle(
              fontSize: 22,
              fontWeight: FontWeight.w900,
              letterSpacing: -0.4,
              color: warn ? AppColors.warn : AppColors.ink,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
          const SizedBox(height: 4),
          Text(
            note,
            style: TextStyle(
              fontSize: 11.5,
              fontWeight: FontWeight.w600,
              color: warn ? AppColors.warn : AppColors.mute,
            ),
          ),
        ],
      ),
    );
  }
}

class _DetailRow extends StatelessWidget {
  const _DetailRow({
    required this.label,
    required this.value,
    this.mute = false,
    this.last = false,
  });

  final String label;
  final String value;
  final bool mute;
  final bool last;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        border: last
            ? null
            : const Border(bottom: BorderSide(color: AppColors.line)),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: const TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w700,
                color: AppColors.mute,
              ),
            ),
          ),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.end,
              style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w800,
                color: mute ? AppColors.mute : AppColors.ink,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ActionCard extends StatelessWidget {
  const _ActionCard({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.lime = false,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final bool lime;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: lime ? AppColors.limeSoft : AppColors.surface,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            border: Border.all(
              color: lime ? AppColors.lime : AppColors.line,
              width: lime ? 1.5 : 1,
            ),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: lime ? AppColors.lime : AppColors.wash,
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(
                    color: lime ? AppColors.ink.withValues(alpha: 0.08) : AppColors.line,
                  ),
                ),
                child: Center(child: AppIcon(icon, size: 18)),
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
  }
}

class _MovementCard extends StatelessWidget {
  const _MovementCard({required this.movement});

  final StockMovement movement;

  @override
  Widget build(BuildContext context) {
    final qty = movement.quantity;
    final signed = qty > 0 ? '+$qty' : '$qty';
    final inStock = qty > 0;
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: BoxDecoration(
        color: AppColors.surface,
        border: Border.all(color: AppColors.line),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          Container(
            width: 4,
            height: 44,
            decoration: BoxDecoration(
              color: inStock ? AppColors.lime : AppColors.danger,
              borderRadius: BorderRadius.circular(4),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  movement.type.replaceAll('_', ' '),
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  'Balance ${movement.balanceAfter}'
                  '${movement.unitCost != null ? ' · ${Money.formatPrice(movement.unitCost!)}' : ''}',
                  style: const TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: AppColors.mute,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  DateFormat.yMMMd()
                      .add_jm()
                      .format(movement.occurredAt.toLocal()),
                  style: const TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                    color: AppColors.mute,
                  ),
                ),
              ],
            ),
          ),
          Text(
            signed,
            style: TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.w900,
              color: qty < 0 ? AppColors.danger : AppColors.ink,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }
}
