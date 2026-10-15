import 'package:flutter/material.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';

/// Shared NOTEE chrome for auth / waiting-room screens.
class AuthScaffold extends StatelessWidget {
  const AuthScaffold({
    super.key,
    required this.child,
    this.overlay,
  });

  final Widget child;
  final Widget? overlay;

  @override
  Widget build(BuildContext context) {
    final body = Scaffold(
      backgroundColor: AppColors.surface,
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 390),
            child: child,
          ),
        ),
      ),
    );
    if (overlay == null) return body;
    return Stack(children: [body, overlay!]);
  }
}

class AuthBackButton extends StatelessWidget {
  const AuthBackButton({super.key, required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.centerLeft,
      child: Material(
        color: AppColors.wash,
        shape: const CircleBorder(),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: onTap,
          child: const SizedBox(
            width: 44,
            height: 44,
            child: Center(child: AppIcon(AppIcons.back, size: 20)),
          ),
        ),
      ),
    );
  }
}

class AuthHero extends StatelessWidget {
  const AuthHero({
    super.key,
    required this.title,
    required this.subtitle,
    this.icon,
  });

  final String title;
  final String subtitle;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        if (icon != null) ...[
          Container(
            width: 72,
            height: 72,
            decoration: const BoxDecoration(
              color: AppColors.limeSoft,
              shape: BoxShape.circle,
            ),
            child: Center(child: AppIcon(icon!, size: 32)),
          ),
          const SizedBox(height: 20),
        ],
        Text(
          title,
          textAlign: TextAlign.center,
          style: const TextStyle(
            fontSize: 28,
            fontWeight: FontWeight.w900,
            letterSpacing: -0.4,
            color: AppColors.ink,
          ),
        ),
        const SizedBox(height: 10),
        Text(
          subtitle,
          textAlign: TextAlign.center,
          style: const TextStyle(
            fontSize: 14,
            fontWeight: FontWeight.w600,
            height: 1.45,
            color: AppColors.mute,
          ),
        ),
      ],
    );
  }
}

class AuthFieldLabel extends StatelessWidget {
  const AuthFieldLabel(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text.toUpperCase(),
      style: const TextStyle(
        fontSize: 11,
        fontWeight: FontWeight.w800,
        letterSpacing: 0.8,
        color: AppColors.mute,
      ),
    );
  }
}

class AuthErrorBanner extends StatelessWidget {
  const AuthErrorBanner(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: AppColors.danger.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          const AppIcon(AppIcons.alert, size: 18, color: AppColors.danger),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: const TextStyle(
                color: AppColors.danger,
                fontSize: 13,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class AuthOrDivider extends StatelessWidget {
  const AuthOrDivider({super.key});

  @override
  Widget build(BuildContext context) {
    return const Row(
      children: [
        Expanded(child: Divider(color: AppColors.line)),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: 12),
          child: Text(
            'or',
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w700,
              color: AppColors.mute,
            ),
          ),
        ),
        Expanded(child: Divider(color: AppColors.line)),
      ],
    );
  }
}

/// Bottom sheet used after login / register / pending.
Future<void> showAuthResultSheet(
  BuildContext context, {
  required String title,
  required String body,
  required String actionLabel,
  required VoidCallback onAction,
  IconData icon = AppIcons.check,
  Color iconWash = AppColors.limeSoft,
  Color iconColor = AppColors.ink,
  Duration? autoContinue,
}) {
  var done = false;
  void finish(BuildContext sheetContext) {
    if (done) return;
    done = true;
    if (sheetContext.mounted) Navigator.of(sheetContext).pop();
    onAction();
  }

  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    backgroundColor: AppColors.surface,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
    ),
    builder: (ctx) {
      if (autoContinue != null) {
        Future<void>.delayed(autoContinue, () {
          if (ctx.mounted) finish(ctx);
        });
      }
      final bottom = MediaQuery.paddingOf(ctx).bottom;
      return SafeArea(
        child: Padding(
          padding: EdgeInsets.fromLTRB(24, 12, 24, bottom + 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: AppColors.line,
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
              const SizedBox(height: 28),
              Container(
                width: 72,
                height: 72,
                decoration: BoxDecoration(
                  color: iconWash,
                  shape: BoxShape.circle,
                ),
                child: Center(child: AppIcon(icon, size: 32, color: iconColor)),
              ),
              const SizedBox(height: 20),
              Text(
                title,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 24,
                  fontWeight: FontWeight.w900,
                  letterSpacing: -0.3,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                body,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  height: 1.45,
                  color: AppColors.mute,
                ),
              ),
              const SizedBox(height: 28),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: () => finish(ctx),
                  child: Text(actionLabel),
                ),
              ),
            ],
          ),
        ),
      );
    },
  );
}
