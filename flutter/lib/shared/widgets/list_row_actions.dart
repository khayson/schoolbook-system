import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';

/// One quick action for swipe / long-press menus.
class ListRowAction {
  const ListRowAction({
    required this.id,
    required this.label,
    required this.icon,
    this.danger = false,
  });

  final String id;
  final String label;
  final IconData icon;
  final bool danger;
}

/// Horizontal swipe reveals up to two leading / trailing actions.
///
/// A short swipe opens the buttons (tap to run). Swiping all the way — or a
/// strong fling — commits the far-end action immediately (leading.first /
/// trailing.last).
class SwipeableRow extends StatefulWidget {
  const SwipeableRow({
    super.key,
    required this.child,
    this.leading = const [],
    this.trailing = const [],
    this.onTap,
    this.onLongPress,
    this.onAction,
    this.borderRadius = 12,
  });

  final Widget child;
  final List<ListRowAction> leading;
  final List<ListRowAction> trailing;
  final VoidCallback? onTap;
  final VoidCallback? onLongPress;
  final ValueChanged<ListRowAction>? onAction;
  final double borderRadius;

  @override
  State<SwipeableRow> createState() => _SwipeableRowState();
}

class _SwipeableRowState extends State<SwipeableRow>
    with SingleTickerProviderStateMixin {
  static const _extent = 76.0;

  /// Extra travel past the open buttons — crossing this commits the action.
  static const _overscroll = 56.0;

  /// Fling velocity (px/s) that commits even from a partial open.
  static const _flingVelocity = 1100.0;

  late final AnimationController _controller;
  double _drag = 0;
  bool _armed = false;

  double get _maxLeading => widget.leading.length * _extent;
  double get _maxTrailing => widget.trailing.length * _extent;

  double get _limitLeading =>
      _maxLeading == 0 ? 0 : _maxLeading + _overscroll;
  double get _limitTrailing =>
      _maxTrailing == 0 ? 0 : _maxTrailing + _overscroll;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 200),
    )..addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  double get _offset {
    if (_controller.isAnimating) {
      return _controller.value * _drag;
    }
    return _drag;
  }

  void _close() {
    _armed = false;
    _drag = _offset;
    _controller.value = 1;
    _controller.animateTo(0, curve: Curves.easeOutCubic).whenComplete(() {
      if (mounted) setState(() => _drag = 0);
    });
  }

  void _openTo(double target) {
    final o = _offset;
    if (target == 0 || o == 0 && target == 0) {
      _close();
      return;
    }
    _drag = target;
    _controller.value = (o / target).clamp(0.0, 1.0);
    _controller.animateTo(1, curve: Curves.easeOutCubic);
  }

  Future<void> _fire(ListRowAction action) async {
    HapticFeedback.mediumImpact();
    _close();
    widget.onAction?.call(action);
  }

  void _snap({double velocity = 0}) {
    final o = _offset;

    // Past the open buttons (into overscroll), or a strong fling → run it.
    if (o > 0 && widget.leading.isNotEmpty) {
      final pastEnd = o > _maxLeading;
      final flung = velocity > _flingVelocity && o > _maxLeading * 0.4;
      if (pastEnd || flung) {
        _fire(widget.leading.first);
        return;
      }
    }
    if (o < 0 && widget.trailing.isNotEmpty) {
      final pastEnd = o < -_maxTrailing;
      final flung = velocity < -_flingVelocity && o < -_maxTrailing * 0.4;
      if (pastEnd || flung) {
        _fire(widget.trailing.last);
        return;
      }
    }

    // Stop on the buttons, or close.
    double target = 0;
    if (o > _maxLeading / 2) {
      target = _maxLeading;
    } else if (o < -_maxTrailing / 2) {
      target = -_maxTrailing;
    }
    if (target == 0) {
      _close();
      return;
    }
    _openTo(target);
  }

  void _updateArmed(double o) {
    final next = (o > _maxLeading && widget.leading.isNotEmpty) ||
        (o < -_maxTrailing && widget.trailing.isNotEmpty);
    if (next != _armed) {
      _armed = next;
      if (_armed) HapticFeedback.selectionClick();
    }
  }

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(widget.borderRadius);
    final o = _offset.clamp(-_limitTrailing, _limitLeading);

    // Stretch the far-end action while overscrolling so it feels “armed”.
    final leadOver = o > _maxLeading ? o - _maxLeading : 0.0;
    final trailOver = o < -_maxTrailing ? -o - _maxTrailing : 0.0;

    return ClipRRect(
      borderRadius: radius,
      child: Stack(
        children: [
          Positioned.fill(
            child: Row(
              children: [
                if (widget.leading.isNotEmpty)
                  SizedBox(
                    width: _maxLeading + leadOver,
                    child: Row(
                      children: [
                        for (var i = 0; i < widget.leading.length; i++)
                          Expanded(
                            flex: i == 0 && leadOver > 0
                                ? (_extent + leadOver).round().clamp(1, 1000)
                                : _extent.round(),
                            child: _ActionPane(
                              action: widget.leading[i],
                              emphasize: _armed && i == 0,
                              onTap: () => _fire(widget.leading[i]),
                            ),
                          ),
                      ],
                    ),
                  ),
                const Spacer(),
                if (widget.trailing.isNotEmpty)
                  SizedBox(
                    width: _maxTrailing + trailOver,
                    child: Row(
                      children: [
                        for (var i = 0; i < widget.trailing.length; i++)
                          Expanded(
                            flex: i == widget.trailing.length - 1 &&
                                    trailOver > 0
                                ? (_extent + trailOver).round().clamp(1, 1000)
                                : _extent.round(),
                            child: _ActionPane(
                              action: widget.trailing[i],
                              emphasize: _armed &&
                                  i == widget.trailing.length - 1,
                              onTap: () => _fire(widget.trailing[i]),
                            ),
                          ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          GestureDetector(
            onHorizontalDragUpdate: (d) {
              if (widget.leading.isEmpty && widget.trailing.isEmpty) return;
              setState(() {
                _controller.stop();
                _drag = (_offset + d.delta.dx).clamp(
                  -_limitTrailing,
                  _limitLeading,
                );
                _updateArmed(_drag);
              });
            },
            onHorizontalDragEnd: (d) =>
                _snap(velocity: d.primaryVelocity ?? 0),
            child: Transform.translate(
              offset: Offset(o, 0),
              child: Material(
                color: AppColors.wash,
                borderRadius: radius,
                child: InkWell(
                  borderRadius: radius,
                  onTap: () {
                    if (_offset.abs() > 4) {
                      _close();
                      return;
                    }
                    widget.onTap?.call();
                  },
                  onLongPress: widget.onLongPress == null
                      ? null
                      : () {
                          HapticFeedback.mediumImpact();
                          if (_offset.abs() > 4) _close();
                          widget.onLongPress!();
                        },
                  child: widget.child,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ActionPane extends StatelessWidget {
  const _ActionPane({
    required this.action,
    required this.onTap,
    this.emphasize = false,
  });

  final ListRowAction action;
  final VoidCallback onTap;
  final bool emphasize;

  @override
  Widget build(BuildContext context) {
    final bg = action.danger
        ? AppColors.danger
        : emphasize
            ? AppColors.ink
            : AppColors.ink;
    final fg = action.danger ? Colors.white : AppColors.lime;
    return Material(
      color: bg,
      child: InkWell(
        onTap: onTap,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            AppIcon(action.icon, size: emphasize ? 24 : 22, color: fg),
            const SizedBox(height: 4),
            Text(
              action.label,
              style: TextStyle(
                fontSize: emphasize ? 12 : 11,
                fontWeight: FontWeight.w800,
                color: fg,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Long-press shortcut menu (not multi-select).
Future<String?> showListActionsSheet(
  BuildContext context, {
  required String title,
  required List<ListRowAction> actions,
}) {
  return showModalBottomSheet<String>(
    context: context,
    backgroundColor: AppColors.surface,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
    ),
    builder: (context) {
      final bottom = MediaQuery.paddingOf(context).bottom;
      return SafeArea(
        child: Padding(
          padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 12),
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
                    borderRadius: BorderRadius.circular(4),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              Text(
                title,
                style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 12),
              for (final a in actions)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Material(
                    color: AppColors.wash,
                    borderRadius: BorderRadius.circular(12),
                    child: InkWell(
                      key: Key('list_action_${a.id}'),
                      borderRadius: BorderRadius.circular(12),
                      onTap: () => Navigator.pop(context, a.id),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 16,
                          vertical: 14,
                        ),
                        child: Row(
                          children: [
                            AppIcon(
                              a.icon,
                              size: 20,
                              color:
                                  a.danger ? AppColors.danger : AppColors.ink,
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Text(
                                a.label,
                                style: TextStyle(
                                  fontWeight: FontWeight.w800,
                                  color: a.danger
                                      ? AppColors.danger
                                      : AppColors.ink,
                                ),
                              ),
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
}
