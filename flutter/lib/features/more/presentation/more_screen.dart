import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/config/api_config.dart';
import 'package:schoolbook/core/server_url_store.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/shared/widgets/app_drawer.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';

class MoreScreen extends StatefulWidget {
  const MoreScreen({super.key});

  @override
  State<MoreScreen> createState() => _MoreScreenState();
}

class _MoreScreenState extends State<MoreScreen> {
  int _titleTaps = 0;
  DateTime? _lastTitleTap;

  void _onTitleTap() {
    final now = DateTime.now();
    if (_lastTitleTap == null ||
        now.difference(_lastTitleTap!) > const Duration(seconds: 2)) {
      _titleTaps = 0;
    }
    _lastTitleTap = now;
    _titleTaps++;
    // Emulator-friendly: 7 quick taps (long-press also works).
    if (_titleTaps >= 7) {
      _titleTaps = 0;
      _openHiddenServerSetup(context);
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final name = auth.user?.name.trim();
    final email = auth.user?.email.trim() ?? '';
    final displayName =
        (name != null && name.isNotEmpty) ? name : 'Owner';
    final initials = _initials(displayName);
    final top = MediaQuery.paddingOf(context).top;
    final bottom = MediaQuery.paddingOf(context).bottom;

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: ListView(
        padding: EdgeInsets.fromLTRB(20, top + 12, 20, bottom + 12),
        children: [
          // Large hit target — long-press or 7 quick taps (emulator-friendly).
          GestureDetector(
            key: const Key('more_title'),
            behavior: HitTestBehavior.opaque,
            onTap: _onTitleTap,
            onLongPress: () => _openHiddenServerSetup(context),
            child: const Padding(
              padding: EdgeInsets.symmetric(vertical: 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'More',
                    style: TextStyle(
                      fontSize: 28,
                      fontWeight: FontWeight.w900,
                      letterSpacing: -0.6,
                      height: 1,
                      color: AppColors.ink,
                    ),
                  ),
                  SizedBox(height: 6),
                  Text(
                    'Catalog, stock, money, and account',
                    style: TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w600,
                      color: AppColors.mute,
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 22),
          GestureDetector(
            key: const Key('more_profile_secret'),
            behavior: HitTestBehavior.opaque,
            onLongPress: () => _openHiddenServerSetup(context),
            child: _ProfileCard(
              initials: initials,
              name: displayName,
              email: email,
              role: auth.user?.role ?? 'owner',
            ),
          ),
          const SizedBox(height: 28),
          const _SectionTitle('Catalog'),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _DestTile(
                  key: const Key('more_products'),
                  title: 'Products',
                  subtitle: 'Search and edit',
                  icon: AppIcons.products,
                  onTap: () => context.push('/products'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _DestTile(
                  key: const Key('more_approved'),
                  title: 'Approved list',
                  subtitle: 'NaCCA books',
                  icon: AppIcons.approvedList,
                  onTap: () => context.push('/products/approved'),
                ),
              ),
            ],
          ),
          const SizedBox(height: 24),
          const _SectionTitle('Operations'),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _DestTile(
                  key: const Key('more_stock'),
                  title: 'Stock',
                  subtitle: 'Receive & count',
                  icon: AppIcons.stock,
                  onTap: () => context.push('/stock'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _DestTile(
                  key: const Key('more_payments'),
                  title: 'Payments',
                  subtitle: 'Receipts & voids',
                  icon: AppIcons.payment,
                  onTap: () => context.push('/payments'),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _DestTile(
                  key: const Key('more_directory'),
                  title: 'Directory',
                  subtitle: 'Find a school',
                  icon: AppIcons.schools,
                  onTap: () => context.push('/customers/directory'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _DestTile(
                  key: const Key('more_reports'),
                  title: 'Reports',
                  subtitle: 'Same as admin',
                  icon: AppIcons.reports,
                  onTap: () => context.push('/reports'),
                ),
              ),
            ],
          ),
          const SizedBox(height: 28),
          const _SectionTitle('Account'),
          const SizedBox(height: 12),
          Material(
            color: AppColors.surface,
            borderRadius: BorderRadius.circular(12),
            child: InkWell(
              key: const Key('more_sign_out'),
              borderRadius: BorderRadius.circular(12),
              onTap: auth.isBusy ? null : () => auth.logout(),
              child: Container(
                padding: const EdgeInsets.fromLTRB(14, 14, 14, 14),
                decoration: BoxDecoration(
                  border: Border.all(color: AppColors.danger.withValues(alpha: 0.35)),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Row(
                  children: [
                    Container(
                      width: 40,
                      height: 40,
                      decoration: BoxDecoration(
                        color: AppColors.danger.withValues(alpha: 0.1),
                        border: Border.all(
                          color: AppColors.danger.withValues(alpha: 0.25),
                        ),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: const Center(
                        child: AppIcon(
                          AppIcons.logout,
                          size: 18,
                          color: AppColors.danger,
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    const Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Sign out',
                            style: TextStyle(
                              fontSize: 16,
                              fontWeight: FontWeight.w800,
                              color: AppColors.danger,
                            ),
                          ),
                          SizedBox(height: 2),
                          Text(
                            'End this session on this device',
                            style: TextStyle(
                              fontSize: 12.5,
                              fontWeight: FontWeight.w600,
                              color: AppColors.mute,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const AppIcon(
                      AppIcons.chevronRight,
                      size: 18,
                      color: AppColors.mute,
                    ),
                  ],
                ),
              ),
            ),
          ),
          const SizedBox(height: 24),
          const Text(
            'Business settings (invoice footer, payment terms) stay on the web admin.',
            style: TextStyle(
              fontSize: 12.5,
              height: 1.45,
              fontWeight: FontWeight.w600,
              color: AppColors.mute,
            ),
          ),
          const SizedBox(height: 16),
        ],
      ),
    );
  }

  static String _initials(String name) {
    final parts = name
        .split(RegExp(r'\s+'))
        .where((p) => p.isNotEmpty)
        .toList();
    if (parts.isEmpty) return 'SB';
    if (parts.length == 1) {
      final w = parts.first;
      return w.substring(0, w.length >= 2 ? 2 : 1).toUpperCase();
    }
    return ('${parts.first[0]}${parts.last[0]}').toUpperCase();
  }
}

class _ProfileCard extends StatelessWidget {
  const _ProfileCard({
    required this.initials,
    required this.name,
    required this.email,
    required this.role,
  });

  final String initials;
  final String name;
  final String email;
  final String role;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
      decoration: BoxDecoration(
        color: AppColors.ink,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          Container(
            width: 52,
            height: 52,
            decoration: BoxDecoration(
              color: AppColors.lime,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Center(
              child: Text(
                initials,
                style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w900,
                  color: AppColors.ink,
                ),
              ),
            ),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                    color: Colors.white,
                  ),
                ),
                if (email.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    email,
                    style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: Colors.white.withValues(alpha: 0.62),
                    ),
                  ),
                ],
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 5,
                  ),
                  decoration: BoxDecoration(
                    color: AppColors.lime.withValues(alpha: 0.18),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    role.isEmpty
                        ? 'Owner'
                        : '${role[0].toUpperCase()}${role.substring(1)}',
                    style: const TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w800,
                      color: AppColors.lime,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text);

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

/// Hidden entry: long-press the "More" title. Nothing visible on the page.
Future<void> _openHiddenServerSetup(BuildContext context) async {
  if (!ApiConfig.serverToolsAvailable) return;

  if (ApiConfig.serverToolsPinRequired) {
    if (!ApiConfig.serverToolsPinConfigured) {
      AppToast.show(
        context,
        'This APK has no setup PIN. Rebuild with DEV_SERVER_PIN.',
        type: ToastType.error,
      );
      return;
    }
    final ok = await _promptSetupPin(context);
    if (!context.mounted) return;
    if (ok != true) {
      if (ok == false) {
        AppToast.show(context, 'Wrong PIN', type: ToastType.error);
      }
      return;
    }
  }

  await showAppDrawer<void>(
    context: context,
    useRootNavigator: true,
    builder: (context) => const AppDrawerScaffold(
      child: _ServerSetupDrawer(),
    ),
  );
}

Future<bool?> _promptSetupPin(BuildContext context) {
  return showAppDrawer<bool>(
    context: context,
    builder: (context) => const AppDrawerScaffold(
      child: _SetupPinDrawer(),
    ),
  );
}

class _SetupPinDrawer extends StatefulWidget {
  const _SetupPinDrawer();

  @override
  State<_SetupPinDrawer> createState() => _SetupPinDrawerState();
}

class _SetupPinDrawerState extends State<_SetupPinDrawer> {
  final _pin = TextEditingController();
  var _obscure = true;
  var _busy = false;

  @override
  void dispose() {
    _pin.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) return;
    setState(() => _busy = true);
    final ok = await _verifyHostSetupPin(context, _pin.text);
    if (!mounted) return;
    Navigator.pop(context, ok);
  }

  @override
  Widget build(BuildContext context) {
    const r = 12.0;
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text(
          'Host setup',
          style: TextStyle(fontSize: 20, fontWeight: FontWeight.w900),
        ),
        const SizedBox(height: 8),
        const Text(
          'Enter the host PIN from Filament → Mobile app (or the APK PIN if none is set on the server).',
          style: TextStyle(fontSize: 13, color: AppColors.mute, height: 1.35),
        ),
        const SizedBox(height: 14),
        TextField(
          key: const Key('dev_server_pin'),
          controller: _pin,
          obscureText: _obscure,
          autofocus: true,
          decoration: InputDecoration(
            labelText: 'Host PIN',
            suffixIcon: IconButton(
              tooltip: _obscure ? 'Show PIN' : 'Hide PIN',
              onPressed: () => setState(() => _obscure = !_obscure),
              icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(r),
              borderSide: const BorderSide(color: AppColors.line),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(r),
              borderSide: const BorderSide(color: AppColors.ink, width: 1.5),
            ),
          ),
          onSubmitted: (_) => _submit(),
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const Key('dev_server_pin_submit'),
          style: FilledButton.styleFrom(
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(r),
            ),
          ),
          onPressed: _busy ? null : _submit,
          child: Text(_busy ? 'Checking…' : 'OK'),
        ),
      ],
    );
  }
}

/// Prefer the PIN set on the current API host; fall back to the APK's compile-time PIN.
Future<bool> _verifyHostSetupPin(BuildContext context, String pin) async {
  final trimmed = pin.trim();
  if (trimmed.isEmpty) return false;

  final baseUrl = context.read<ApiClient>().baseUrl;
  try {
    final dio = Dio(
      BaseOptions(
        baseUrl: baseUrl,
        connectTimeout: const Duration(seconds: 8),
        receiveTimeout: const Duration(seconds: 8),
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      ),
    );
    final res = await dio.post<Map<String, dynamic>>(
      '/auth/verify-setup-pin',
      data: {'pin': trimmed},
    );
    final data = res.data;
    if (data != null && data['valid'] == true) return true;
    if (data != null && data['code'] == 'pin_not_configured') {
      return ApiConfig.serverToolsPinConfigured &&
          trimmed == ApiConfig.serverToolsPin;
    }
  } on DioException catch (e) {
    final data = e.response?.data;
    if (data is Map && data['code'] == 'pin_not_configured') {
      return ApiConfig.serverToolsPinConfigured &&
          trimmed == ApiConfig.serverToolsPin;
    }
    // Offline / wrong host: still allow the baked APK PIN.
    if (e.response == null) {
      return ApiConfig.serverToolsPinConfigured &&
          trimmed == ApiConfig.serverToolsPin;
    }
  }

  return false;
}

class _ServerSetupDrawer extends StatefulWidget {
  const _ServerSetupDrawer();

  @override
  State<_ServerSetupDrawer> createState() => _ServerSetupDrawerState();
}

class _ServerSetupDrawerState extends State<_ServerSetupDrawer> {
  late final TextEditingController _url;
  bool _saving = false;
  String? _activeUrl;

  static const _r = 12.0;

  @override
  void initState() {
    super.initState();
    _url = TextEditingController();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _load();
    });
  }

  @override
  void dispose() {
    _url.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final api = context.read<ApiClient>();
    final stored = await context.read<ServerUrlStore>().read();
    if (!mounted) return;
    setState(() {
      _activeUrl = stored ?? api.baseUrl;
      _url.text = _activeUrl ?? '';
    });
  }

  Future<void> _save() async {
    final normalized = ApiConfig.normalizeBaseUrl(_url.text);
    if (normalized == null) {
      AppToast.show(
        context,
        'Enter a valid http(s) host (…/api/v1 is added if missing).',
        type: ToastType.error,
      );
      return;
    }
    setState(() => _saving = true);
    try {
      await context.read<ServerUrlStore>().write(normalized);
      if (!mounted) return;
      context.read<ApiClient>().setBaseUrl(normalized);
      setState(() => _activeUrl = normalized);
      AppToast.show(
        context,
        'Saved. Sign out and back in if the session breaks.',
        type: ToastType.success,
      );
      Navigator.pop(context);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _reset() async {
    setState(() => _saving = true);
    try {
      await context.read<ServerUrlStore>().clear();
      if (!mounted) return;
      context.read<ApiClient>().setBaseUrl(ApiConfig.defaultBaseUrl);
      setState(() {
        _activeUrl = ApiConfig.defaultBaseUrl;
        _url.text = ApiConfig.defaultBaseUrl;
      });
      AppToast.show(context, 'Back to build default.', type: ToastType.info);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text(
          'Host',
          style: TextStyle(fontSize: 20, fontWeight: FontWeight.w900),
        ),
        const SizedBox(height: 8),
        Text(
          _activeUrl ?? '—',
          style: const TextStyle(
            fontSize: 12.5,
            fontWeight: FontWeight.w700,
            color: AppColors.mute,
            height: 1.35,
          ),
        ),
        const SizedBox(height: 14),
        TextField(
          key: const Key('more_server_url'),
          controller: _url,
          keyboardType: TextInputType.url,
          decoration: InputDecoration(
            labelText: 'URL',
            hintText: 'https://host',
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(_r),
              borderSide: const BorderSide(color: AppColors.line),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(_r),
              borderSide: const BorderSide(color: AppColors.ink, width: 1.5),
            ),
          ),
        ),
        const SizedBox(height: 14),
        Row(
          children: [
            Expanded(
              child: OutlinedButton(
                key: const Key('more_server_reset'),
                style: OutlinedButton.styleFrom(
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(_r),
                  ),
                ),
                onPressed: _saving ? null : _reset,
                child: const Text('Default'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              flex: 2,
              child: FilledButton(
                key: const Key('more_server_save'),
                style: FilledButton.styleFrom(
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(_r),
                  ),
                ),
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Saving…' : 'Save'),
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _DestTile extends StatelessWidget {
  const _DestTile({
    super.key,
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.onTap,
  });

  final String title;
  final String subtitle;
  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.surface,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.fromLTRB(14, 14, 14, 14),
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.line),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: AppColors.wash,
                  border: Border.all(color: AppColors.line),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Center(
                  child: AppIcon(icon, size: 18, color: AppColors.ink),
                ),
              ),
              const SizedBox(height: 12),
              Text(
                title,
                style: const TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 3),
              Text(
                subtitle,
                style: const TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: AppColors.mute,
                  height: 1.3,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
