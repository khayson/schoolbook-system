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
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/domain/sale.dart';
import 'package:schoolbook/features/sales/presentation/sale_actions_controller.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';
import 'package:schoolbook/shared/widgets/insufficient_stock_drawer.dart';

class SaleDetailScreen extends StatefulWidget {
  const SaleDetailScreen({
    super.key,
    required this.saleId,
    this.openConfirm = false,
  });

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
    if (mounted) setState(() {});
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final sale = await context.read<SalesRepository>().getSale(widget.saleId);
      if (!mounted) return;
      setState(() => _sale = sale);
      if (widget.openConfirm && !_confirmOpened && sale.isDraft) {
        _confirmOpened = true;
        WidgetsBinding.instance.addPostFrameCallback((_) => _startConfirm());
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  void _snack(String text, {ToastType type = ToastType.info}) =>
      AppToast.show(context, text, type: type);

  void _showError(ApiException e) {
    final d = describeApiError(e);
    AppToast.show(context, d.body, title: d.title, type: ToastType.error);
  }

  Future<void> _startConfirm() async {
    final sale = _sale;
    if (sale == null) return;
    final choice = await showModalBottomSheet<_ConfirmChoice>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      builder: (_) => _ConfirmSheet(sale: sale),
    );
    if (choice != null) await _confirm(choice);
  }

