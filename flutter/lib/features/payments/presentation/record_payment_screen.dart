import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/pdf_sharer.dart';
import 'package:schoolbook/core/validators.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/payments/domain/payment.dart';
import 'package:schoolbook/features/payments/presentation/payment_widgets.dart';
import 'package:schoolbook/features/payments/presentation/record_payment_controller.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

class RecordPaymentScreen extends StatefulWidget {
  const RecordPaymentScreen({super.key, required this.customerId, this.now});

  final int customerId;

  /// Injectable clock for tests.
  final DateTime Function()? now;

  @override
  State<RecordPaymentScreen> createState() => _RecordPaymentScreenState();
}

class _RecordPaymentScreenState extends State<RecordPaymentScreen> {
  final _formKey = GlobalKey<FormState>();
  final _amount = TextEditingController();
  final _reference = TextEditingController();
  final _notes = TextEditingController();

  late final RecordPaymentController _controller;
  late DateTime _paidAt;
  String _method = 'cash';
  bool _keepAsCredit = false;

  Customer? _customer;
  String? _customerError;

  DateTime _now() => (widget.now ?? DateTime.now)();

  @override
  void initState() {
    super.initState();
    final now = _now();
    _paidAt = DateTime(now.year, now.month, now.day, now.hour, now.minute);
    _controller = RecordPaymentController(
      payments: context.read<PaymentsRepository>(),
      pendingStore: context.read<PendingSubmissionStore>(),
      customerId: widget.customerId,
    )..addListener(_onChanged);
    _loadCustomer();
    _controller.load();
  }

  @override
  void dispose() {
    _controller
      ..removeListener(_onChanged)
      ..dispose();
    _amount.dispose();
    _reference.dispose();
    _notes.dispose();
    super.dispose();
  }

  void _onChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  Future<void> _loadCustomer() async {
    try {
      final customer = await context.read<CustomersRepository>().getCustomer(widget.customerId);
      if (mounted) {
        setState(() => _customer = customer);
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _customerError = e.message);
      }
    }
  }

  Map<String, dynamic> _payload() {
    return RecordPaymentController.buildPayload(
      customerId: widget.customerId,
      amountPesewas: Money.parseGhsToPesewas(_amount.text)!,
      method: _method,
      reference: _reference.text,
      paidAt: _paidAt,
      notes: _notes.text,
      keepAsCredit: _keepAsCredit,
    );
  }

  /// Puts the unfinished submission's exact details back, so retrying reuses its key.
  void _restoreUnfinished(PendingSubmission unfinished) {
    final p = unfinished.payload;
    setState(() {
      _amount.text = Money.toGhsInput(p['amount'] as int);
      _method = p['method'] as String;
      _reference.text = (p['reference'] as String?) ?? '';
      _notes.text = (p['notes'] as String?) ?? '';
      _paidAt = DateTime.parse(p['paid_at'] as String).toLocal();
      _keepAsCredit = p['auto_allocate'] == false;
    });
  }

  Future<void> _pickDate() async {
    final now = _now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _paidAt,
      firstDate: DateTime(now.year - 2),
      lastDate: now,
    );
    if (picked == null) {
      return;
    }
    final today = DateTime(now.year, now.month, now.day);
    setState(() {
      _paidAt = picked == today
          ? DateTime(now.year, now.month, now.day, now.hour, now.minute)
          : DateTime(picked.year, picked.month, picked.day, 12);
    });
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) {
      return;
    }
    final payment = await _controller.submit(_payload());
    if (payment == null || !mounted) {
      return;
    }
    await _showRecorded(payment);
  }

  Future<void> _showRecorded(Payment payment) async {
    final applied = payment.amount - payment.unallocatedAmount;
    final router = GoRouter.of(context);
    await showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        key: const Key('pay_recorded_dialog'),
        title: Text('Payment ${payment.receiptNo} recorded'),
        content: Text(
          'Applied to invoices: ${Money.formatPesewas(applied)}\n'
          'Kept as credit: ${Money.formatPesewas(payment.unallocatedAmount)}',
        ),
        actions: [
          TextButton(
            onPressed: () => shareReceipt(dialogContext, payment),
            child: const Text('Share receipt'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: const Text('Done'),
          ),
        ],
      ),
    );
    router.pushReplacement('/payments/${payment.id}');
  }

  @override
  Widget build(BuildContext context) {
    final c = _controller;
    final dateLabel = DateFormat('EEE d MMM y, HH:mm').format(_paidAt);

    return Scaffold(
      appBar: AppBar(title: const Text('Record payment')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            _CustomerHeader(customer: _customer, error: _customerError),
            if (c.unfinished != null) ...[
              const SizedBox(height: 12),
              _UnfinishedBanner(
                unfinished: c.unfinished!,
                onRestore: () => _restoreUnfinished(c.unfinished!),
                onDiscard: c.discardUnfinished,
              ),
            ],
            const SizedBox(height: 16),
            TextFormField(
              key: const Key('pay_amount'),
              controller: _amount,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(
                labelText: 'Amount',
                prefixText: 'GHS ',
                border: OutlineInputBorder(),
              ),
              validator: (v) => Validators.ghsAmount(v),
            ),
            const SizedBox(height: 12),
            KeyedSubtree(
              key: const Key('pay_method'),
              // Keyed on the value so a restored unfinished payment shows its method.
              child: DropdownButtonFormField<String>(
              key: ValueKey('pay_method_$_method'),
              initialValue: _method,
              decoration: const InputDecoration(labelText: 'Method', border: OutlineInputBorder()),
              items: [
                for (final m in PaymentMethods.all)
                  DropdownMenuItem(value: m, child: Text(PaymentMethods.label(m))),
              ],
              onChanged: (v) => setState(() => _method = v ?? 'cash'),
            ),
            ),
            const SizedBox(height: 12),
            TextFormField(
              key: const Key('pay_reference'),
              controller: _reference,
              decoration: InputDecoration(
                labelText: PaymentMethods.referenceLabel(_method),
                border: const OutlineInputBorder(),
              ),
              validator: (v) => Validators.paymentReference(v, method: _method),
            ),
            const SizedBox(height: 12),
            ListTile(
              key: const Key('pay_date'),
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.event),
              title: const Text('Date paid'),
              subtitle: Text(dateLabel),
              trailing: const Icon(Icons.edit_calendar),
              onTap: _pickDate,
            ),
            TextFormField(
              key: const Key('pay_notes'),
              controller: _notes,
              maxLines: 2,
              decoration: const InputDecoration(labelText: 'Notes (optional)', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 12),
            Text('Apply the money', style: Theme.of(context).textTheme.titleSmall),
            RadioGroup<bool>(
              groupValue: _keepAsCredit,
              onChanged: (v) => setState(() => _keepAsCredit = v ?? false),
              child: const Column(
                children: [
                  RadioListTile<bool>(
                    key: Key('pay_mode_oldest'),
                    value: false,
                    title: Text('To the oldest invoices first'),
                    contentPadding: EdgeInsets.zero,
                  ),
                  RadioListTile<bool>(
                    key: Key('pay_mode_credit'),
                    value: true,
                    title: Text('Keep it all as customer credit'),
                    contentPadding: EdgeInsets.zero,
                  ),
                ],
              ),
            ),
            if (c.error != null) ...[
              const SizedBox(height: 8),
              ErrorCard(key: const Key('pay_error'), error: c.error!),
            ],
            const SizedBox(height: 16),
            FilledButton(
              key: const Key('pay_submit'),
              onPressed: c.submitting ? null : _submit,
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
              child: c.submitting
                  ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2))
                  : Text(c.error?.isNetworkError ?? false ? 'Try again' : 'Record payment'),
            ),
            const SizedBox(height: 24),
            Text(
              'Recent payments from this customer',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            Text(
              'Check here before recording again, so the same money is not entered twice.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 8),
            if (c.loadingRecent && c.recent.isEmpty)
              const Padding(padding: EdgeInsets.all(16), child: LoadingBody())
            else if (c.recentError != null)
              ErrorState(message: c.recentError!, onRetry: c.refreshRecent)
            else if (c.recent.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 8),
                child: Text('No payments yet.'),
              )
            else
              ...c.recent.map((p) => PaymentTile(key: Key('recent_${p.id}'), payment: p)),
          ],
        ),
      ),
    );
  }
}

