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
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/domain/sale.dart';
import 'package:schoolbook/features/sales/presentation/sale_actions_controller.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

class SaleDetailScreen extends StatefulWidget {
  const SaleDetailScreen({super.key, required this.saleId, this.openConfirm = false});

  final int saleId;

  /// Open the confirm sheet as soon as the draft loads ("Save & confirm").
  final bool openConfirm;

  @override
  State<SaleDetailScreen> createState() => _SaleDetailScreenState();
}

class _SaleDetailScreenState extends State<SaleDetailScreen> {
  late final SaleActionsController _actions;
  Sale? _sale;
  String? _error;
  bool _confirmOpened = false;

  @override
  void initState() {
    super.initState();
    _actions = SaleActionsController(
      sales: context.read<SalesRepository>(),
      pendingStore: context.read<PendingSubmissionStore>(),
    )..addListener(_onChanged);
    _load();
  }

  @override
  void dispose() {
    _actions
      ..removeListener(_onChanged)
      ..dispose();
    super.dispose();
  }

  void _onChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final sale = await context.read<SalesRepository>().getSale(widget.saleId);
      if (!mounted) {
        return;
      }
      setState(() => _sale = sale);
      if (widget.openConfirm && !_confirmOpened && sale.isDraft) {
        _confirmOpened = true;
        WidgetsBinding.instance.addPostFrameCallback((_) => _startConfirm());
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = e.message);
      }
    }
  }

  void _snack(String text) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));

  void _showError(ApiException e) {
    final d = describeApiError(e);
    _snack('${d.title}: ${d.body}');
  }

  // --- Confirm ------------------------------------------------------------------------

  Future<void> _startConfirm() async {
    final sale = _sale;
    if (sale == null) {
      return;
    }
    final choice = await showModalBottomSheet<_ConfirmChoice>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _ConfirmSheet(sale: sale),
    );
    if (choice != null) {
      await _confirm(choice);
    }
  }

  Future<void> _confirm(_ConfirmChoice choice) async {
    final sale = _sale!;
    final outcome = await _actions.confirm(
      sale,
      dueDate: choice.dueDate,
      applyCredit: choice.applyCredit,
      overrideCreditLimit: choice.overrideCreditLimit,
    );
    if (!mounted) {
      return;
    }

    switch (outcome) {
      case Confirmed(:final sale):
        setState(() => _sale = sale);
        _snack('Invoice ${sale.invoiceNo} issued');
        await _load();
      case PricesChanged(:final newOrder):
        final accept = await showDialog<bool>(
          context: context,
          builder: (_) => PriceChangedDialog(sale: sale, newOrder: newOrder),
        );
        if (accept == true && mounted) {
          try {
            final repriced = await _actions.acceptNewPrices(sale);
            setState(() => _sale = repriced);
            // Re-priced draft: new updated_at and total, so the confirm gets a new key.
            await _confirm(choice);
          } on ApiException catch (e) {
            _showError(e);
          }
        }
      case StockShort(:final items):
        await showDialog<void>(context: context, builder: (_) => InsufficientStockDialog(items: items));
      case CreditWarning():
        final proceed = await showDialog<bool>(context: context, builder: (_) => CreditWarningDialog(warning: outcome));
        if (proceed == true && mounted) {
          await _confirm(choice.withOverride());
        }
      case ConfirmFailed(:final error):
        _showError(error);
    }
  }

  // --- Other actions --------------------------------------------------------------------

  Future<void> _cancel() async {
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => const ReasonDialog(title: 'Cancel this draft?', action: 'Cancel draft', required: false),
    );
    if (reason == null) {
      return;
    }
    await _act(() => _actions.cancel(_sale!, reason: reason.isEmpty ? null : reason), 'Draft cancelled');
  }

  Future<void> _void() async {
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => const ReasonDialog(
        title: 'Void this invoice?',
        message: 'The books go back into stock and any payments on it become customer credit. This cannot be undone.',
        action: 'Void invoice',
      ),
    );
    if (reason == null) {
      return;
    }
    await _act(() => _actions.voidSale(_sale!, reason), 'Invoice voided');
  }

  Future<void> _deliver() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Mark as delivered?'),
        content: const Text('After delivery the invoice can no longer be voided.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Not yet')),
          FilledButton(key: const Key('deliver_confirm'), onPressed: () => Navigator.pop(context, true), child: const Text('Delivered')),
        ],
      ),
    );
    if (ok == true) {
      await _act(() => _actions.deliver(_sale!), 'Marked as delivered');
    }
  }

  Future<void> _act(Future<Sale> Function() action, String done) async {
    try {
      final sale = await action();
      if (!mounted) {
        return;
      }
      setState(() => _sale = sale);
      _snack(done);
      await _load();
    } on ApiException catch (e) {
      if (mounted) {
        _showError(e);
      }
    }
  }

  Future<void> _shareInvoice() async {
    final sale = _sale!;
    try {
      final bytes = await context.read<SalesRepository>().invoicePdf(sale.id);
      if (!mounted) {
        return;
      }
      await context.read<PdfSharer>().sharePdf(bytes, fileName: '${sale.invoiceNo}.pdf', subject: 'Invoice ${sale.invoiceNo}');
    } on ApiException catch (e) {
      if (mounted) {
        _showError(e);
      }
    }
  }

  // --- Build ----------------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final sale = _sale;
    return Scaffold(
      appBar: AppBar(
        title: Text(sale?.label ?? 'Sale'),
        actions: [
          if (sale != null && sale.isConfirmed)
            IconButton(key: const Key('sale_share_invoice'), tooltip: 'Share invoice', icon: const Icon(Icons.share), onPressed: _shareInvoice),
        ],
      ),
      body: _error != null
          ? ErrorState(message: _error!, onRetry: _load)
          : sale == null
              ? const LoadingBody()
              : RefreshIndicator(onRefresh: _load, child: _details(sale)),
    );
  }

  Widget _details(Sale sale) {
    final theme = Theme.of(context);
    final busy = _actions.busy;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Wrap(
          spacing: 8,
          children: [
            Chip(label: Text(SaleStatuses.label(sale.status))),
            if (sale.isConfirmed) Chip(label: Text(SaleStatuses.paymentLabel(sale.paymentStatus))),
            if (sale.deliveredAt != null) const Chip(label: Text('Delivered')),
          ],
        ),
        Text(sale.customer?.name ?? 'Customer #${sale.customerId}', style: theme.textTheme.titleLarge),
        Text('Sale date ${DateFormat('d MMM y').format(sale.saleDate)}'
            '${sale.dueDate == null ? '' : ' | due ${DateFormat('d MMM y').format(sale.dueDate!)}'}'),
        if (sale.voidReason != null) Text('Void: ${sale.voidReason}', style: TextStyle(color: theme.colorScheme.error)),
        if (sale.cancelReason != null) Text('Cancelled: ${sale.cancelReason}'),
        const SizedBox(height: 16),
        Text('Books', style: theme.textTheme.titleMedium),
        ...sale.items.map(
          (line) => ListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(line.productTitle),
            subtitle: Text('${line.quantity} x ${Money.formatPesewas(line.unitPrice)}'
                '${line.isPriceOverridden ? ' (override: ${line.overrideReason ?? ''})' : ''}'),
            trailing: Text(Money.formatPesewas(line.lineTotal)),
          ),
        ),
        const Divider(),
        _amountRow('Total', sale.total, bold: true),
        if (!sale.isDraft) ...[
          _amountRow('Paid', sale.amountPaid),
          _amountRow('Balance due', sale.balanceDue, bold: true),
        ],
        if (sale.allocations.isNotEmpty) ...[
          const SizedBox(height: 16),
          Text('Payments applied', style: theme.textTheme.titleMedium),
          ...sale.allocations.map(
            (a) => ListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: Text(a.receiptNo ?? 'Payment #${a.id}'),
              subtitle: a.isReversal ? const Text('Reversal') : null,
              trailing: Text(Money.formatPesewas(a.amount)),
            ),
          ),
        ],
        const SizedBox(height: 24),
        if (sale.isDraft) ...[
          FilledButton.icon(
            key: const Key('sale_confirm'),
            onPressed: busy ? null : _startConfirm,
            icon: const Icon(Icons.check_circle_outline),
            label: const Text('Confirm sale'),
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          ),
          const SizedBox(height: 8),
          OutlinedButton(key: const Key('sale_cancel'), onPressed: busy ? null : _cancel, child: const Text('Cancel draft')),
        ],
        if (sale.isConfirmed) ...[
          if (sale.balanceDue > 0)
            FilledButton.icon(
              key: const Key('sale_record_payment'),
              onPressed: () async {
                await context.push('/customers/${sale.customerId}/pay');
                _load();
              },
              icon: const Icon(Icons.payments_outlined),
              label: const Text('Record payment'),
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
            ),
          if (sale.deliveredAt == null) ...[
            const SizedBox(height: 8),
            OutlinedButton.icon(key: const Key('sale_deliver'), onPressed: busy ? null : _deliver, icon: const Icon(Icons.local_shipping_outlined), label: const Text('Mark delivered')),
          ],
          if (sale.canVoid)
            TextButton(
              key: const Key('sale_void'),
              onPressed: busy ? null : _void,
              style: TextButton.styleFrom(foregroundColor: theme.colorScheme.error),
              child: const Text('Void invoice'),
            ),
        ],
      ],
    );
  }

  Widget _amountRow(String label, int pesewas, {bool bold = false}) {
    final style = bold ? const TextStyle(fontWeight: FontWeight.w600) : null;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [Text(label, style: style), Text(Money.formatPesewas(pesewas), style: style)],
      ),
    );
  }
}

