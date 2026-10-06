import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

class CustomersListController extends ChangeNotifier {
  CustomersListController(this._repository);

  final CustomersRepository _repository;

  List<Customer> customers = [];
  String search = '';
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
      final page = await _repository.listCustomers(search: search);
      customers = page.data;
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
      final page = await _repository.listCustomers(
        page: _page + 1,
        search: search,
      );
      customers = [...customers, ...page.data];
      _page = page.meta.currentPage;
      _lastPage = page.meta.lastPage;
    } on ApiException catch (e) {
      error = e.message;
    } finally {
      loadingMore = false;
      notifyListeners();
    }
  }
}

class CustomersListScreen extends StatefulWidget {
  const CustomersListScreen({super.key});

  @override
  State<CustomersListScreen> createState() => _CustomersListScreenState();
}

class _CustomersListScreenState extends State<CustomersListScreen> {
  late final CustomersListController _controller;
  final _search = TextEditingController();
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _controller = CustomersListController(context.read<CustomersRepository>())
      ..addListener(_onChanged)
      ..load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
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

  void _onSearch(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () {
      _controller.search = value;
      _controller.load();
    });
  }

  @override
  Widget build(BuildContext context) {
    final c = _controller;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Customers'),
        actions: [
          IconButton(
            key: const Key('customers_directory'),
            tooltip: 'Add from school directory',
            icon: const Icon(Icons.travel_explore),
            onPressed: () async {
              await context.push('/customers/directory');
              _controller.load();
            },
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        key: const Key('customers_new'),
        onPressed: () async {
          await context.push('/customers/new');
          c.load();
        },
        icon: const Icon(Icons.add),
        label: const Text('New customer'),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: SearchBar(
              key: const Key('customers_search'),
              controller: _search,
              hintText: 'Search name, code, phone…',
              leading: const Icon(Icons.search),
              onChanged: _onSearch,
            ),
          ),
          Expanded(child: _body(c)),
        ],
      ),
    );
  }

  Widget _body(CustomersListController c) {
    if (c.loading && c.customers.isEmpty) {
      return const LoadingBody();
    }
    if (c.error != null && c.customers.isEmpty) {
      return ErrorState(message: c.error!, onRetry: c.load);
    }
    if (c.customers.isEmpty) {
      return const EmptyState(
        title: 'No customers',
        subtitle: 'Add a school or reseller.',
      );
    }
    return RefreshIndicator(
      onRefresh: c.load,
      child: ListView.builder(
        itemCount: c.customers.length + (c.hasMore ? 1 : 0),
        itemBuilder: (context, i) {
          if (i == c.customers.length) {
            c.loadMore();
            return const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            );
          }
          final customer = c.customers[i];
          return ListTile(
            key: Key('customer_${customer.id}'),
            title: Text(customer.name),
            subtitle: Text(
              '${customer.code} | ${customer.region}${customer.isActive ? '' : ' | inactive'}',
            ),
            trailing: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  'Owes ${Money.formatPesewas(customer.outstandingBalance)}',
                ),
                if (customer.creditBalance > 0)
                  Text(
                    'Credit ${Money.formatPesewas(customer.creditBalance)}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
              ],
            ),
            onTap: () async {
              await context.push('/customers/${customer.id}');
              c.load();
            },
          );
        },
      ),
    );
  }
}
