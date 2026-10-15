import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/domain/sale.dart';
import 'package:schoolbook/features/sales/presentation/sale_detail_screen.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';
import 'package:schoolbook/shared/widgets/list_row_actions.dart';

class SalesListController extends ChangeNotifier {
  SalesListController(this._repository);

  final SalesRepository _repository;

  List<Sale> sales = [];
  String? status;
  String? paymentStatus;
  bool loading = false;
  bool loadingMore = false;
  String? error;
  int _page = 1;
  int _lastPage = 1;

  bool get hasMore => _page < _lastPage;

  Future<void> load() async {
    loading = true;
    error = null;
    notifyListeners();
    try {
      final page = await _repository.listSales(
        status: status,
        paymentStatus: paymentStatus,
      );
      sales = page.data;
      _page = page.meta.currentPage;
      _lastPage = page.meta.lastPage;
    } on ApiException catch (e) {
      error = e.message;
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  Future<void> loadMore() async {
    if (!hasMore || loadingMore) {
      return;
    }
    loadingMore = true;
    notifyListeners();
    try {
      final page = await _repository.listSales(
        page: _page + 1,
        status: status,
        paymentStatus: paymentStatus,
      );
      sales = [...sales, ...page.data];
      _page = page.meta.currentPage;
      _lastPage = page.meta.lastPage;
    } on ApiException catch (e) {
      error = e.message;
    } finally {
      loadingMore = false;
      notifyListeners();
    }
  }

  Future<void> setFilters({String? status, String? paymentStatus}) async {
    this.status = status;
    this.paymentStatus = paymentStatus;
    await load();
  }
}

class SalesListScreen extends StatefulWidget {
  const SalesListScreen({super.key});

  @override
  State<SalesListScreen> createState() => _SalesListScreenState();
}

class _SalesListScreenState extends State<SalesListScreen> {
  late final SalesListController _controller;
  String _path = '';

  @override
  void initState() {
    super.initState();
    _controller = SalesListController(context.read<SalesRepository>())
      ..addListener(_onChanged)
      ..load();
  }

  @override
  void dispose() {
    _controller
      ..removeListener(_onChanged)
      ..dispose();
    super.dispose();
  }

  void _onChanged() {
    if (mounted) setState(() {});
  }

  Future<void> _openNew() async {
    await context.push('/sales/new');
    await _controller.load();
  }

  /// Reload when returning from new sale / detail (e.g. dock FAB → save draft).
  void _refreshIfReturned(String path) {
    if (_path.isNotEmpty && _path != '/sales' && path == '/sales') {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _controller.load();
      });
    }
    _path = path;
  }

