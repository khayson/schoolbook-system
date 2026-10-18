import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/offline/presentation/offline_controller.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';

/// Thin strip under the status bar when offline or the outbound queue is non-empty.
class OfflineBanner extends StatelessWidget {
  const OfflineBanner({super.key});

  @override
  Widget build(BuildContext context) {
    final offline = context.watch<OfflineController>();
    if (!offline.showBanner) return const SizedBox.shrink();

    final conflict = offline.conflictCount > 0;
    final bg = !offline.online
        ? AppColors.ink
        : conflict
        ? AppColors.warn
        : AppColors.mute;

    return Material(
      color: bg,
      child: SafeArea(
        bottom: false,
        child: InkWell(
          onTap: offline.online && offline.queue.isNotEmpty
              ? () => offline.kickSync()
              : null,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            child: Row(
              children: [
                AppIcon(
                  !offline.online
                      ? AppIcons.alert
                      : conflict
                      ? AppIcons.alert
                      : AppIcons.check,
                  size: 18,
                  color: Colors.white,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    offline.bannerMessage,
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w700,
                      fontSize: 13,
                      height: 1.3,
                    ),
                  ),
                ),
                if (offline.syncing)
                  const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  )
                else if (offline.online && offline.queue.isNotEmpty)
                  Text(
                    conflict ? 'Review' : 'Sync',
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.85),
                      fontWeight: FontWeight.w800,
                      fontSize: 12,
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
