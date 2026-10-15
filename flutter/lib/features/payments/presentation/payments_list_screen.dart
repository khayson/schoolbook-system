import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/core/validators.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/payments/domain/payment.dart';
import 'package:schoolbook/features/payments/presentation/record_payment_screen.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';
import 'package:schoolbook/shared/widgets/list_row_actions.dart';

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
    if (!hasMore || loadingMore) return;
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

  Future<void> setStatus(String? status) async {
    this.status = status;
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
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final c = _controller;
    return AppPage(
      title: 'Payments',
      subtitle: 'Receipts and voids',
      showBack: true,
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
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
      color: AppColors.ink,
      backgroundColor: AppColors.lime,
      onRefresh: c.load,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 20),
        itemCount: c.payments.length + (c.hasMore ? 1 : 0),
        separatorBuilder: (_, _) => const SizedBox(height: 10),
        itemBuilder: (context, i) {
          if (i == c.payments.length) {
            WidgetsBinding.instance.addPostFrameCallback((_) {
              if (context.mounted) c.loadMore();
            });
            return const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            );
          }
          final p = c.payments[i];
          return _PaymentRow(
            key: Key('payment_${p.id}'),
            payment: p,
            onChanged: c.load,
          );
        },
      ),
    );
  }
}

class _PaymentRow extends StatelessWidget {
  const _PaymentRow({
    super.key,
    required this.payment,
    required this.onChanged,
  });

  final Payment payment;
  final Future<void> Function() onChanged;

  List<ListRowAction> get _leading => payment.isVoid
      ? const []
      : const [
          ListRowAction(id: 'share', label: 'Share', icon: AppIcons.share),
        ];

  List<ListRowAction> get _menu {
    final actions = <ListRowAction>[
      const ListRowAction(
        id: 'open',
        label: 'Open',
        icon: AppIcons.visibility,
      ),
    ];
    if (!payment.isVoid) {
      actions.addAll(const [
        ListRowAction(id: 'share', label: 'Share receipt', icon: AppIcons.share),
        ListRowAction(
          id: 'void',
          label: 'Void payment',
          icon: AppIcons.delete,
          danger: true,
        ),
      ]);
    }
    return actions;
  }

  Future<void> _handle(BuildContext context, String id) async {
    switch (id) {
      case 'open':
        await context.push('/payments/${payment.id}');
        await onChanged();
      case 'share':
        await shareReceipt(context, payment);
      case 'void':
        await _voidPayment(context);
    }
  }

  Future<void> _voidPayment(BuildContext context) async {
    final reason = await showModalBottomSheet<String>(
      context: context,
      backgroundColor: AppColors.surface,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (_) => const _VoidPaymentSheet(),
    );
    if (reason == null || !context.mounted) return;
    try {
      await context.read<PaymentsRepository>().voidPayment(
        payment.id,
        reason,
      );
      if (context.mounted) {
        AppToast.show(
          context,
          'Payment ${payment.receiptNo} voided',
          type: ToastType.success,
        );
      }
      await onChanged();
    } on ApiException catch (e) {
      if (!context.mounted) return;
      final d = describeApiError(e);
      AppToast.show(context, d.body, title: d.title, type: ToastType.error);
      await onChanged();
    }
  }

  @override
  Widget build(BuildContext context) {
    return SwipeableRow(
      leading: _leading,
      onTap: () async {
        await context.push('/payments/${payment.id}');
        await onChanged();
      },
      onLongPress: () async {
        final id = await showListActionsSheet(
          context,
          title: payment.receiptNo,
          actions: _menu,
        );
        if (id != null && context.mounted) await _handle(context, id);
      },
      onAction: (a) => _handle(context, a.id),
      child: _PaymentCardBody(payment: payment),
    );
  }
}

class _PaymentCardBody extends StatelessWidget {
  const _PaymentCardBody({required this.payment});

  final Payment payment;

  @override
  Widget build(BuildContext context) {
    final p = payment;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 14, 14),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  p.receiptNo,
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w900,
                    decoration: p.isVoid ? TextDecoration.lineThrough : null,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  [
                    if (p.customerName != null) p.customerName!,
                    DateFormat('d MMM y, HH:mm').format(p.paidAt),
                    p.methodLabel,
                    if (p.reference != null) p.reference!,
                  ].join(' · '),
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
                Money.formatPesewas(p.amount),
                style: TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w900,
                  decoration: p.isVoid ? TextDecoration.lineThrough : null,
                  fontFeatures: const [FontFeature.tabularFigures()],
                ),
              ),
              if (p.isVoid) ...[
                const SizedBox(height: 2),
                const Text(
                  'Void',
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w800,
                    color: AppColors.danger,
                  ),
                ),
              ] else if (p.unallocatedAmount > 0) ...[
                const SizedBox(height: 2),
                Text(
                  'Credit ${Money.formatPesewas(p.unallocatedAmount)}',
                  style: const TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color: AppColors.mute,
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

class _VoidPaymentSheet extends StatefulWidget {
  const _VoidPaymentSheet();

  @override
  State<_VoidPaymentSheet> createState() => _VoidPaymentSheetState();
}

class _VoidPaymentSheetState extends State<_VoidPaymentSheet> {
  final _formKey = GlobalKey<FormState>();
  final _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    final inset = MediaQuery.viewInsetsOf(context).bottom;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + inset + 16),
      child: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Void payment?',
              style: TextStyle(fontSize: 22, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 8),
            const Text(
              'Invoices it paid will owe that money again. This cannot be undone.',
              style: TextStyle(
                fontWeight: FontWeight.w600,
                color: AppColors.mute,
                height: 1.4,
              ),
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _reason,
              maxLines: 2,
              decoration: const InputDecoration(labelText: 'Reason'),
              validator: (v) => Validators.required(v, label: 'A reason'),
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text('Keep'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: FilledButton(
                    style: FilledButton.styleFrom(
                      backgroundColor: AppColors.danger,
                    ),
                    onPressed: () {
                      if (_formKey.currentState!.validate()) {
                        Navigator.pop(context, _reason.text.trim());
                      }
                    },
                    child: const Text('Void payment'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