  Future<void> _confirm(_ConfirmChoice choice) async {
    final sale = _sale!;
    final outcome = await _actions.confirm(
      sale,
      dueDate: choice.dueDate,
      applyCredit: choice.applyCredit,
      overrideCreditLimit: choice.overrideCreditLimit,
    );
    if (!mounted) return;

    switch (outcome) {
      case Confirmed(:final sale):
        setState(() => _sale = sale);
        _snack('Invoice ${sale.invoiceNo} issued', type: ToastType.success);
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
            await _confirm(choice);
          } on ApiException catch (e) {
            _showError(e);
          }
        }
      case StockShort(:final items):
        final retry = await showInsufficientStockDrawer(
          context,
          items: items,
        );
        if (retry == true && mounted) {
          await _confirm(choice);
        }
      case CreditWarning():
        final proceed = await showDialog<bool>(
          context: context,
          builder: (_) => CreditWarningDialog(warning: outcome),
        );
        if (proceed == true && mounted) {
          await _confirm(choice.withOverride());
        }
      case ConfirmFailed(:final error):
        _showError(error);
    }
  }

  Future<void> _cancel() async {
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => const CancelDraftDialog(),
    );
    if (reason == null) return;
    await _act(
      SaleAction.cancel,
      () => _actions.cancel(_sale!, reason: reason.isEmpty ? null : reason),
      'Draft cancelled',
    );
  }

  Future<void> _void() async {
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => const ReasonDialog(
        title: 'Void this invoice?',
        message:
            'The books go back into stock and any payments on it become customer credit. This cannot be undone.',
        action: 'Void invoice',
      ),
    );
    if (reason == null) return;
    await _act(
      SaleAction.voidSale,
      () => _actions.voidSale(_sale!, reason),
      'Invoice voided',
    );
  }

  Future<void> _deliver() async {
    final ok = await showModalBottomSheet<bool>(
      context: context,
      backgroundColor: AppColors.surface,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (context) {
        final bottom = MediaQuery.paddingOf(context).bottom;
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
                'Mark as delivered?',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 8),
              const Text(
                'After delivery the invoice can no longer be voided.',
                style: TextStyle(
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
                      child: const Text('Not yet'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    flex: 2,
                    child: FilledButton(
                      key: const Key('deliver_confirm'),
                      onPressed: () => Navigator.pop(context, true),
                      child: const Text('Delivered'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    );
    if (ok == true) {
      await _act(
        SaleAction.deliver,
        () => _actions.deliver(_sale!),
        'Marked as delivered',
      );
    }
  }

  Future<void> _act(
    SaleAction kind,
    Future<Sale> Function() action,
    String done,
  ) async {
    try {
      final sale = await action();
      if (!mounted) return;
      setState(() => _sale = sale);
      _snack(done, type: ToastType.success);
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      if (isStateConflict(e)) {
        await _load();
        if (mounted) {
          _snack(alreadyChangedMessage(kind, _sale), type: ToastType.warning);
        }
        return;
      }
      _showError(e);
    }
  }

  Future<void> _shareInvoice() async {
    final sale = _sale!;
    try {
      final bytes = await context.read<SalesRepository>().invoicePdf(sale.id);
      if (!mounted) return;
      await context.read<PdfSharer>().sharePdf(
        bytes,
        fileName: '${sale.invoiceNo}.pdf',
        subject: 'Invoice ${sale.invoiceNo}',
      );
    } on ApiException catch (e) {
      if (mounted) _showError(e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final sale = _sale;
    final top = MediaQuery.paddingOf(context).top;
    final bottom = MediaQuery.paddingOf(context).bottom;

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: _error != null
          ? ErrorState(message: _error!, onRetry: _load)
          : sale == null
          ? const LoadingBody()
          : Column(
              children: [
                Expanded(
                  child: RefreshIndicator(
                    color: AppColors.ink,
                    backgroundColor: AppColors.lime,
                    onRefresh: _load,
                    child: _details(sale, top),
                  ),
                ),
                if (sale.isDraft || sale.isConfirmed)
                  _ActionBar(
                    sale: sale,
                    busy: _actions.busy,
                    bottom: bottom,
                    onConfirm: _startConfirm,
                    onCancel: _cancel,
                    onPay: () async {
                      await context.push('/customers/${sale.customerId}/pay');
                      _load();
                    },
                    onDeliver: _deliver,
                    onVoid: _void,
                  ),
              ],
            ),
    );
  }

  Widget _details(Sale sale, double top) {
    return ListView(
      padding: EdgeInsets.fromLTRB(20, top + 4, 20, 24),
      children: [
        Row(
          children: [
            IconButton(
              onPressed: () => Navigator.of(context).maybePop(),
              icon: const AppIcon(AppIcons.back),
            ),
            Expanded(
              child: Text(
                sale.label,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 17,
                  fontWeight: FontWeight.w900,
                  letterSpacing: -0.2,
                ),
              ),
            ),
            if (sale.isDraft)
              IconButton(
                key: const Key('sale_edit_draft'),
                tooltip: 'Edit draft',
                onPressed: () async {
                  await context.push('/sales/${sale.id}/edit');
                  if (mounted) _load();
                },
                icon: const AppIcon(AppIcons.edit),
              )
            else if (sale.isConfirmed)
              IconButton(
                key: const Key('sale_share_invoice'),
                tooltip: 'Share invoice',
                onPressed: _shareInvoice,
                icon: const AppIcon(AppIcons.share),
              )
            else
              const SizedBox(width: 48),
          ],
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            _StatusChip(
              label: SaleStatuses.label(sale.status),
              filled: sale.isDraft,
            ),
            if (sale.isConfirmed)
              _StatusChip(
                label: SaleStatuses.paymentLabel(sale.paymentStatus),
              ),
            if (sale.deliveredAt != null)
              const _StatusChip(label: 'Delivered', accent: true),
          ],
        ),
        const SizedBox(height: 18),
        Text(
          sale.customer?.name ?? 'Customer #${sale.customerId}',
          style: const TextStyle(
            fontSize: 26,
            fontWeight: FontWeight.w900,
            letterSpacing: -0.5,
            height: 1.15,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          'Sale date ${DateFormat('d MMM y').format(sale.saleDate)}'
          '${sale.dueDate == null ? '' : ' · due ${DateFormat('d MMM y').format(sale.dueDate!)}'}',
          style: const TextStyle(
            fontSize: 13,
            fontWeight: FontWeight.w600,
            color: AppColors.mute,
          ),
        ),
        if (sale.voidReason != null) ...[
          const SizedBox(height: 10),
          _NoteBanner(
            text: 'Void: ${sale.voidReason}',
            danger: true,
          ),
        ],
        if (sale.cancelReason != null) ...[
          const SizedBox(height: 10),
          _NoteBanner(text: 'Cancelled: ${sale.cancelReason}'),
        ],
        const SizedBox(height: 24),
        const _SectionTitle('Books'),
        const SizedBox(height: 10),
        Container(
          decoration: BoxDecoration(
            color: AppColors.wash,
            borderRadius: BorderRadius.circular(22),
          ),
          child: Column(
            children: [
              for (var i = 0; i < sale.items.length; i++) ...[
                if (i > 0)
                  const Divider(height: 1, color: AppColors.line),
                _BookLine(line: sale.items[i]),
              ],
            ],
          ),
        ),
        const SizedBox(height: 16),
        Container(
          padding: const EdgeInsets.fromLTRB(18, 18, 18, 16),
          decoration: BoxDecoration(
            color: AppColors.ink,
            borderRadius: BorderRadius.circular(22),
          ),
          child: Column(
            children: [
              _InkAmountRow(label: 'Total', value: sale.total, emphasize: true),
              if (!sale.isDraft) ...[
                const SizedBox(height: 10),
                _InkAmountRow(label: 'Paid', value: sale.amountPaid),
                const SizedBox(height: 10),
                _InkAmountRow(
                  label: 'Balance due',
                  value: sale.balanceDue,
                  emphasize: true,
                  lime: sale.balanceDue > 0,
                ),
              ],
            ],
          ),
        ),
        if (sale.allocations.isNotEmpty) ...[
          const SizedBox(height: 24),
          const _SectionTitle('Payments applied'),
          const SizedBox(height: 10),
          ...sale.allocations.map(
            (a) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Material(
                color: AppColors.wash,
                borderRadius: BorderRadius.circular(18),
                child: ListTile(
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(18),
                  ),
                  title: Text(
                    a.receiptNo ?? 'Payment #${a.id}',
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                  subtitle: a.isReversal
                      ? const Text(
                          'Reversal',
                          style: TextStyle(
                            fontWeight: FontWeight.w600,
                            color: AppColors.mute,
                          ),
                        )
                      : null,
                  trailing: Text(
                    Money.formatPesewas(a.amount),
                    style: const TextStyle(
                      fontWeight: FontWeight.w900,
                      fontFeatures: [FontFeature.tabularFigures()],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ],
    );
  }
}

class _ActionBar extends StatelessWidget {
  const _ActionBar({
    required this.sale,
    required this.busy,
    required this.bottom,
    required this.onConfirm,
    required this.onCancel,
    required this.onPay,
    required this.onDeliver,
    required this.onVoid,
  });

  final Sale sale;
  final bool busy;
  final double bottom;
  final VoidCallback onConfirm;
  final VoidCallback onCancel;
  final VoidCallback onPay;
  final VoidCallback onDeliver;
  final VoidCallback onVoid;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 16),
      decoration: const BoxDecoration(
        color: AppColors.surface,
        border: Border(top: BorderSide(color: AppColors.line)),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (sale.isDraft)
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    key: const Key('sale_cancel'),
                    onPressed: busy ? null : onCancel,
                    child: const Text('Cancel'),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: FilledButton.icon(
                    key: const Key('sale_confirm'),
                    onPressed: busy ? null : onConfirm,
                    icon: const AppIcon(
                      AppIcons.check,
                      size: 20,
                      color: Colors.white,
                    ),
                    label: const Text('Confirm'),
                  ),
                ),
              ],
            ),
          if (sale.isConfirmed) _ConfirmedActions(
            sale: sale,
            busy: busy,
            onPay: onPay,
            onDeliver: onDeliver,
            onVoid: onVoid,
          ),
        ],
      ),
    );
  }
}

class _ConfirmedActions extends StatelessWidget {
  const _ConfirmedActions({
    required this.sale,
    required this.busy,
    required this.onPay,
    required this.onDeliver,
    required this.onVoid,
  });

  final Sale sale;
  final bool busy;
  final VoidCallback onPay;
  final VoidCallback onDeliver;
  final VoidCallback onVoid;

  @override
  Widget build(BuildContext context) {
    final showPay = sale.balanceDue > 0;
    final showDeliver = sale.deliveredAt == null;
    final showVoid = sale.canVoid;
    final showMore = showDeliver || showVoid;

    if (!showPay && !showMore) return const SizedBox.shrink();

    return Row(
      children: [
        if (showPay)
          Expanded(
            child: FilledButton.icon(
              key: const Key('sale_record_payment'),
              onPressed: busy ? null : onPay,
              icon: const AppIcon(
                AppIcons.payment,
                size: 20,
                color: Colors.white,
              ),
              label: const Text('Pay'),
            ),
          ),
        if (showPay && showMore) const SizedBox(width: 10),
        if (!showPay && showMore) const Spacer(),
        if (showMore)
          _MoreActionsCircle(
            busy: busy,
            showDeliver: showDeliver,
            showVoid: showVoid,
            onDeliver: onDeliver,
            onVoid: onVoid,
          ),
      ],
    );
  }
}

enum _MorePick { deliver, voidInvoice }

class _MoreActionsCircle extends StatelessWidget {
  const _MoreActionsCircle({
    required this.busy,
    required this.showDeliver,
    required this.showVoid,
    required this.onDeliver,
    required this.onVoid,
  });

  final bool busy;
  final bool showDeliver;
  final bool showVoid;
  final VoidCallback onDeliver;
  final VoidCallback onVoid;

  Future<void> _open(BuildContext context) async {
    final box = context.findRenderObject() as RenderBox?;
    final overlay =
        Navigator.of(context).overlay?.context.findRenderObject() as RenderBox?;
    if (box == null || overlay == null) return;

    final origin = box.localToGlobal(Offset.zero, ancestor: overlay);
    final size = box.size;
    final screen = overlay.size;
    const menuWidth = 228.0;
    final right = (screen.width - origin.dx - size.width).clamp(
      12.0,
      screen.width - menuWidth - 12,
    );
    final bottom = screen.height - origin.dy + 10;

    final pick = await showGeneralDialog<_MorePick>(
      context: context,
      barrierDismissible: true,
      barrierLabel: 'Dismiss',
      barrierColor: Colors.black.withValues(alpha: 0.22),
      transitionDuration: const Duration(milliseconds: 160),
      pageBuilder: (ctx, anim, _) {
        return Stack(
          children: [
            Positioned(
              right: right,
              bottom: bottom,
              width: menuWidth,
              child: Material(
                color: AppColors.surface,
                elevation: 10,
                shadowColor: Colors.black26,
                borderRadius: BorderRadius.circular(20),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(8, 10, 8, 8),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      if (showDeliver)
                        _MoreMenuTile(
                          key: const Key('sale_deliver'),
                          icon: AppIcons.delivery,
                          label: 'Mark delivered',
                          onTap: () =>
                              Navigator.pop(ctx, _MorePick.deliver),
                        ),
                      if (showDeliver && showVoid) const SizedBox(height: 4),
                      if (showVoid)
                        _MoreMenuTile(
                          key: const Key('sale_void'),
                          icon: AppIcons.delete,
                          label: 'Void invoice',
                          danger: true,
                          onTap: () =>
                              Navigator.pop(ctx, _MorePick.voidInvoice),
                        ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        );
      },
      transitionBuilder: (context, anim, _, child) {
        return FadeTransition(
          opacity: CurvedAnimation(parent: anim, curve: Curves.easeOut),
          child: child,
        );
      },
    );

    if (pick == _MorePick.deliver) onDeliver();
    if (pick == _MorePick.voidInvoice) onVoid();
  }

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.wash,
      shape: const CircleBorder(),
      child: InkWell(
        key: const Key('sale_more_actions'),
        customBorder: const CircleBorder(),
        onTap: busy ? null : () => _open(context),
        child: const SizedBox(
          width: 52,
          height: 52,
          child: Center(
            child: AppIcon(AppIcons.moreVertical, size: 22),
          ),
        ),
      ),
    );
  }
}

class _MoreMenuTile extends StatelessWidget {
  const _MoreMenuTile({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    this.danger = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final color = danger ? AppColors.danger : AppColors.ink;
    return Material(
      color: AppColors.wash,
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
          child: Row(
            children: [
              AppIcon(icon, size: 20, color: color),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  label,
                  style: TextStyle(
                    fontWeight: FontWeight.w800,
                    color: color,
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

class _BookLine extends StatelessWidget {
  const _BookLine({required this.line});

  final SaleLine line;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  line.productTitle,
                  style: const TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  '${line.quantity} × ${Money.formatPesewas(line.unitPrice)}'
                  '${line.isPriceOverridden ? ' · override' : ''}',
                  style: const TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: AppColors.mute,
                  ),
                ),
                if (line.hasDiscount)
                  Text(
                    'Was ${Money.formatPesewas(line.basePrice)}'
                    '${line.discountAmount > 0 ? ' · save ${Money.formatPesewas(line.discountAmount)}' : ''}',
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: AppColors.mute,
                    ),
                  ),
                if (line.appliedRules.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                      line.appliedRules.map((r) => r.name).join(' · '),
                      style: const TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                        color: AppColors.ink,
                      ),
                    ),
                  ),
                if (line.isPriceOverridden && line.overrideReason != null)
                  Text(
                    line.overrideReason!,
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: AppColors.warn,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Text(
            Money.formatPesewas(line.lineTotal),
            style: const TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w900,
              fontFeatures: [FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }
}

class _InkAmountRow extends StatelessWidget {
  const _InkAmountRow({
    required this.label,
    required this.value,
    this.emphasize = false,
    this.lime = false,
  });

  final String label;
  final int value;
  final bool emphasize;
  final bool lime;

  @override
  Widget build(BuildContext context) {
    final color = lime ? AppColors.lime : Colors.white;
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          label,
          style: TextStyle(
            fontSize: emphasize ? 15 : 13,
            fontWeight: FontWeight.w700,
            color: Colors.white.withValues(alpha: emphasize ? 0.9 : 0.55),
          ),
        ),
        Text(
          Money.formatPesewas(value),
          style: TextStyle(
            fontSize: emphasize ? 20 : 14,
            fontWeight: FontWeight.w900,
            color: color,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
      ],
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({
    required this.label,
    this.filled = false,
    this.accent = false,
  });

  final String label;
  final bool filled;
  final bool accent;

  @override
  Widget build(BuildContext context) {
    final bg = accent
        ? AppColors.lime
        : filled
            ? AppColors.ink
            : AppColors.wash;
    final fg = accent
        ? AppColors.ink
        : filled
            ? AppColors.lime
            : AppColors.mute;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(999),
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

class _NoteBanner extends StatelessWidget {
  const _NoteBanner({required this.text, this.danger = false});

  final String text;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: danger ? const Color(0xFFFFE4E6) : AppColors.wash,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Text(
        text,
        style: TextStyle(
          fontWeight: FontWeight.w700,
          color: danger ? AppColors.danger : AppColors.mute,
        ),
      ),
    );
  }
}

enum SaleAction { cancel, voidSale, deliver }

bool isStateConflict(ApiException e) =>
    e.code == 'sale_not_editable' ||
    e.code == 'sale_state_conflict' ||
    e.code == 'sale_delivered';

String alreadyChangedMessage(SaleAction action, Sale? sale) {
  if (sale != null) {
    switch (action) {
      case SaleAction.voidSale:
        if (sale.status == 'void') {
          return 'This invoice was already voided.';
        }
        if (sale.deliveredAt != null) {
          return 'This invoice has been delivered, so it can no longer be voided.';
        }
      case SaleAction.cancel:
        if (sale.status == 'cancelled') {
          return 'This draft was already cancelled.';
        }
        if (sale.status == 'confirmed') {
          return 'This sale was confirmed meanwhile, so it can no longer be cancelled.';
        }
      case SaleAction.deliver:
        if (sale.deliveredAt != null) {
          return 'This invoice was already marked as delivered.';
        }
        if (sale.status == 'void') {
          return 'This invoice was voided, so it cannot be delivered.';
        }
    }
  }
  return 'This sale changed in the meantime. Showing its current state.';
}

class _ConfirmChoice {
  const _ConfirmChoice({
    this.dueDate,
    this.applyCredit = false,
    this.overrideCreditLimit = false,
  });

  final DateTime? dueDate;
  final bool applyCredit;
  final bool overrideCreditLimit;

  _ConfirmChoice withOverride() => _ConfirmChoice(
    dueDate: dueDate,
    applyCredit: applyCredit,
    overrideCreditLimit: true,
  );
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
    final bottom = MediaQuery.paddingOf(context).bottom;
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
          Text(
            'Confirm ${Money.formatPesewas(widget.sale.total)}',
            style: const TextStyle(
              fontSize: 22,
              fontWeight: FontWeight.w900,
              letterSpacing: -0.3,
            ),
          ),
          const SizedBox(height: 8),
          const Text(
            'Issues the invoice number and takes the books out of stock.',
            style: TextStyle(
              fontWeight: FontWeight.w600,
              color: AppColors.mute,
              height: 1.4,
            ),
          ),
          const SizedBox(height: 18),
          const Text(
            'WHEN SHOULD THEY PAY?',
            style: TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w800,
              letterSpacing: 0.8,
              color: AppColors.mute,
            ),
          ),
          const SizedBox(height: 8),
          const Text(
            'This is the payment due date on the invoice — when the school is expected to settle the balance.',
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: AppColors.mute,
              height: 1.4,
            ),
          ),
          const SizedBox(height: 12),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 14),
            decoration: BoxDecoration(
              color: AppColors.wash,
              borderRadius: BorderRadius.circular(18),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Payment due by',
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: AppColors.mute,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  _dueDate == null
                      ? 'Your usual payment terms'
                      : DateFormat('EEEE, d MMM y').format(_dueDate!),
                  style: const TextStyle(
                    fontSize: 17,
                    fontWeight: FontWeight.w900,
                    letterSpacing: -0.2,
                  ),
                ),
                if (_dueDate == null) ...[
                  const SizedBox(height: 4),
                  const Text(
                    'Set on the web admin. Leave this if that is fine.',
                    style: TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w600,
                      color: AppColors.mute,
                    ),
                  ),
                ],
                const SizedBox(height: 14),
                OutlinedButton.icon(
                  onPressed: () async {
                    final now = DateTime.now();
                    final picked = await showDatePicker(
                      context: context,
                      helpText: 'Payment due by',
                      initialDate: _dueDate ?? now.add(const Duration(days: 30)),
                      firstDate: DateTime(now.year - 1),
                      lastDate: DateTime(now.year + 2),
                    );
                    if (picked != null) setState(() => _dueDate = picked);
                  },
                  icon: const AppIcon(AppIcons.edit, size: 18),
                  label: Text(
                    _dueDate == null ? 'Pick a due date' : 'Change due date',
                  ),
                ),
                if (_dueDate != null) ...[
                  const SizedBox(height: 8),
                  TextButton(
                    onPressed: () => setState(() => _dueDate = null),
                    child: const Text(
                      'Use usual payment terms instead',
                      style: TextStyle(fontWeight: FontWeight.w800),
                    ),
                  ),
                ],
              ],
            ),
          ),
          if (credit > 0) ...[
            const SizedBox(height: 10),
            Material(
              color: AppColors.wash,
              borderRadius: BorderRadius.circular(18),
              child: SwitchListTile(
                key: const Key('confirm_apply_credit'),
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 16,
                  vertical: 8,
                ),
                title: Text(
                  'Use school credit (${Money.formatPesewas(credit)})',
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
                subtitle: const Padding(
                  padding: EdgeInsets.only(top: 4),
                  child: Text(
                    'This school already has prepaid credit on account. '
                    'Turn on to put that credit toward this invoice so they '
                    'owe less (or nothing) when it is confirmed.',
                    style: TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w600,
                      color: AppColors.mute,
                      height: 1.35,
                    ),
                  ),
                ),
                activeThumbColor: AppColors.ink,
                activeTrackColor: AppColors.lime,
                value: _applyCredit,
                onChanged: (v) => setState(() => _applyCredit = v),
              ),
            ),
          ],
          const SizedBox(height: 16),
          FilledButton(
            key: const Key('confirm_submit'),
            onPressed: () => Navigator.of(context).pop(
              _ConfirmChoice(
                dueDate: _dueDate,
                applyCredit: credit > 0 && _applyCredit,
              ),
            ),
            child: const Text('Confirm'),
          ),
        ],
      ),
    );
  }
}

