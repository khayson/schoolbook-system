import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/pdf_sharer.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/payments/domain/payment.dart';
import 'package:schoolbook/features/payments/presentation/payment_widgets.dart';
import 'package:schoolbook/features/sales/domain/sale_summary.dart';
import 'package:schoolbook/shared/widgets/app_drawer.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

class CustomerDetailScreen extends StatefulWidget {
  const CustomerDetailScreen({super.key, required this.customerId});

  final int customerId;

  @override
  State<CustomerDetailScreen> createState() => _CustomerDetailScreenState();
}

class _CustomerDetailScreenState extends State<CustomerDetailScreen> {
  Customer? _customer;
  List<SaleSummary> _sales = const [];
  List<Payment> _payments = const [];
  String? _error;
  bool _applyingCredit = false;
  bool _sharingStatement = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final customers = context.read<CustomersRepository>();
      final payments = context.read<PaymentsRepository>();
      final customerFuture = customers.getCustomer(widget.customerId);
      final salesFuture = customers.recentSales(widget.customerId, perPage: 5);
      final paymentsFuture = payments.listPayments(
        customerId: widget.customerId,
        perPage: 5,
      );
      final customer = await customerFuture;
      final sales = await salesFuture;
      final recentPayments = await paymentsFuture;
      if (!mounted) return;
      setState(() {
        _customer = customer;
        _sales = sales;
        _payments = recentPayments.data;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _applyCredit(Customer customer) async {
    final confirmed = await showAppDrawer<bool>(
      context: context,
      builder: (context) => AppDrawerScaffold(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Apply credit?',
              style: TextStyle(fontSize: 22, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 8),
            Text(
              'Apply ${Money.formatPesewas(customer.creditBalance)} of prepaid credit to the oldest unpaid invoices first.',
              style: const TextStyle(
                fontWeight: FontWeight.w600,
                color: AppColors.mute,
                height: 1.4,
              ),
            ),
            const SizedBox(height: 20),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    style: OutlinedButton.styleFrom(
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                    onPressed: () => Navigator.pop(context, false),
                    child: const Text(
                      'Cancel',
                      style: TextStyle(fontWeight: FontWeight.w800),
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: FilledButton(
                    key: const Key('apply_credit_confirm'),
                    style: FilledButton.styleFrom(
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                    onPressed: () => Navigator.pop(context, true),
                    child: const Text('Apply credit'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
    if (confirmed != true || !mounted) return;

    setState(() => _applyingCredit = true);
    final store = context.read<PendingSubmissionStore>();
    final repository = context.read<PaymentsRepository>();
    final intent = 'apply_credit.customer.${customer.id}';
    try {
      final key = await store.keyFor(intent, {'customer_id': customer.id});
      final result = await repository.applyCredit(
        customerId: customer.id,
        idempotencyKey: key,
      );
      await store.complete(intent);
      if (!mounted) return;
      AppToast.show(
        context,
        result.appliedTotal == 0
            ? 'No open invoices — credit unchanged.'
            : 'Applied ${Money.formatPesewas(result.appliedTotal)}. Credit left ${Money.formatPesewas(result.creditBalance)}.',
        type: ToastType.success,
      );
      await _load();
    } on ApiException catch (e) {
      if (!e.isOutcomeUnknown) await store.complete(intent);
      if (!mounted) return;
      final d = describeApiError(e);
      AppToast.show(context, d.body, title: d.title, type: ToastType.error);
    } finally {
      if (mounted) setState(() => _applyingCredit = false);
    }
  }

  Future<void> _shareStatement(Customer customer) async {
    final now = DateTime.now();
    final range = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2020),
      lastDate: DateTime(now.year, now.month, now.day),
      initialDateRange: DateTimeRange(
        start: DateTime(now.year, now.month),
        end: DateTime(now.year, now.month, now.day),
      ),
      helpText: 'Statement period',
    );
    if (range == null || !mounted) return;
    await shareStatement(customer, range.start, range.end);
  }

  Future<void> shareStatement(
    Customer customer,
    DateTime from,
    DateTime to,
  ) async {
    final repository = context.read<CustomersRepository>();
    final sharer = context.read<PdfSharer>();
    final f = DateFormat('yyyy-MM-dd');
    setState(() => _sharingStatement = true);
    try {
      final bytes = await repository.statementPdf(
        customer.id,
        from: f.format(from),
        to: f.format(to),
      );
      await sharer.sharePdf(
        bytes,
        fileName:
            'statement-${customer.code}-${f.format(from)}-${f.format(to)}.pdf',
        subject: 'Statement for ${customer.name}',
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      final d = describeApiError(e);
      AppToast.show(context, d.body, title: d.title, type: ToastType.error);
    } finally {
      if (mounted) setState(() => _sharingStatement = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final customer = _customer;
    final top = MediaQuery.paddingOf(context).top;
    // Includes MainShell dock clearance so Recent payments can scroll clear.
    final bottom = MediaQuery.paddingOf(context).bottom;

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: _error != null
          ? ErrorState(message: _error!, onRetry: _load)
          : customer == null
          ? const LoadingBody()
          : RefreshIndicator(
              color: AppColors.ink,
              backgroundColor: AppColors.lime,
              onRefresh: _load,
              child: ListView(
                padding: EdgeInsets.fromLTRB(20, top + 4, 20, bottom + 12),
                children: [
                  Row(
                    children: [
                      IconButton(
                        onPressed: () => Navigator.of(context).maybePop(),
                        icon: const AppIcon(AppIcons.back),
                      ),
                      const Spacer(),
                      IconButton(
                        key: const Key('customer_edit'),
                        tooltip: 'Edit',
                        onPressed: () async {
                          await context.push('/customers/${customer.id}/edit');
                          _load();
                        },
                        icon: const AppIcon(AppIcons.edit),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    customer.name,
                    style: const TextStyle(
                      fontSize: 28,
                      fontWeight: FontWeight.w900,
                      letterSpacing: -0.6,
                      height: 1.1,
                    ),
                  ),
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      _MetaChip(
                        label: customer.code,
                        filled: true,
                      ),
                      _MetaChip(
                        label:
                            CustomerOptions.types[customer.type] ??
                            customer.type,
                      ),
                      _MetaChip(
                        label: customer.district == null
                            ? customer.region
                            : '${customer.region}, ${customer.district}',
                      ),
                      if (!customer.isActive)
                        const _MetaChip(
                          label: 'Inactive',
                          danger: true,
                        ),
                    ],
                  ),
                  if (customer.contactPerson != null ||
                      customer.phone != null) ...[
                    const SizedBox(height: 12),
                    Text(
                      [
                        customer.contactPerson,
                        customer.phone,
                      ].whereType<String>().join(' · '),
                      style: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        color: AppColors.mute,
                      ),
                    ),
                  ],
                  const SizedBox(height: 20),
                  _BalanceHero(customer: customer),
                  const SizedBox(height: 16),
                  if (customer.isActive)
                    FilledButton.icon(
                      key: const Key('customer_new_sale'),
                      style: FilledButton.styleFrom(
                        backgroundColor: AppColors.lime,
                        foregroundColor: AppColors.ink,
                        minimumSize: const Size.fromHeight(48),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                        ),
                      ),
                      onPressed: () => context.push(
                        '/sales/new?customer_id=${customer.id}',
                      ),
                      icon: const AppIcon(
                        AppIcons.add,
                        size: 20,
                        color: AppColors.ink,
                      ),
                      label: const Text('New sale'),
                    ),
                  if (customer.isActive) const SizedBox(height: 10),
                  FilledButton.icon(
                    key: const Key('customer_record_payment'),
                    style: FilledButton.styleFrom(
                      minimumSize: const Size.fromHeight(48),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                    onPressed: () async {
                      await context.push('/customers/${customer.id}/pay');
                      _load();
                    },
                    icon: const AppIcon(
                      AppIcons.payment,
                      size: 20,
                      color: Colors.white,
                    ),
                    label: const Text('Record payment'),
                  ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          key: const Key('customer_statement'),
                          style: OutlinedButton.styleFrom(
                            minimumSize: const Size.fromHeight(44),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12),
                            ),
                          ),
                          onPressed: _sharingStatement
                              ? null
                              : () => _shareStatement(customer),
                          icon: const AppIcon(AppIcons.sales, size: 18),
                          label: const Text('Statement'),
                        ),
                      ),
                      if (customer.creditBalance > 0) ...[
                        const SizedBox(width: 10),
                        Expanded(
                          child: OutlinedButton.icon(
                            key: const Key('customer_apply_credit'),
                            style: OutlinedButton.styleFrom(
                              minimumSize: const Size.fromHeight(44),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(12),
                              ),
                            ),
                            onPressed: _applyingCredit
                                ? null
                                : () => _applyCredit(customer),
                            icon: const AppIcon(AppIcons.wallet, size: 18),
                            label: const Text('Apply credit'),
                          ),
                        ),
                      ],
                    ],
                  ),
                  const SizedBox(height: 28),
                  const _SectionTitle('Recent invoices'),
                  const SizedBox(height: 10),
                  if (_sales.isEmpty)
                    _EmptyBlock(
                      icon: AppIcons.sales,
                      title: 'No sales yet',
                      subtitle: customer.isActive
                          ? 'Start the first invoice for this school.'
                          : 'This school is inactive.',
                      actionLabel: customer.isActive ? 'New sale' : null,
                      onAction: customer.isActive
                          ? () => context.push(
                                '/sales/new?customer_id=${customer.id}',
                              )
                          : null,
                    )
                  else
                    ..._sales.map(
                      (s) => Padding(
                        padding: const EdgeInsets.only(bottom: 8),
                        child: Material(
                          color: AppColors.surface,
                          borderRadius: BorderRadius.circular(12),
                          child: InkWell(
                            onTap: () => context.push('/sales/${s.id}'),
                            borderRadius: BorderRadius.circular(12),
                            child: Container(
                              padding: const EdgeInsets.all(14),
                              decoration: BoxDecoration(
                                border: Border.all(color: AppColors.line),
                                borderRadius: BorderRadius.circular(12),
                              ),
                              child: Row(
                                children: [
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          s.label,
                                          style: const TextStyle(
                                            fontWeight: FontWeight.w800,
                                          ),
                                        ),
                                        const SizedBox(height: 3),
                                        Text(
                                          '${s.status} · ${s.paymentStatus}'
                                          '${s.dueDate == null ? '' : ' · due ${DateFormat('d MMM y').format(s.dueDate!)}'}',
                                          style: const TextStyle(
                                            fontSize: 12.5,
                                            fontWeight: FontWeight.w600,
                                            color: AppColors.mute,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                  Text(
                                    Money.formatPesewas(s.balanceDue),
                                    style: const TextStyle(
                                      fontWeight: FontWeight.w900,
                                      fontFeatures: [
                                        FontFeature.tabularFigures(),
                                      ],
                                    ),
                                  ),
                                  const SizedBox(width: 4),
                                  const AppIcon(
                                    AppIcons.chevronRight,
                                    size: 16,
                                    color: AppColors.mute,
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  const SizedBox(height: 22),
                  const _SectionTitle('Recent payments'),
                  const SizedBox(height: 10),
                  if (_payments.isEmpty)
                    const _EmptyBlock(
                      icon: AppIcons.payment,
                      title: 'No payments yet',
                      subtitle: 'Record a payment when money comes in.',
                    )
                  else
                    ..._payments.map(
                      (p) => Padding(
                        padding: const EdgeInsets.only(bottom: 8),
                        child: Material(
                          color: AppColors.surface,
                          borderRadius: BorderRadius.circular(12),
                          child: Container(
                            decoration: BoxDecoration(
                              border: Border.all(color: AppColors.line),
                              borderRadius: BorderRadius.circular(12),
                            ),
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 2,
                            ),
                            child: PaymentTile(
                              payment: p,
                              onTap: () async {
                                await context.push('/payments/${p.id}');
                                _load();
                              },
                            ),
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

class _BalanceHero extends StatelessWidget {
  const _BalanceHero({required this.customer});

  final Customer customer;

  @override
  Widget build(BuildContext context) {
    final owes = customer.outstandingBalance;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 14),
      decoration: BoxDecoration(
        color: AppColors.ink,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'OWES',
            style: TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w800,
              letterSpacing: 0.9,
              color: Colors.white.withValues(alpha: 0.55),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            Money.formatPesewas(owes),
            style: TextStyle(
              fontSize: 32,
              fontWeight: FontWeight.w900,
              letterSpacing: -0.8,
              color: owes > 0 ? AppColors.lime : Colors.white,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
          const SizedBox(height: 18),
          Row(
            children: [
              Expanded(
                child: _InkMetric(
                  label: 'Credit',
                  value: Money.formatPesewas(customer.creditBalance),
                ),
              ),
              Container(
                width: 1,
                height: 36,
                color: Colors.white.withValues(alpha: 0.12),
              ),
              Expanded(
                child: _InkMetric(
                  label: 'Limit',
                  value: customer.creditLimit == null
                      ? 'None'
                      : Money.formatPesewas(customer.creditLimit!),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _InkMetric extends StatelessWidget {
  const _InkMetric({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w700,
              color: Colors.white.withValues(alpha: 0.5),
            ),
          ),
          const SizedBox(height: 4),
          Text(
            value,
            style: const TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w800,
              color: Colors.white,
              fontFeatures: [FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }
}

class _MetaChip extends StatelessWidget {
  const _MetaChip({
    required this.label,
    this.filled = false,
    this.danger = false,
  });

  final String label;
  final bool filled;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final bg = danger
        ? const Color(0xFFFFE4E6)
        : filled
            ? AppColors.lime
            : AppColors.wash;
    final fg = danger
        ? AppColors.danger
        : filled
            ? AppColors.ink
            : AppColors.mute;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: bg,
        border: Border.all(
          color: danger
              ? AppColors.danger
              : filled
                  ? AppColors.ink.withValues(alpha: 0.08)
                  : AppColors.line,
        ),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w800,
          color: fg,
        ),
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text);

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

class _EmptyBlock extends StatelessWidget {
  const _EmptyBlock({
    required this.icon,
    required this.title,
    required this.subtitle,
    this.actionLabel,
    this.onAction,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 18, 16, 16),
      decoration: BoxDecoration(
        border: Border.all(color: AppColors.line),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: AppColors.wash,
              border: Border.all(color: AppColors.line),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Center(child: AppIcon(icon, size: 20)),
          ),
          const SizedBox(height: 12),
          Text(
            title,
            style: const TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            subtitle,
            textAlign: TextAlign.center,
            style: const TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: AppColors.mute,
            ),
          ),
          if (actionLabel != null && onAction != null) ...[
            const SizedBox(height: 14),
            TextButton(
              onPressed: onAction,
              child: Text(
                actionLabel!,
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
