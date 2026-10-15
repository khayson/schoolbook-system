import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/features/reference/presentation/reference_book_tile.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';
import 'package:schoolbook/shared/widgets/app_chrome.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

/// Search the approved list on the phone (works offline from the stored copy).
/// Tapping a title adds it as a product; in [pickMode] it returns the title instead.
class ReferenceSearchScreen extends StatefulWidget {
  const ReferenceSearchScreen({super.key, this.pickMode = false});

  final bool pickMode;

  @override
  State<ReferenceSearchScreen> createState() => _ReferenceSearchScreenState();
}

class _ReferenceSearchScreenState extends State<ReferenceSearchScreen> {
  final _query = TextEditingController();
  List<ReferenceBook> _results = const [];

  static const _r = 12.0;

  @override
  void initState() {
    super.initState();
    final catalog = context.read<ReferenceCatalog>();
    catalog.sync(includeStock: true);
  }

  @override
  void dispose() {
    _query.dispose();
    super.dispose();
  }

  void _search(String text) =>
      setState(() => _results = context.read<ReferenceCatalog>().search(text));

  Future<void> _open(ReferenceBook book) async {
    if (widget.pickMode) {
      context.pop(book);
      return;
    }
    await context.push('/products/approved/${book.id}/new');
    if (mounted) _search(_query.text);
  }

  @override
  Widget build(BuildContext context) {
    final catalog = context.watch<ReferenceCatalog>();
    final querying = _query.text.trim().isNotEmpty;
    final showing = querying ? _results : const <ReferenceBook>[];

    return AppPage(
      title: widget.pickMode ? 'Choose a title' : 'Approved list',
      subtitle: widget.pickMode
          ? 'NaCCA reference books'
          : 'Add a book to your catalog',
      body: RefreshIndicator(
        color: AppColors.ink,
        backgroundColor: AppColors.lime,
        onRefresh: () => catalog.sync(includeStock: true, force: true),
        child: ListView(
          padding: const EdgeInsets.fromLTRB(0, 12, 0, 28),
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: Container(
                width: double.infinity,
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: AppColors.limeSoft,
                  border: Border.all(color: AppColors.lime, width: 1.5),
                  borderRadius: BorderRadius.circular(_r),
                ),
                child: Row(
                  children: [
                    Container(
                      width: 40,
                      height: 40,
                      decoration: BoxDecoration(
                        color: AppColors.lime,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: const Center(
                        child: AppIcon(AppIcons.approvedList, size: 18),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        widget.pickMode
                            ? 'Pick a NaCCA title. Stock chips show what you already carry.'
                            : 'Search NaCCA titles, then add one to your catalog with cost and price.',
                        style: const TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          height: 1.35,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 14),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: TextField(
                key: const Key('ref_search'),
                controller: _query,
                decoration: InputDecoration(
                  hintText: 'e.g. sunrise maths basic 4',
                  prefixIcon: const Padding(
                    padding: EdgeInsets.only(left: 14, right: 8),
                    child: AppIcon(AppIcons.search, size: 20),
                  ),
                  prefixIconConstraints:
                      const BoxConstraints(minWidth: 44, minHeight: 44),
                  suffixIcon: _query.text.isEmpty
                      ? null
                      : IconButton(
                          onPressed: () {
                            _query.clear();
                            _search('');
                          },
                          icon: const AppIcon(AppIcons.close, size: 18),
                        ),
                  filled: true,
                  fillColor: AppColors.surface,
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(_r),
                    borderSide: const BorderSide(color: AppColors.line),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(_r),
                    borderSide:
                        const BorderSide(color: AppColors.ink, width: 1.5),
                  ),
                ),
                onChanged: _search,
              ),
            ),
            _StatusLine(catalog: catalog),
            if (catalog.syncing)
              const Padding(
                padding: EdgeInsets.fromLTRB(20, 0, 20, 8),
                child: LinearProgressIndicator(
                  minHeight: 2,
                  backgroundColor: AppColors.line,
                  color: AppColors.lime,
                ),
              ),
            if (catalog.isEmpty && catalog.loaded && !catalog.syncing)
              const Padding(
                padding: EdgeInsets.symmetric(horizontal: 20),
                child: EmptyState(
                  title: 'No approved list yet',
                  subtitle:
                      'Pull down to download it. It is published from the admin (Approved list).',
                ),
              )
            else if (querying && showing.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(horizontal: 20),
                child: EmptyState(
                  title: 'Nothing found',
                  subtitle: 'Try fewer words, or a class like "p4".',
                ),
              )
            else if (!querying)
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
                child: Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    border: Border.all(color: AppColors.line),
                    borderRadius: BorderRadius.circular(_r),
                  ),
                  child: const Text(
                    'Type a title, subject, or class (e.g. “p4”) to search the list.',
                    style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: AppColors.mute,
                      height: 1.4,
                    ),
                  ),
                ),
              )
            else
              for (final book in showing)
                ReferenceBookTile(
                  book: book,
                  stock: catalog.stockFor(book.id),
                  onTap: () => _open(book),
                ),
          ],
        ),
      ),
    );
  }
}

class _StatusLine extends StatelessWidget {
  const _StatusLine({required this.catalog});

  final ReferenceCatalog catalog;

  static const _r = 12.0;

  @override
  Widget build(BuildContext context) {
    final synced = catalog.syncedAt == null
        ? null
        : DateFormat('d MMM, HH:mm').format(catalog.syncedAt!.toLocal());
    final String text;
    final warn = catalog.offline || catalog.error != null;
    if (catalog.syncing) {
      text = 'Updating the approved list…';
    } else if (catalog.offline) {
      text =
          'Offline: using the copy on this phone${synced == null ? '' : ' from $synced'}.';
    } else if (catalog.error != null) {
      text = catalog.error!;
    } else {
      text =
          '${catalog.editionLabel ?? 'Approved list'} · ${catalog.books.length} titles${synced == null ? '' : ' · stock as of $synced'}';
    }

    return Padding(
      key: const Key('ref_status'),
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 10),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          color: warn ? AppColors.danger.withValues(alpha: 0.06) : AppColors.wash,
          border: Border.all(
            color: warn ? AppColors.danger.withValues(alpha: 0.35) : AppColors.line,
          ),
          borderRadius: BorderRadius.circular(_r),
        ),
        child: Row(
          children: [
            AppIcon(
              warn ? AppIcons.alert : AppIcons.approvedList,
              size: 16,
              color: warn ? AppColors.danger : AppColors.ink,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                text,
                style: TextStyle(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w600,
                  color: warn ? AppColors.danger : AppColors.mute,
                  height: 1.4,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