class PriceChangedDialog extends StatelessWidget {
  const PriceChangedDialog({
    super.key,
    required this.sale,
    required this.newOrder,
  });

  final Sale sale;
  final PricedOrder newOrder;

  @override
  Widget build(BuildContext context) {
    final changes = <String>[];
    for (var i = 0; i < newOrder.lines.length; i++) {
      final line = newOrder.lines[i];
      final old =
          i < sale.items.length && sale.items[i].productId == line.productId
          ? sale.items[i]
          : null;
      if (old == null) {
        changes.add(
          '${line.productTitle}: now ${Money.formatPesewas(line.unitPrice)}',
        );
      } else if (old.unitPrice != line.unitPrice) {
        changes.add(
          '${line.productTitle}: ${Money.formatPesewas(old.unitPrice)} -> ${Money.formatPesewas(line.unitPrice)}',
        );
      }
    }
    return AlertDialog(
      key: const Key('price_changed_dialog'),
      backgroundColor: AppColors.surface,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
      title: const Text(
        'Prices changed',
        style: TextStyle(fontWeight: FontWeight.w900),
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Nothing was confirmed. Since this draft was saved:',
            style: TextStyle(
              fontWeight: FontWeight.w600,
              color: AppColors.mute,
            ),
          ),
          const SizedBox(height: 8),
          ...changes.map(
            (c) => Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Text(
                c,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'Total: ${Money.formatPesewas(sale.total)} -> ${Money.formatPesewas(newOrder.total)}',
            style: const TextStyle(fontWeight: FontWeight.w900),
          ),
          if (newOrder.discountTotal > 0) ...[
            const SizedBox(height: 4),
            Text(
              'Order discount −${Money.formatPesewas(newOrder.discountTotal)}',
              key: const Key('price_changed_discount'),
              style: const TextStyle(
                fontWeight: FontWeight.w700,
                color: AppColors.mute,
              ),
            ),
          ],
          if (newOrder.warnings.isNotEmpty) ...[
            const SizedBox(height: 8),
            ...newOrder.warnings.map(
              (w) => Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Text(
                  w,
                  style: const TextStyle(
                    fontWeight: FontWeight.w700,
                    color: AppColors.warn,
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context, false),
          child: const Text(
            'Not now',
            style: TextStyle(fontWeight: FontWeight.w800),
          ),
        ),
        FilledButton(
          key: const Key('accept_new_prices'),
          onPressed: () => Navigator.pop(context, true),
          child: const Text('Accept new prices'),
        ),
      ],
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
      backgroundColor: AppColors.surface,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
      title: const Text(
        'Over credit limit',
        style: TextStyle(fontWeight: FontWeight.w900),
      ),
      content: Text(
        'Owes ${Money.formatPesewas(w.outstanding)} + this sale ${Money.formatPesewas(w.saleTotal)}'
        '${w.creditApplied > 0 ? ' − credit ${Money.formatPesewas(w.creditApplied)}' : ''}'
        ' = ${Money.formatPesewas(w.projectedBalance)}, over the limit of ${Money.formatPesewas(w.creditLimit)}.',
        style: const TextStyle(
          fontWeight: FontWeight.w600,
          color: AppColors.mute,
          height: 1.4,
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context, false),
          child: const Text(
            'Back',
            style: TextStyle(fontWeight: FontWeight.w800),
          ),
        ),
        FilledButton(
          key: const Key('credit_override'),
          onPressed: () => Navigator.pop(context, true),
          child: const Text('Confirm anyway'),
        ),
      ],
    );
  }
}

/// Cancel a draft — optional note. Returns '' when confirmed with no note, null if dismissed.
class CancelDraftDialog extends StatefulWidget {
  const CancelDraftDialog({super.key});