class _ConfirmChoice {
  const _ConfirmChoice({this.dueDate, this.applyCredit = false, this.overrideCreditLimit = false});

  final DateTime? dueDate;
  final bool applyCredit;
  final bool overrideCreditLimit;

  _ConfirmChoice withOverride() => _ConfirmChoice(dueDate: dueDate, applyCredit: applyCredit, overrideCreditLimit: true);
}

class _ConfirmSheet extends StatefulWidget {
  const _ConfirmSheet({required this.sale});

  final Sale sale;

  @override
  State<_ConfirmSheet> createState() => _ConfirmSheetState();
}

class _ConfirmSheetState extends State<_ConfirmSheet> {
  DateTime? _dueDate;
  bool _applyCredit = false;

  @override
  void initState() {
    super.initState();
    _dueDate = widget.sale.dueDate;
    _applyCredit = (widget.sale.customer?.creditBalance ?? 0) > 0;
  }

  @override
  Widget build(BuildContext context) {
    final credit = widget.sale.customer?.creditBalance ?? 0;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Confirm ${Money.formatPesewas(widget.sale.total)}', style: Theme.of(context).textTheme.titleLarge),
            const Text('Issues the invoice number and takes the books out of stock.'),
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.event),
              title: const Text('Due date'),
              subtitle: Text(_dueDate == null ? 'From payment terms' : DateFormat('d MMM y').format(_dueDate!)),
              onTap: () async {
                final now = DateTime.now();
                final picked = await showDatePicker(
                  context: context,
                  initialDate: _dueDate ?? now.add(const Duration(days: 30)),
                  firstDate: DateTime(now.year - 1),
                  lastDate: DateTime(now.year + 2),
                );
                if (picked != null) {
                  setState(() => _dueDate = picked);
                }
              },
            ),
            if (credit > 0)
              SwitchListTile(
                key: const Key('confirm_apply_credit'),
                contentPadding: EdgeInsets.zero,
                title: Text('Apply customer credit (${Money.formatPesewas(credit)})'),
                value: _applyCredit,
                onChanged: (v) => setState(() => _applyCredit = v),
              ),
            const SizedBox(height: 8),
            FilledButton(
              key: const Key('confirm_submit'),
              onPressed: () => Navigator.of(context).pop(_ConfirmChoice(dueDate: _dueDate, applyCredit: credit > 0 && _applyCredit)),
              child: const Text('Confirm'),
            ),
          ],
        ),
      ),
    );
  }
}

