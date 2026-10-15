import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/core/validators.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/app_toast.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

/// Create (no [customerId]) or edit a customer. Balances are never edited here.
///
/// Create keeps only what the API requires (name, type, region) plus an optional
/// credit limit. Contact / notes / active live on edit.
class CustomerFormScreen extends StatefulWidget {
  const CustomerFormScreen({super.key, this.customerId});

  final int? customerId;

  @override
  State<CustomerFormScreen> createState() => _CustomerFormScreenState();
}

class _CustomerFormScreenState extends State<CustomerFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _district = TextEditingController();
  final _address = TextEditingController();
  final _contact = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _creditLimit = TextEditingController();
  final _notes = TextEditingController();
  String _type = 'school';
  String? _region;
  bool _active = true;

  bool _loading = false;
  bool _saving = false;
  String? _loadError;
  ApiException? _saveError;

  bool get _isEdit => widget.customerId != null;

  @override
  void initState() {
    super.initState();
    if (_isEdit) _load();
  }

  @override
  void dispose() {
    for (final c in [
      _name,
      _district,
      _address,
      _contact,
      _phone,
      _email,
      _creditLimit,
      _notes,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _loadError = null;
    });
    try {
      final c = await context.read<CustomersRepository>().getCustomer(
        widget.customerId!,
      );
      _name.text = c.name;
      _district.text = c.district ?? '';
      _address.text = c.address ?? '';
      _contact.text = c.contactPerson ?? '';
      _phone.text = c.phone ?? '';
      _email.text = c.email ?? '';
      _creditLimit.text = c.creditLimit == null
          ? ''
          : Money.toGhsInput(c.creditLimit!);
      _notes.text = c.notes ?? '';
      _type = c.type;
      _region = CustomerOptions.regions.contains(c.region) ? c.region : null;
      _active = c.isActive;
    } on ApiException catch (e) {
      _loadError = e.message;
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String? _blankToNull(TextEditingController c) =>
      c.text.trim().isEmpty ? null : c.text.trim();

  Future<void> _save() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    final payload = <String, dynamic>{
      'name': _name.text.trim(),
      'type': _type,
      'region': _region,
      'credit_limit': _creditLimit.text.trim().isEmpty
          ? null
          : Money.parseGhsToPesewas(_creditLimit.text),
      'is_active': _active,
    };

    if (_isEdit) {
      payload.addAll({
        'district': _blankToNull(_district),
        'address': _blankToNull(_address),
        'contact_person': _blankToNull(_contact),
        'phone': _blankToNull(_phone),
        'email': _blankToNull(_email),
        'notes': _blankToNull(_notes),
      });
    } else {
      // Create: omit empty contact fields so defaults stay null server-side.
      payload.addAll({
        'district': null,
        'address': null,
        'contact_person': null,
        'phone': null,
        'email': null,
        'notes': null,
      });
    }

    setState(() {
      _saving = true;
      _saveError = null;
    });
    final repository = context.read<CustomersRepository>();
    try {
      final saved = _isEdit
          ? await repository.updateCustomer(widget.customerId!, payload)
          : await repository.createCustomer(payload);
      if (!mounted) return;
      AppToast.show(
        context,
        '${saved.name} saved',
        type: ToastType.success,
      );
      _isEdit
          ? context.pop()
          : context.pushReplacement('/customers/${saved.id}');
    } on ApiException catch (e) {
      setState(() => _saveError = e);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    final bottom = MediaQuery.paddingOf(context).bottom;

    return Scaffold(
      backgroundColor: AppColors.surface,
      body: _loading
          ? const LoadingBody()
          : _loadError != null
          ? ErrorState(message: _loadError!, onRetry: _load)
          : Column(
              children: [
                Padding(
                  padding: EdgeInsets.fromLTRB(8, top + 4, 16, 0),
                  child: Row(
                    children: [
                      IconButton(
                        onPressed: () => Navigator.of(context).maybePop(),
                        icon: const AppIcon(AppIcons.back),
                      ),
                      Expanded(
                        child: Text(
                          _isEdit ? 'Edit school' : 'New school',
                          style: const TextStyle(
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
                  child: Form(
                    key: _formKey,
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
                      children: [
                        if (!_isEdit) ...[
                          Container(
                            width: double.infinity,
                            padding: const EdgeInsets.all(14),
                            decoration: BoxDecoration(
                              color: AppColors.limeSoft,
                              border:
                                  Border.all(color: AppColors.lime, width: 1.5),
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: const Text(
                              'Name, type, and region are enough to start. Add contact details later from Edit.',
                              style: TextStyle(
                                fontSize: 13,
                                fontWeight: FontWeight.w700,
                                height: 1.35,
                              ),
                            ),
                          ),
                          const SizedBox(height: 18),
                        ],
                        const _SectionLabel('School'),
                        _field(
                          _name,
                          'School name',
                          key: 'cust_name',
                          validator: (v) =>
                              Validators.required(v, label: 'Name'),
                        ),
                        const SizedBox(height: 4),
                        const Text(
                          'Type',
                          style: TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.w700,
                            color: AppColors.mute,
                          ),
                        ),
                        const SizedBox(height: 8),
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            for (final e in CustomerOptions.types.entries)
                              _TypeChip(
                                label: e.value,
                                active: _type == e.key,
                                onTap: () => setState(() => _type = e.key),
                              ),
                          ],
                        ),
                        const SizedBox(height: 14),
                        DropdownButtonFormField<String>(
                          key: ValueKey('cust_region_$_region'),
                          initialValue: _region,
                          decoration: InputDecoration(
                            labelText: 'Region',
                            enabledBorder: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(12),
                              borderSide:
                                  const BorderSide(color: AppColors.line),
                            ),
                            focusedBorder: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(12),
                              borderSide: const BorderSide(
                                color: AppColors.ink,
                                width: 1.5,
                              ),
                            ),
                          ),
                          items: [
                            for (final r in CustomerOptions.regions)
                              DropdownMenuItem(value: r, child: Text(r)),
                          ],
                          validator: (v) => v == null ? 'Pick a region' : null,
                          onChanged: (v) => setState(() => _region = v),
                        ),
                        const SizedBox(height: 14),
                        _field(
                          _creditLimit,
                          'Credit limit (GHS, blank = no limit)',
                          key: 'cust_credit_limit',
                          keyboard: const TextInputType.numberWithOptions(
                            decimal: true,
                          ),
                          validator: Validators.optionalGhsAmount,
                        ),
                        if (_isEdit) ...[
                          const SizedBox(height: 10),
                          const _SectionLabel('Contact'),
                          _field(_contact, 'Contact person'),
                          _field(
                            _phone,
                            'Phone',
                            keyboard: TextInputType.phone,
                          ),
                          _field(
                            _email,
                            'Email',
                            keyboard: TextInputType.emailAddress,
                            validator: Validators.optionalEmail,
                          ),
                          _field(_district, 'District'),
                          _field(_address, 'Address', maxLines: 2),
                          const SizedBox(height: 10),
                          const _SectionLabel('Notes'),
                          _field(_notes, 'Notes', maxLines: 3),
                          const SizedBox(height: 8),
                          Material(
                            color: AppColors.surface,
                            borderRadius: BorderRadius.circular(12),
                            child: Container(
                              decoration: BoxDecoration(
                                border: Border.all(color: AppColors.line),
                                borderRadius: BorderRadius.circular(12),
                              ),
                              child: SwitchListTile(
                                contentPadding: const EdgeInsets.symmetric(
                                  horizontal: 14,
                                  vertical: 4,
                                ),
                                title: const Text(
                                  'Active',
                                  style: TextStyle(fontWeight: FontWeight.w800),
                                ),
                                subtitle: const Text(
                                  'Inactive schools can still pay but cannot get new sales.',
                                  style: TextStyle(
                                    fontWeight: FontWeight.w600,
                                    color: AppColors.mute,
                                  ),
                                ),
                                activeThumbColor: AppColors.ink,
                                activeTrackColor: AppColors.lime,
                                value: _active,
                                onChanged: (v) => setState(() => _active = v),
                              ),
                            ),
                          ),
                        ],
                        if (_saveError != null) ...[
                          const SizedBox(height: 12),
                          Container(
                            padding: const EdgeInsets.all(14),
                            decoration: BoxDecoration(
                              color: AppColors.danger.withValues(alpha: 0.08),
                              border: Border.all(color: AppColors.danger),
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: Text(
                              describeApiError(_saveError!).body,
                              style: const TextStyle(
                                color: AppColors.danger,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                        ],
                      ],
                    ),
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
                    key: const Key('cust_save'),
                    style: FilledButton.styleFrom(
                      minimumSize: const Size.fromHeight(48),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                    onPressed: _saving ? null : _save,
                    child: Text(_saving ? 'Saving…' : 'Save'),
                  ),
                ),
              ],
            ),
    );
  }

  Widget _field(
    TextEditingController controller,
    String label, {
    String? key,
    int maxLines = 1,
    TextInputType? keyboard,
    String? Function(String?)? validator,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextFormField(
        key: key == null ? null : Key(key),
        controller: controller,
        maxLines: maxLines,
        keyboardType: keyboard,
        validator: validator,
        decoration: InputDecoration(
          hintText: label,
          labelText: label,
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: const BorderSide(color: AppColors.line),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: const BorderSide(color: AppColors.ink, width: 1.5),
          ),
          errorBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: const BorderSide(color: AppColors.danger),
          ),
          focusedErrorBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: const BorderSide(color: AppColors.danger, width: 1.5),
          ),
        ),
      ),
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Text(
        text.toUpperCase(),
        style: const TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w800,
          letterSpacing: 0.8,
          color: AppColors.mute,
        ),
      ),
    );
  }
}

class _TypeChip extends StatelessWidget {
  const _TypeChip({
    required this.label,
    required this.active,
    required this.onTap,
  });

  final String label;
  final bool active;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: active ? AppColors.ink : AppColors.surface,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: BoxDecoration(
            border: Border.all(
              color: active ? AppColors.ink : AppColors.line,
            ),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Text(
            label,
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w800,
              color: active ? AppColors.lime : AppColors.ink,
            ),
          ),
        ),
      ),
    );
  }
}
