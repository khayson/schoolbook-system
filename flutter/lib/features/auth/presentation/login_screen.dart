import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/auth/presentation/auth_chrome.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/loading_overlay.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen>
    with SingleTickerProviderStateMixin {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _obscurePassword = true;
  bool _rememberMe = false;
  late final AnimationController _anim;
  late final Animation<double> _fade;
  late final Animation<Offset> _slide;

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
    _emailController.dispose();
    _passwordController.dispose();
    _anim.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final auth = context.read<AuthProvider>();
    final email = _emailController.text.trim();
    final password = _passwordController.text;
    final ok = await auth.login(email, password);
    if (!mounted) return;
    if (ok) {
      await showAuthResultSheet(
        context,
        title: 'Signed in',
        body: 'Your account is ready. Continue to the home screen.',
        actionLabel: 'Continue',
        autoContinue: const Duration(seconds: 6),
        onAction: () {
          if (mounted) context.go('/');
        },
      );
    } else if (auth.status == AuthStatus.pendingApproval) {
      await showAuthResultSheet(
        context,
        title: 'Account pending',
        body:
            'Your account is awaiting admin approval. You will be notified once it is activated.',
        actionLabel: 'Check my status',
        icon: AppIcons.hourglass,
        iconWash: const Color(0xFFFFF1E0),
        iconColor: AppColors.warn,
        onAction: () {},
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final authBusy = context.watch<AuthProvider>().isBusy;
    return LoadingOverlay(
      isLoading: authBusy,
      message: 'Signing in…',
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
                  if (context.canPop())
                    AuthBackButton(onTap: () => context.pop()),
                  const SizedBox(height: 28),
                  const AuthHero(
                    title: 'Welcome back',
                    subtitle:
                        'Sign in with your email and password to access your account.',
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
                        const AuthFieldLabel('Email'),
                        const SizedBox(height: 8),
                        TextFormField(
                          key: const Key('login_email'),
                          controller: _emailController,
                          keyboardType: TextInputType.emailAddress,
                          textInputAction: TextInputAction.next,
                          validator: (v) =>
                              v == null || v.isEmpty ? 'Email required' : null,
                          decoration: const InputDecoration(
                            hintText: 'you@school.edu.gh',
                          ),
                        ),
                        const SizedBox(height: 14),
                        const AuthFieldLabel('Password'),
                        const SizedBox(height: 8),
                        TextFormField(
                          key: const Key('login_password'),
                          controller: _passwordController,
                          obscureText: _obscurePassword,
                          textInputAction: TextInputAction.done,
                          onFieldSubmitted: (_) => _submit(),
                          validator: (v) => v == null || v.isEmpty
                              ? 'Password required'
                              : null,
                          decoration: InputDecoration(
                            hintText: '••••••••',
                            suffixIcon: IconButton(
                              onPressed: () => setState(
                                () => _obscurePassword = !_obscurePassword,
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
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(
                        child: Row(
                          children: [
                            Switch(
                              value: _rememberMe,
                              onChanged: (v) =>
                                  setState(() => _rememberMe = v),
                              activeThumbColor: AppColors.ink,
                              activeTrackColor: AppColors.lime,
                            ),
                            const Text(
                              'Remember me',
                              style: TextStyle(
                                fontSize: 13,
                                fontWeight: FontWeight.w600,
                                color: AppColors.mute,
                              ),
                            ),
                          ],
                        ),
                      ),
                      TextButton(
                        onPressed: () => context.go('/forgot-password'),
                        child: const Text(
                          'Forgot password?',
                          style: TextStyle(fontWeight: FontWeight.w800),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 20),
                  Consumer<AuthProvider>(
                    builder: (context, auth, _) => Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        FilledButton(
                          key: const Key('login_submit'),
                          onPressed: auth.isBusy ? null : _submit,
                          child: const Text('Sign In'),
                        ),
                        if (auth.errorMessage != null) ...[
                          const SizedBox(height: 12),
                          AuthErrorBanner(auth.errorMessage!),
                        ],
                      ],
                    ),
                  ),
                  const SizedBox(height: 28),
                  Center(
                    child: Wrap(
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        const Text(
                          "Don't have an account? ",
                          style: TextStyle(
                            color: AppColors.mute,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        GestureDetector(
                          onTap: () => context.go('/register'),
                          child: const Text(
                            'Sign Up',
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
  }
}
