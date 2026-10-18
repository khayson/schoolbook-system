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
import 'package:schoolbook/core/validators.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/offline/presentation/offline_controller.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/payments/domain/payment.dart';
import 'package:schoolbook/features/payments/presentation/payment_widgets.dart';
import 'package:schoolbook/features/payments/presentation/record_payment_controller.dart';
import 'package:schoolbook/shared/widgets/app_drawer.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
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
      offline: context.read<OfflineController>(),
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
    if (mounted) setState(() {});
  }

  Future<void> _loadCustomer() async {
    try {
      final customer = await context.read<CustomersRepository>().getCustomer(
        widget.customerId,
      );
      if (mounted) setState(() => _customer = customer);
    } on ApiException catch (e) {
      if (!mounted) return;
      final cached = context
          .read<OfflineController>()
          .snapshot
          .customers
          .where((c) => c.id == widget.customerId);
      if (e.isNetworkError && cached.isNotEmpty) {
        setState(() => _customer = cached.first);
      } else {
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
    if (picked == null) return;
    final today = DateTime(now.year, now.month, now.day);
    setState(() {
      _paidAt = picked == today
          ? DateTime(now.year, now.month, now.day, now.hour, now.minute)
          : DateTime(picked.year, picked.month, picked.day, 12);
    });
  }

  bool get _pageLoading =>
      _customer == null && _customerError == null;

  Future<void> _submit() async {
    if (_pageLoading || _controller.submitting) return;
    if (!(_formKey.currentState?.validate() ?? false)) return;

    final amount = Money.parseGhsToPesewas(_amount.text)!;
    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      backgroundColor: AppColors.surface,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (context) {
        final bottom = MediaQuery.paddingOf(context).bottom;
        final school = _customer?.name ?? 'this school';
        return Padding(
          padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AppColors.line,
                    borderRadius: BorderRadius.circular(999),
                  ),
                ),
              ),
              const SizedBox(height: 18),
              const Text(
                'Confirm payment?',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 8),
              Text(
                'Record ${Money.formatPesewas(amount)} from $school '
                'via ${PaymentMethods.label(_method)}'
                '${_keepAsCredit ? ', kept as credit' : ', applied to oldest invoices first'}.',
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
                      onPressed: () => Navigator.pop(context, false),
                      child: const Text('Back'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    flex: 2,
                    child: FilledButton(
                      key: const Key('pay_confirm_submit'),
                      onPressed: () => Navigator.pop(context, true),
                      child: const Text('Confirm'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    );
    if (confirmed != true || !mounted) return;

    final payment = await _controller.submit(_payload());
    if (!mounted) return;
    if (_controller.queuedOffline) {
      AppToast.show(
        context,
        'Saved offline — will sync',
        type: ToastType.info,
        title: 'Queued',
      );
      if (context.canPop()) {
        context.pop();
      } else {
        context.go('/payments');
      }
      return;
    }
    if (payment == null) return;
    await _showRecorded(payment);
  }

  Future<void> _showRecorded(Payment payment) async {
    final applied = payment.amount - payment.unallocatedAmount;
    final router = GoRouter.of(context);
    await showAppDrawer<void>(
      context: context,
      builder: (sheetContext) => AppDrawerScaffold(
        child: Column(
          key: const Key('pay_recorded_dialog'),
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Payment ${payment.receiptNo} recorded',
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 8),
            Text(
              'Applied to invoices: ${Money.formatPesewas(applied)}\n'
              'Kept as credit: ${Money.formatPesewas(payment.unallocatedAmount)}',
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
                    onPressed: () => shareReceipt(sheetContext, payment),
                    child: const Text(
                      'Share receipt',
                      style: TextStyle(fontWeight: FontWeight.w800),
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: FilledButton(
                    style: FilledButton.styleFrom(
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                    onPressed: () => Navigator.of(sheetContext).pop(),
                    child: const Text('Done'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
    router.pushReplacement('/payments/${payment.id}');
  }

  @override
  Widget build(BuildContext context) {
    final c = _controller;
    final top = MediaQuery.paddingOf(context).top;
    final bottom = MediaQuery.paddingOf(context).bottom;
    final dateLabel = DateFormat('EEE d MMM y, HH:mm').format(_paidAt);

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: Column(
        children: [
          Padding(
            padding: EdgeInsets.fromLTRB(8, top + 4, 16, 0),
            child: Row(
              children: [
                IconButton(
                  onPressed: () => Navigator.of(context).maybePop(),
                  icon: const AppIcon(AppIcons.back),
                ),
                const Expanded(
                  child: Text(
                    'Record payment',
                    style: TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.w900,
                      letterSpacing: -0.3,
                    ),
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: Form(
              key: _formKey,
              child: AbsorbPointer(
                absorbing: _pageLoading || c.submitting,
                child: ListView(
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
                children: [
                  _CustomerHeader(
                    customer: _customer,
                    error: _customerError,
                  ),
                  if (c.unfinished != null) ...[
                    const SizedBox(height: 12),
                    _UnfinishedBanner(
                      unfinished: c.unfinished!,
                      onRestore: () => _restoreUnfinished(c.unfinished!),
                      onDiscard: c.discardUnfinished,
                    ),
                  ],
                  const SizedBox(height: 20),
                  const _SectionLabel('Payment'),
                  TextFormField(
                    key: const Key('pay_amount'),
                    controller: _amount,
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(
                      labelText: 'Amount',
                      prefixText: 'GHS ',
                    ),
                    validator: (v) => Validators.ghsAmount(v),
                  ),
                  const SizedBox(height: 12),
                  KeyedSubtree(
                    key: const Key('pay_method'),
                    child: DropdownButtonFormField<String>(
                      key: ValueKey('pay_method_$_method'),
                      initialValue: _method,
                      decoration: const InputDecoration(labelText: 'Method'),
                      items: [
                        for (final m in PaymentMethods.all)
                          DropdownMenuItem(
                            value: m,
                            child: Text(PaymentMethods.label(m)),
                          ),
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
                      helperText: PaymentMethods.referenceHelp,
                      helperMaxLines: 2,
                    ),
                    validator: (v) =>
                        Validators.paymentReference(v, method: _method),
                  ),
                  const SizedBox(height: 8),
                  Material(
                    color: AppColors.wash,
                    borderRadius: BorderRadius.circular(18),
                    child: ListTile(
                      key: const Key('pay_date'),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(18),
                      ),
                      leading: const AppIcon(AppIcons.sales, size: 22),
                      title: const Text(
                        'Date paid',
                        style: TextStyle(fontWeight: FontWeight.w800),
                      ),
                      subtitle: Text(
                        dateLabel,
                        style: const TextStyle(
                          fontWeight: FontWeight.w600,
                          color: AppColors.mute,
                        ),
                      ),
                      trailing: const AppIcon(AppIcons.edit, size: 20),
                      onTap: _pickDate,
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    key: const Key('pay_notes'),
                    controller: _notes,
                    maxLines: 2,
                    decoration: const InputDecoration(
                      labelText: 'Notes (optional)',
                    ),
                  ),
                  const SizedBox(height: 20),
                  const _SectionLabel('Apply the money'),
                  _ModeChip(
                    key: const Key('pay_mode_oldest'),
                    label: 'Oldest invoices first',
                    active: !_keepAsCredit,
                    onTap: () => setState(() => _keepAsCredit = false),
                  ),
                  const SizedBox(height: 8),
                  _ModeChip(
                    key: const Key('pay_mode_credit'),
                    label: 'Keep it all as credit',
                    active: _keepAsCredit,
                    onTap: () => setState(() => _keepAsCredit = true),
                  ),
                  if (c.error != null) ...[
                    const SizedBox(height: 12),
                    ErrorCard(key: const Key('pay_error'), error: c.error!),
                  ],
                  const SizedBox(height: 28),
                  const _SectionLabel('Recent from this school'),
                  Text(
                    'Check before recording again so the same money is not entered twice.',
                    style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: AppColors.mute.withValues(alpha: 0.95),
                      height: 1.35,
                    ),
                  ),
                  const SizedBox(height: 10),
                  if (c.loadingRecent && c.recent.isEmpty)
                    const Padding(
                      padding: EdgeInsets.all(16),
                      child: LoadingBody(),
                    )
                  else if (c.recentError != null)
                    ErrorState(
                      message: c.recentError!,
                      onRetry: c.refreshRecent,
                    )
                  else if (c.recent.isEmpty)
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: AppColors.wash,
                        borderRadius: BorderRadius.circular(18),
                      ),
                      child: const Text(
                        'No payments yet.',
                        style: TextStyle(
                          fontWeight: FontWeight.w600,
                          color: AppColors.mute,
                        ),
                      ),
                    )
                  else
                    ...c.recent.map(
                      (p) => Padding(
                        padding: const EdgeInsets.only(bottom: 8),
                        child: Material(
                          color: AppColors.wash,
                          borderRadius: BorderRadius.circular(18),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 4,
                            ),
                            child: PaymentTile(
                              key: Key('recent_${p.id}'),
                              payment: p,
                            ),
                          ),
                        ),
                      ),
                    ),
                ],
              ),
              ),
            ),
          ),
          Container(
            width: double.infinity,
            padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 16),
            decoration: const BoxDecoration(
              color: AppColors.surface,
              border: Border(top: BorderSide(color: AppColors.line)),
            ),
            child: FilledButton(
              key: const Key('pay_submit'),
              onPressed:
                  (_pageLoading || c.submitting) ? null : _submit,
              child: (_pageLoading || c.submitting)
                  ? const SizedBox(
                      height: 22,
                      width: 22,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: Colors.white,
                      ),
                    )
                  : Text(
                      c.error?.isNetworkError ?? false
                          ? 'Try again'
                          : 'Record payment',
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Text(
        text.toUpperCase(),
        style: const TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w800,
          letterSpacing: 0.8,
          color: AppColors.mute,
        ),
      ),
    );
  }
}

class _ModeChip extends StatelessWidget {
  const _ModeChip({
    super.key,
    required this.label,
    required this.active,
    required this.onTap,
  });

  final String label;
  final bool active;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: active ? AppColors.ink : AppColors.wash,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          child: Row(
            children: [
              AppIcon(
                active ? AppIcons.check : AppIcons.wallet,
                size: 20,
                color: active ? AppColors.lime : AppColors.ink,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  label,
                  style: TextStyle(
                    fontWeight: FontWeight.w800,
                    color: active ? AppColors.lime : AppColors.ink,
                  ),
                ),
              ),
            ],
          ),
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
      return Text(
        error!,
        style: const TextStyle(
          color: AppColors.danger,
          fontWeight: FontWeight.w700,
        ),
      );
    }
    final c = customer;
    if (c == null) {
      return const LinearProgressIndicator(
        color: AppColors.ink,
        backgroundColor: AppColors.line,
      );
    }
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: AppColors.ink,
        borderRadius: BorderRadius.circular(22),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            c.name,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 17,
              fontWeight: FontWeight.w900,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'Owes ${Money.formatPesewas(c.outstandingBalance)}  ·  Credit ${Money.formatPesewas(c.creditBalance)}',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.65),
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

class _UnfinishedBanner extends StatelessWidget {
  const _UnfinishedBanner({
    required this.unfinished,
    required this.onRestore,
    required this.onDiscard,
  });

  final PendingSubmission unfinished;
  final VoidCallback onRestore;
  final VoidCallback onDiscard;

  @override
  Widget build(BuildContext context) {
    final p = unfinished.payload;
    final amount = p['amount'] is int
        ? Money.formatPesewas(p['amount'] as int)
        : '?';
    final method = PaymentMethods.label('${p['method']}');
    final reference = p['reference'] == null ? '' : ' (${p['reference']})';
    final when = DateFormat('d MMM, HH:mm').format(unfinished.startedAt);
    return Container(
      key: const Key('pay_unfinished'),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFFFFF1E0),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Unfinished payment',
            style: TextStyle(fontWeight: FontWeight.w900),
          ),
          const SizedBox(height: 6),
          Text(
            '$amount by $method$reference was sent at $when but never confirmed. '
            'It may already be recorded — check recent payments below. '
            'Restoring and tapping Record is safe; it cannot be recorded twice.',
            style: const TextStyle(
              fontWeight: FontWeight.w600,
              color: AppColors.mute,
              height: 1.4,
            ),
          ),
          const SizedBox(height: 10),
          Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              TextButton(
                key: const Key('pay_unfinished_discard'),
                onPressed: onDiscard,
                child: const Text(
                  'Discard',
                  style: TextStyle(fontWeight: FontWeight.w800),
                ),
              ),
              FilledButton(
                key: const Key('pay_unfinished_restore'),
                onPressed: onRestore,
                child: const Text('Restore'),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Downloads the receipt PDF and opens the share sheet.
Future<void> shareReceipt(BuildContext context, Payment payment) async {
  try {
    final bytes = await context.read<PaymentsRepository>().receiptPdf(
      payment.id,
    );
    if (!context.mounted) return;
    await context.read<PdfSharer>().sharePdf(
      bytes,
      fileName: '${payment.receiptNo}.pdf',
      subject: 'Receipt ${payment.receiptNo}',
    );
  } on ApiException catch (e) {
    if (!context.mounted) return;
    AppToast.show(
      context,
      describeApiError(e).body,
      type: ToastType.error,
    );
  }
}
