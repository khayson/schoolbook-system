import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';
import 'package:schoolbook/features/auth/presentation/register_provider.dart';

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
  late AnimationController _animController;
  late Animation<double> _fadeAnim;
  late Animation<Offset> _slideAnim;

  @override
  void initState() {
    super.initState();
    _animController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 500),
    );
    _fadeAnim =
        CurvedAnimation(parent: _animController, curve: Curves.easeOut);
    _slideAnim = Tween<Offset>(
      begin: const Offset(0, 0.04),
      end: Offset.zero,
    ).animate(
        CurvedAnimation(parent: _animController, curve: Curves.easeOut));
    _animController.forward();
  }

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _passwordController.dispose();
    _confirmController.dispose();
    _animController.dispose();
    super.dispose();
  }

  InputDecoration _fieldDecoration(String hint) {
    return InputDecoration(
      hintText: hint,
      hintStyle: TextStyle(color: Colors.grey.shade400, fontSize: 14),
      filled: true,
      fillColor: Colors.grey.shade50,
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: Color(0xFFE8E8E8), width: 1),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: Colors.black, width: 1.5),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: Colors.red, width: 1),
      ),
      focusedErrorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: Colors.red, width: 1.5),
      ),
      contentPadding:
          const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    );
  }

  void _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (!_agreedToTerms) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please agree to the Terms & Privacy Policy'),
          behavior: SnackBarBehavior.floating,
        ),
      );
      return;
    }
    final repo = context.read<AuthRepository>();
    final provider = context.read<RegisterProvider>();
    final email = _emailController.text.trim();
    final ok = await provider.register(
      repo,
      name: _nameController.text.trim(),
      email: email,
      password: _passwordController.text,
    );
    if (ok && mounted) {
      _showSuccessSheet(email);
    }
  }

  void _showSuccessSheet(String email) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      builder: (ctx) {
        Future.delayed(const Duration(milliseconds: 1500), () {
          if (ctx.mounted) {
            Navigator.of(ctx).pop();
            if (ctx.mounted) {
              ctx.go(
                  '/waiting-room?email=${Uri.encodeComponent(email)}');
            }
          }
        });
        return Padding(
          padding: const EdgeInsets.fromLTRB(24, 40, 24, 32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 72,
                height: 72,
                decoration: const BoxDecoration(
                  color: Color(0xFF22C55E),
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.check_rounded,
                    color: Colors.white, size: 36),
              ),
              const SizedBox(height: 20),
              const Text(
                'Account Created!',
                style: TextStyle(
                    fontSize: 24, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 8),
              Text(
                'Your account is pending admin approval.',
                style: TextStyle(
                    fontSize: 14, color: Colors.grey.shade500),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 32),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: () {
                    Navigator.of(ctx).pop();
                    if (ctx.mounted) {
                      ctx.go(
                          '/waiting-room?email=${Uri.encodeComponent(email)}');
                    }
                  },
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.black,
                    foregroundColor: Colors.white,
                    shape: const StadiumBorder(),
                    minimumSize: const Size(double.infinity, 52),
                  ),
                  child: const Text('Check My Status'),
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider(
      create: (_) => RegisterProvider(),
      child: Consumer<RegisterProvider>(
        builder: (context, reg, _) {
          return Scaffold(
            backgroundColor: Colors.white,
            body: SafeArea(
              child: FadeTransition(
                opacity: _fadeAnim,
                child: SlideTransition(
                  position: _slideAnim,
                  child: SingleChildScrollView(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 24),
                    child: Center(
                      child: ConstrainedBox(
                        constraints:
                            const BoxConstraints(maxWidth: 440),
                        child: Column(
                          crossAxisAlignment:
                              CrossAxisAlignment.stretch,
                          children: [
                            const SizedBox(height: 16),
                            Align(
                              alignment: Alignment.centerLeft,
                              child: GestureDetector(
                                onTap: () {
                                  if (context.canPop()) {
                                    context.pop();
                                  } else {
                                    context.go('/login');
                                  }
                                },
                                child: Container(
                                  width: 32,
                                  height: 32,
                                  decoration: BoxDecoration(
                                    border: Border.all(
                                        color:
                                            const Color(0xFFE8E8E8)),
                                    borderRadius:
                                        BorderRadius.circular(50),
                                  ),
                                  child: const Icon(
                                    Icons.arrow_back_ios_new,
                                    size: 15,
                                    color: Colors.black,
                                  ),
                                ),
                              ),
                            ),
                            const SizedBox(height: 32),
                            const Text(
                              'Create your account',
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                fontSize: 28,
                                fontWeight: FontWeight.w700,
                                color: Colors.black,
                              ),
                            ),
                            const SizedBox(height: 8),
                            Text(
                              'Provide your full name, email, and password to create your account and get started.',
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                fontSize: 13,
                                color: Colors.grey.shade500,
                              ),
                            ),
                            const SizedBox(height: 28),
                            // Google button
                            OutlinedButton(
                              style: OutlinedButton.styleFrom(
                                side: const BorderSide(
                                    color: Color(0xFFE8E8E8)),
                                shape: const StadiumBorder(),
                                backgroundColor: Colors.white,
                                minimumSize:
                                    const Size(double.infinity, 52),
                              ),
                              onPressed: () {
                                ScaffoldMessenger.of(context)
                                    .showSnackBar(
                                  const SnackBar(
                                    content: Text(
                                        'Google Sign-In coming soon'),
                                    behavior:
                                        SnackBarBehavior.floating,
                                  ),
                                );
                              },
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                mainAxisAlignment:
                                    MainAxisAlignment.center,
                                children: const [
                                  Icon(
                                    Icons.g_mobiledata,
                                    color: Colors.red,
                                    size: 26,
                                  ),
                                  SizedBox(width: 6),
                                  Text(
                                    'Continue with Google',
                                    style: TextStyle(
                                      color: Colors.black,
                                      fontWeight: FontWeight.w500,
                                      fontSize: 14,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(height: 20),
                            Row(
                              children: [
                                const Expanded(
                                    child: Divider(
                                        color: Color(0xFFE0E0E0))),
                                Padding(
                                  padding:
                                      const EdgeInsets.symmetric(
                                          horizontal: 12),
                                  child: Text(
                                    'or',
                                    style: TextStyle(
                                        fontSize: 12,
                                        color: Colors.grey.shade500),
                                  ),
                                ),
                                const Expanded(
                                    child: Divider(
                                        color: Color(0xFFE0E0E0))),
                              ],
                            ),
                            const SizedBox(height: 20),
                            Form(
                              key: _formKey,
                              child: Column(
                                crossAxisAlignment:
                                    CrossAxisAlignment.stretch,
                                children: [
                                  const Text(
                                    'Full Name',
                                    style: TextStyle(
                                        fontSize: 13,
                                        fontWeight: FontWeight.w500,
                                        color: Colors.black87),
                                  ),
                                  const SizedBox(height: 6),
                                  TextFormField(
                                    controller: _nameController,
                                    textCapitalization:
                                        TextCapitalization.words,
                                    decoration: _fieldDecoration(
                                        'Ethan Miller'),
                                    validator: (v) => v == null ||
                                            v.trim().isEmpty
                                        ? 'Name required'
                                        : null,
                                  ),
                                  const SizedBox(height: 14),
                                  const Text(
                                    'Email Address',
                                    style: TextStyle(
                                        fontSize: 13,
                                        fontWeight: FontWeight.w500,
                                        color: Colors.black87),
                                  ),
                                  const SizedBox(height: 6),
                                  TextFormField(
                                    controller: _emailController,
                                    keyboardType:
                                        TextInputType.emailAddress,
                                    decoration: _fieldDecoration(
                                        'ethan@example.com'),
                                    validator: (v) => v == null ||
                                            v.trim().isEmpty
                                        ? 'Email required'
                                        : null,
                                  ),
                                  const SizedBox(height: 14),
                                  const Text(
                                    'Password',
                                    style: TextStyle(
                                        fontSize: 13,
                                        fontWeight: FontWeight.w500,
                                        color: Colors.black87),
                                  ),
                                  const SizedBox(height: 6),
                                  TextFormField(
                                    controller: _passwordController,
                                    obscureText: _obscurePassword,
                                    decoration:
                                        _fieldDecoration('••••••••')
                                            .copyWith(
                                      suffixIcon: IconButton(
                                        icon: Icon(
                                          _obscurePassword
                                              ? Icons
                                                  .visibility_off_outlined
                                              : Icons
                                                  .visibility_outlined,
                                          color: Colors.grey.shade400,
                                          size: 20,
                                        ),
                                        onPressed: () => setState(
                                            () => _obscurePassword =
                                                !_obscurePassword),
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
                                  const Text(
                                    'Confirm Password',
                                    style: TextStyle(
                                        fontSize: 13,
                                        fontWeight: FontWeight.w500,
                                        color: Colors.black87),
                                  ),
                                  const SizedBox(height: 6),
                                  TextFormField(
                                    controller: _confirmController,
                                    obscureText: _obscureConfirm,
                                    decoration:
                                        _fieldDecoration('••••••••')
                                            .copyWith(
                                      suffixIcon: IconButton(
                                        icon: Icon(
                                          _obscureConfirm
                                              ? Icons
                                                  .visibility_off_outlined
                                              : Icons
                                                  .visibility_outlined,
                                          color: Colors.grey.shade400,
                                          size: 20,
                                        ),
                                        onPressed: () => setState(
                                            () => _obscureConfirm =
                                                !_obscureConfirm),
                                      ),
                                    ),
                                    validator: (v) {
                                      if (v == null || v.isEmpty) {
                                        return 'Please confirm password';
                                      }
                                      if (v !=
                                          _passwordController.text) {
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
                                Transform.scale(
                                  scale: 0.8,
                                  child: Switch(
                                    value: _agreedToTerms,
                                    onChanged: (v) => setState(
                                        () => _agreedToTerms = v),
                                    activeThumbColor: Colors.black,
                                  ),
                                ),
                                const SizedBox(width: 4),
                                Expanded(
                                  child: Text.rich(
                                    TextSpan(
                                      style: const TextStyle(
                                          fontSize: 13,
                                          color: Colors.black87),
                                      children: const [
                                        TextSpan(
                                            text: 'I agree to the '),
                                        TextSpan(
                                          text: 'Terms',
                                          style: TextStyle(
                                              fontWeight:
                                                  FontWeight.bold),
                                        ),
                                        TextSpan(text: ' & '),
                                        TextSpan(
                                          text: 'Privacy Policy',
                                          style: TextStyle(
                                              fontWeight:
                                                  FontWeight.bold),
                                        ),
                                      ],
                                    ),
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 24),
                            ElevatedButton(
                              onPressed: reg.isBusy ? null : _submit,
                              style: ElevatedButton.styleFrom(
                                backgroundColor: Colors.black,
                                foregroundColor: Colors.white,
                                shape: const StadiumBorder(),
                                minimumSize:
                                    const Size(double.infinity, 56),
                                disabledBackgroundColor: Colors.black,
                              ),
                              child: reg.isBusy
                                  ? const SizedBox(
                                      width: 22,
                                      height: 22,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2.5,
                                        color: Colors.white,
                                      ),
                                    )
                                  : const Text(
                                      'Sign Up',
                                      style: TextStyle(
                                          fontSize: 16,
                                          fontWeight:
                                              FontWeight.w600),
                                    ),
                            ),
                            if (reg.errorMessage != null) ...[
                              const SizedBox(height: 12),
                              Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 14, vertical: 10),
                                decoration: BoxDecoration(
                                  color: Theme.of(context)
                                      .colorScheme
                                      .errorContainer,
                                  borderRadius:
                                      BorderRadius.circular(10),
                                ),
                                child: Row(
                                  children: [
                                    Icon(
                                      Icons.error_outline,
                                      color: Theme.of(context)
                                          .colorScheme
                                          .onErrorContainer,
                                      size: 18,
                                    ),
                                    const SizedBox(width: 8),
                                    Expanded(
                                      child: Text(
                                        reg.errorMessage!,
                                        style: TextStyle(
                                          color: Theme.of(context)
                                              .colorScheme
                                              .onErrorContainer,
                                          fontSize: 13,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                            const SizedBox(height: 28),
                            Center(
                              child: Text.rich(
                                TextSpan(
                                  children: [
                                    TextSpan(
                                      text: 'Already have an account? ',
                                      style: TextStyle(
                                          color: Colors.grey.shade600,
                                          fontSize: 14),
                                    ),
                                    WidgetSpan(
                                      child: GestureDetector(
                                        onTap: () {
                                          if (context.canPop()) {
                                            context.pop();
                                          } else {
                                            context.go('/login');
                                          }
                                        },
                                        child: const Text(
                                          'Sign In',
                                          style: TextStyle(
                                            color: Colors.black,
                                            fontSize: 14,
                                            fontWeight:
                                                FontWeight.w700,
                                          ),
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                            const SizedBox(height: 32),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}