/// 409 price_changed: line-by-line old -> new and the totals.
class PriceChangedDialog extends StatelessWidget {
  const PriceChangedDialog({super.key, required this.sale, required this.newOrder});

  final Sale sale;
  final PricedOrder newOrder;

  @override
  Widget build(BuildContext context) {
    final changes = <String>[];
    for (var i = 0; i < newOrder.lines.length; i++) {
      final line = newOrder.lines[i];
      final old = i < sale.items.length && sale.items[i].productId == line.productId ? sale.items[i] : null;
      if (old == null) {
        changes.add('${line.productTitle}: now ${Money.formatPesewas(line.unitPrice)}');
      } else if (old.unitPrice != line.unitPrice) {
        changes.add('${line.productTitle}: ${Money.formatPesewas(old.unitPrice)} -> ${Money.formatPesewas(line.unitPrice)}');
      }
    }
    return AlertDialog(
      key: const Key('price_changed_dialog'),
      title: const Text('Prices changed'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Nothing was confirmed. Since this draft was saved:'),
          const SizedBox(height: 8),
          ...changes.map(Text.new),
          const SizedBox(height: 8),
          Text('Total: ${Money.formatPesewas(sale.total)} -> ${Money.formatPesewas(newOrder.total)}',
              style: const TextStyle(fontWeight: FontWeight.w600)),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Not now')),
        FilledButton(key: const Key('accept_new_prices'), onPressed: () => Navigator.pop(context, true), child: const Text('Accept new prices')),
      ],
    );
  }
}

