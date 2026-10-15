import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';
import 'package:schoolbook/features/auth/presentation/auth_chrome.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/loading_overlay.dart';

class WaitingRoomScreen extends StatefulWidget {
  const WaitingRoomScreen({super.key, required this.email});

  final String email;

  @override
  State<WaitingRoomScreen> createState() => _WaitingRoomScreenState();
}

class _WaitingRoomScreenState extends State<WaitingRoomScreen>
    with SingleTickerProviderStateMixin {
  String _status = 'pending';
  bool _isBusy = false;
  late final AnimationController _anim;
  late final Animation<double> _fade;
  late final Animation<Offset> _slide;

  @override
  void initState() {
    super.initState();
    _anim = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 450),
    );
    _fade = CurvedAnimation(parent: _anim, curve: Curves.easeOut);
    _slide = Tween<Offset>(
      begin: const Offset(0, 0.03),
      end: Offset.zero,
    ).animate(CurvedAnimation(parent: _anim, curve: Curves.easeOut));
    _anim.forward();
  }

  @override
  void dispose() {
    _anim.dispose();
    super.dispose();
  }

  Future<void> _leaveWaitingRoom() async {
    final auth = context.read<AuthProvider>();
    await auth.clearPendingRegistration();
    if (mounted) context.go('/login');
  }

  void _showBackendToast(
    String message, {
    required ToastType type,
  }) {
    final text = message.trim();
    if (text.isEmpty) return;
    // Backend `message` is the primary text — no Flutter wrapper copy.
    AppToast.show(context, '', title: text, type: type);
  }

  Future<void> _checkStatus() async {
    if (widget.email.isEmpty) {
      AppToast.show(
        context,
        'Sign in again so we know which account to check.',
        title: 'No email on file',
        type: ToastType.warning,
      );
      return;
    }
    setState(() => _isBusy = true);
    try {
      final repo = context.read<AuthRepository>();
      final result = await repo.checkAccountStatus(widget.email);
      final s = result['status'] as String? ?? 'pending';
      final message = (result['message'] as String?)?.trim() ?? '';
      setState(() => _status = s);

      if (!mounted) return;
      if (s == 'active') {
        final authProvider = context.read<AuthProvider>();
        await authProvider.clearPendingRegistration();
        if (!mounted) return;
        _showBackendToast(message, type: ToastType.success);
        await Future.delayed(const Duration(milliseconds: 1200));
        if (mounted) context.go('/login');
      } else if (s == 'rejected') {
        _showBackendToast(message, type: ToastType.error);
      } else {
        _showBackendToast(message, type: ToastType.info);
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      // API error envelope: `{ message, code, ... }` — show server message as-is.
      _showBackendToast(e.message, type: ToastType.error);
    } finally {
      if (mounted) setState(() => _isBusy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isActive = _status == 'active';
    final isRejected = _status == 'rejected';

    final title = isRejected
        ? 'Not approved'
        : isActive
            ? 'You are approved'
            : 'Almost there';

    final body = isRejected
        ? 'This registration was not activated. Contact support if that looks wrong.'
        : isActive
            ? 'Your account is ready. Sign in with your email and password.'
            : 'An admin still needs to activate your account. This usually takes 1–2 business days.';

    final icon = isRejected
        ? AppIcons.close
        : isActive
            ? AppIcons.check
            : AppIcons.hourglass;
    final badgeColor = isRejected
        ? AppColors.danger.withValues(alpha: 0.1)
        : isActive
            ? AppColors.lime
            : AppColors.limeSoft;
    final iconColor = isRejected ? AppColors.danger : AppColors.ink;
    final statusLabel = isRejected
        ? 'Not approved'
        : isActive
            ? 'Active'
            : 'Pending review';

    return LoadingOverlay(
      isLoading: _isBusy,
      message: 'Checking status…',
      child: AuthScaffold(
        child: FadeTransition(
          opacity: _fade,
          child: SlideTransition(
            position: _slide,
            child: LayoutBuilder(
              builder: (context, constraints) {
                return SingleChildScrollView(
                  padding: const EdgeInsets.symmetric(horizontal: 24),
                  child: ConstrainedBox(
                    constraints: BoxConstraints(minHeight: constraints.maxHeight),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const SizedBox(height: 48),
                        Center(
                          child: Container(
                            width: 88,
                            height: 88,
                            decoration: BoxDecoration(
                              color: badgeColor,
                              shape: BoxShape.circle,
                            ),
                            child: Center(
                              child: AppIcon(icon, size: 36, color: iconColor),
                            ),
                          ),
                        ),
                        const SizedBox(height: 28),
                        Text(
                          title,
                          textAlign: TextAlign.center,
                          style: const TextStyle(
                            fontSize: 30,
                            fontWeight: FontWeight.w900,
                            letterSpacing: -0.5,
                            color: AppColors.ink,
                          ),
                        ),
                        const SizedBox(height: 12),
                        Text(
                          body,
                          textAlign: TextAlign.center,
                          style: const TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.w600,
                            height: 1.45,
                            color: AppColors.mute,
                          ),
                        ),
                        if (widget.email.isNotEmpty) ...[
                          const SizedBox(height: 28),
                          Container(
                            width: double.infinity,
                            padding: const EdgeInsets.fromLTRB(18, 16, 18, 16),
                            decoration: BoxDecoration(
                              color: AppColors.wash,
                              borderRadius: BorderRadius.circular(20),
                            ),
                            child: Column(
                              children: [
                                const Text(
                                  'SIGNED UP AS',
                                  style: TextStyle(
                                    fontSize: 11,
                                    fontWeight: FontWeight.w800,
                                    letterSpacing: 0.8,
                                    color: AppColors.mute,
                                  ),
                                ),
                                const SizedBox(height: 8),
                                Text(
                                  widget.email,
                                  textAlign: TextAlign.center,
                                  style: const TextStyle(
                                    fontSize: 16,
                                    fontWeight: FontWeight.w800,
                                  ),
                                ),
                                const SizedBox(height: 10),
                                Container(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 12,
                                    vertical: 6,
                                  ),
                                  decoration: BoxDecoration(
                                    color: isRejected
                                        ? AppColors.danger.withValues(alpha: 0.1)
                                        : isActive
                                            ? AppColors.lime
                                            : AppColors.surface,
                                    borderRadius: BorderRadius.circular(999),
                                  ),
                                  child: Text(
                                    statusLabel,
                                    style: TextStyle(
                                      fontSize: 12,
                                      fontWeight: FontWeight.w800,
                                      color: isRejected
                                          ? AppColors.danger
                                          : AppColors.ink,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                        if (!isActive && !isRejected) ...[
                          const SizedBox(height: 24),
                          const _NextStep(
                            step: '1',
                            label: 'We received your registration',
                            done: true,
                          ),
                          const SizedBox(height: 10),
                          const _NextStep(
                            step: '2',
                            label: 'An admin reviews and activates you',
                            done: false,
                          ),
                          const SizedBox(height: 10),
                          const _NextStep(
                            step: '3',
                            label: 'You sign in and start selling',
                            done: false,
                          ),
                        ],
                        const SizedBox(height: 40),
                        if (isActive)
                          FilledButton(
                            key: const Key('waiting_room_leave'),
                            onPressed: _isBusy ? null : _leaveWaitingRoom,
                            child: const Text('Go to Sign In'),
                          )
                        else if (isRejected)
                          FilledButton(
                            key: const Key('waiting_room_leave'),
                            onPressed: _isBusy ? null : _leaveWaitingRoom,
                            child: const Text('Back to Sign In'),
                          )
                        else ...[
                          FilledButton(
                            key: const Key('waiting_room_check'),
                            onPressed: _isBusy ? null : _checkStatus,
                            child: const Text('Check status'),
                          ),
                          const SizedBox(height: 12),
                          TextButton(
                            key: const Key('waiting_room_leave'),
                            onPressed: _isBusy ? null : _leaveWaitingRoom,
                            child: const Text(
                              'Back to Sign In',
                              style: TextStyle(fontWeight: FontWeight.w800),
                            ),
                          ),
                        ],
                        const SizedBox(height: 28),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
        ),
      ),
    );
  }
}

class _NextStep extends StatelessWidget {
  const _NextStep({
    required this.step,
    required this.label,
    required this.done,
  });

  final String step;
  final String label;
  final bool done;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Container(
          width: 32,
          height: 32,
          decoration: BoxDecoration(
            color: done ? AppColors.ink : AppColors.wash,
            shape: BoxShape.circle,
          ),
          child: Center(
            child: done
                ? const AppIcon(AppIcons.check, size: 16, color: AppColors.lime)
                : Text(
                    step,
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w900,
                      color: AppColors.mute,
                    ),
                  ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Text(
            label,
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: done ? AppColors.ink : AppColors.mute,
            ),
          ),
        ),
      ],
    );
  }
}
