import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/customers/data/school_directory_repository.dart';
import 'package:schoolbook/features/customers/domain/directory_school.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

/// Find a school in the directory (Greater Accra and Central, from OpenStreetMap) and add
/// it as a customer in one step. Schools not listed are added with "New customer".
class SchoolDirectoryScreen extends StatefulWidget {
  const SchoolDirectoryScreen({super.key});

  @override
  State<SchoolDirectoryScreen> createState() => _SchoolDirectoryScreenState();
}

class _SchoolDirectoryScreenState extends State<SchoolDirectoryScreen> {
  final _search = TextEditingController();
  Timer? _debounce;
  String? _region;
  List<DirectorySchool>? _schools;
  String _attribution = '';
  String? _error;
  int _total = 0;
  int? _adding;
  int _requestId = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final id = ++_requestId;
    setState(() => _error = null);
    try {
      final result = await context.read<SchoolDirectoryRepository>().search(
        search: _search.text,
        region: _region,
      );
      if (!mounted || id != _requestId) {
        return;
      }
      setState(() {
        _schools = result.page.data;
        _total = result.page.meta.total;
        _attribution = result.attribution;
      });
    } on ApiException catch (e) {
      if (mounted && id == _requestId) {
        setState(() => _error = describeApiError(e).body);
      }
    }
  }

  void _onSearchChanged(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), _load);
  }

  Future<void> _open(DirectorySchool school) async {
    if (school.isAdded) {
      await context.push('/customers/${school.customerId}');
      return;
    }
    // Full-screen page — not a sheet — so nothing sits under the keyboard / dock.
    final details = await Navigator.of(context).push<_AddDetails>(
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => _AddSchoolPage(school: school),
      ),
    );
    if (details == null || !mounted) {
      return;
    }
    await _add(school, details);
  }

  /// One idempotency key per school until it succeeds, so a retry after a lost
  /// connection cannot add the school twice.
  Future<void> _add(
    DirectorySchool school,
    _AddDetails details, {
    int? linkCustomerId,
  }) async {
    final router = GoRouter.of(context);
    final store = context.read<PendingSubmissionStore>();
    final repository = context.read<SchoolDirectoryRepository>();
    final intent = 'directory.add.${school.id}';
    setState(() => _adding = school.id);
    try {
      final key = await store.keyFor(intent, {
        'school': school.id,
        'link': linkCustomerId,
      });
      final customer = await repository.addAsCustomer(
        school.id,
        idempotencyKey: key,
        linkCustomerId: linkCustomerId,
        contactPerson: details.contactPerson,
        phone: details.phone,
      );
      await store.complete(intent);
      if (mounted) {
        AppToast.show(
          context,
          '${customer.name} added as ${customer.code}',
          type: ToastType.success,
        );
      }
      router.pushReplacement('/customers/${customer.id}');
    } on ApiException catch (e) {
      if (!e.isOutcomeUnknown) {
        await store.complete(intent);
      }
      if (e.code == 'customer_name_exists' && mounted) {
        setState(() => _adding = null);
        final existingId = e.details['customer_id'];
        final link = await showDialog<bool>(
          context: context,
          builder: (context) => AlertDialog(
            backgroundColor: AppColors.surface,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
            ),
            title: const Text(
              'Already a customer?',
              style: TextStyle(fontWeight: FontWeight.w900),
            ),
            content: Text(
              '${e.details['name']} (${e.details['code']}) is already a customer. Link this school to it instead of adding it again?',
              style: const TextStyle(
                fontWeight: FontWeight.w600,
                color: AppColors.mute,
                height: 1.4,
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text(
                  'Cancel',
                  style: TextStyle(fontWeight: FontWeight.w800),
                ),
              ),
              FilledButton(
                key: const Key('directory_link_existing'),
                onPressed: () => Navigator.pop(context, true),
                child: const Text('Link'),
              ),
            ],
          ),
        );
        if (link == true && existingId is int && mounted) {
          await _add(school, details, linkCustomerId: existingId);
        }
        return;
      }
      if (e.code == 'already_customer' && e.details['customer_id'] is int) {
        router.push('/customers/${e.details['customer_id']}');
        return;
      }
      final d = describeApiError(e);
      if (mounted) {
        AppToast.show(
          context,
          d.body,
          title: d.title,
          type: ToastType.error,
        );
      }
    } finally {
      if (mounted) {
        setState(() => _adding = null);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final schools = _schools;
    final top = MediaQuery.paddingOf(context).top;
    final canPop = Navigator.of(context).canPop();

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: EdgeInsets.fromLTRB(12, top + 8, 16, 0),
            child: Row(
              children: [
                if (canPop)
                  IconButton(
                    onPressed: () => Navigator.of(context).maybePop(),
                    icon: const AppIcon(AppIcons.back),
                  )
                else
                  const SizedBox(width: 8),
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'School directory',
                        style: TextStyle(
                          fontSize: 24,
                          fontWeight: FontWeight.w900,
                          letterSpacing: -0.5,
                          height: 1.1,
                        ),
                      ),
                      SizedBox(height: 4),
                      Text(
                        'Find a school, then add it as a customer',
                        style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                          color: AppColors.mute,
                        ),
                      ),
                    ],
                  ),
                ),
                Material(
                  color: AppColors.wash,
                  shape: const CircleBorder(),
                  child: InkWell(
                    customBorder: const CircleBorder(),
                    onTap: () => context.push('/customers/new'),
                    child: const SizedBox(
                      width: 44,
                      height: 44,
                      child: Center(
                        child: AppIcon(AppIcons.add, size: 22),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: TextField(
              key: const Key('directory_search'),
              controller: _search,
              textInputAction: TextInputAction.search,
              decoration: const InputDecoration(
                hintText: 'School name, district or town',
                prefixIcon: Padding(
                  padding: EdgeInsets.only(left: 14, right: 8),
                  child: AppIcon(AppIcons.search, size: 20),
                ),
                prefixIconConstraints: BoxConstraints(minWidth: 44, minHeight: 44),
              ),
              onChanged: _onSearchChanged,
            ),
          ),
          const SizedBox(height: 14),
          SizedBox(
            key: const Key('directory_region'),
            height: 42,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 20),
              children: [
                _RegionChip(
                  label: 'Both',
                  active: _region == null,
                  onTap: () {
                    setState(() => _region = null);
                    _load();
                  },
                ),
                _RegionChip(
                  label: 'Greater Accra',
                  active: _region == 'Greater Accra',
                  onTap: () {
                    setState(() => _region = 'Greater Accra');
                    _load();
                  },
                ),
                _RegionChip(
                  label: 'Central',
                  active: _region == 'Central',
                  onTap: () {
                    setState(() => _region = 'Central');
                    _load();
                  },
                ),
              ],
            ),
          ),
          const SizedBox(height: 8),
          Expanded(
            child: _error != null
                ? ErrorState(message: _error!, onRetry: _load)
                : schools == null
                ? const LoadingBody(message: 'Loading schools…')
                : schools.isEmpty
                ? EmptyState(
                    title: 'No school found',
                    subtitle:
                        'The directory lists schools mapped on OpenStreetMap. Add any other school manually.',
                    actionLabel: 'Add school manually',
                    onAction: () => context.push('/customers/new'),
                  )
                : RefreshIndicator(
                    color: AppColors.ink,
                    backgroundColor: AppColors.lime,
                    onRefresh: _load,
                    child: ListView.separated(
                      padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
                      itemCount: schools.length + 1,
                      separatorBuilder: (_, _) => const SizedBox(height: 10),
                      itemBuilder: (context, index) {
                        if (index == schools.length) {
                          return Padding(
                            padding: const EdgeInsets.only(top: 8, bottom: 8),
                            child: Text(
                              '${schools.length < _total ? 'Showing ${schools.length} of $_total. Type more of the name to narrow it. ' : ''}$_attribution',
                              style: const TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w600,
                                color: AppColors.mute,
                                height: 1.4,
                              ),
                            ),
                          );
                        }
                        final school = schools[index];
                        return _SchoolCard(
                          key: Key('directory_school_${school.id}'),
                          school: school,
                          busy: _adding == school.id,
                          enabled: _adding == null,
                          onTap: () => _open(school),
                        );
                      },
                    ),
                  ),
          ),
        ],
      ),
    );
  }
}