class InsufficientStockDialog extends StatelessWidget {
  const InsufficientStockDialog({super.key, required this.items});

  final List<ShortItem> items;

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      key: const Key('insufficient_stock_dialog'),
      title: const Text('Not enough stock'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ...items.map((i) => Text('${i.sku} ${i.title}: need ${i.requested}, have ${i.available}')),
          const SizedBox(height: 8),
          const Text('Nothing was confirmed. Reduce the quantities or receive stock first.'),
        ],
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('OK'))],
    );
  }
}

class CreditWarningDialog extends StatelessWidget {
  const CreditWarningDialog({super.key, required this.warning});

  final CreditWarning warning;

  @override
  Widget build(BuildContext context) {
    final w = warning;
    return AlertDialog(
      key: const Key('credit_warning_dialog'),
      title: const Text('Over credit limit'),
      content: Text(
        'Owes ${Money.formatPesewas(w.outstanding)} + this sale ${Money.formatPesewas(w.saleTotal)}'
        '${w.creditApplied > 0 ? ' - credit ${Money.formatPesewas(w.creditApplied)}' : ''}'
        ' = ${Money.formatPesewas(w.projectedBalance)}, over the limit of ${Money.formatPesewas(w.creditLimit)}.',
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Back')),
        FilledButton(key: const Key('credit_override'), onPressed: () => Navigator.pop(context, true), child: const Text('Confirm anyway')),
      ],
    );
  }
}

/// Asks for a reason; returns it, or null when dismissed.
class ReasonDialog extends StatefulWidget {
  const ReasonDialog({super.key, required this.title, required this.action, this.message, this.required = true});

  final String title;
  final String action;
  final String? message;
  final bool required;

  @override
  State<ReasonDialog> createState() => _ReasonDialogState();
}

class _ReasonDialogState extends State<ReasonDialog> {
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
      title: Text(widget.title),
      content: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (widget.message != null) Text(widget.message!),
            const SizedBox(height: 12),
            TextFormField(
              key: const Key('reason_field'),
              controller: _reason,
              decoration: InputDecoration(
                labelText: widget.required ? 'Reason' : 'Reason (optional)',
                border: const OutlineInputBorder(),
              ),
              validator: widget.required ? (v) => Validators.required(v, label: 'A reason') : null,
            ),
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Back')),
        FilledButton(
          key: const Key('reason_submit'),
          onPressed: () {
            if (_formKey.currentState!.validate()) {
              Navigator.pop(context, _reason.text.trim());
            }
          },
          child: Text(widget.action),
        ),
      ],
    );
  }
}
