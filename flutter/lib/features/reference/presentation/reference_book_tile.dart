import 'package:flutter/material.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';

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

  static const _r = 12.0;

  @override
  Widget build(BuildContext context) {
    final carried = stock != null && stock!.productsCount > 0;
    final stockLabel = carried
        ? 'In stock ${stock!.stockOnHand}'
        : 'Not in your products';

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 5),
      child: Material(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(_r),
        child: InkWell(
          key: Key('ref_book_${book.id}'),
          onTap: onTap,
          borderRadius: BorderRadius.circular(_r),
          child: Container(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
            decoration: BoxDecoration(
              border: Border.all(
                color: carried ? AppColors.lime : AppColors.line,
                width: carried ? 1.5 : 1,
              ),
              borderRadius: BorderRadius.circular(_r),
              color: carried ? AppColors.limeSoft.withValues(alpha: 0.35) : null,
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: carried ? AppColors.lime : AppColors.wash,
                    border: Border.all(
                      color: carried ? AppColors.ink.withValues(alpha: 0.08) : AppColors.line,
                    ),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Center(
                    child: AppIcon(AppIcons.approvedList, size: 18),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        book.title,
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w800,
                          height: 1.25,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        book.subtitle,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 12.5,
                          fontWeight: FontWeight.w600,
                          color: AppColors.mute,
                          height: 1.35,
                        ),
                      ),
                      const SizedBox(height: 10),
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 10,
                          vertical: 5,
                        ),
                        decoration: BoxDecoration(
                          color: carried ? AppColors.lime : AppColors.wash,
                          border: Border.all(
                            color: carried ? AppColors.ink.withValues(alpha: 0.08) : AppColors.line,
                          ),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          stockLabel,
                          style: TextStyle(
                            fontSize: 11.5,
                            fontWeight: FontWeight.w800,
                            color: carried ? AppColors.ink : AppColors.mute,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                const Padding(
                  padding: EdgeInsets.only(top: 10),
                  child: AppIcon(
                    AppIcons.chevronRight,
                    size: 18,
                    color: AppColors.mute,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