  @override
  State<CancelDraftDialog> createState() => _CancelDraftDialogState();
}

class _CancelDraftDialogState extends State<CancelDraftDialog> {
  final _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      backgroundColor: AppColors.surface,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
      titlePadding: const EdgeInsets.fromLTRB(24, 24, 24, 0),
      contentPadding: const EdgeInsets.fromLTRB(24, 16, 24, 8),
      actionsPadding: const EdgeInsets.fromLTRB(20, 8, 20, 20),
      title: Column(
        children: [
          Container(
            width: 52,
            height: 52,
            decoration: const BoxDecoration(
              color: AppColors.wash,
              shape: BoxShape.circle,
            ),
            child: const Center(child: AppIcon(AppIcons.delete, size: 24)),
          ),
          const SizedBox(height: 14),
          const Text(
            'Cancel this draft?',
            textAlign: TextAlign.center,
            style: TextStyle(fontWeight: FontWeight.w900, fontSize: 20),
          ),
        ],
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Text(
            'The draft will be discarded. Stock is unchanged — nothing was reserved.',
            textAlign: TextAlign.center,
            style: TextStyle(
              fontWeight: FontWeight.w600,
              color: AppColors.mute,
              height: 1.4,
            ),
          ),
          const SizedBox(height: 16),
          TextField(
            key: const Key('reason_field'),
            controller: _reason,
            maxLines: 2,
            decoration: const InputDecoration(
              labelText: 'Note (optional)',
              hintText: 'e.g. Wrong school, will redo',
            ),
          ),
        ],
      ),
      actions: [
        Row(
          children: [
            Expanded(
              child: OutlinedButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('Keep draft'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: FilledButton(
                key: const Key('reason_submit'),
                style: FilledButton.styleFrom(
                  backgroundColor: AppColors.danger,
                ),
                onPressed: () => Navigator.pop(context, _reason.text.trim()),
                child: const Text('Cancel draft'),
              ),
            ),
          ],
        ),
      ],
    );
  }
}

