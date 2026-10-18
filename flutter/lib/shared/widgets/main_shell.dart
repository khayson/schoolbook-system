import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/offline/presentation/offline_banner.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';

/// Floating black pill dock (NOTEE-style) with a lime center action for New sale.
///
/// Content scrolls under the dock. A frosted veil blurs whatever sits behind it;
/// [MediaQuery] bottom padding is inflated so the last items can still scroll
/// into the clear area above the dock.
class MainShell extends StatelessWidget {
  const MainShell({super.key, required this.child});

  final Widget child;

  /// Extra bottom inset screens get while the dock is visible.
  static const double dockClearance = 100.0;

  /// Side destinations around the center Sell action.
  static const _sides = <_Tab>[
    _Tab(label: 'Home', icon: AppIcons.home, path: '/'),
    _Tab(label: 'Sales', icon: AppIcons.sales, path: '/sales'),
    _Tab(label: 'Schools', icon: AppIcons.schools, path: '/customers'),
    _Tab(label: 'More', icon: AppIcons.more, path: '/more'),
  ];

  int? _sideIndex(String loc) {
    if (loc == '/' || loc.startsWith('/reports')) return 0;
    if (loc.startsWith('/sales') && loc != '/sales/new') return 1;
    if (loc.startsWith('/customers')) return 2;
    if (loc.startsWith('/more') ||
        loc.startsWith('/products') ||
        loc.startsWith('/payments') ||
        loc.startsWith('/stock')) {
      return 3;
    }
    return null;
  }

  bool _showNav(String loc) {
    if (loc == '/sales/new') return false;
    // Sale detail / edit are action screens — dock fights Confirm / Cancel.
    if (RegExp(r'^/sales/\d+(/edit)?$').hasMatch(loc)) return false;
    if (RegExp(r'^/payments/\d+$').hasMatch(loc)) return false;
    if (loc == '/stock/receive') return false;
    if (loc == '/stock/adjust') return false;
    if (loc.startsWith('/stock/counts')) return false;
    if (RegExp(r'^/stock/receipts/\d+$').hasMatch(loc)) return false;
    // Exchange draft / edit — leaving via the dock would wipe the form.
    if (loc == '/stock/exchanges/new') return false;
    if (RegExp(r'^/stock/exchanges/\d+/edit$').hasMatch(loc)) return false;
    // Report detail lists — dock covers the last rows.
    if (RegExp(r'^/reports/.+').hasMatch(loc)) return false;
    if (loc.startsWith('/products/scan')) return false;
    // Product create/edit and approved-list quick-create — dock covers Save.
    if (loc == '/products/new') return false;
    if (RegExp(r'^/products/\d+/edit$').hasMatch(loc)) return false;
    if (RegExp(r'^/products/approved/\d+/new$').hasMatch(loc)) return false;
    // Focus flows — no floating dock over forms / directory.
    if (loc == '/customers/directory') return false;
    if (loc == '/customers/new') return false;
    if (RegExp(r'^/customers/\d+/edit$').hasMatch(loc)) return false;
    if (RegExp(r'^/customers/\d+/pay$').hasMatch(loc)) return false;
    return true;
  }

