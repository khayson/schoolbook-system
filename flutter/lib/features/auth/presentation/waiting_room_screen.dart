import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';

class WaitingRoomScreen extends StatefulWidget {
  const WaitingRoomScreen({super.key, required this.email});

  final String email;

  @override
  State<WaitingRoomScreen> createState() => _WaitingRoomScreenState();
}

class _WaitingRoomScreenState extends State<WaitingRoomScreen> {
  String _status = 'pending';
  bool _isBusy = false;
  String? _registeredAt;

  Future<void> _checkStatus() async {
    if (widget.email.isEmpty) return;
    setState(() => _isBusy = true);
    try {
      final repo = context.read<AuthRepository>();
      final result = await repo.checkAccountStatus(widget.email);
      final s = result['status'] as String? ?? 'pending';
      setState(() {
        _status = s;
        _registeredAt = result['registered_at'] as String?;
      });
      if (s == 'active' && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content:
                Text('Your account is now active! Please sign in.'),
            behavior: SnackBarBehavior.floating,
          ),
        );
        await Future.delayed(const Duration(milliseconds: 1500));
        if (mounted) context.go('/login');
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Could not check status: $e'),
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _isBusy = false);
    }
  }

  Widget _statusStep({
    required String label,
    required bool done,
    required String number,
  }) {
    return Column(
      children: [
        Container(
          width: 32,
          height: 32,
          decoration: BoxDecoration(
            color: done
                ? const Color(0xFF22C55E)
                : const Color(0xFFF3F4F6),
            shape: BoxShape.circle,
            border: Border.all(
                color: done
                    ? const Color(0xFF22C55E)
                    : const Color(0xFFE5E7EB)),
          ),
          child: Center(
            child: done
                ? const Icon(Icons.check_rounded,
                    color: Colors.white, size: 16)
                : Text(
                    number,
                    style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        color: Colors.grey.shade500),
                  ),
          ),
        ),
        const SizedBox(height: 4),
        Text(
          label,
          style: TextStyle(
              fontSize: 10,
              color: done
                  ? const Color(0xFF22C55E)
                  : Colors.grey.shade400),
        ),
      ],
    );
  }

  Widget _infoCard({
    required IconData icon,
    required Color iconColor,
    required String title,
    required String subtitle,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFE8E8E8)),
      ),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: const Color(0xFFF8F8F8),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, color: iconColor, size: 20),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title,
                    style: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        color: Colors.black87)),
                const SizedBox(height: 2),
                Text(subtitle,
                    style: TextStyle(
                        fontSize: 12, color: Colors.grey.shade500)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SizedBox(height: 16),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: GestureDetector(
                      onTap: () => context.go('/login'),
                      child: Container(
                        width: 32,
                        height: 32,
                        decoration: BoxDecoration(
                          border: Border.all(
                              color: const Color(0xFFE8E8E8)),
                          borderRadius: BorderRadius.circular(50),
                        ),
                        child: const Icon(
                          Icons.arrow_back_ios_new,
                          size: 15,
                          color: Colors.black,
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 48),
                  // Status illustration
                  Center(
                    child: SizedBox(
                      width: 140,
                      height: 140,
                      child: Stack(
                        alignment: Alignment.center,
                        children: [
                          Container(
                            width: 140,
                            height: 140,
                            decoration: const BoxDecoration(
                              color: Color(0xFFF0FDF4),
                              shape: BoxShape.circle,
                            ),
                          ),
                          Container(
                            width: 96,
                            height: 96,
                            decoration: const BoxDecoration(
                              color: Color(0xFFDCFCE7),
                              shape: BoxShape.circle,
                            ),
                          ),
                          const Icon(
                            Icons.hourglass_bottom_rounded,
                            size: 40,
                            color: Color(0xFF16A34A),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 20),
                  // Status steps row
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      _statusStep(
                          label: 'Registered',
                          done: true,
                          number: '1'),
                      Expanded(
                        child: Container(
                          height: 1,
                          margin: const EdgeInsets.only(bottom: 20),
                          color: const Color(0xFFE5E7EB),
                        ),
                      ),
                      _statusStep(
                          label: 'Under Review',
                          done: _status == 'active',
                          number: '2'),
                      Expanded(
                        child: Container(
                          height: 1,
                          margin: const EdgeInsets.only(bottom: 20),
                          color: const Color(0xFFE5E7EB),
                        ),
                      ),
                      _statusStep(
                          label: 'Access Granted',
                          done: _status == 'active',
                          number: '3'),
                    ],
                  ),
                  const SizedBox(height: 28),
                  const Text(
                    'Account Under Review',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                        fontSize: 24,
                        fontWeight: FontWeight.w700,
                        color: Colors.black),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    'Your account has been created and is currently being reviewed by our team. This usually takes 1–2 business days.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                        fontSize: 14,
                        color: Colors.grey.shade600,
                        height: 1.6),
                  ),
                  const SizedBox(height: 28),
                  _infoCard(
                    icon: Icons.verified_user_outlined,
                    iconColor: const Color(0xFF0B6E4F),
                    title: 'Identity Verified',
                    subtitle:
                        'Your email and details have been recorded',
                  ),
                  _infoCard(
                    icon: Icons.shield_outlined,
                    iconColor: const Color(0xFF3B82F6),
                    title: 'Security Review',
                    subtitle:
                        'Our team verifies each account manually',
                  ),
                  _infoCard(
                    icon: Icons.notifications_outlined,
                    iconColor: const Color(0xFFF59E0B),
                    title: "You'll Be Notified",
                    subtitle:
                        "We'll contact you once your account is approved",
                  ),
                  const SizedBox(height: 24),
                  // Status banner
                  Container(
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: const Color(0xFFF0FDF4),
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(
                          color: const Color(0xFFBBF7D0)),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Container(
                              width: 8,
                              height: 8,
                              decoration: const BoxDecoration(
                                color: Color(0xFF22C55E),
                                shape: BoxShape.circle,
                              ),
                            ),
                            const SizedBox(width: 8),
                            Text(
                              'Status: ${_status == 'active' ? 'Active' : 'Pending Approval'}',
                              style: const TextStyle(
                                  fontSize: 13,
                                  fontWeight: FontWeight.w600,
                                  color: Colors.black87),
                            ),
                            const Spacer(),
                            if (_registeredAt != null)
                              Text(
                                'Registered',
                                style: TextStyle(
                                    fontSize: 11,
                                    color: Colors.grey.shade500),
                              ),
                          ],
                        ),
                        if (widget.email.isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Text(
                            widget.email,
                            style: TextStyle(
                                fontSize: 12,
                                color: Colors.grey.shade400),
                          ),
                        ],
                      ],
                    ),
                  ),
                  const SizedBox(height: 32),
                  OutlinedButton(
                    onPressed: _isBusy ? null : _checkStatus,
                    style: OutlinedButton.styleFrom(
                      side: const BorderSide(
                          color: Colors.black, width: 1.5),
                      shape: const StadiumBorder(),
                      minimumSize: const Size(double.infinity, 52),
                      foregroundColor: Colors.black,
                    ),
                    child: _isBusy
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(
                                strokeWidth: 2.5,
                                color: Colors.black),
                          )
                        : const Text(
                            'Check Status',
                            style: TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.w600),
                          ),
                  ),
                  const SizedBox(height: 16),
                  Center(
                    child: TextButton(
                      onPressed: () => context.go('/login'),
                      style: TextButton.styleFrom(
                          foregroundColor: Colors.black),
                      child: const Text(
                        'Back to Sign In',
                        style: TextStyle(fontSize: 14),
                      ),
                    ),
                  ),
                  const SizedBox(height: 24),
                  Center(
                    child: Text(
                      'Powered by Schoolbook Supply',
                      style: TextStyle(
                          fontSize: 11, color: Colors.grey.shade400),
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
