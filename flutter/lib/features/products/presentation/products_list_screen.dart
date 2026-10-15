import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/products/presentation/products_list_provider.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';
import 'package:schoolbook/shared/widgets/list_row_actions.dart';
import 'package:schoolbook/shared/widgets/option_picker.dart';

class ProductsListScreen extends StatefulWidget {
  const ProductsListScreen({super.key});

  @override
  State<ProductsListScreen> createState() => _ProductsListScreenState();
}

class _ProductsListScreenState extends State<ProductsListScreen> {
  final _searchController = TextEditingController();
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<ProductsListProvider>().initialize();
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String value) {
    _debounce?.cancel();
    setState(() {});
    _debounce = Timer(const Duration(milliseconds: 350), () {
      final provider = context.read<ProductsListProvider>();
      provider.setSearch(value);
      provider.refresh();
    });
  }

  @override
  Widget build(BuildContext context) {
    final provider = context.watch<ProductsListProvider>();
    final top = MediaQuery.paddingOf(context).top;
    final bottom = MediaQuery.paddingOf(context).bottom;
    final hasFilters = provider.levelId != null ||
        provider.subjectId != null ||
        provider.languageId != null;
    final countLabel = provider.isLoading && provider.products.isEmpty
        ? 'Loading catalog…'
        : '${provider.products.length}${provider.canLoadMore ? '+' : ''} in catalog';

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: EdgeInsets.fromLTRB(20, top + 12, 16, 0),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Products',
                        style: TextStyle(
                          fontSize: 28,
                          fontWeight: FontWeight.w900,
                          letterSpacing: -0.6,
                          height: 1,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        countLabel,
                        style: const TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w600,
                          color: AppColors.mute,
                        ),
                      ),
                    ],
                  ),
                ),
                _RoundAction(
                  key: const Key('products_scan'),
                  icon: AppIcons.scan,
                  onTap: () => context.push('/products/scan'),
                ),
                const SizedBox(width: 8),
                _RoundAction(
                  key: const Key('products_from_list'),
                  icon: AppIcons.approvedList,
                  onTap: () => context.push('/products/approved'),
                ),
                const SizedBox(width: 8),
                Material(
                  color: AppColors.lime,
                  shape: const CircleBorder(),
                  child: InkWell(
                    key: const Key('products_new'),
                    customBorder: const CircleBorder(),
                    onTap: () => context.push('/products/new'),
                    child: const SizedBox(
                      width: 48,
                      height: 48,
                      child: Center(
                        child: AppIcon(
                          AppIcons.add,
                          size: 24,
                          color: AppColors.ink,
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: TextField(
              key: const Key('products_search'),
              controller: _searchController,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                hintText: 'Search title, SKU, ISBN…',
                prefixIcon: const Padding(
                  padding: EdgeInsets.only(left: 14, right: 8),
                  child: AppIcon(AppIcons.search, size: 20),
                ),
                prefixIconConstraints:
                    const BoxConstraints(minWidth: 44, minHeight: 44),
                suffixIcon: _searchController.text.isEmpty
                    ? null
                    : IconButton(
                        icon: const AppIcon(AppIcons.close, size: 18),
                        onPressed: () {
                          _debounce?.cancel();
                          _searchController.clear();
                          provider.setSearch('');
                          provider.refresh();
                          setState(() {});
                        },
                      ),
              ),
              onChanged: _onSearchChanged,
              onSubmitted: (value) {
                _debounce?.cancel();
                provider.setSearch(value);
                provider.refresh();
              },
            ),
          ),
          const SizedBox(height: 12),
          SizedBox(
            height: 40,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 20),
              children: [
                _FilterPill(
                  label: 'Level',
                  value: provider.levelId,
                  options:
                      provider.levels.map((l) => (l.id, l.name)).toList(),
                  onChanged: provider.setLevelFilter,
                ),
                const SizedBox(width: 8),
                _FilterPill(
                  label: 'Subject',
                  value: provider.subjectId,
                  options:
                      provider.subjects.map((s) => (s.id, s.name)).toList(),
                  onChanged: provider.setSubjectFilter,
                ),
                const SizedBox(width: 8),
                _FilterPill(
                  label: 'Language',
                  value: provider.languageId,
                  options:
                      provider.languages.map((l) => (l.id, l.name)).toList(),
                  onChanged: provider.setLanguageFilter,
                ),
                if (hasFilters) ...[
                  const SizedBox(width: 8),
                  Material(
                    color: AppColors.wash,
                    shape: const StadiumBorder(),
                    child: InkWell(
                      customBorder: const StadiumBorder(),
                      onTap: () {
                        provider.setLevelFilter(null);
                        provider.setSubjectFilter(null);
                        provider.setLanguageFilter(null);
                      },
                      child: const Padding(
                        padding: EdgeInsets.symmetric(
                          horizontal: 14,
                          vertical: 10,
                        ),
                        child: Text(
                          'Clear',
                          style: TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 8),
          Expanded(child: _buildBody(provider, bottom)),
        ],
      ),
    );
  }

  Widget _buildBody(ProductsListProvider provider, double bottom) {
    if (provider.isLoading && provider.products.isEmpty) {
      return const LoadingBody(message: 'Loading products…');
    }
    if (provider.errorMessage != null && provider.products.isEmpty) {
      return ErrorState(
        message: provider.errorMessage!,
        onRetry: provider.refresh,
      );
    }
    if (provider.products.isEmpty) {
      return EmptyState(
        title: 'No products found',
        subtitle:
            'Try another search, clear filters, or add from the approved list.',
        actionLabel: 'Add from approved list',
        onAction: () => context.push('/products/approved'),
      );
    }

    return RefreshIndicator(
      color: AppColors.ink,
      backgroundColor: AppColors.lime,
      onRefresh: provider.refresh,
      child: ListView.separated(
        padding: EdgeInsets.fromLTRB(20, 8, 20, bottom + 12),
        itemCount: provider.products.length + (provider.canLoadMore ? 1 : 0),
        separatorBuilder: (_, _) => const SizedBox(height: 10),
        itemBuilder: (context, index) {
          if (index >= provider.products.length) {
            if (!provider.isLoadingMore) {
              WidgetsBinding.instance.addPostFrameCallback((_) {
                if (context.mounted) provider.loadMore();
              });
            }
            return const Padding(
              padding: EdgeInsets.all(20),
              child: Center(
                child: SizedBox(
                  width: 22,
                  height: 22,
                  child: CircularProgressIndicator(strokeWidth: 2.4),
                ),
              ),
            );
          }
          final product = provider.products[index];
          return _ProductCard(
            key: Key('product_${product.id}'),
            product: product,
            onChanged: provider.refresh,
          );
        },
      ),
    );
  }
}

class _RoundAction extends StatelessWidget {
  const _RoundAction({
    super.key,
    required this.icon,
    required this.onTap,
  });

  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.wash,
      shape: const CircleBorder(),
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: SizedBox(
          width: 44,
          height: 44,
          child: Center(child: AppIcon(icon, size: 20, color: AppColors.ink)),
        ),
      ),
    );
  }
}

