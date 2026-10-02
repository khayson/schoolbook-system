import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/validators.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/payments/domain/payment.dart';
import 'package:schoolbook/features/payments/presentation/record_payment_screen.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

class PaymentDetailScreen extends StatefulWidget {
  const PaymentDetailScreen({super.key, required this.paymentId});

  final int paymentId;

  @override
  State<PaymentDetailScreen> createState() => _PaymentDetailScreenState();
}

class _PaymentDetailScreenState extends State<PaymentDetailScreen> {
  Payment? _payment;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final payment = await context.read<PaymentsRepository>().getPayment(widget.paymentId);
      if (mounted) {
        setState(() => _payment = payment);
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = e.message);
      }
    }
  }

  Future<void> _void() async {
    final reason = await showDialog<String>(context: context, builder: (_) => const _VoidDialog());
    if (reason == null || !mounted) {
      return;
    }
    setState(() => _busy = true);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final payment = await context.read<PaymentsRepository>().voidPayment(widget.paymentId, reason);
      setState(() => _payment = payment);
      messenger.showSnackBar(SnackBar(content: Text('Payment ${payment.receiptNo} voided')));
    } on ApiException catch (e) {
      final d = describeApiError(e);
      messenger.showSnackBar(SnackBar(content: Text('${d.title}: ${d.body}')));
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _payment;
    return Scaffold(
      appBar: AppBar(
        title: Text(p?.receiptNo ?? 'Payment'),
        actions: [
          if (p != null && !p.isVoid)
            IconButton(
              key: const Key('payment_share'),
              tooltip: 'Share receipt',
              icon: const Icon(Icons.share),
              onPressed: () => shareReceipt(context, p),
            ),
        ],
      ),
      body: _error != null
          ? ErrorState(message: _error!, onRetry: _load)
          : p == null
              ? const LoadingBody()
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      Text(Money.formatPesewas(p.amount), style: Theme.of(context).textTheme.headlineMedium),
                      if (p.isVoid)
                        Text('VOID: ${p.voidReason ?? ''}', style: TextStyle(color: Theme.of(context).colorScheme.error)),
                      const SizedBox(height: 8),
                      _row('Customer', p.customerName ?? '#${p.customerId}'),
                      _row('Paid', DateFormat('EEE d MMM y, HH:mm').format(p.paidAt)),
                      _row('Method', p.methodLabel),
                      if (p.reference != null) _row('Reference', p.reference!),
                      _row('Held as credit', Money.formatPesewas(p.unallocatedAmount)),
                      if (p.notes != null) _row('Notes', p.notes!),
                      const SizedBox(height: 16),
                      Text('Applied to invoices', style: Theme.of(context).textTheme.titleMedium),
                      if (p.allocations.isEmpty)
                        const Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Text('Not applied to any invoice.'))
                      else
                        ...p.allocations.map(
                          (a) => ListTile(
                            contentPadding: EdgeInsets.zero,
                            dense: true,
                            title: Text(a.invoiceNo ?? 'Sale #${a.saleId}'),
                            subtitle: a.isReversal ? Text('Reversal of #${a.reversalOfId}') : null,
                            trailing: Text(Money.formatPesewas(a.amount)),
                          ),
                        ),
                      const SizedBox(height: 24),
                      if (!p.isVoid)
                        OutlinedButton.icon(
                          key: const Key('payment_void'),
                          onPressed: _busy ? null : _void,
                          icon: const Icon(Icons.block),
                          label: const Text('Void payment'),
                          style: OutlinedButton.styleFrom(
                            foregroundColor: Theme.of(context).colorScheme.error,
                            minimumSize: const Size.fromHeight(48),
                          ),
                        ),
                    ],
                  ),
                ),
    );
  }

  Widget _row(String label, String value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(width: 120, child: Text(label, style: Theme.of(context).textTheme.bodySmall)),
            Expanded(child: Text(value)),
          ],
        ),
      );
}

class _VoidDialog extends StatefulWidget {
  const _VoidDialog();

  @override
  State<_VoidDialog> createState() => _VoidDialogState();
}

class _VoidDialogState extends State<_VoidDialog> {
  final _formKey = GlobalKey<FormState>();
  final _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Void payment?'),
      content: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text('Every invoice it paid will owe that money again, and its credit is removed. This cannot be undone.'),
            const SizedBox(height: 12),
            TextFormField(
              key: const Key('void_reason'),
              controller: _reason,
              decoration: const InputDecoration(labelText: 'Reason', border: OutlineInputBorder()),
              validator: (v) => Validators.required(v, label: 'A reason'),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Cancel')),
        FilledButton(
          key: const Key('void_confirm'),
          onPressed: () {
            if (_formKey.currentState!.validate()) {
              Navigator.of(context).pop(_reason.text.trim());
            }
          },
          child: const Text('Void'),
        ),
      ],
    );
  }
}
