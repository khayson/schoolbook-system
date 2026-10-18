import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/exchanges/data/exchanges_repository.dart';
import 'package:schoolbook/features/exchanges/domain/stock_exchange.dart';
import 'package:schoolbook/features/exchanges/presentation/exchange_actions_controller.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';
import 'package:schoolbook/shared/widgets/loading_overlay.dart';

class ExchangeDetailScreen extends StatefulWidget {
  const ExchangeDetailScreen({super.key, required this.exchangeId});

  final int exchangeId;

  @override
  State<ExchangeDetailScreen> createState() => _ExchangeDetailScreenState();
}

class _ExchangeDetailScreenState extends State<ExchangeDetailScreen> {
  late final ExchangeActionsController _actions;
  StockExchange? _exchange;
  String? _error;
  bool _loading = true;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _actions = ExchangeActionsController(
      exchanges: context.read<ExchangesRepository>(),
      pendingStore: context.read<PendingSubmissionStore>(),
    );
    _load();
  }

  @override
  void dispose() {
    _actions.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final exchange = await context.read<ExchangesRepository>().getExchange(
        widget.exchangeId,
      );
      if (mounted) setState(() => _exchange = exchange);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _confirm({
    ExchangeSettlement? settlement,
    bool overrideCreditLimit = false,
  }) async {
    final exchange = _exchange;
    if (exchange == null || !exchange.isDraft) return;

    var chosen = settlement ?? exchange.settlement;
    if (chosen == null) {
      chosen = await showDialog<ExchangeSettlement>(
        context: context,
        builder: (ctx) => SimpleDialog(
          title: const Text('How they pay'),
          children: [
            for (final s in ExchangeSettlement.values)
              SimpleDialogOption(
                onPressed: () => Navigator.pop(ctx, s),
                child: ListTile(
                  title: Text(s.label),
                  subtitle: Text(s.description),
                ),
              ),
          ],
        ),
      );
      if (chosen == null || !mounted) return;
    }

    setState(() => _busy = true);
    final outcome = await _actions.confirm(
      exchange,
      settlement: chosen,
      overrideCreditLimit: overrideCreditLimit,
    );
    if (!mounted) return;
    setState(() => _busy = false);

    switch (outcome) {
      case ExchangeConfirmed(:final exchange):
        setState(() => _exchange = exchange);
        AppToast.show(
          context,
          'Confirmed ${exchange.displayNo}',
          type: ToastType.success,
        );
        if (exchange.saleId != null) {
          final cash = exchange.settlement == ExchangeSettlement.cash;
          final goSale = await showDialog<bool>(
            context: context,
            builder: (ctx) => AlertDialog(
              title: Text(cash ? 'Record cash payment' : 'Invoice created'),
              content: Text(
                cash
                    ? 'Cash and carry: open the sale and record the full payment now.'
                    : '${exchange.saleInvoiceNo ?? 'Invoice'} · ${Money.formatPesewas(exchange.saleTotal ?? 0)}. '
                        '${exchange.settlement?.label ?? ''}. Open sale for payments?',
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx, false),
                  child: const Text('Stay here'),
                ),
                FilledButton(
                  onPressed: () => Navigator.pop(ctx, true),
                  child: Text(cash ? 'Record payment' : 'Open sale'),
                ),
              ],
            ),
          );
          if (goSale == true && mounted) {
            if (cash) {
              context.push('/customers/${exchange.customerId}/pay');
            } else {
              context.push('/sales/${exchange.saleId}');
            }
          }
        }
      case ExchangeCreditWarning(
        :final creditLimit,
        :final outstanding,
        :final saleTotal,
        :final projectedBalance,
      ):
        final proceed = await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('Credit limit warning'),
            content: Text(
              'Limit ${Money.formatPesewas(creditLimit)}, outstanding ${Money.formatPesewas(outstanding)}, '
              'this sale ${Money.formatPesewas(saleTotal)} → projected ${Money.formatPesewas(projectedBalance)}.\n\n'
              'Confirm anyway?',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx, false),
                child: const Text('Cancel'),
              ),
              FilledButton(
                onPressed: () => Navigator.pop(ctx, true),
                child: const Text('Confirm anyway'),
              ),
            ],
          ),
        );
        if (proceed == true && mounted) {
          await _confirm(
            settlement: chosen,
            overrideCreditLimit: true,
          );
        }
      case ExchangeStockShort(:final items):
        await showDialog<void>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('Not enough stock'),
            content: Text(
              items
                  .map(
                    (i) =>
                        '${i.title}: need ${i.requested}, have ${i.available}',
                  )
                  .join('\n'),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: const Text('OK'),
              ),
            ],
          ),
        );
      case ExchangeConfirmFailed(:final error):
        setState(() => _error = describeApiError(error).body);
    }
  }

  Future<void> _cancelDraft() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Cancel draft?'),
        content: const Text('This deletes the draft exchange.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Keep'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Cancel draft'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    setState(() => _busy = true);
    try {
      await context.read<ExchangesRepository>().cancel(widget.exchangeId);
      if (!mounted) return;
      AppToast.show(context, 'Draft cancelled', type: ToastType.success);
      context.go('/stock/exchanges');
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _error = e.message;
          _busy = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final exchange = _exchange;
    final bottom = MediaQuery.paddingOf(context).bottom;

    return LoadingOverlay(
      isLoading: _busy || _actions.busy,
      child: AppPage(
        title: exchange?.displayNo ?? 'Exchange',
        subtitle: exchange == null
            ? null
            : '${exchange.counterpartyName} · ${exchange.settlement?.label ?? 'Settlement?'}',
        actions: [
          if (exchange?.isDraft == true)
            IconButton(
              tooltip: 'Edit draft',
              onPressed: () async {
                await context.push(
                  '/stock/exchanges/${widget.exchangeId}/edit',
                );
                if (mounted) _load();
              },
              icon: const Icon(Icons.edit_outlined),
            ),
        ],
        body: _loading
            ? const LoadingBody()
            : _error != null && exchange == null
                ? ErrorState(message: _error!, onRetry: _load)
                : exchange == null
                    ? const EmptyState(title: 'Not found')
                    : ListView(
                        padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 24),
                        children: [
                          if (_error != null) ...[
                            Text(
                              _error!,
                              style: const TextStyle(
                                color: Colors.red,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            const SizedBox(height: 12),
                          ],
                          const Text(
                            'Who',
                            style: TextStyle(
                              fontWeight: FontWeight.w900,
                              fontSize: 16,
                            ),
                          ),
                          _MetaRow(
                            'Person',
                            exchange.customer == null
                                ? 'Customer #${exchange.customerId}'
                                : '${exchange.customer!.name}'
                                    '${exchange.customer!.type.isNotEmpty ? ' (${exchange.customer!.type})' : ''}',
                          ),
                          _MetaRow('Paper name', exchange.counterpartyName),
                          _MetaRow(
                            'Settlement',
                            exchange.settlement?.label ?? 'Not chosen yet',
                          ),
                          _MetaRow('Status', exchange.status),
                          const SizedBox(height: 16),
                          _BookSide(
                            title: 'They gave us (Taken)',
                            items: exchange.takenItems,
                            copies: exchange.takenCopies,
                            showCost: true,
                          ),
                          const SizedBox(height: 12),
                          _BookSide(
                            title: 'They took from us',
                            items: exchange.theyTookItems,
                            copies: exchange.theyTookCopies,
                            showPrice: true,
                          ),
                          if (exchange.saleInvoiceNo != null) ...[
                            const SizedBox(height: 16),
                            _MetaRow('Invoice', exchange.saleInvoiceNo!),
                            if (exchange.saleTotal != null)
                              _MetaRow(
                                'Invoice total',
                                Money.formatPesewas(exchange.saleTotal!),
                              ),
                          ],
                          if (exchange.receiptNo != null)
                            _MetaRow('Taken GRN', exchange.receiptNo!),
                          const SizedBox(height: 20),
                          if (exchange.isDraft) ...[
                            FilledButton(
                              onPressed: () => _confirm(),
                              child: const Text('Confirm exchange'),
                            ),
                            const SizedBox(height: 8),
                            OutlinedButton(
                              onPressed: _cancelDraft,
                              child: const Text('Cancel draft'),
                            ),
                          ],
                          if (exchange.isConfirmed && exchange.saleId != null) ...[
                            FilledButton(
                              onPressed: () =>
                                  context.push('/sales/${exchange.saleId}'),
                              child: const Text('Open sale / installments'),
                            ),
                            if ((exchange.saleBalanceDue ?? 0) > 0) ...[
                              const SizedBox(height: 8),
                              OutlinedButton(
                                onPressed: () => context.push(
                                  '/customers/${exchange.customerId}/pay',
                                ),
                                child: const Text('Record payment'),
                              ),
                            ],
                          ],
                        ],
                      ),
      ),
    );
  }
}

class _BookSide extends StatelessWidget {
  const _BookSide({
    required this.title,
    required this.items,
    required this.copies,
    this.showPrice = false,
    this.showCost = false,
  });

  final String title;
  final List<StockExchangeItem> items;
  final int copies;
  final bool showPrice;
  final bool showCost;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '$title · $copies copies',
          style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15),
        ),
        const SizedBox(height: 6),
        if (items.isEmpty)
          const Text('None', style: TextStyle(color: AppColors.mute))
        else
          ...items.map(
            (item) => Container(
              margin: const EdgeInsets.only(bottom: 8),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                border: Border.all(color: AppColors.line),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          item.productTitle,
                          style: const TextStyle(fontWeight: FontWeight.w800),
                        ),
                        if (showPrice && item.unitPrice != null)
                          Text(
                            Money.formatPesewas(item.unitPrice!),
                            style: const TextStyle(
                              color: AppColors.mute,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        if (showCost && item.unitCost != null)
                          Text(
                            'Cost ${Money.formatPesewas(item.unitCost!)}',
                            style: const TextStyle(
                              color: AppColors.mute,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                      ],
                    ),
                  ),
                  Text(
                    '× ${item.quantity}',
                    style: const TextStyle(
                      fontWeight: FontWeight.w900,
                      fontSize: 18,
                    ),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}

class _MetaRow extends StatelessWidget {
  const _MetaRow(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 110,
            child: Text(
              label,
              style: const TextStyle(
                color: AppColors.mute,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
          ),
        ],
      ),
    );
  }
}
