import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/payments/domain/payment.dart';
import 'package:schoolbook/features/payments/presentation/payment_widgets.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

class PaymentsListController extends ChangeNotifier {
  PaymentsListController(this._repository);

  final PaymentsRepository _repository;

  List<Payment> payments = [];
  String? status; // null = all, 'valid', 'void'
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
      final page = await _repository.listPayments(status: status);
      payments = page.data;
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
      final page = await _repository.listPayments(
        page: _page + 1,
        status: status,
      );
      payments = [...payments, ...page.data];
      _page = page.meta.currentPage;
      _lastPage = page.meta.lastPage;
    } on ApiException catch (e) {
      error = e.message;
    } finally {
      loadingMore = false;
      notifyListeners();
    }
  }

  Future<void> setStatus(String? value) async {
    status = value;
    await load();
  }
}

class PaymentsListScreen extends StatefulWidget {
  const PaymentsListScreen({super.key});

  @override
  State<PaymentsListScreen> createState() => _PaymentsListScreenState();
}

class _PaymentsListScreenState extends State<PaymentsListScreen> {
  late final PaymentsListController _controller;

  @override
  void initState() {
    super.initState();
    _controller = PaymentsListController(context.read<PaymentsRepository>())
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

  @override
  Widget build(BuildContext context) {
    final c = _controller;
    return Scaffold(
      appBar: AppBar(title: const Text('Payments')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: SegmentedButton<String?>(
              segments: const [
                ButtonSegment(value: null, label: Text('All')),
                ButtonSegment(value: 'valid', label: Text('Valid')),
                ButtonSegment(value: 'void', label: Text('Void')),
              ],
              selected: {c.status},
              onSelectionChanged: (s) => c.setStatus(s.first),
            ),
          ),
          Expanded(child: _body(c)),
        ],
      ),
    );
  }

  Widget _body(PaymentsListController c) {
    if (c.loading && c.payments.isEmpty) {
      return const LoadingBody();
    }
    if (c.error != null && c.payments.isEmpty) {
      return ErrorState(message: c.error!, onRetry: c.load);
    }
    if (c.payments.isEmpty) {
      return const EmptyState(
        title: 'No payments',
        subtitle: 'Record one from a customer.',
      );
    }
    return RefreshIndicator(
      onRefresh: c.load,
      child: ListView.builder(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        itemCount: c.payments.length + (c.hasMore ? 1 : 0),
        itemBuilder: (context, i) {
          if (i == c.payments.length) {
            c.loadMore();
            return const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            );
          }
          final p = c.payments[i];
          return PaymentTile(
            payment: p,
            showCustomer: true,
            onTap: () async {
              await context.push('/payments/${p.id}');
              c.load();
            },
          );
        },
      ),
    );
  }
}
