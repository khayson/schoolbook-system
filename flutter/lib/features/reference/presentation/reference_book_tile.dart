import 'package:flutter/material.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';

/// An approved title in a list, with whether the shop carries it.
class ReferenceBookTile extends StatelessWidget {
  const ReferenceBookTile({
    super.key,
    required this.book,
    required this.stock,
    this.onTap,
  });

  final ReferenceBook book;
  final TitleStock? stock;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final carried = stock != null && stock!.productsCount > 0;
    return ListTile(
      key: Key('ref_book_${book.id}'),
      title: Text(book.title),
      subtitle: Text(
        book.subtitle,
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
      trailing: Chip(
        visualDensity: VisualDensity.compact,
        label: Text(
          carried ? 'In stock ${stock!.stockOnHand}' : 'Not in your products',
        ),
        backgroundColor: carried ? theme.colorScheme.secondaryContainer : null,
      ),
      onTap: onTap,
    );
  }
}
