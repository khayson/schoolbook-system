import 'package:flutter/material.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';

enum ToastType { info, success, error, warning }

/// System toast — use this instead of [SnackBar] / [ScaffoldMessenger].
///
/// ```dart
/// AppToast.show(context, 'School saved', type: ToastType.success);
/// AppToast.show(context, describeApiError(e).body, type: ToastType.error);
/// ```
class AppToast {
  AppToast._();

  static void show(
    BuildContext context,
    String message, {
    ToastType type = ToastType.info,
    String? title,
    Duration duration = const Duration(seconds: 3),
  }) {
    final overlay = Overlay.of(context, rootOverlay: true);
    late OverlayEntry entry;
    entry = OverlayEntry(
      builder: (_) => _Toast(
        message: message,
        title: title,
        type: type,
        duration: duration,
        onDismissed: () => entry.remove(),
      ),
    );
    overlay.insert(entry);
  }
}

class _ToastStyle {
  const _ToastStyle({
    required this.accent,
    required this.icon,
    required this.defaultTitle,
  });

  final Color accent;
  final IconData icon;
  final String defaultTitle;

  static _ToastStyle of(ToastType type) => switch (type) {
        ToastType.success => const _ToastStyle(
            accent: AppColors.lime,
            icon: AppIcons.check,
            defaultTitle: 'Done',
          ),
        ToastType.error => const _ToastStyle(
            accent: AppColors.danger,
            icon: AppIcons.alert,
            defaultTitle: 'Couldn’t finish',
          ),
        ToastType.warning => const _ToastStyle(
            accent: AppColors.warn,
            icon: AppIcons.alert,
            defaultTitle: 'Heads up',
          ),
        ToastType.info => const _ToastStyle(
            accent: AppColors.mute,
            icon: AppIcons.visibility,
            defaultTitle: 'Note',
          ),
      };
}

class _Toast extends StatefulWidget {
  const _Toast({
    required this.message,
    required this.type,
    required this.duration,
    required this.onDismissed,
    this.title,
  });

  final String message;
  final String? title;
  final ToastType type;
  final Duration duration;
  final VoidCallback onDismissed;

  @override
  State<_Toast> createState() => _ToastState();
}

class _ToastState extends State<_Toast> with SingleTickerProviderStateMixin {
  static const _enterMs = 280;
  static const _exitMs = 240;

  late final AnimationController _ctrl;
  late final Animation<double> _fade;
  late final Animation<Offset> _slide;
  bool _dismissed = false;

  int get _holdMs => widget.duration.inMilliseconds.clamp(800, 12000);
  int get _totalMs => _enterMs + _holdMs + _exitMs;

  @override
  void initState() {
    super.initState();
    // One controller covers enter → hold → exit so widget tests (pumpAndSettle)
    // finish without a stray Timer.
    _ctrl = AnimationController(
      vsync: this,
      duration: Duration(milliseconds: _totalMs),
    );
    final enterEnd = _enterMs / _totalMs;
    final holdEnd = (_enterMs + _holdMs) / _totalMs;

    _fade = TweenSequence<double>([
      TweenSequenceItem(
        tween: Tween<double>(begin: 0, end: 1)
            .chain(CurveTween(curve: Curves.easeOut)),
        weight: _enterMs.toDouble(),
      ),
      TweenSequenceItem(
        tween: ConstantTween<double>(1),
        weight: _holdMs.toDouble(),
      ),
      TweenSequenceItem(
        tween: Tween<double>(begin: 1, end: 0)
            .chain(CurveTween(curve: Curves.easeIn)),
        weight: _exitMs.toDouble(),
      ),
    ]).animate(_ctrl);

    _slide = TweenSequence<Offset>([
      TweenSequenceItem(
        tween: Tween(begin: const Offset(0, 0.35), end: Offset.zero)
            .chain(CurveTween(curve: Curves.easeOutCubic)),
        weight: _enterMs.toDouble(),
      ),
      TweenSequenceItem(
        tween: ConstantTween(Offset.zero),
        weight: _holdMs.toDouble(),
      ),
      TweenSequenceItem(
        tween: Tween(begin: Offset.zero, end: const Offset(0, 0.2))
            .chain(CurveTween(curve: Curves.easeIn)),
        weight: _exitMs.toDouble(),
      ),
    ]).animate(_ctrl);

    // Silence unused locals if analyzer is picky about enterEnd/holdEnd —
    // they document the timeline; sequence weights already encode it.
    assert(enterEnd > 0 && holdEnd > enterEnd);

    _ctrl.forward().whenComplete(_finish);
  }

  void _finish() {
    if (_dismissed || !mounted) return;
    _dismissed = true;
    widget.onDismissed();
  }

  Future<void> _dismissEarly() async {
    if (_dismissed || !mounted) return;
    // Jump into the exit portion of the timeline.
    final exitStart = (_enterMs + _holdMs) / _totalMs;
    if (_ctrl.value < exitStart) {
      _ctrl.value = exitStart;
    }
    await _ctrl.forward();
    _finish();
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final style = _ToastStyle.of(widget.type);
    final mq = MediaQuery.of(context);
    final title = widget.title ?? style.defaultTitle;

    return Positioned(
      // MediaQuery bottom already includes MainShell dock clearance when shown.
      bottom: mq.padding.bottom + 12,
      left: 20,
      right: 20,
      child: FadeTransition(
        opacity: _fade,
        child: SlideTransition(
          position: _slide,
          child: Material(
            color: Colors.transparent,
            child: GestureDetector(
              onTap: _dismissEarly,
              behavior: HitTestBehavior.opaque,
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 420),
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      color: AppColors.ink,
                      borderRadius: BorderRadius.circular(22),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withValues(alpha: 0.28),
                          blurRadius: 24,
                          offset: const Offset(0, 10),
                        ),
                      ],
                    ),
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(14, 14, 12, 14),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Container(
                            width: 40,
                            height: 40,
                            decoration: BoxDecoration(
                              color: style.accent.withValues(
                                alpha: widget.type == ToastType.success
                                    ? 1
                                    : 0.18,
                              ),
                              borderRadius: BorderRadius.circular(14),
                            ),
                            child: Center(
                              child: AppIcon(
                                style.icon,
                                size: 20,
                                color: widget.type == ToastType.success
                                    ? AppColors.ink
                                    : style.accent,
                              ),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Text(
                                  title,
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 14,
                                    fontWeight: FontWeight.w800,
                                    height: 1.2,
                                  ),
                                ),
                                if (widget.message.trim().isNotEmpty) ...[
                                  const SizedBox(height: 4),
                                  Text(
                                    widget.message,
                                    style: TextStyle(
                                      color: Colors.white.withValues(alpha: 0.72),
                                      fontSize: 13,
                                      fontWeight: FontWeight.w600,
                                      height: 1.4,
                                    ),
                                  ),
                                ],
                              ],
                            ),
                          ),
                          const SizedBox(width: 4),
                          IconButton(
                            onPressed: _dismissEarly,
                            visualDensity: VisualDensity.compact,
                            icon: AppIcon(
                              AppIcons.close,
                              size: 18,
                              color: Colors.white.withValues(alpha: 0.45),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
