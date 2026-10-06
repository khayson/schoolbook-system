import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/pdf_sharer.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/payments/domain/payment.dart';
import 'package:schoolbook/features/payments/presentation/payment_widgets.dart';
import 'package:schoolbook/features/sales/domain/sale_summary.dart';
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
      // Started together, awaited in turn: the three requests run in parallel.
      final customerFuture = customers.getCustomer(widget.customerId);
      final salesFuture = customers.recentSales(widget.customerId, perPage: 5);
      final paymentsFuture = payments.listPayments(
        customerId: widget.customerId,
        perPage: 5,
      );
      final customer = await customerFuture;
      final sales = await salesFuture;
      final recentPayments = await paymentsFuture;
      if (!mounted) {
        return;
      }
      setState(() {
        _customer = customer;
        _sales = sales;
        _payments = recentPayments.data;
      });
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = e.message);
      }
    }
  }

  /// Oldest invoices first. One idempotency key per "apply credit" intent, kept until
  /// success, so a retry after a lost connection cannot apply the credit twice.
  Future<void> _applyCredit(Customer customer) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Apply credit?'),
        content: Text(
          'Apply ${Money.formatPesewas(customer.creditBalance)} of credit to the oldest unpaid invoices.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const Key('apply_credit_confirm'),
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Apply'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) {
      return;
    }

    setState(() => _applyingCredit = true);
    final messenger = ScaffoldMessenger.of(context);
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
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            result.appliedTotal == 0
                ? 'No open invoices: credit unchanged.'
                : 'Applied ${Money.formatPesewas(result.appliedTotal)}. Credit left ${Money.formatPesewas(result.creditBalance)}.',
          ),
        ),
      );
      await _load();
    } on ApiException catch (e) {
      if (!e.isOutcomeUnknown) {
        await store.complete(intent);
      }
      final d = describeApiError(e);
      messenger.showSnackBar(SnackBar(content: Text('${d.title}: ${d.body}')));
    } finally {
      if (mounted) {
        setState(() => _applyingCredit = false);
      }
    }
  }

  /// Picks a date range (this month by default) and shares the statement PDF.
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
    if (range == null || !mounted) {
      return;
    }
    await shareStatement(customer, range.start, range.end);
  }

  /// Fetches and shares the statement for [from]..[to] (inclusive).
  Future<void> shareStatement(
    Customer customer,
    DateTime from,
    DateTime to,
  ) async {
    final messenger = ScaffoldMessenger.of(context);
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
      final d = describeApiError(e);
      messenger.showSnackBar(SnackBar(content: Text('${d.title}: ${d.body}')));
    } finally {
      if (mounted) {
        setState(() => _sharingStatement = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final customer = _customer;
    return Scaffold(
      appBar: AppBar(
        title: Text(customer?.name ?? 'Customer'),
        actions: [
          if (customer != null)
            IconButton(
              key: const Key('customer_edit'),
              tooltip: 'Edit',
              icon: const Icon(Icons.edit),
              onPressed: () async {
                await context.push('/customers/${customer.id}/edit');
                _load();
              },
            ),
        ],
      ),
      body: _error != null
          ? ErrorState(message: _error!, onRetry: _load)
          : customer == null
          ? const LoadingBody()
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  Text(
                    '${customer.code} | ${CustomerOptions.types[customer.type] ?? customer.type} | ${customer.region}'
                    '${customer.district == null ? '' : ', ${customer.district}'}',
                  ),
                  if (customer.contactPerson != null || customer.phone != null)
                    Text(
                      [
                        customer.contactPerson,
                        customer.phone,
                      ].whereType<String>().join(' | '),
                    ),
                  if (!customer.isActive)
                    Text(
                      'Inactive',
                      style: TextStyle(
                        color: Theme.of(context).colorScheme.error,
                      ),
                    ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(
                        child: _Figure(
                          label: 'Owes',
                          value: customer.outstandingBalance,
                        ),
                      ),
                      Expanded(
                        child: _Figure(
                          label: 'Credit',
                          value: customer.creditBalance,
                        ),
                      ),
                      Expanded(
                        child: customer.creditLimit == null
                            ? const _Text(label: 'Limit', value: 'None')
                            : _Figure(
                                label: 'Limit',
                                value: customer.creditLimit!,
                              ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    key: const Key('customer_record_payment'),
                    onPressed: () async {
                      await context.push('/customers/${customer.id}/pay');
                      _load();
                    },
                    icon: const Icon(Icons.payments_outlined),
                    label: const Text('Record payment'),
                    style: FilledButton.styleFrom(
                      minimumSize: const Size.fromHeight(48),
                    ),
                  ),
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    key: const Key('customer_statement'),
                    onPressed: _sharingStatement
                        ? null
                        : () => _shareStatement(customer),
                    icon: const Icon(Icons.description_outlined),
                    label: const Text('Share statement'),
                    style: OutlinedButton.styleFrom(
                      minimumSize: const Size.fromHeight(48),
                    ),
                  ),
                  if (customer.creditBalance > 0) ...[
                    const SizedBox(height: 8),
                    OutlinedButton.icon(
                      key: const Key('customer_apply_credit'),
                      onPressed: _applyingCredit
                          ? null
                          : () => _applyCredit(customer),
                      icon: const Icon(Icons.swap_horiz),
                      label: const Text('Apply credit to invoices'),
                      style: OutlinedButton.styleFrom(
                        minimumSize: const Size.fromHeight(48),
                      ),
                    ),
                  ],
                  const SizedBox(height: 24),
                  Text(
                    'Recent invoices',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  if (_sales.isEmpty)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 8),
                      child: Text('No sales yet.'),
                    )
                  else
                    ..._sales.map(
                      (s) => ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(s.label),
                        subtitle: Text(
                          '${s.status} | ${s.paymentStatus}'
                          '${s.dueDate == null ? '' : ' | due ${DateFormat('d MMM y').format(s.dueDate!)}'}',
                        ),
                        trailing: Text(Money.formatPesewas(s.balanceDue)),
                      ),
                    ),
                  const SizedBox(height: 16),
                  Text(
                    'Recent payments',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  if (_payments.isEmpty)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 8),
                      child: Text('No payments yet.'),
                    )
                  else
                    ..._payments.map(
                      (p) => PaymentTile(
                        payment: p,
                        onTap: () async {
                          await context.push('/payments/${p.id}');
                          _load();
                        },
                      ),
                    ),
                ],
              ),
            ),
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure({required this.label, required this.value});

  final String label;
  final int value;

  @override
  Widget build(BuildContext context) =>
      _Text(label: label, value: Money.formatPesewas(value));
}

class _Text extends StatelessWidget {
  const _Text({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.bodySmall),
        Text(value, style: Theme.of(context).textTheme.titleMedium),
      ],
    );
  }
}
