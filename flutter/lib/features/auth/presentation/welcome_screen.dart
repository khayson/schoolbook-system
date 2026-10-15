import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/auth/presentation/auth_chrome.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';

/// First-launch screen — brand-first, same quiet NOTEE language as login.
class WelcomeScreen extends StatefulWidget {
  const WelcomeScreen({super.key});

  @override
  State<WelcomeScreen> createState() => _WelcomeScreenState();
}

class _WelcomeScreenState extends State<WelcomeScreen>
    with SingleTickerProviderStateMixin {
  bool _busy = false;
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

  Future<void> _goLogin() async {
    if (_busy) return;
    setState(() => _busy = true);
    await context.read<AuthProvider>().completeOnboarding();
    if (mounted) context.go('/login');
  }

  Future<void> _goRegister() async {
    if (_busy) return;
    setState(() => _busy = true);
    await context.read<AuthProvider>().completeOnboarding();
    if (mounted) context.go('/register');
  }

  @override
  Widget build(BuildContext context) {
    return AuthScaffold(
      child: FadeTransition(
        opacity: _fade,
        child: SlideTransition(
          position: _slide,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Spacer(flex: 2),
                Center(
                  child: Container(
                    width: 64,
                    height: 64,
                    decoration: const BoxDecoration(
                      color: AppColors.lime,
                      shape: BoxShape.circle,
                    ),
                    child: const Center(
                      child: Text(
                        'S',
                        style: TextStyle(
                          fontSize: 28,
                          fontWeight: FontWeight.w900,
                          color: AppColors.ink,
                        ),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 20),
                const Text(
                  'Schoolbook Supply',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    fontSize: 28,
                    fontWeight: FontWeight.w900,
                    letterSpacing: -0.4,
                    color: AppColors.ink,
                  ),
                ),
                const SizedBox(height: 10),
                const Text(
                  'Sell to schools, keep stock honest, and know who still owes — from your phone.',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    fontSize: 14,
                    height: 1.5,
                    fontWeight: FontWeight.w600,
                    color: AppColors.mute,
                  ),
                ),
                const SizedBox(height: 36),
                const _JobLine(
                  icon: AppIcons.sales,
                  label: 'Record bulk sales and share invoices',
                ),
                const SizedBox(height: 12),
                const _JobLine(
                  icon: AppIcons.stock,
                  label: 'Receive stock and check what is on hand',
                ),
                const SizedBox(height: 12),
                const _JobLine(
                  icon: AppIcons.payment,
                  label: 'Take payments against school balances',
                ),
                const Spacer(flex: 3),
                FilledButton(
                  key: const Key('onboarding_next'),
                  onPressed: _busy ? null : _goLogin,
                  child: _busy
                      ? const SizedBox(
                          width: 22,
                          height: 22,
                          child: CircularProgressIndicator(
                            strokeWidth: 2.5,
                            color: Colors.white,
                          ),
                        )
                      : const Text('Sign In'),
                ),
                const SizedBox(height: 12),
                OutlinedButton(
                  key: const Key('onboarding_skip'),
                  onPressed: _busy ? null : _goRegister,
                  child: const Text('Create an account'),
                ),
                const SizedBox(height: 28),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _JobLine extends StatelessWidget {
  const _JobLine({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 14, 16, 14),
      decoration: BoxDecoration(
        color: AppColors.wash,
        borderRadius: BorderRadius.circular(18),
      ),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: const BoxDecoration(
              color: AppColors.surface,
              shape: BoxShape.circle,
            ),
            child: Center(child: AppIcon(icon, size: 20)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              label,
              style: const TextStyle(
                fontSize: 14,
                fontWeight: FontWeight.w700,
                color: AppColors.ink,
                height: 1.35,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
