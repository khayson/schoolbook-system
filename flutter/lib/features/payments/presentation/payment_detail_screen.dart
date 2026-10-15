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
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
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
      final payment = await context.read<PaymentsRepository>().getPayment(
        widget.paymentId,
      );
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
    final reason = await showModalBottomSheet<String>(
      context: context,
      backgroundColor: AppColors.surface,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (_) => const _VoidPaymentDrawer(),
    );
    if (reason == null || !mounted) {
      return;
    }
    setState(() => _busy = true);
    try {
      final payment = await context.read<PaymentsRepository>().voidPayment(
        widget.paymentId,
        reason,
      );
      setState(() => _payment = payment);
      if (mounted) {
        AppToast.show(
          context,
          'Payment ${payment.receiptNo} voided',
          type: ToastType.success,
        );
      }
    } on ApiException catch (e) {
      final d = describeApiError(e);
      if (mounted) {
        AppToast.show(context, d.body, title: d.title, type: ToastType.error);
      }
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _payment;
    final top = MediaQuery.paddingOf(context).top;
    final bottom = MediaQuery.paddingOf(context).bottom;

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: _error != null
          ? ErrorState(message: _error!, onRetry: _load)
          : p == null
          ? const LoadingBody()
          : Column(
              children: [
                Expanded(
                  child: RefreshIndicator(
                    color: AppColors.ink,
                    backgroundColor: AppColors.lime,
                    onRefresh: _load,
                    child: ListView(
                      padding: EdgeInsets.fromLTRB(20, top + 4, 20, 24),
                      children: [
                        Row(
                          children: [
                            IconButton(
                              onPressed: () =>
                                  Navigator.of(context).maybePop(),
                              icon: const AppIcon(AppIcons.back),
                            ),
                            const Expanded(
                              child: Text(
                                'Payment',
                                textAlign: TextAlign.center,
                                style: TextStyle(
                                  fontSize: 17,
                                  fontWeight: FontWeight.w900,
                                  letterSpacing: -0.2,
                                ),
                              ),
                            ),
                            const SizedBox(width: 48),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Text(
                          p.receiptNo,
                          style: const TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.w700,
                            color: AppColors.mute,
                            letterSpacing: 0.4,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          Money.formatPesewas(p.amount),
                          style: const TextStyle(
                            fontSize: 36,
                            fontWeight: FontWeight.w900,
                            letterSpacing: -1,
                            fontFeatures: [FontFeature.tabularFigures()],
                          ),
                        ),
                        if (p.isVoid) ...[
                          const SizedBox(height: 10),
                          Container(
                            width: double.infinity,
                            padding: const EdgeInsets.all(14),
                            decoration: BoxDecoration(
                              color: AppColors.danger.withValues(alpha: 0.08),
                              borderRadius: BorderRadius.circular(16),
                            ),
                            child: Text(
                              'VOID · ${p.voidReason ?? 'No reason given'}',
                              style: const TextStyle(
                                color: AppColors.danger,
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                          ),
                        ],
                        const SizedBox(height: 20),
                        _FactCard(
                          children: [
                            _Fact(
                              label: 'School',
                              value: p.customerName ?? '#${p.customerId}',
                              onTap: () => context.push(
                                '/customers/${p.customerId}',
                              ),
                            ),
                            _Fact(
                              label: 'Paid',
                              value: DateFormat(
                                'EEE d MMM y · HH:mm',
                              ).format(p.paidAt),
                            ),
                            _Fact(label: 'Method', value: p.methodLabel),
                            if (p.reference != null)
                              _Fact(label: 'Reference', value: p.reference!),
                            _Fact(
                              label: 'Held as credit',
                              value: Money.formatPesewas(p.unallocatedAmount),
                            ),
                            if (p.notes != null)
                              _Fact(label: 'Notes', value: p.notes!),
                          ],
                        ),
                        const SizedBox(height: 22),
                        const Text(
                          'APPLIED TO INVOICES',
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w800,
                            letterSpacing: 0.8,
                            color: AppColors.mute,
                          ),
                        ),
                        const SizedBox(height: 10),
                        if (p.allocations.isEmpty)
                          Container(
                            width: double.infinity,
                            padding: const EdgeInsets.all(16),
                            decoration: BoxDecoration(
                              color: AppColors.wash,
                              borderRadius: BorderRadius.circular(18),
                            ),
                            child: const Text(
                              'Not applied to any invoice — held as school credit.',
                              style: TextStyle(
                                fontWeight: FontWeight.w600,
                                color: AppColors.mute,
                              ),
                            ),
                          )
                        else
                          ...p.allocations.map(
                            (a) => Padding(
                              padding: const EdgeInsets.only(bottom: 8),
                              child: Material(
                                color: AppColors.wash,
                                borderRadius: BorderRadius.circular(18),
                                child: InkWell(
                                  borderRadius: BorderRadius.circular(18),
                                  onTap: () =>
                                      context.push('/sales/${a.saleId}'),
                                  child: Padding(
                                    padding: const EdgeInsets.fromLTRB(
                                      16,
                                      14,
                                      14,
                                      14,
                                    ),
                                    child: Row(
                                      children: [
                                        Expanded(
                                          child: Column(
                                            crossAxisAlignment:
                                                CrossAxisAlignment.start,
                                            children: [
                                              Text(
                                                a.invoiceNo ??
                                                    'Sale #${a.saleId}',
                                                style: const TextStyle(
                                                  fontWeight: FontWeight.w800,
                                                ),
                                              ),
                                              if (a.isReversal) ...[
                                                const SizedBox(height: 4),
                                                Text(
                                                  'Reversal of #${a.reversalOfId}',
                                                  style: const TextStyle(
                                                    fontSize: 12.5,
                                                    fontWeight: FontWeight.w600,
                                                    color: AppColors.mute,
                                                  ),
                                                ),
                                              ],
                                            ],
                                          ),
                                        ),
                                        Text(
                                          Money.formatPesewas(a.amount),
                                          style: const TextStyle(
                                            fontWeight: FontWeight.w900,
                                            fontFeatures: [
                                              FontFeature.tabularFigures(),
                                            ],
                                          ),
                                        ),
                                        const SizedBox(width: 6),
                                        const AppIcon(
                                          AppIcons.chevronRight,
                                          size: 18,
                                          color: AppColors.mute,
                                        ),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                            ),
                          ),
                      ],
                    ),
                  ),
                ),
                if (!p.isVoid)
                  Container(
                    width: double.infinity,
                    padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 16),
                    decoration: const BoxDecoration(
                      color: AppColors.surface,
                      border: Border(top: BorderSide(color: AppColors.line)),
                    ),
                    child: Row(
                      children: [
                        Expanded(
                          child: OutlinedButton.icon(
                            key: const Key('payment_share'),
                            onPressed: () => shareReceipt(context, p),
                            icon: const AppIcon(AppIcons.share, size: 20),
                            label: const Text('Share'),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: OutlinedButton.icon(
                            key: const Key('payment_void'),
                            onPressed: _busy ? null : _void,
                            icon: const AppIcon(
                              AppIcons.delete,
                              size: 20,
                              color: AppColors.danger,
                            ),
                            label: const Text(
                              'Void',
                              style: TextStyle(color: AppColors.danger),
                            ),
                            style: OutlinedButton.styleFrom(
                              foregroundColor: AppColors.danger,
                              side: const BorderSide(color: AppColors.danger),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
    );
  }
}

class _FactCard extends StatelessWidget {
  const _FactCard({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      decoration: BoxDecoration(
        color: AppColors.wash,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Column(
        children: [
          for (var i = 0; i < children.length; i++) ...[
            if (i > 0) const SizedBox(height: 12),
            children[i],
          ],
        ],
      ),
    );
  }
}

class _Fact extends StatelessWidget {
  const _Fact({required this.label, required this.value, this.onTap});

  final String label;
  final String value;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final row = Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 110,
          child: Text(
            label,
            style: const TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w700,
              color: AppColors.mute,
            ),
          ),
        ),
        Expanded(
          child: Text(
            value,
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w800,
              color: onTap != null ? AppColors.ink : AppColors.ink,
              decoration: onTap != null ? TextDecoration.underline : null,
              decorationColor: AppColors.mute,
            ),
          ),
        ),
        if (onTap != null)
          const AppIcon(AppIcons.chevronRight, size: 16, color: AppColors.mute),
      ],
    );
    if (onTap == null) return row;
    return InkWell(onTap: onTap, child: row);
  }
}

class _VoidPaymentDrawer extends StatefulWidget {
  const _VoidPaymentDrawer();

  @override
  State<_VoidPaymentDrawer> createState() => _VoidPaymentDrawerState();
}

class _VoidPaymentDrawerState extends State<_VoidPaymentDrawer> {
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
              'Void payment?',
              style: TextStyle(fontSize: 22, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 8),
            const Text(
              'Every invoice it paid will owe that money again, and its credit is removed. This cannot be undone.',
              style: TextStyle(
                fontWeight: FontWeight.w600,
                color: AppColors.mute,
                height: 1.4,
              ),
            ),
            const SizedBox(height: 16),
            TextFormField(
              key: const Key('void_reason'),
              controller: _reason,
              maxLines: 2,
              decoration: const InputDecoration(
                labelText: 'Reason',
                hintText: 'e.g. Entered twice, wrong school',
              ),
              validator: (v) => Validators.required(v, label: 'A reason'),
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.of(context).pop(),
                    child: const Text('Keep'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: FilledButton(
                    key: const Key('void_confirm'),
                    style: FilledButton.styleFrom(
                      backgroundColor: AppColors.danger,
                    ),
                    onPressed: () {
                      if (_formKey.currentState!.validate()) {
                        Navigator.of(context).pop(_reason.text.trim());
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
