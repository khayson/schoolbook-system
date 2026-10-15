import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';
import 'package:schoolbook/shared/widgets/list_row_actions.dart';

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
    if (mounted) setState(() {});
  }

  void _onSearch(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () {
      _controller.search = value;
      _controller.load();
    });
  }

  Future<void> _openDirectory() async {
    await context.push('/customers/directory');
    _controller.load();
  }

  Future<void> _openNew() async {
    await context.push('/customers/new');
    _controller.load();
  }

  @override
  Widget build(BuildContext context) {
    final c = _controller;
    final top = MediaQuery.paddingOf(context).top;
    final bottom = MediaQuery.paddingOf(context).bottom;

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: EdgeInsets.fromLTRB(20, top + 12, 16, 0),
            child: Row(
              children: [
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Schools',
                        style: TextStyle(
                          fontSize: 28,
                          fontWeight: FontWeight.w900,
                          letterSpacing: -0.6,
                          height: 1,
                        ),
                      ),
                      SizedBox(height: 6),
                      Text(
                        'Customers and balances',
                        style: TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w600,
                          color: AppColors.mute,
                        ),
                      ),
                    ],
                  ),
                ),
                Material(
                  color: AppColors.wash,
                  borderRadius: BorderRadius.circular(12),
                  child: InkWell(
                    key: const Key('customers_directory'),
                    borderRadius: BorderRadius.circular(12),
                    onTap: _openDirectory,
                    child: Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(
                        border: Border.all(color: AppColors.line),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Center(
                        child: AppIcon(AppIcons.search, size: 20),
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Material(
                  color: AppColors.lime,
                  borderRadius: BorderRadius.circular(12),
                  child: InkWell(
                    key: const Key('customers_new'),
                    borderRadius: BorderRadius.circular(12),
                    onTap: _openNew,
                    child: Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(
                        border: Border.all(
                          color: AppColors.ink.withValues(alpha: 0.08),
                        ),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Center(
                        child: AppIcon(
                          AppIcons.add,
                          size: 22,
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
              key: const Key('customers_search'),
              controller: _search,
              decoration: InputDecoration(
                hintText: 'Search name, code, phone…',
                prefixIcon: const Padding(
                  padding: EdgeInsets.only(left: 14, right: 8),
                  child: AppIcon(AppIcons.search, size: 20),
                ),
                prefixIconConstraints:
                    const BoxConstraints(minWidth: 44, minHeight: 44),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: const BorderSide(color: AppColors.line),
                ),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide:
                      const BorderSide(color: AppColors.ink, width: 1.5),
                ),
              ),
              onChanged: _onSearch,
            ),
          ),
          const SizedBox(height: 8),
          Expanded(child: _body(c, bottom)),
        ],
      ),
    );
  }

  Widget _body(CustomersListController c, double bottom) {
    if (c.loading && c.customers.isEmpty) {
      return const LoadingBody(message: 'Loading schools…');
    }
    if (c.error != null && c.customers.isEmpty) {
      return ErrorState(message: c.error!, onRetry: c.load);
    }
    if (c.customers.isEmpty) {
      return EmptyState(
        title: 'No schools yet',
        subtitle: 'Add from the directory, or create one with +.',
        actionLabel: 'Add from directory',
        onAction: _openDirectory,
      );
    }
    return RefreshIndicator(
      color: AppColors.ink,
      backgroundColor: AppColors.lime,
      onRefresh: c.load,
      child: ListView.separated(
        padding: EdgeInsets.fromLTRB(20, 8, 20, bottom + 12),
        itemCount: c.customers.length + (c.hasMore ? 1 : 0),
        separatorBuilder: (_, _) => const SizedBox(height: 10),
        itemBuilder: (context, i) {
          if (i == c.customers.length) {
            WidgetsBinding.instance.addPostFrameCallback((_) {
              if (context.mounted) c.loadMore();
            });
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
          final customer = c.customers[i];
          return _CustomerRow(
            key: Key('customer_${customer.id}'),
            customer: customer,
            onChanged: c.load,
          );
        },
      ),
    );
  }
}

class _CustomerRow extends StatelessWidget {
  const _CustomerRow({
    super.key,
    required this.customer,
    required this.onChanged,
  });

  final Customer customer;
  final Future<void> Function() onChanged;

  List<ListRowAction> get _leading => const [
        ListRowAction(id: 'sale', label: 'Sale', icon: AppIcons.sales),
      ];

  List<ListRowAction> get _trailing => const [
        ListRowAction(id: 'pay', label: 'Pay', icon: AppIcons.payment),
      ];

  List<ListRowAction> get _menu => [
        const ListRowAction(
          id: 'open',
          label: 'Open',
          icon: AppIcons.visibility,
        ),
        const ListRowAction(
          id: 'sale',
          label: 'New sale',
          icon: AppIcons.sales,
        ),
        const ListRowAction(
          id: 'pay',
          label: 'Record payment',
          icon: AppIcons.payment,
        ),
        const ListRowAction(id: 'edit', label: 'Edit', icon: AppIcons.edit),
      ];

  Future<void> _handle(BuildContext context, String id) async {
    switch (id) {
      case 'open':
        await context.push('/customers/${customer.id}');
        await onChanged();
      case 'sale':
        await context.push('/sales/new?customer_id=${customer.id}');
        await onChanged();
      case 'pay':
        await context.push('/customers/${customer.id}/pay');
        await onChanged();
      case 'edit':
        await context.push('/customers/${customer.id}/edit');
        await onChanged();
    }
  }

  @override
  Widget build(BuildContext context) {
    return SwipeableRow(
      leading: _leading,
      trailing: _trailing,
      onTap: () async {
        await context.push('/customers/${customer.id}');
        await onChanged();
      },
      onLongPress: () async {
        final id = await showListActionsSheet(
          context,
          title: customer.name,
          actions: _menu,
        );
        if (id != null && context.mounted) await _handle(context, id);
      },
      onAction: (a) => _handle(context, a.id),
      child: _CustomerCardBody(customer: customer),
    );
  }
}

class _CustomerCardBody extends StatelessWidget {
  const _CustomerCardBody({required this.customer});

  final Customer customer;

  @override
  Widget build(BuildContext context) {
    final owes = customer.outstandingBalance;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 14, 14),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: AppColors.limeSoft,
              border: Border.all(color: AppColors.lime),
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Center(child: AppIcon(AppIcons.schools, size: 18)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  customer.name,
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    color: customer.isActive ? AppColors.ink : AppColors.mute,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  '${customer.code} · ${customer.region}'
                  '${customer.isActive ? '' : ' · inactive'}'
                  '${customer.creditBalance > 0 ? ' · credit ${Money.formatPesewas(customer.creditBalance)}' : ''}',
                  style: const TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: AppColors.mute,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                Money.formatPesewas(owes),
                style: TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w900,
                  color: owes > 0 ? AppColors.warn : AppColors.ink,
                  fontFeatures: const [FontFeature.tabularFigures()],
                ),
              ),
              const SizedBox(height: 2),
              const Text(
                'owed',
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: AppColors.mute,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
