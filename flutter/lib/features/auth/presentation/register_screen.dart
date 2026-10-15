import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';
import 'package:schoolbook/features/auth/presentation/auth_chrome.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/features/auth/presentation/register_provider.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/loading_overlay.dart';

class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen>
    with SingleTickerProviderStateMixin {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final _confirmController = TextEditingController();
  bool _obscurePassword = true;
  bool _obscureConfirm = true;
  bool _agreedToTerms = false;
  late final AnimationController _anim;
  late final Animation<double> _fade;
  late final Animation<Offset> _slide;
  final _registerProvider = RegisterProvider();

  @override
  void initState() {
    super.initState();
    _anim = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 500),
    );
    _fade = CurvedAnimation(parent: _anim, curve: Curves.easeOut);
    _slide = Tween<Offset>(
      begin: const Offset(0, 0.04),
      end: Offset.zero,
    ).animate(CurvedAnimation(parent: _anim, curve: Curves.easeOut));
    _anim.forward();
  }

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _passwordController.dispose();
    _confirmController.dispose();
    _anim.dispose();
    _registerProvider.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (!_agreedToTerms) {
      AppToast.show(
        context,
        'Please agree to the Terms & Privacy Policy',
        type: ToastType.warning,
      );
      return;
    }
    final repo = context.read<AuthRepository>();
    final auth = context.read<AuthProvider>();
    final email = _emailController.text.trim();
    final ok = await _registerProvider.register(
      repo,
      name: _nameController.text.trim(),
      email: email,
      password: _passwordController.text,
    );
    if (ok && mounted) {
      await auth.setPendingRegistration(email);
      if (!mounted) return;
      await showAuthResultSheet(
        context,
        title: 'Account created',
        body:
            'An admin still needs to activate your account. Check status anytime.',
        actionLabel: 'Check my status',
        icon: AppIcons.hourglass,
        iconWash: const Color(0xFFFFF1E0),
        iconColor: AppColors.warn,
        autoContinue: const Duration(seconds: 6),
        onAction: () {
          if (mounted) {
            context.go('/waiting-room?email=${Uri.encodeComponent(email)}');
          }
        },
      );
    }
  }

  void _goLogin() {
    if (context.canPop()) {
      context.pop();
    } else {
      context.go('/login');
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _registerProvider,
      builder: (context, _) {
        final reg = _registerProvider;
        return LoadingOverlay(
          isLoading: reg.isBusy,
          message: 'Creating account…',
          child: AuthScaffold(
            child: FadeTransition(
              opacity: _fade,
              child: SlideTransition(
                position: _slide,
                child: SingleChildScrollView(
                  padding: const EdgeInsets.symmetric(horizontal: 24),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const SizedBox(height: 12),
                      AuthBackButton(onTap: _goLogin),
                      const SizedBox(height: 28),
                      const AuthHero(
                        title: 'Create your account',
                        subtitle:
                            'Name, email, and password are enough to get started.',
                      ),
                      const SizedBox(height: 28),
                      OutlinedButton(
                        onPressed: () => AppToast.show(
                          context,
                          'Google Sign-In coming soon',
                        ),
                        child: const Text('Continue with Google'),
                      ),
                      const SizedBox(height: 18),
                      const AuthOrDivider(),
                      const SizedBox(height: 18),
                      Form(
                        key: _formKey,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            const AuthFieldLabel('Full name'),
                            const SizedBox(height: 8),
                            TextFormField(
                              controller: _nameController,
                              textCapitalization: TextCapitalization.words,
                              decoration: const InputDecoration(
                                hintText: 'Your name',
                              ),
                              validator: (v) => v == null || v.trim().isEmpty
                                  ? 'Name required'
                                  : null,
                            ),
                            const SizedBox(height: 14),
                            const AuthFieldLabel('Email'),
                            const SizedBox(height: 8),
                            TextFormField(
                              controller: _emailController,
                              keyboardType: TextInputType.emailAddress,
                              decoration: const InputDecoration(
                                hintText: 'you@school.edu.gh',
                              ),
                              validator: (v) => v == null || v.trim().isEmpty
                                  ? 'Email required'
                                  : null,
                            ),
                            const SizedBox(height: 14),
                            const AuthFieldLabel('Password'),
                            const SizedBox(height: 8),
                            TextFormField(
                              controller: _passwordController,
                              obscureText: _obscurePassword,
                              decoration: InputDecoration(
                                hintText: '••••••••',
                                suffixIcon: IconButton(
                                  onPressed: () => setState(
                                    () =>
                                        _obscurePassword = !_obscurePassword,
                                  ),
                                  icon: AppIcon(
                                    _obscurePassword
                                        ? AppIcons.visibilityOff
                                        : AppIcons.visibility,
                                    size: 20,
                                    color: AppColors.mute,
                                  ),
                                ),
                              ),
                              validator: (v) {
                                if (v == null || v.isEmpty) {
                                  return 'Password required';
                                }
                                if (v.length < 8) {
                                  return 'Minimum 8 characters';
                                }
                                return null;
                              },
                            ),
                            const SizedBox(height: 14),
                            const AuthFieldLabel('Confirm password'),
                            const SizedBox(height: 8),
                            TextFormField(
                              controller: _confirmController,
                              obscureText: _obscureConfirm,
                              decoration: InputDecoration(
                                hintText: '••••••••',
                                suffixIcon: IconButton(
                                  onPressed: () => setState(
                                    () => _obscureConfirm = !_obscureConfirm,
                                  ),
                                  icon: AppIcon(
                                    _obscureConfirm
                                        ? AppIcons.visibilityOff
                                        : AppIcons.visibility,
                                    size: 20,
                                    color: AppColors.mute,
                                  ),
                                ),
                              ),
                              validator: (v) {
                                if (v == null || v.isEmpty) {
                                  return 'Please confirm password';
                                }
                                if (v != _passwordController.text) {
                                  return 'Passwords do not match';
                                }
                                return null;
                              },
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 16),
                      Row(
                        children: [
                          Switch(
                            value: _agreedToTerms,
                            onChanged: (v) =>
                                setState(() => _agreedToTerms = v),
                            activeThumbColor: AppColors.ink,
                            activeTrackColor: AppColors.lime,
                          ),
                          const Expanded(
                            child: Text.rich(
                              TextSpan(
                                style: TextStyle(
                                  fontSize: 13,
                                  fontWeight: FontWeight.w600,
                                  color: AppColors.ink,
                                ),
                                children: [
                                  TextSpan(text: 'I agree to the '),
                                  TextSpan(
                                    text: 'Terms',
                                    style: TextStyle(fontWeight: FontWeight.w900),
                                  ),
                                  TextSpan(text: ' & '),
                                  TextSpan(
                                    text: 'Privacy Policy',
                                    style: TextStyle(fontWeight: FontWeight.w900),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 20),
                      FilledButton(
                        onPressed: reg.isBusy ? null : _submit,
                        child: const Text('Sign Up'),
                      ),
                      if (reg.errorMessage != null) ...[
                        const SizedBox(height: 12),
                        AuthErrorBanner(reg.errorMessage!),
                      ],
                      const SizedBox(height: 28),
                      Center(
                        child: Wrap(
                          crossAxisAlignment: WrapCrossAlignment.center,
                          children: [
                            const Text(
                              'Already have an account? ',
                              style: TextStyle(
                                color: AppColors.mute,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            GestureDetector(
                              onTap: _goLogin,
                              child: const Text(
                                'Sign In',
                                style: TextStyle(
                                  color: AppColors.ink,
                                  fontWeight: FontWeight.w900,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 32),
                    ],
                  ),
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}
