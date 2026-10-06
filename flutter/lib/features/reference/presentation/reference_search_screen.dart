import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/features/reference/presentation/reference_book_tile.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';
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

  @override
  void initState() {
    super.initState();
    final catalog = context.read<ReferenceCatalog>();
    if (!catalog.loaded) {
      catalog.load();
    }
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
    if (mounted) _search(_query.text); // refresh the stock chips
  }

  @override
  Widget build(BuildContext context) {
    final catalog = context.watch<ReferenceCatalog>();

    return Scaffold(
      appBar: AppBar(
        title: Text(
          widget.pickMode ? 'Choose an approved title' : 'Approved list',
        ),
      ),
      body: RefreshIndicator(
        onRefresh: catalog.sync,
        child: ListView(
          padding: const EdgeInsets.symmetric(vertical: 8),
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: SearchBar(
                key: const Key('ref_search'),
                controller: _query,
                hintText: 'e.g. sunrise maths basic 4',
                leading: const Icon(Icons.search),
                onChanged: _search,
              ),
            ),
            _StatusLine(catalog: catalog),
            if (catalog.isEmpty && catalog.loaded && !catalog.syncing)
              const EmptyState(
                title: 'No approved list yet',
                subtitle: 'Pull down to download it. It is published from the admin (Approved list).',
              )
            else if (_query.text.trim().isNotEmpty && _results.isEmpty)
              const EmptyState(
                title: 'Nothing found',
                subtitle: 'Try fewer words, or a class like "p4".',
              )
            else
              for (final book in _results)
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

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final synced = catalog.syncedAt == null
        ? null
        : DateFormat('d MMM, HH:mm').format(catalog.syncedAt!.toLocal());
    final String text;
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
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
      child: Text(
        text,
        style: theme.textTheme.bodySmall?.copyWith(
          color: catalog.offline || catalog.error != null
              ? theme.colorScheme.error
              : null,
        ),
      ),
    );
  }
}
