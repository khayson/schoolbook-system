import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/domain/sale.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

class SalesListController extends ChangeNotifier {
  SalesListController(this._repository);

  final SalesRepository _repository;

  List<Sale> sales = [];
  String? status;
  String? paymentStatus;
  bool loading = false;
  bool loadingMore = false;
  String? error;
  int _page = 1;
  int _lastPage = 1;

  bool get hasMore => _page < _lastPage;

  Future<void> load() async {
    loading = true;
    error = null;
    notifyListeners();
    try {
      final page = await _repository.listSales(
        status: status,
        paymentStatus: paymentStatus,
      );
      sales = page.data;
      _page = page.meta.currentPage;
      _lastPage = page.meta.lastPage;
    } on ApiException catch (e) {
      error = e.message;
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  Future<void> loadMore() async {
    if (!hasMore || loadingMore) {
      return;
    }
    loadingMore = true;
    notifyListeners();
    try {
      final page = await _repository.listSales(
        page: _page + 1,
        status: status,
        paymentStatus: paymentStatus,
      );
      sales = [...sales, ...page.data];
      _page = page.meta.currentPage;
      _lastPage = page.meta.lastPage;
    } on ApiException catch (e) {
      error = e.message;
    } finally {
      loadingMore = false;
      notifyListeners();
    }
  }

  Future<void> setFilters({String? status, String? paymentStatus}) async {
    this.status = status;
    this.paymentStatus = paymentStatus;
    await load();
  }
}

class SalesListScreen extends StatefulWidget {
  const SalesListScreen({super.key});

  @override
  State<SalesListScreen> createState() => _SalesListScreenState();
}

class _SalesListScreenState extends State<SalesListScreen> {
  late final SalesListController _controller;

  @override
  void initState() {
    super.initState();
    _controller = SalesListController(context.read<SalesRepository>())
      ..addListener(_onChanged)
      ..load();
  }

  @override
  void dispose() {
    _controller
      ..removeListener(_onChanged)
      ..dispose();
    super.dispose();
  }

  void _onChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  Widget _chip(String label, bool selected, VoidCallback onTap) => Padding(
    padding: const EdgeInsets.only(right: 6),
    child: ChoiceChip(
      label: Text(label),
      selected: selected,
      onSelected: (_) => onTap(),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final c = _controller;
    return Scaffold(
      appBar: AppBar(title: const Text('Sales')),
      floatingActionButton: FloatingActionButton.extended(
        key: const Key('sales_new'),
        onPressed: () async {
          await context.push('/sales/new');
          c.load();
        },
        icon: const Icon(Icons.add),
        label: const Text('New sale'),
      ),
      body: Column(
        children: [
          SizedBox(
            height: 48,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              children: [
                _chip(
                  'All',
                  c.status == null && c.paymentStatus == null,
                  () => c.setFilters(),
                ),
                _chip(
                  'Drafts',
                  c.status == 'draft',
                  () => c.setFilters(status: 'draft'),
                ),
                _chip(
                  'Unpaid',
                  c.paymentStatus == 'unpaid',
                  () => c.setFilters(
                    status: 'confirmed',
                    paymentStatus: 'unpaid',
                  ),
                ),
                _chip(
                  'Part paid',
                  c.paymentStatus == 'partial',
                  () => c.setFilters(
                    status: 'confirmed',
                    paymentStatus: 'partial',
                  ),
                ),
                _chip(
                  'Paid',
                  c.paymentStatus == 'paid',
                  () =>
                      c.setFilters(status: 'confirmed', paymentStatus: 'paid'),
                ),
                _chip(
                  'Void',
                  c.status == 'void',
                  () => c.setFilters(status: 'void'),
                ),
                _chip(
                  'Cancelled',
                  c.status == 'cancelled',
                  () => c.setFilters(status: 'cancelled'),
                ),
              ],
            ),
          ),
          Expanded(child: _body(c)),
        ],
      ),
    );
  }

  Widget _body(SalesListController c) {
    if (c.loading && c.sales.isEmpty) {
      return const LoadingBody();
    }
    if (c.error != null && c.sales.isEmpty) {
      return ErrorState(message: c.error!, onRetry: c.load);
    }
    if (c.sales.isEmpty) {
      return const EmptyState(
        title: 'No sales',
        subtitle: 'Start one with New sale.',
      );
    }
    return RefreshIndicator(
      onRefresh: c.load,
      child: ListView.builder(
        itemCount: c.sales.length + (c.hasMore ? 1 : 0),
        itemBuilder: (context, i) {
          if (i == c.sales.length) {
            c.loadMore();
            return const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            );
          }
          final s = c.sales[i];
          final status = s.isConfirmed
              ? SaleStatuses.paymentLabel(s.paymentStatus)
              : SaleStatuses.label(s.status);
          return ListTile(
            key: Key('sale_${s.id}'),
            title: Text('${s.label}  ${s.customer?.name ?? ''}'),
            subtitle: Text(
              '$status | ${DateFormat('d MMM y').format(s.saleDate)}',
            ),
            trailing: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(Money.formatPesewas(s.total)),
                if (s.isConfirmed && s.balanceDue > 0)
                  Text(
                    'due ${Money.formatPesewas(s.balanceDue)}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
              ],
            ),
            onTap: () async {
              await context.push('/sales/${s.id}');
              c.load();
            },
          );
        },
      ),
    );
  }
}