  @override
  Widget build(BuildContext context) {
    final loc = GoRouterState.of(context).uri.path;
    final side = _sideIndex(loc);
    final show = _showNav(loc);
    final media = MediaQuery.of(context);
    final veilHeight = media.padding.bottom + dockClearance;

    return MediaQuery(
      data: media.copyWith(
        padding: media.padding.copyWith(
          bottom: media.padding.bottom + (show ? dockClearance : 0),
        ),
      ),
      child: Scaffold(
        backgroundColor: AppColors.surface,
        body: Column(
          children: [
            const OfflineBanner(),
            Expanded(
              child: Stack(
                children: [
                  child,
                  if (show) ...[
                    // Frosted strip — content scrolls under the dock and is blurred here.
                    Positioned(
                      left: 0,
                      right: 0,
                      bottom: 0,
                      height: veilHeight,
                      child: const IgnorePointer(child: _DockBlurVeil()),
                    ),
                    Positioned(
                      left: 0,
                      right: 0,
                      bottom: 0,
                      child: MediaQuery(
                        data: media,
                        child: SafeArea(
                          top: false,
                          child: Padding(
                            padding: const EdgeInsets.fromLTRB(24, 0, 24, 10),
                            child: _FloatingDock(
                              sideIndex: side,
                              onSideTap: (i) {
                                final path = _sides[i].path;
                                if (loc == path ||
                                    (path != '/' && loc.startsWith(path))) {
                                  return;
                                }
                                context.go(path);
                              },
                              onCenterTap: () => context.push('/sales/new'),
                              sides: _sides,
                            ),
                          ),
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Soft blur + fade so scrolling content under the dock stays readable.
class _DockBlurVeil extends StatelessWidget {
  const _DockBlurVeil();

  @override
  Widget build(BuildContext context) {
    return ClipRect(
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 22, sigmaY: 22),
        child: DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [
                AppColors.surface.withValues(alpha: 0.0),
                AppColors.surface.withValues(alpha: 0.42),
                AppColors.surface.withValues(alpha: 0.82),
              ],
              stops: const [0.0, 0.4, 1.0],
            ),
          ),
        ),
      ),
    );
  }
}

class _FloatingDock extends StatelessWidget {
  const _FloatingDock({
    required this.sides,
    required this.sideIndex,
    required this.onSideTap,
    required this.onCenterTap,
  });

  final List<_Tab> sides;
  final int? sideIndex;
  final ValueChanged<int> onSideTap;
  final VoidCallback onCenterTap;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
        decoration: BoxDecoration(
          color: AppColors.ink,
          borderRadius: BorderRadius.circular(999),
          boxShadow: const [
            BoxShadow(
              color: Color(0x33000000),
              blurRadius: 24,
              offset: Offset(0, 10),
            ),
          ],
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            _DockIcon(
              icon: sides[0].icon,
              selected: sideIndex == 0,
              onTap: () => onSideTap(0),
              tooltip: sides[0].label,
            ),
            const SizedBox(width: 4),
            _DockIcon(
              icon: sides[1].icon,
              selected: sideIndex == 1,
              onTap: () => onSideTap(1),
              tooltip: sides[1].label,
            ),
            const SizedBox(width: 8),
            _CenterSell(onTap: onCenterTap),
            const SizedBox(width: 8),
            _DockIcon(
              icon: sides[2].icon,
              selected: sideIndex == 2,
              onTap: () => onSideTap(2),
              tooltip: sides[2].label,
            ),
            const SizedBox(width: 4),
            _DockIcon(
              icon: sides[3].icon,
              selected: sideIndex == 3,
              onTap: () => onSideTap(3),
              tooltip: sides[3].label,
            ),
          ],
        ),
      ),
    );
  }
}

class _DockIcon extends StatelessWidget {
  const _DockIcon({
    required this.icon,
    required this.selected,
    required this.onTap,
    required this.tooltip,
  });

  final IconData icon;
  final bool selected;
  final VoidCallback onTap;
  final String tooltip;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: tooltip,
      child: Material(
        color: selected ? Colors.white : Colors.transparent,
        shape: const CircleBorder(),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: onTap,
          child: SizedBox(
            width: 46,
            height: 46,
            child: Center(
              child: AppIcon(
                icon,
                size: 22,
                color: selected ? AppColors.ink : Colors.white70,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _CenterSell extends StatelessWidget {
  const _CenterSell({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: 'New sale',
      child: Material(
        color: AppColors.lime,
        shape: const CircleBorder(),
        elevation: 0,
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: onTap,
          child: const SizedBox(
            width: 58,
            height: 58,
            child: Center(
              child: AppIcon(AppIcons.add, size: 28, color: AppColors.ink),
            ),
          ),
        ),
      ),
    );
  }
}

class _Tab {
  const _Tab({
    required this.label,
    required this.icon,
    required this.path,
  });

  final String label;
  final IconData icon;
  final String path;
}