class _FilterPill extends StatelessWidget {
  const _FilterPill({
    required this.label,
    required this.value,
    required this.options,
    required this.onChanged,
  });

  final String label;
  final int? value;
  final List<(int, String)> options;
  final ValueChanged<int?> onChanged;

  String get _display {
    if (value == null) return label;
    for (final o in options) {
      if (o.$1 == value) return o.$2;
    }
    return label;
  }

  @override
  Widget build(BuildContext context) {
    final selected = value != null;
    return Material(
      color: selected ? AppColors.ink : AppColors.wash,
      shape: const StadiumBorder(),
      child: InkWell(
        customBorder: const StadiumBorder(),
        onTap: () async {
          final picked = await showOptionPickerSheet<int>(
            context: context,
            label: label,
            value: value,
            options: options,
            clearLabel: 'All $label',
            unchanged: value,
          );
          if (picked != value) onChanged(picked);
        },
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                _display,
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w800,
                  color: selected ? AppColors.lime : AppColors.ink,
                ),
              ),
              const SizedBox(width: 4),
              AppIcon(
                AppIcons.chevronRight,
                size: 14,
                color: selected ? AppColors.lime : AppColors.mute,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ProductCard extends StatelessWidget {
  const _ProductCard({
    super.key,
    required this.product,
    required this.onChanged,
  });

  final Product product;
  final Future<void> Function() onChanged;

  List<ListRowAction> get _trailing => const [
        ListRowAction(id: 'edit', label: 'Edit', icon: AppIcons.edit),
      ];

  List<ListRowAction> get _leading => const [
        ListRowAction(id: 'adjust', label: 'Adjust', icon: AppIcons.stock),
      ];

  List<ListRowAction> get _menu => const [
        ListRowAction(id: 'open', label: 'Open', icon: AppIcons.visibility),
        ListRowAction(id: 'edit', label: 'Edit', icon: AppIcons.edit),
        ListRowAction(
          id: 'adjust',
          label: 'Adjust stock',
          icon: AppIcons.stock,
        ),
        ListRowAction(
          id: 'receive',
          label: 'Receive stock',
          icon: AppIcons.receive,
        ),
      ];

  Future<void> _handle(BuildContext context, String id) async {
    switch (id) {
      case 'open':
        await context.push('/products/${product.id}');
        await onChanged();
      case 'edit':
        await context.push('/products/${product.id}/edit');
        await onChanged();
      case 'adjust':
        await context.push('/stock/adjust?product_id=${product.id}');
        await onChanged();
      case 'receive':
        await context.push('/stock/receive?product_id=${product.id}');
        await onChanged();
    }
  }

  @override
  Widget build(BuildContext context) {
    return SwipeableRow(
      leading: _leading,
      trailing: _trailing,
      onTap: () async {
        await context.push('/products/${product.id}');
        await onChanged();
      },
      onLongPress: () async {
        final id = await showListActionsSheet(
          context,
          title: product.title,
          actions: _menu,
        );
        if (id != null && context.mounted) await _handle(context, id);
      },
      onAction: (a) => _handle(context, a.id),
      child: _ProductCardBody(product: product),
    );
  }
}

class _ProductCardBody extends StatelessWidget {
  const _ProductCardBody({required this.product});

  final Product product;

  @override
  Widget build(BuildContext context) {
    final low =
        product.reorderLevel > 0 && product.stockOnHand <= product.reorderLevel;
    final out = product.stockOnHand <= 0;
    final meta = [
      product.sku,
      if (product.level != null) product.level!.name,
      if (product.subject != null) product.subject!.name,
    ].join(' · ');
    final stockLabel = out
        ? 'Out of stock'
        : low
            ? '${product.stockOnHand} left · low'
            : '${product.stockOnHand} on hand';

    return Container(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      decoration: BoxDecoration(
        color: AppColors.wash,
        borderRadius: BorderRadius.circular(22),
      ),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: const BoxDecoration(
              color: AppColors.surface,
              shape: BoxShape.circle,
            ),
            child: const Center(
              child: AppIcon(AppIcons.products, size: 20),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  product.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    height: 1.25,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  meta,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: AppColors.mute,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                Money.formatPrice(product.sellingPrice),
                style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w800,
                  fontFeatures: [FontFeature.tabularFigures()],
                ),
              ),
              const SizedBox(height: 4),
              Text(
                stockLabel,
                style: TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w700,
                  color: out || low ? AppColors.warn : AppColors.mute,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
