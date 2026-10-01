import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/features/products/presentation/products_list_provider.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

class ProductsListScreen extends StatefulWidget {
  const ProductsListScreen({super.key});

  @override
  State<ProductsListScreen> createState() => _ProductsListScreenState();
}

class _ProductsListScreenState extends State<ProductsListScreen> {
  final _searchController = TextEditingController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<ProductsListProvider>().initialize();
    });
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final provider = context.watch<ProductsListProvider>();

    return Scaffold(
      appBar: AppBar(
        title: const Text('Products'),
        actions: [
          IconButton(
            tooltip: 'Scan barcode',
            onPressed: () => context.push('/products/scan'),
            icon: const Icon(Icons.qr_code_scanner),
          ),
          IconButton(
            tooltip: 'Add product',
            onPressed: () => context.push('/products/new'),
            icon: const Icon(Icons.add),
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: SearchBar(
              controller: _searchController,
              hintText: 'Search title, SKU, ISBN…',
              leading: const Icon(Icons.search),
              onSubmitted: (value) {
                provider.setSearch(value);
                provider.refresh();
              },
              trailing: [
                if (_searchController.text.isNotEmpty)
                  IconButton(
                    icon: const Icon(Icons.clear),
                    onPressed: () {
                      _searchController.clear();
                      provider.setSearch('');
                      provider.refresh();
                    },
                  ),
              ],
            ),
          ),
          SizedBox(
            height: 48,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              children: [
                _FilterChip(
                  label: 'Level',
                  selectedId: provider.levelId,
                  options: provider.levels
                      .map((l) => (l.id, l.name))
                      .toList(),
                  onSelected: provider.setLevelFilter,
                ),
                const SizedBox(width: 8),
                _FilterChip(
                  label: 'Subject',
                  selectedId: provider.subjectId,
                  options: provider.subjects
                      .map((s) => (s.id, s.name))
                      .toList(),
                  onSelected: provider.setSubjectFilter,
                ),
                const SizedBox(width: 8),
                _FilterChip(
                  label: 'Language',
                  selectedId: provider.languageId,
                  options: provider.languages
                      .map((l) => (l.id, l.name))
                      .toList(),
                  onSelected: provider.setLanguageFilter,
                ),
              ],
            ),
          ),
          Expanded(child: _buildBody(provider)),
        ],
      ),
    );
  }

  Widget _buildBody(ProductsListProvider provider) {
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
      return const EmptyState(
        title: 'No products found',
        subtitle: 'Try another search or add a new product.',
        icon: Icons.inventory_2_outlined,
      );
    }

    return RefreshIndicator(
      onRefresh: provider.refresh,
      child: ListView.separated(
        padding: const EdgeInsets.all(16),
        itemCount: provider.products.length + (provider.canLoadMore ? 1 : 0),
        separatorBuilder: (context, index) => const SizedBox(height: 8),
        itemBuilder: (context, index) {
          if (index >= provider.products.length) {
            if (!provider.isLoadingMore) {
              provider.loadMore();
            }
            return const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            );
          }
          final product = provider.products[index];
          return Card(
            child: ListTile(
              contentPadding: const EdgeInsets.symmetric(
                horizontal: 16,
                vertical: 8,
              ),
              minVerticalPadding: 12,
              title: Text(product.title),
              subtitle: Text(
                '${product.sku} · Stock ${product.stockOnHand} · '
                '${Money.formatPrice(product.sellingPrice)}',
              ),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => context.push('/products/${product.id}'),
            ),
          );
        },
      ),
    );
  }
}

class _FilterChip extends StatelessWidget {
  const _FilterChip({
    required this.label,
    required this.selectedId,
    required this.options,
    required this.onSelected,
  });

  final String label;
  final int? selectedId;
  final List<(int id, String name)> options;
  final ValueChanged<int?> onSelected;

  @override
  Widget build(BuildContext context) {
    String? selectedName;
    if (selectedId != null) {
      for (final option in options) {
        if (option.$1 == selectedId) {
          selectedName = option.$2;
          break;
        }
      }
    }

    return FilterChip(
      label: Text(selectedName ?? label),
      selected: selectedId != null,
      onSelected: (_) async {
        final picked = await showModalBottomSheet<int?>(
          context: context,
          showDragHandle: true,
          builder: (context) {
            return SafeArea(
              child: ListView(
                children: [
                  ListTile(
                    title: Text('All $label'),
                    onTap: () => Navigator.pop(context, null),
                  ),
                  ...options.map(
                    (o) => ListTile(
                      title: Text(o.$2),
                      selected: o.$1 == selectedId,
                      onTap: () => Navigator.pop(context, o.$1),
                    ),
                  ),
                ],
              ),
            );
          },
        );
        if (picked != selectedId) {
          onSelected(picked);
        }
      },
    );
  }
}
