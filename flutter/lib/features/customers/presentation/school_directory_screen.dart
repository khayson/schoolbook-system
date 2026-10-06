import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/customers/data/school_directory_repository.dart';
import 'package:schoolbook/features/customers/domain/directory_school.dart';
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
    final details = await showModalBottomSheet<_AddDetails>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _AddSheet(school: school),
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
    final messenger = ScaffoldMessenger.of(context);
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
      messenger.showSnackBar(
        SnackBar(content: Text('${customer.name} added as ${customer.code}')),
      );
      router.pushReplacement('/customers/${customer.id}');
    } on ApiException catch (e) {
      if (!e.isOutcomeUnknown) {
        await store.complete(intent);
      }
      if (e.code == 'customer_name_exists' && mounted) {
        // This attempt is over: stop its spinner before asking.
        setState(() => _adding = null);
        final existingId = e.details['customer_id'];
        final link = await showDialog<bool>(
          context: context,
          builder: (context) => AlertDialog(
            title: const Text('Already a customer?'),
            content: Text(
              '${e.details['name']} (${e.details['code']}) is already a customer. Link this school to it instead of adding it again?',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Cancel'),
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
      messenger.showSnackBar(SnackBar(content: Text('${d.title}: ${d.body}')));
    } finally {
      if (mounted) {
        setState(() => _adding = null);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final schools = _schools;
    return Scaffold(
      appBar: AppBar(title: const Text('School directory')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
            child: TextField(
              key: const Key('directory_search'),
              controller: _search,
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.search),
                hintText: 'School name, district or town',
              ),
              onChanged: _onSearchChanged,
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: SegmentedButton<String?>(
              key: const Key('directory_region'),
              segments: const [
                ButtonSegment(value: null, label: Text('Both')),
                ButtonSegment(
                  value: 'Greater Accra',
                  label: Text('Greater Accra'),
                ),
                ButtonSegment(value: 'Central', label: Text('Central')),
              ],
              selected: {_region},
              onSelectionChanged: (s) {
                setState(() => _region = s.first);
                _load();
              },
            ),
          ),
          Expanded(
            child: _error != null
                ? ErrorState(message: _error!, onRetry: _load)
                : schools == null
                ? const LoadingBody()
                : schools.isEmpty
                ? const EmptyState(
                    icon: Icons.search_off,
                    title: 'No school found',
                    subtitle: 'The directory lists the schools mapped on OpenStreetMap. Add any other school with "New customer".',
                  )
                : ListView.builder(
                    itemCount: schools.length + 1,
                    itemBuilder: (context, index) {
                      if (index == schools.length) {
                        return Padding(
                          padding: const EdgeInsets.all(16),
                          child: Text(
                            '${schools.length < _total ? 'First ${schools.length} of $_total. Type more of the name to narrow it down. ' : ''}$_attribution',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        );
                      }
                      final school = schools[index];
                      return ListTile(
                        key: Key('directory_school_${school.id}'),
                        title: Text(school.name),
                        subtitle: Text(
                          school.details.isEmpty
                              ? school.region
                              : school.details,
                        ),
                        trailing: _adding == school.id
                            ? const SizedBox(
                                width: 24,
                                height: 24,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              )
                            : school.isAdded
                            ? const Chip(label: Text('Added'))
                            : const Icon(Icons.person_add_alt_1_outlined),
                        onTap: _adding == null ? () => _open(school) : null,
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}

class _AddDetails {
  const _AddDetails({this.contactPerson, this.phone});

  final String? contactPerson;
  final String? phone;
}

class _AddSheet extends StatefulWidget {
  const _AddSheet({required this.school});

  final DirectorySchool school;

  @override
  State<_AddSheet> createState() => _AddSheetState();
}

class _AddSheetState extends State<_AddSheet> {
  final _contact = TextEditingController();
  late final _phone = TextEditingController(text: widget.school.phone ?? '');

  @override
  void dispose() {
    _contact.dispose();
    _phone.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final school = widget.school;
    return Padding(
      padding: EdgeInsets.fromLTRB(
        16,
        16,
        16,
        16 + MediaQuery.of(context).viewInsets.bottom,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Add as customer',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 8),
          Text(school.name, style: Theme.of(context).textTheme.titleMedium),
          Text(
            [
              school.region,
              school.details,
            ].where((s) => s.isNotEmpty).join(' | '),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('directory_contact'),
            controller: _contact,
            decoration: const InputDecoration(
              labelText: 'Contact person (optional)',
            ),
          ),
          const SizedBox(height: 8),
          TextField(
            key: const Key('directory_phone'),
            controller: _phone,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(labelText: 'Phone (optional)'),
          ),
          const SizedBox(height: 16),
          FilledButton(
            key: const Key('directory_add_confirm'),
            onPressed: () => Navigator.pop(
              context,
              _AddDetails(contactPerson: _contact.text, phone: _phone.text),
            ),
            style: FilledButton.styleFrom(
              minimumSize: const Size.fromHeight(48),
            ),
            child: const Text('Add customer'),
          ),
        ],
      ),
    );
  }
}
