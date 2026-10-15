import 'package:flutter/material.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';

/// Standard white page with optional back, title, and actions.
class AppPage extends StatelessWidget {
  const AppPage({
    super.key,
    required this.title,
    required this.body,
    this.subtitle,
    this.actions,
    this.floatingActionButton,
    this.bottom,
    this.showBack = true,
  });

  final String title;
  final String? subtitle;
  final Widget body;
  final List<Widget>? actions;
  final Widget? floatingActionButton;
  final Widget? bottom;
  final bool showBack;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface,
      floatingActionButton: floatingActionButton,
      bottomNavigationBar: bottom,
      appBar: AppBar(
        backgroundColor: AppColors.surface,
        automaticallyImplyLeading: showBack,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title),
            if (subtitle != null)
              Text(
                subtitle!,
                style: const TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w400,
                  color: AppColors.mute,
                ),
              ),
          ],
        ),
        actions: actions,
      ),
      body: body,
    );
  }
}

class AppSectionLabel extends StatelessWidget {
  const AppSectionLabel(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 20, 20, 8),
      child: Text(
        text.toUpperCase(),
        style: const TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w600,
          letterSpacing: 0.8,
          color: AppColors.mute,
        ),
      ),
    );
  }
}

/// Hairline row for dense ops lists (no card chrome).
class AppRow extends StatelessWidget {
  const AppRow({
    super.key,
    required this.title,
    this.subtitle,
    this.trailing,
    this.leading,
    this.onTap,
    this.warn = false,
  });

  final String title;
  final String? subtitle;
  final Widget? trailing;
  final Widget? leading;
  final VoidCallback? onTap;
  final bool warn;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
        decoration: const BoxDecoration(
          border: Border(bottom: BorderSide(color: AppColors.line)),
        ),
        child: Row(
          children: [
            if (leading != null) ...[leading!, const SizedBox(width: 12)],
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w600,
                      color: warn ? AppColors.warn : AppColors.ink,
                    ),
                  ),
                  if (subtitle != null) ...[
                    const SizedBox(height: 3),
                    Text(
                      subtitle!,
                      style: const TextStyle(fontSize: 12.5, color: AppColors.mute),
                    ),
                  ],
                ],
              ),
            ),
            if (trailing != null) ...[
              const SizedBox(width: 12),
              DefaultTextStyle(
                style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: AppColors.ink,
                  fontFeatures: [FontFeature.tabularFigures()],
                ),
                child: trailing!,
              ),
            ] else if (onTap != null)
              const AppIcon(AppIcons.chevronRight, size: 18, color: AppColors.mute),
          ],
        ),
      ),
    );
  }
}

class AppMetric extends StatelessWidget {
  const AppMetric({
    super.key,
    required this.label,
    required this.value,
    this.note,
    this.onTap,
    this.warn = false,
  });

  final String label;
  final String value;
  final String? note;
  final VoidCallback? onTap;
  final bool warn;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          border: Border.all(color: AppColors.line),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label,
              style: const TextStyle(fontSize: 12, color: AppColors.mute),
            ),
            const SizedBox(height: 8),
            Text(
              value,
              style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.w700,
                letterSpacing: -0.3,
                color: warn ? AppColors.warn : AppColors.ink,
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
            if (note != null) ...[
              const SizedBox(height: 4),
              Text(
                note!,
                style: const TextStyle(fontSize: 11.5, color: AppColors.mute),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class AppNavLink extends StatelessWidget {
  const AppNavLink({
    super.key,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.trailingLabel,
  });

  final String title;
  final String subtitle;
  final String? trailingLabel;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return AppRow(
      title: title,
      subtitle: subtitle,
      onTap: onTap,
      trailing: trailingLabel == null
          ? null
          : Text(
              trailingLabel!,
              style: const TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w600,
                color: AppColors.mute,
              ),
            ),
    );
  }
}