  @override
  Widget build(BuildContext context) {
    final path = GoRouterState.of(context).uri.path;
    _refreshIfReturned(path);

    final c = _controller;
    final top = MediaQuery.paddingOf(context).top;
    final bottom = MediaQuery.paddingOf(context).bottom;

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: EdgeInsets.fromLTRB(20, top + 12, 16, 0),
            child: Row(
              children: [
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Sales',
                        style: TextStyle(
                          fontSize: 28,
                          fontWeight: FontWeight.w900,
                          letterSpacing: -0.6,
                          height: 1,
                        ),
                      ),
                      SizedBox(height: 6),
                      Text(
                        'Drafts and invoices',
                        style: TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w600,
                          color: AppColors.mute,
                        ),
                      ),
                    ],
                  ),
                ),
                Material(
                  color: AppColors.lime,
                  shape: const CircleBorder(),
                  child: InkWell(
                    key: const Key('sales_new'),
                    customBorder: const CircleBorder(),
                    onTap: _openNew,
                    child: const SizedBox(
                      width: 48,
                      height: 48,
                      child: Center(
                        child: AppIcon(AppIcons.add, size: 24, color: AppColors.ink),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Row(
              children: [
                Expanded(
                  child: _FilterDropdown(
                    key: const Key('sales_filter_status'),
                    label: 'Status',
                    value: _statusFilterValue(c),
                    options: const [
                      (null, 'All statuses'),
                      ('draft', 'Drafts'),
                      ('confirmed', 'Confirmed'),
                      ('void', 'Void'),
                      ('cancelled', 'Cancelled'),
                    ],
                    onChanged: (status) {
                      if (status == null) {
                        c.setFilters();
                      } else if (status == 'confirmed') {
                        c.setFilters(
                          status: 'confirmed',
                          paymentStatus: c.paymentStatus,
                        );
                      } else {
                        c.setFilters(status: status);
                      }
                    },
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _FilterDropdown(
                    key: const Key('sales_filter_payment'),
                    label: 'Payment',
                    value: c.paymentStatus,
                    options: const [
                      (null, 'Any payment'),
                      ('unpaid', 'Unpaid'),
                      ('partial', 'Part paid'),
                      ('paid', 'Paid'),
                    ],
                    onChanged: (payment) {
                      if (payment == null) {
                        c.setFilters(
                          status: c.status == 'confirmed' ? 'confirmed' : null,
                        );
                      } else {
                        c.setFilters(
                          status: 'confirmed',
                          paymentStatus: payment,
                        );
                      }
                    },
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 8),
          Expanded(child: _body(c, bottom)),
        ],
      ),
    );
  }

  Widget _body(SalesListController c, double bottom) {
    if (c.loading && c.sales.isEmpty) {
      return const LoadingBody(message: 'Loading sales…');
    }
    if (c.error != null && c.sales.isEmpty) {
      return ErrorState(message: c.error!, onRetry: c.load);
    }
    if (c.sales.isEmpty) {
      return EmptyState(
        title: 'No sales yet',
        subtitle: 'Tap + to draft an order.',
        actionLabel: 'New sale',
        onAction: _openNew,
      );
    }
    return RefreshIndicator(
      color: AppColors.ink,
      backgroundColor: AppColors.lime,
      onRefresh: c.load,
      child: ListView.separated(
        padding: EdgeInsets.fromLTRB(20, 8, 20, bottom + 12),
        itemCount: c.sales.length + (c.hasMore ? 1 : 0),
        separatorBuilder: (_, _) => const SizedBox(height: 10),
        itemBuilder: (context, i) {
          if (i == c.sales.length) {
            WidgetsBinding.instance.addPostFrameCallback((_) {
              if (context.mounted) c.loadMore();
            });
            return const Padding(
              padding: EdgeInsets.all(20),
              child: Center(
                child: SizedBox(
                  width: 22,
                  height: 22,
                  child: CircularProgressIndicator(strokeWidth: 2.4),
                ),
              ),
            );
          }
          final s = c.sales[i];
          return _SaleRow(
            key: Key('sale_${s.id}'),
            sale: s,
            onChanged: c.load,
          );
        },
      ),
    );
  }
}

class _SaleRow extends StatelessWidget {
  const _SaleRow({super.key, required this.sale, required this.onChanged});

  final Sale sale;
  final Future<void> Function() onChanged;

  List<ListRowAction> get _leading {
    if (sale.isDraft) {
      return const [
        ListRowAction(id: 'edit', label: 'Edit', icon: AppIcons.edit),
      ];
    }
    return const [];
  }

  List<ListRowAction> get _trailing {
    if (sale.isDraft) {
      return const [
        ListRowAction(
          id: 'cancel',
          label: 'Cancel',
          icon: AppIcons.delete,
          danger: true,
        ),
      ];
    }
    if (sale.isConfirmed && sale.balanceDue > 0) {
      return const [
        ListRowAction(id: 'pay', label: 'Pay', icon: AppIcons.payment),
      ];
    }
    return const [];
  }

  List<ListRowAction> get _menu {
    final actions = <ListRowAction>[
      const ListRowAction(id: 'open', label: 'Open', icon: AppIcons.visibility),
    ];
    if (sale.isDraft) {
      actions.addAll(const [
        ListRowAction(id: 'edit', label: 'Edit draft', icon: AppIcons.edit),
        ListRowAction(
          id: 'cancel',
          label: 'Cancel draft',
          icon: AppIcons.delete,
          danger: true,
        ),
      ]);
    }
    if (sale.isConfirmed && sale.balanceDue > 0) {
      actions.add(
        const ListRowAction(
          id: 'pay',
          label: 'Record payment',
          icon: AppIcons.payment,
        ),
      );
    }
    if (sale.isConfirmed && sale.deliveredAt == null) {
      actions.add(
        const ListRowAction(
          id: 'deliver',
          label: 'Mark delivered',
          icon: AppIcons.delivery,
        ),
      );
    }
    return actions;
  }

  Future<void> _open(BuildContext context) async {
    await context.push('/sales/${sale.id}');
    await onChanged();
  }

  Future<void> _handle(BuildContext context, String id) async {
    switch (id) {
      case 'open':
        await _open(context);
      case 'edit':
        await context.push('/sales/${sale.id}/edit');
        await onChanged();
      case 'pay':
        await context.push('/customers/${sale.customerId}/pay');
        await onChanged();
      case 'cancel':
        await _cancelDraft(context);
      case 'deliver':
        await _deliver(context);
    }
  }

  Future<void> _deliver(BuildContext context) async {
    final ok = await showModalBottomSheet<bool>(
      context: context,
      backgroundColor: AppColors.surface,
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
              const Text(
                'Mark as delivered?',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.w900),
              ),
              const SizedBox(height: 8),
              const Text(
                'After delivery the invoice can no longer be voided.',
                style: TextStyle(
                  fontWeight: FontWeight.w600,
                  color: AppColors.mute,
                ),
              ),
              const SizedBox(height: 16),
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
    if (ok != true || !context.mounted) return;
    try {
      await context.read<SalesRepository>().deliver(sale.id);
      if (context.mounted) {
        AppToast.show(
          context,
          'Marked as delivered',
          type: ToastType.success,
        );
      }
      await onChanged();
    } on ApiException catch (e) {
      if (!context.mounted) return;
      final d = describeApiError(e);
      AppToast.show(context, d.body, title: d.title, type: ToastType.error);
      await onChanged();
    }
  }

  Future<void> _cancelDraft(BuildContext context) async {
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => const CancelDraftDialog(),
    );
    if (reason == null || !context.mounted) return;
    try {
      await context.read<SalesRepository>().cancel(
        sale.id,
        reason: reason.isEmpty ? null : reason,
      );
      if (context.mounted) {
        AppToast.show(context, 'Draft cancelled', type: ToastType.success);
      }
      await onChanged();
    } on ApiException catch (e) {
      if (!context.mounted) return;
      final d = describeApiError(e);
      AppToast.show(context, d.body, title: d.title, type: ToastType.error);
      await onChanged();
    }
  }

  @override
  Widget build(BuildContext context) {
    return SwipeableRow(
      leading: _leading,
      trailing: _trailing,
      onTap: () => _open(context),
      onLongPress: () async {
        final id = await showListActionsSheet(
          context,
          title: sale.label,
          actions: _menu,
        );
        if (id != null && context.mounted) await _handle(context, id);
      },
      onAction: (a) => _handle(context, a.id),
      child: _SaleCardBody(sale: sale),
    );
  }
}

String? _statusFilterValue(SalesListController c) {
  if (c.paymentStatus != null) return 'confirmed';
  return c.status;
}

/// Sentinel so a bottom-sheet can return "clear" without looking like dismiss.
class _ClearFilter {
  const _ClearFilter._();
  static const instance = _ClearFilter._();
}

class _FilterDropdown extends StatelessWidget {
  const _FilterDropdown({
    super.key,
    required this.label,
    required this.value,
    required this.options,
    required this.onChanged,
  });

  final String label;
  final String? value;
  final List<(String?, String)> options;
  final ValueChanged<String?> onChanged;

  Future<void> _open(BuildContext context) async {
    final result = await showModalBottomSheet<Object>(
      context: context,
      backgroundColor: AppColors.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (context) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
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
                const SizedBox(height: 16),
                Text(
                  label,
                  style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 12),
                for (final o in options)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Material(
                      color: o.$1 == value ? AppColors.ink : AppColors.wash,
                      borderRadius: BorderRadius.circular(14),
                      child: InkWell(
                        borderRadius: BorderRadius.circular(14),
                        onTap: () => Navigator.pop(
                          context,
                          o.$1 ?? _ClearFilter.instance,
                        ),
                        child: Padding(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 16,
                            vertical: 14,
                          ),
                          child: Row(
                            children: [
                              Expanded(
                                child: Text(
                                  o.$2,
                                  style: TextStyle(
                                    fontWeight: FontWeight.w800,
                                    color: o.$1 == value
                                        ? AppColors.lime
                                        : AppColors.ink,
                                  ),
                                ),
                              ),
                              if (o.$1 == value)
                                const AppIcon(
                                  AppIcons.check,
                                  size: 18,
                                  color: AppColors.lime,
                                ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
        );
      },
    );
    if (result == null) return;
    onChanged(result == _ClearFilter.instance ? null : result as String);
  }

  @override
  Widget build(BuildContext context) {
    final selected = options.firstWhere(
      (o) => o.$1 == value,
      orElse: () => options.first,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label.toUpperCase(),
          style: const TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w800,
            letterSpacing: 0.8,
            color: AppColors.mute,
          ),
        ),
        const SizedBox(height: 8),
        Material(
          color: AppColors.wash,
          borderRadius: BorderRadius.circular(16),
          child: InkWell(
            borderRadius: BorderRadius.circular(16),
            onTap: () => _open(context),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      selected.$2,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w800,
                        color: AppColors.ink,
                      ),
                    ),
                  ),
                  const AppIcon(
                    AppIcons.chevronRight,
                    size: 18,
                    color: AppColors.ink,
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _SaleCardBody extends StatelessWidget {
  const _SaleCardBody({required this.sale});

  final Sale sale;

  _StatusStyle get _status {
    if (sale.status == 'void' || sale.status == 'cancelled') {
      return _StatusStyle(
        label: SaleStatuses.label(sale.status),
        fg: AppColors.mute,
        bg: AppColors.surface,
      );
    }
    if (sale.isDraft) {
      return const _StatusStyle(
        label: 'Draft',
        fg: AppColors.ink,
        bg: AppColors.limeSoft,
      );
    }
    return switch (sale.paymentStatus) {
      'paid' => const _StatusStyle(
        label: 'Paid',
        fg: AppColors.ink,
        bg: AppColors.lime,
      ),
      'partial' => const _StatusStyle(
        label: 'Part paid',
        fg: AppColors.warn,
        bg: Color(0xFFFFF1E0),
      ),
      _ => const _StatusStyle(
        label: 'Unpaid',
        fg: AppColors.warn,
        bg: Color(0xFFFFF1E0),
      ),
    };
  }

  @override
  Widget build(BuildContext context) {
    final style = _status;
    final school = sale.customer?.name ?? 'No school';
    final date = DateFormat('d MMM y').format(sale.saleDate);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 14, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  sale.label,
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w900,
                    letterSpacing: -0.2,
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 5,
                ),
                decoration: BoxDecoration(
                  color: style.bg,
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  style.label,
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w800,
                    color: style.fg,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            school,
            style: const TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: AppColors.ink,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            date,
            style: const TextStyle(
              fontSize: 12.5,
              fontWeight: FontWeight.w600,
              color: AppColors.mute,
            ),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Text(
                Money.formatPesewas(sale.total),
                style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w900,
                  letterSpacing: -0.3,
                  fontFeatures: [FontFeature.tabularFigures()],
                ),
              ),
              if (sale.isConfirmed && sale.balanceDue > 0) ...[
                const SizedBox(width: 10),
                Text(
                  'Due ${Money.formatPesewas(sale.balanceDue)}',
                  style: const TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.warn,
                  ),
                ),
              ],
              const Spacer(),
              const AppIcon(
                AppIcons.chevronRight,
                size: 18,
                color: AppColors.mute,
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _StatusStyle {
  const _StatusStyle({
    required this.label,
    required this.fg,
    required this.bg,
  });

  final String label;
  final Color fg;
  final Color bg;
}