class _RegionChip extends StatelessWidget {
  const _RegionChip({
    required this.label,
    required this.active,
    required this.onTap,
  });

  final String label;
  final bool active;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: Material(
        color: active ? AppColors.ink : AppColors.wash,
        shape: const StadiumBorder(),
        child: InkWell(
          customBorder: const StadiumBorder(),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            child: Text(
              label,
              style: TextStyle(
                fontSize: 14,
                fontWeight: FontWeight.w800,
                color: active ? AppColors.lime : AppColors.ink,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _SchoolCard extends StatelessWidget {
  const _SchoolCard({
    super.key,
    required this.school,
    required this.busy,
    required this.enabled,
    required this.onTap,
  });

  final DirectorySchool school;
  final bool busy;
  final bool enabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: school.isAdded ? AppColors.limeSoft : AppColors.wash,
      borderRadius: BorderRadius.circular(22),
      child: InkWell(
        onTap: enabled ? onTap : null,
        borderRadius: BorderRadius.circular(22),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 14, 14),
          child: Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: const BoxDecoration(
                  color: AppColors.surface,
                  shape: BoxShape.circle,
                ),
                child: Center(
                  child: AppIcon(
                    school.isAdded ? AppIcons.check : AppIcons.schools,
                    size: 22,
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      school.name,
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                        height: 1.25,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      school.details.isEmpty ? school.region : school.details,
                      style: const TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w600,
                        color: AppColors.mute,
                        height: 1.3,
                      ),
                    ),
                    if (school.details.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Text(
                        school.region,
                        style: const TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          color: AppColors.mute,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 8),
              if (busy)
                const SizedBox(
                  width: 22,
                  height: 22,
                  child: CircularProgressIndicator(
                    strokeWidth: 2.4,
                    color: AppColors.ink,
                  ),
                )
              else if (school.isAdded)
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 5,
                  ),
                  decoration: BoxDecoration(
                    color: AppColors.lime,
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: const Text(
                    'Added',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w800,
                      color: AppColors.ink,
                    ),
                  ),
                )
              else
                const AppIcon(
                  AppIcons.add,
                  size: 20,
                  color: AppColors.mute,
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _AddDetails {
  const _AddDetails({this.contactPerson, this.phone});

  final String? contactPerson;
  final String? phone;
}

/// Full-screen confirm step — keeps every field and the action above the keyboard.
class _AddSchoolPage extends StatefulWidget {
  const _AddSchoolPage({required this.school});

  final DirectorySchool school;

  @override
  State<_AddSchoolPage> createState() => _AddSchoolPageState();
}

class _AddSchoolPageState extends State<_AddSchoolPage> {
  final _contact = TextEditingController();
  late final _phone = TextEditingController(text: widget.school.phone ?? '');

  @override
  void dispose() {
    _contact.dispose();
    _phone.dispose();
    super.dispose();
  }

  void _confirm() {
    Navigator.pop(
      context,
      _AddDetails(contactPerson: _contact.text, phone: _phone.text),
    );
  }

  @override
  Widget build(BuildContext context) {
    final school = widget.school;
    final top = MediaQuery.paddingOf(context).top;
    final bottom = MediaQuery.paddingOf(context).bottom;

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: Column(
        children: [
          Padding(
            padding: EdgeInsets.fromLTRB(8, top + 4, 16, 0),
            child: Row(
              children: [
                IconButton(
                  onPressed: () => Navigator.pop(context),
                  icon: const AppIcon(AppIcons.close),
                ),
                const Expanded(
                  child: Text(
                    'Add as customer',
                    style: TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.w900,
                      letterSpacing: -0.3,
                    ),
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
              children: [
                Container(
                  padding: const EdgeInsets.all(18),
                  decoration: BoxDecoration(
                    color: AppColors.lime,
                    borderRadius: BorderRadius.circular(22),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'School',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w800,
                          color: AppColors.ink,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        school.name,
                        style: const TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w900,
                          letterSpacing: -0.3,
                          height: 1.2,
                          color: AppColors.ink,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        [
                          school.region,
                          school.details,
                        ].where((s) => s.isNotEmpty).join(' · '),
                        style: const TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          color: AppColors.ink,
                          height: 1.35,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 22),
                const Text(
                  'Optional details',
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w800,
                    color: AppColors.mute,
                    letterSpacing: 0.2,
                  ),
                ),
                const SizedBox(height: 10),
                TextField(
                  key: const Key('directory_contact'),
                  controller: _contact,
                  textCapitalization: TextCapitalization.words,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(
                    hintText: 'Contact person',
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  key: const Key('directory_phone'),
                  controller: _phone,
                  keyboardType: TextInputType.phone,
                  textInputAction: TextInputAction.done,
                  onSubmitted: (_) => _confirm(),
                  decoration: const InputDecoration(
                    hintText: 'Phone',
                  ),
                ),
                const SizedBox(height: 12),
                const Text(
                  'You can skip these and fill them later on the customer page.',
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: AppColors.mute,
                    height: 1.4,
                  ),
                ),
              ],
            ),
          ),
          Container(
            width: double.infinity,
            padding: EdgeInsets.fromLTRB(20, 12, 20, bottom + 16),
            decoration: const BoxDecoration(
              color: AppColors.surface,
              border: Border(top: BorderSide(color: AppColors.line)),
            ),
            child: FilledButton(
              key: const Key('directory_add_confirm'),
              onPressed: _confirm,
              child: const Text('Add customer'),
            ),
          ),
        ],
      ),
    );
  }
}