/// Asks for a reason; returns it, or null when dismissed.
class ReasonDialog extends StatefulWidget {
  const ReasonDialog({
    super.key,
    required this.title,
    required this.action,
    this.message,
    this.required = true,
  });

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
      backgroundColor: AppColors.surface,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
      titlePadding: const EdgeInsets.fromLTRB(24, 24, 24, 0),
      contentPadding: const EdgeInsets.fromLTRB(24, 16, 24, 8),
      actionsPadding: const EdgeInsets.fromLTRB(20, 8, 20, 20),
      title: Text(
        widget.title,
        style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 20),
      ),
      content: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (widget.message != null) ...[
              Text(
                widget.message!,
                style: const TextStyle(
                  fontWeight: FontWeight.w600,
                  color: AppColors.mute,
                  height: 1.4,
                ),
              ),
              const SizedBox(height: 14),
            ],
            TextFormField(
              key: const Key('reason_field'),
              controller: _reason,
              maxLines: 2,
              decoration: InputDecoration(
                labelText: widget.required ? 'Reason' : 'Reason (optional)',
              ),
              validator: widget.required
                  ? (v) => Validators.required(v, label: 'A reason')
                  : null,
            ),
          ],
        ),
      ),
      actions: [
        Row(
          children: [
            Expanded(
              child: OutlinedButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('Back'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: FilledButton(
                key: const Key('reason_submit'),
                onPressed: () {
                  if (_formKey.currentState!.validate()) {
                    Navigator.pop(context, _reason.text.trim());
                  }
                },
                child: Text(widget.action),
              ),
            ),
          ],
        ),
      ],
    );
  }
}
