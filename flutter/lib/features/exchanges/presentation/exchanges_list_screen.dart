import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/exchanges/data/exchanges_repository.dart';
import 'package:schoolbook/features/exchanges/domain/stock_exchange.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

class ExchangesListScreen extends StatefulWidget {
  const ExchangesListScreen({super.key});

  @override
  State<ExchangesListScreen> createState() => _ExchangesListScreenState();
}

class _ExchangesListScreenState extends State<ExchangesListScreen> {
  List<StockExchange> _rows = [];
  String? _error;
  bool _loading = true;
  String? _statusFilter;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final page = await context.read<ExchangesRepository>().listExchanges(
        status: _statusFilter,
      );
      if (mounted) setState(() => _rows = page.data);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;

    return AppPage(
      title: 'Book exchanges',
      subtitle: 'Who · books & qty · cash / installment / pay later',
      actions: [
        IconButton(
          tooltip: 'New exchange',
          onPressed: () async {
            await context.push('/stock/exchanges/new');
            if (mounted) _load();
          },
          icon: const AppIcon(AppIcons.add, size: 22),
        ),
      ],
      body: Column(
        children: [
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 4),
            child: Row(
              children: [
                for (final entry in [
                  (null, 'All'),
                  ('draft', 'Draft'),
                  ('confirmed', 'Confirmed'),
                  ('void', 'Void'),
                ])
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: FilterChip(
                      label: Text(entry.$2),
                      selected: _statusFilter == entry.$1,
                      onSelected: (_) {
                        setState(() => _statusFilter = entry.$1);
                        _load();
                      },
                    ),
                  ),
              ],
            ),
          ),
          Expanded(
            child: _loading
                ? const LoadingBody()
                : _error != null
                    ? ErrorState(message: _error!, onRetry: _load)
                    : RefreshIndicator(
                        color: AppColors.ink,
                        backgroundColor: AppColors.lime,
                        onRefresh: _load,
                        child: _rows.isEmpty
                            ? ListView(
                                padding: EdgeInsets.only(bottom: bottom + 12),
                                children: [
                                  EmptyState(
                                    title: 'No exchanges yet',
                                    subtitle:
                                        'Record a dealer Taken / they-took swap.',
                                    actionLabel: 'New exchange',
                                    onAction: () =>
                                        context.push('/stock/exchanges/new'),
                                  ),
                                ],
                              )
                            : ListView.separated(
                                padding: EdgeInsets.fromLTRB(
                                  20,
                                  8,
                                  20,
                                  bottom + 16,
                                ),
                                itemCount: _rows.length,
                                separatorBuilder: (_, _) =>
                                    const SizedBox(height: 8),
                                itemBuilder: (context, i) {
                                  final e = _rows[i];
                                  return Material(
                                    color: AppColors.surface,
                                    borderRadius: BorderRadius.circular(12),
                                    child: InkWell(
                                      borderRadius: BorderRadius.circular(12),
                                      onTap: () async {
                                        await context.push(
                                          '/stock/exchanges/${e.id}',
                                        );
                                        if (mounted) _load();
                                      },
                                      child: Container(
                                        padding: const EdgeInsets.all(14),
                                        decoration: BoxDecoration(
                                          border: Border.all(
                                            color: AppColors.line,
                                          ),
                                          borderRadius: BorderRadius.circular(
                                            12,
                                          ),
                                        ),
                                        child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            Row(
                                              children: [
                                                Expanded(
                                                  child: Text(
                                                    e.displayNo,
                                                    style: const TextStyle(
                                                      fontWeight:
                                                          FontWeight.w800,
                                                      fontSize: 15,
                                                    ),
                                                  ),
                                                ),
                                                _StatusBadge(e.status),
                                              ],
                                            ),
                                            const SizedBox(height: 4),
                                            Text(
                                              e.counterpartyName,
                                              style: const TextStyle(
                                                fontWeight: FontWeight.w700,
                                              ),
                                            ),
                                            const SizedBox(height: 2),
                                              Text(
                                              '${e.customer?.name ?? 'Dealer #${e.customerId}'} · Taken ${e.takenCopies} · They took ${e.theyTookCopies}'
                                              '${e.settlement != null ? ' · ${e.settlement!.label}' : ''}',
                                              style: const TextStyle(
                                                fontSize: 12.5,
                                                fontWeight: FontWeight.w600,
                                                color: AppColors.mute,
                                              ),
                                            ),
                                          ],
                                        ),
                                      ),
                                    ),
                                  );
                                },
                              ),
                      ),
          ),
        ],
      ),
    );
  }
}

class _StatusBadge extends StatelessWidget {
  const _StatusBadge(this.status);

  final String status;

  @override
  Widget build(BuildContext context) {
    final color = switch (status) {
      'confirmed' => AppColors.lime,
      'void' => AppColors.mute,
      _ => AppColors.wash,
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: AppColors.line),
      ),
      child: Text(
        status,
        style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800),
      ),
    );
  }
}
