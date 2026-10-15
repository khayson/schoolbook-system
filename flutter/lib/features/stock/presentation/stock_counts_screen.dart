import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/stock/data/stock_counts_repository.dart';
import 'package:schoolbook/features/stock/domain/stock_count.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_drawer.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

/// Open stock-takes: continue one, or start a count of every active product.
class StockCountsScreen extends StatefulWidget {
  const StockCountsScreen({super.key});

  @override
  State<StockCountsScreen> createState() => _StockCountsScreenState();
}

class _StockCountsScreenState extends State<StockCountsScreen> {
  List<StockCount>? _counts;
  String? _error;
  bool _starting = false;

  static const _r = 12.0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final counts = await context.read<StockCountsRepository>().openCounts();
      if (mounted) {
        setState(() => _counts = counts);
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = describeApiError(e).body);
      }
    }
  }

  Future<void> _start() async {
    final confirmed = await showAppDrawer<bool>(
      context: context,
      builder: (context) => AppDrawerScaffold(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Start a stock-take?',
              style: TextStyle(fontSize: 22, fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 8),
            const Text(
              'Lists every active product. Count the shelves on the phone; '
              'the owner applies adjustments from the web admin when ready.',
              style: TextStyle(
                fontWeight: FontWeight.w600,
                color: AppColors.mute,
                height: 1.4,
              ),
            ),
            const SizedBox(height: 14),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AppColors.limeSoft,
                border: Border.all(color: AppColors.lime, width: 1.5),
                borderRadius: BorderRadius.circular(_r),
              ),
              child: const Text(
                '1. Count each title · 2. Review variances · 3. Apply on web',
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  height: 1.35,
                ),
              ),
            ),
            const SizedBox(height: 20),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    style: OutlinedButton.styleFrom(
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(_r),
                      ),
                    ),
                    onPressed: () => Navigator.pop(context, false),
                    child: const Text(
                      'Cancel',
                      style: TextStyle(fontWeight: FontWeight.w800),
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: FilledButton(
                    key: const Key('start_count_confirm'),
                    style: FilledButton.styleFrom(
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(_r),
                      ),
                    ),
                    onPressed: () => Navigator.pop(context, true),
                    child: const Text('Start count'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
    if (confirmed != true || !mounted) {
      return;
    }
    setState(() => _starting = true);
    final router = GoRouter.of(context);
    try {
      final count = await context.read<StockCountsRepository>().create();
      await router.push('/stock/counts/${count.id}');
      await _load();
    } on ApiException catch (e) {
      final d = describeApiError(e);
      if (mounted) {
        AppToast.show(context, d.body, title: d.title, type: ToastType.error);
      }
    } finally {
      if (mounted) {
        setState(() => _starting = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final counts = _counts;
    return AppPage(
      title: 'Stock-take',
      subtitle: 'Open counts',
      actions: [
        IconButton(
          key: const Key('start_count'),
          tooltip: 'New count',
          onPressed: _starting ? null : _start,
          icon: const AppIcon(AppIcons.add, size: 22),
        ),
      ],
      body: _error != null
          ? ErrorState(message: _error!, onRetry: _load)
          : counts == null
              ? const LoadingBody()
              : counts.isEmpty
                  ? EmptyState(
                      title: 'No open counts',
                      subtitle: 'Start one to count the shelves.',
                      actionLabel: _starting ? null : 'New count',
                      onAction: _starting ? null : _start,
                    )
                  : RefreshIndicator(
                      color: AppColors.ink,
                      backgroundColor: AppColors.lime,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(20, 12, 20, 28),
                        children: [
                          Container(
                            width: double.infinity,
                            padding: const EdgeInsets.all(14),
                            decoration: BoxDecoration(
                              color: AppColors.limeSoft,
                              border:
                                  Border.all(color: AppColors.lime, width: 1.5),
                              borderRadius: BorderRadius.circular(_r),
                            ),
                            child: const Row(
                              children: [
                                _SquareIcon(icon: AppIcons.check, lime: true),
                                SizedBox(width: 12),
                                Expanded(
                                  child: Text(
                                    'Count on the phone. Apply adjustments from the web admin when the count is ready.',
                                    style: TextStyle(
                                      fontSize: 13,
                                      fontWeight: FontWeight.w700,
                                      height: 1.35,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 16),
                          for (final c in counts) ...[
                            Material(
                              color: AppColors.surface,
                              borderRadius: BorderRadius.circular(_r),
                              child: InkWell(
                                key: Key('count_${c.id}'),
                                borderRadius: BorderRadius.circular(_r),
                                onTap: () async {
                                  await context.push('/stock/counts/${c.id}');
                                  _load();
                                },
                                child: Container(
                                  padding: const EdgeInsets.all(14),
                                  decoration: BoxDecoration(
                                    border: Border.all(color: AppColors.line),
                                    borderRadius: BorderRadius.circular(_r),
                                  ),
                                  child: Row(
                                    children: [
                                      Container(
                                        width: 4,
                                        height: 40,
                                        decoration: BoxDecoration(
                                          color: AppColors.lime,
                                          borderRadius:
                                              BorderRadius.circular(4),
                                        ),
                                      ),
                                      const SizedBox(width: 12),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              c.reference,
                                              style: const TextStyle(
                                                fontSize: 15,
                                                fontWeight: FontWeight.w800,
                                              ),
                                            ),
                                            const SizedBox(height: 3),
                                            Text(
                                              'Opened ${DateFormat('d MMM y, HH:mm').format(c.createdAt)} · Continue',
                                              style: const TextStyle(
                                                fontSize: 12.5,
                                                fontWeight: FontWeight.w600,
                                                color: AppColors.mute,
                                              ),
                                            ),
                                          ],
                                        ),
                                      ),
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
                            const SizedBox(height: 8),
                          ],
                        ],
                      ),
                    ),
    );
  }
}

class _SquareIcon extends StatelessWidget {
  const _SquareIcon({required this.icon, this.lime = false});

  final IconData icon;
  final bool lime;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 40,
      height: 40,
      decoration: BoxDecoration(
        color: lime ? AppColors.lime : AppColors.wash,
        border: Border.all(
          color: lime ? AppColors.ink.withValues(alpha: 0.08) : AppColors.line,
        ),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Center(child: AppIcon(icon, size: 18)),
    );
  }
}