class _CustomerHeader extends StatelessWidget {
  const _CustomerHeader({required this.customer, required this.error});

  final Customer? customer;
  final String? error;

  @override
  Widget build(BuildContext context) {
    if (error != null) {
      return Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error));
    }
    final c = customer;
    if (c == null) {
      return const LinearProgressIndicator();
    }
    return Card(
      child: ListTile(
        title: Text(c.name),
        subtitle: Text('Owes ${Money.formatPesewas(c.outstandingBalance)} | credit ${Money.formatPesewas(c.creditBalance)}'),
      ),
    );
  }
}

class _UnfinishedBanner extends StatelessWidget {
  const _UnfinishedBanner({required this.unfinished, required this.onRestore, required this.onDiscard});

  final PendingSubmission unfinished;
  final VoidCallback onRestore;
  final VoidCallback onDiscard;

  @override
  Widget build(BuildContext context) {
    final p = unfinished.payload;
    final amount = p['amount'] is int ? Money.formatPesewas(p['amount'] as int) : '?';
    final method = PaymentMethods.label('${p['method']}');
    final reference = p['reference'] == null ? '' : ' (${p['reference']})';
    final when = DateFormat('d MMM, HH:mm').format(unfinished.startedAt);
    return Card(
      key: const Key('pay_unfinished'),
      color: Theme.of(context).colorScheme.tertiaryContainer,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Unfinished payment', style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: 4),
            Text(
              '$amount by $method$reference was sent at $when but never confirmed. '
              'It may already be recorded: check the recent payments below. '
              'Restoring it and tapping Record is safe; it cannot be recorded twice.',
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(key: const Key('pay_unfinished_discard'), onPressed: onDiscard, child: const Text('Discard')),
                FilledButton.tonal(key: const Key('pay_unfinished_restore'), onPressed: onRestore, child: const Text('Restore')),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// Downloads the receipt PDF and opens the share sheet.
Future<void> shareReceipt(BuildContext context, Payment payment) async {
  final messenger = ScaffoldMessenger.maybeOf(context);
  try {
    final bytes = await context.read<PaymentsRepository>().receiptPdf(payment.id);
    if (!context.mounted) {
      return;
    }
    await context.read<PdfSharer>().sharePdf(
          bytes,
          fileName: '${payment.receiptNo}.pdf',
          subject: 'Receipt ${payment.receiptNo}',
        );
  } on ApiException catch (e) {
    messenger?.showSnackBar(SnackBar(content: Text(describeApiError(e).body)));
  }
}
